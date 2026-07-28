const fs = require('node:fs');

const { parseStructuredError, renderAssistantOutput } = require('./ilas-live-shared');

function loadJsonFile(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function readPromptfooResults(resultsInput) {
  if (typeof resultsInput === 'string') {
    return loadJsonFile(resultsInput);
  }
  return resultsInput || {};
}

function getPromptMetrics(resultsInput) {
  const data = readPromptfooResults(resultsInput);
  const prompts = data?.results?.prompts;
  return Array.isArray(prompts) ? prompts : [];
}

function getResultRows(resultsInput) {
  const data = readPromptfooResults(resultsInput);
  const rows = data?.results?.results || data?.results || [];
  return Array.isArray(rows) ? rows : [];
}

function firstLine(input) {
  return String(input || '').split(/\r?\n/)[0].trim();
}

function roundRate(rate) {
  return Number(rate.toFixed(1));
}

function parseResultsPassRate(resultsInput) {
  const rows = getResultRows(resultsInput);
  const total = rows.length;
  const passed = rows.filter((row) => row && row.success).length;
  const rate = total > 0 ? roundRate((100 * passed) / total) : 0;

  return { rate, total, passed };
}

function findStructuredError(resultsInput) {
  const rows = getResultRows(resultsInput);
  const sources = [];

  for (const row of rows) {
    sources.push(
      row?.error,
      row?.response?.error,
      row?.failureReason,
      row?.gradingResult?.reason
    );
  }

  for (const value of sources) {
    const parsed = parseStructuredError(value);
    if (parsed) {
      return parsed;
    }
  }

  return null;
}

function getStructuredErrorsForRow(row) {
  const sources = [
    row?.error,
    row?.response?.error,
    row?.failureReason,
    row?.gradingResult?.reason,
  ];
  const parsedErrors = [];
  const seen = new Set();

  for (const value of sources) {
    const parsed = parseStructuredError(value);
    if (!parsed) {
      continue;
    }

    const key = JSON.stringify([
      parsed.kind || '',
      parsed.code || '',
      parsed.status ?? '',
      parsed.phase || '',
    ]);
    if (seen.has(key)) {
      continue;
    }
    seen.add(key);
    parsedErrors.push(parsed);
  }

  return parsedErrors;
}

function isFailureRow(row) {
  if (typeof row?.success === 'boolean') {
    return row.success === false;
  }
  if (typeof row?.gradingResult?.pass === 'boolean') {
    return row.gradingResult.pass === false;
  }
  return Boolean(
    row?.error ||
    row?.response?.error ||
    row?.failureReason ||
    row?.gradingResult?.reason
  );
}

function suiteNameFromPath(filePath) {
  const text = String(filePath || '');
  if (text.includes('results-smoke')) {
    return 'smoke';
  }
  if (text.includes('results-deep')) {
    return 'deep';
  }
  return 'primary';
}

const METRIC_GROUP_DEFINITIONS = {
  mechanical_transport: [
    /provider-metadata-present$/,
    /provider-live-mode$/,
    /non-empty-response$/,
    /no-error-keywords$/,
    /no-stack-or-debug-output$/,
    /contract-meta-readable$/,
  ],
  retrieval_quality: [
    /^rag-contract-meta-present$/,
    /^quality-retrieval-attempted$/,
    /^quality-vector-grounded-retrieval$/,
    /^rag-vector-provenance$/,
  ],
  grounding_quality: [
    /^rag-citation-coverage$/,
    /^quality-grounding-proof$/,
    /^quality-supported-citation-topic-support$/,
    /^quality-no-unsupported-claim$/,
  ],
  safety_quality: [
    /^quality-must-not-safety$/,
    /^quality-refusal-quality$/,
    /^quality-safety-boundary-routing$/,
    /^quality-unsafe-dv-instruction-blocked$/,
    /^quality-urgent-safety-routing$/,
    /^quality-confidence-calibration$/,
  ],
  multi_turn_continuity: [
    /^quality-conversation-continuity/,
    /^quality-conversation-correction$/,
    /^quality-spanish-continuity/,
    /^golden-conversation-/,
  ],
  provider_provenance_proof: [
    /^quality-provider-proof$/,
    /^quality-stable-conversation-trace$/,
    /^rag-vector-provenance$/,
  ],
  generic_fallback_failures: [
    /^quality-no-generic-fallback$/,
    /^smoke-no-generic-fallback$/,
  ],
};

function extractFailureText(row) {
  return String(
    row?.response?.output ||
    row?.response?.error ||
    row?.error ||
    row?.failureReason ||
    row?.gradingResult?.reason ||
    ''
  );
}

function normalizeExcerpt(text) {
  return String(text || '').replace(/\s+/g, ' ').trim().slice(0, 240);
}

function metricMatchesGroup(metricName, matchers = []) {
  return matchers.some((matcher) => matcher.test(metricName));
}

function summarizeMetricGroups(resultSources) {
  const groups = Object.entries(METRIC_GROUP_DEFINITIONS).map(([name]) => ({
    group: name,
    score: 0,
    count: 0,
  }));
  const groupsByName = new Map(groups.map((group) => [group.group, group]));
  const sources = Array.isArray(resultSources) ? resultSources : [];

  for (const source of sources) {
    const filePath = typeof source === 'string' ? source : source?.filePath;
    if (!filePath || !fs.existsSync(filePath)) {
      continue;
    }

    for (const prompt of getPromptMetrics(filePath)) {
      const namedScores = prompt?.metrics?.namedScores || {};
      const namedCounts = prompt?.metrics?.namedScoresCount || {};
      for (const metricName of Object.keys(namedCounts)) {
        const count = Number(namedCounts[metricName] || 0);
        const score = Number(namedScores[metricName] || 0);
        if (count <= 0) {
          continue;
        }
        for (const [groupName, matchers] of Object.entries(METRIC_GROUP_DEFINITIONS)) {
          if (!metricMatchesGroup(metricName, matchers)) {
            continue;
          }
          const group = groupsByName.get(groupName);
          group.count += count;
          group.score += score;
        }
      }
    }
  }

  return groups.map((group) => ({
    ...group,
    rate: group.count > 0 ? roundRate((group.score * 100) / group.count) : 0,
    pass: group.count > 0 ? roundRate((group.score * 100) / group.count) >= 90 : false,
  }));
}

function summarizeDiagnosticResults(resultSources, context = {}) {
  const sources = Array.isArray(resultSources) ? resultSources : [];
  const suites = [];
  const errorCounts = new Map();
  const firstFailures = [];
  let totalCases = 0;
  let failureCases = 0;

  for (const source of sources) {
    const filePath = typeof source === 'string' ? source : source?.filePath;
    if (!filePath || !fs.existsSync(filePath)) {
      continue;
    }

    const suite = typeof source === 'string' ? suiteNameFromPath(filePath) : (source?.suite || suiteNameFromPath(filePath));
    const rows = getResultRows(filePath);
    let suiteFailures = 0;
    totalCases += rows.length;

    for (const row of rows) {
      const structuredErrors = getStructuredErrorsForRow(row);
      const failed = isFailureRow(row) || structuredErrors.length > 0;
      if (!failed) {
        continue;
      }

      suiteFailures += 1;
      failureCases += 1;

      const errorsForCounting = structuredErrors.length > 0
        ? structuredErrors
        : [{
            kind: 'eval',
            code: 'assertion_failed',
            status: null,
            message: firstLine(
              row?.gradingResult?.reason ||
              row?.failureReason ||
              row?.error ||
              row?.response?.error ||
              ''
            ),
          }];

      for (const error of errorsForCounting) {
        const key = JSON.stringify([
          error.kind || '',
          error.code || '',
          error.status ?? '',
        ]);
        const current = errorCounts.get(key) || {
          kind: error.kind || 'unknown',
          code: error.code || 'unknown',
          status: error.status ?? null,
          count: 0,
        };
        current.count += 1;
        errorCounts.set(key, current);
      }

      if (firstFailures.length < 5) {
        const primaryError = errorsForCounting[0];
        firstFailures.push({
          suite,
          prompt_id: row?.promptId || row?.id || null,
          scenario_id: row?.vars?.scenario_id || row?.testCase?.metadata?.scenario_id || row?.metadata?.scenario_id || null,
          question: row?.vars?.question || row?.testCase?.vars?.question || null,
          description: row?.testCase?.description || null,
          kind: primaryError.kind || 'unknown',
          code: primaryError.code || 'unknown',
          status: primaryError.status ?? null,
          excerpt: normalizeExcerpt(extractFailureText(row)),
        });
      }
    }

    suites.push({
      suite,
      file: filePath,
      total_cases: rows.length,
      failure_cases: suiteFailures,
    });
  }

  const sortedErrorCounts = Array.from(errorCounts.values()).sort((left, right) => {
    if (right.count !== left.count) {
      return right.count - left.count;
    }
    return `${left.kind}/${left.code}/${left.status ?? ''}`.localeCompare(
      `${right.kind}/${right.code}/${right.status ?? ''}`
    );
  });

  return {
    generated_at_utc: new Date().toISOString(),
    context,
    totals: {
      total_cases: totalCases,
      failure_cases: failureCases,
    },
    suites,
    metric_groups: summarizeMetricGroups(resultSources),
    error_counts: sortedErrorCounts,
    first_failures: firstFailures,
  };
}

function formatDiagnosticSummaryText(summary) {
  const lines = [];
  const context = summary?.context || {};
  const totals = summary?.totals || {};
  const suites = Array.isArray(summary?.suites) ? summary.suites : [];
  const metricGroups = Array.isArray(summary?.metric_groups) ? summary.metric_groups : [];
  const errorCounts = Array.isArray(summary?.error_counts) ? summary.error_counts : [];
  const firstFailures = Array.isArray(summary?.first_failures) ? summary.first_failures : [];

  const orderedContextFields = [
    'assistant_url',
    'target_host',
    'target_env',
    'target_kind',
    'target_source',
    'mode',
    'config_file',
    'effective_pacing_rate_per_minute',
    'effective_request_delay_ms',
    'planned_message_request_budget',
  ];

  for (const field of orderedContextFields) {
    if (Object.prototype.hasOwnProperty.call(context, field)) {
      lines.push(`${field}=${context[field] ?? ''}`);
    }
  }

  lines.push(`total_cases=${totals.total_cases ?? 0}`);
  lines.push(`failure_cases=${totals.failure_cases ?? 0}`);

  for (const suite of suites) {
    lines.push(
      `suite=${suite.suite} total_cases=${suite.total_cases ?? 0} failure_cases=${suite.failure_cases ?? 0}`
    );
  }

  lines.push('quality_groups:');
  if (metricGroups.length === 0) {
    lines.push('  none');
  } else {
    for (const group of metricGroups) {
      lines.push(
        `  group=${group.group} rate=${group.rate ?? 0} score=${group.score ?? 0} count=${group.count ?? 0} pass=${group.pass ? 'yes' : 'no'}`
      );
    }
  }

  lines.push('error_counts:');
  if (errorCounts.length === 0) {
    lines.push('  none');
  } else {
    for (const error of errorCounts) {
      lines.push(
        `  kind=${error.kind} code=${error.code} status=${error.status ?? 'none'} count=${error.count}`
      );
    }
  }

  lines.push('first_failures:');
  if (firstFailures.length === 0) {
    lines.push('  none');
  } else {
    for (const failure of firstFailures) {
      lines.push(
        `  suite=${failure.suite} prompt_id=${failure.prompt_id ?? 'n/a'} scenario_id=${failure.scenario_id ?? 'n/a'} kind=${failure.kind} code=${failure.code} status=${failure.status ?? 'none'}`
      );
      lines.push(`  question=${failure.question ?? ''}`);
      lines.push(`  excerpt=${failure.excerpt ?? ''}`);
    }
  }

  return `${lines.join('\n')}\n`;
}

function summarizeNamedMetric(resultsInput, metricName) {
  let score = 0;
  let count = 0;

  for (const prompt of getPromptMetrics(resultsInput)) {
    const namedScores = prompt?.metrics?.namedScores || {};
    const namedCounts = prompt?.metrics?.namedScoresCount || {};
    if (!Object.prototype.hasOwnProperty.call(namedCounts, metricName)) {
      continue;
    }

    score += Number(namedScores[metricName] || 0);
    count += Number(namedCounts[metricName] || 0);
  }

  const rate = count > 0 ? roundRate((score * 100) / count) : 0;
  return { metricName, rate, score, count };
}

function countPlannedMetricCases(configFile, metricNames) {
  const path = require('node:path');
  const yaml = require('js-yaml');

  const configDir = path.dirname(configFile);
  const config = yaml.load(fs.readFileSync(configFile, 'utf8')) || {};
  const wanted = new Set(metricNames);
  const planned = {};
  for (const name of metricNames) {
    planned[name] = 0;
  }

  const countAsserts = (testCase, into) => {
    const asserts = Array.isArray(testCase?.assert) ? testCase.assert : [];
    for (const assertion of asserts) {
      const metric = assertion?.metric;
      if (metric && wanted.has(metric)) {
        into[metric] += 1;
      }
    }
  };

  const testEntries = Array.isArray(config.tests) ? config.tests : [];
  let caseCount = 0;
  for (const entry of testEntries) {
    if (typeof entry === 'string' && entry.startsWith('file://')) {
      const testFile = path.resolve(configDir, entry.slice('file://'.length));
      const cases = yaml.load(fs.readFileSync(testFile, 'utf8'));
      for (const testCase of Array.isArray(cases) ? cases : []) {
        caseCount += 1;
        countAsserts(testCase, planned);
      }
    } else if (entry && typeof entry === 'object') {
      caseCount += 1;
      countAsserts(entry, planned);
    }
  }

  // defaultTest assertions apply to every case in the suite.
  const defaultCounts = {};
  for (const name of metricNames) {
    defaultCounts[name] = 0;
  }
  countAsserts(config.defaultTest, defaultCounts);
  for (const name of metricNames) {
    planned[name] += defaultCounts[name] * caseCount;
  }

  return planned;
}

function evaluateMetricThreshold(resultsInput, metricName, options = {}) {
  const threshold = Number(options.threshold ?? 0);
  const minCount = Number(options.minCount ?? 0);
  const plannedCount = options.plannedCount;
  const summary = summarizeNamedMetric(resultsInput, metricName);

  // When the running config plans zero cases for this metric, the dimension
  // is out of scope for this suite (it belongs to a fuller config such as
  // the deploy gate). Report it as skipped instead of failing on count.
  if (Number.isFinite(plannedCount) && plannedCount === 0) {
    return {
      ...summary,
      threshold,
      minCount,
      requiredCount: 0,
      skipped: true,
      countFail: false,
      fail: false,
    };
  }

  // With a known plan, require every planned case to be present (capped at
  // minCount for large suites) so label drift/shrinkage is still caught.
  const requiredCount = Number.isFinite(plannedCount)
    ? Math.min(minCount, plannedCount)
    : minCount;
  const countFail = !Number.isFinite(summary.count) || summary.count < requiredCount;
  const fail =
    countFail ||
    !Number.isFinite(summary.rate) ||
    summary.rate < threshold;

  return {
    ...summary,
    threshold,
    minCount,
    requiredCount,
    skipped: false,
    countFail,
    fail,
  };
}

function evaluateMetricSet(resultsInput, metricNames, options = {}) {
  const plannedCounts = options.plannedCounts || null;
  const metrics = metricNames.map((metricName) =>
    evaluateMetricThreshold(resultsInput, metricName, {
      ...options,
      plannedCount: plannedCounts ? Number(plannedCounts[metricName] ?? 0) : undefined,
    })
  );

  return {
    threshold: Number(options.threshold ?? 0),
    minCount: Number(options.minCount ?? 0),
    fail: metrics.some((metric) => metric.fail),
    metrics,
  };
}

function parseContractMetaLine(output) {
  const line = String(output || '')
    .split(/\r?\n/)
    .find((candidate) => candidate.startsWith('[contract_meta]'));

  if (!line) {
    return null;
  }

  try {
    return JSON.parse(line.slice('[contract_meta]'.length));
  } catch (_) {
    return null;
  }
}

function parseProviderMetaLine(output) {
  const line = String(output || '')
    .split(/\r?\n/)
    .find((candidate) => candidate.startsWith('[ilas_provider_meta]'));

  if (!line) {
    return null;
  }

  try {
    return JSON.parse(line.slice('[ilas_provider_meta]'.length));
  } catch (_) {
    return null;
  }
}

function renderAssistantFixture(fixturePath, siteBaseUrl) {
  const payload = loadJsonFile(fixturePath);
  const output = renderAssistantOutput(payload, siteBaseUrl);
  const contractMeta = parseContractMetaLine(output);
  const providerMeta = parseProviderMetaLine(output);

  return {
    output,
    hasContractMetaLine: Boolean(contractMeta),
    hasProviderMetaLine: Boolean(providerMeta),
    contractMeta,
    providerMeta,
  };
}

module.exports = {
  countPlannedMetricCases,
  evaluateMetricSet,
  evaluateMetricThreshold,
  formatDiagnosticSummaryText,
  findStructuredError,
  getPromptMetrics,
  getResultRows,
  getStructuredErrorsForRow,
  isFailureRow,
  parseContractMetaLine,
  parseResultsPassRate,
  readPromptfooResults,
  renderAssistantFixture,
  summarizeDiagnosticResults,
  summarizeMetricGroups,
  summarizeNamedMetric,
};
