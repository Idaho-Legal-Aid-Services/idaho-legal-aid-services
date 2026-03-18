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
    error_counts: sortedErrorCounts,
    first_failures: firstFailures,
  };
}

function formatDiagnosticSummaryText(summary) {
  const lines = [];
  const context = summary?.context || {};
  const totals = summary?.totals || {};
  const suites = Array.isArray(summary?.suites) ? summary.suites : [];
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

function evaluateMetricThreshold(resultsInput, metricName, options = {}) {
  const threshold = Number(options.threshold ?? 0);
  const minCount = Number(options.minCount ?? 0);
  const summary = summarizeNamedMetric(resultsInput, metricName);
  const countFail = !Number.isFinite(summary.count) || summary.count < minCount;
  const fail =
    countFail ||
    !Number.isFinite(summary.rate) ||
    summary.rate < threshold;

  return {
    ...summary,
    threshold,
    minCount,
    countFail,
    fail,
  };
}

function evaluateMetricSet(resultsInput, metricNames, options = {}) {
  const metrics = metricNames.map((metricName) =>
    evaluateMetricThreshold(resultsInput, metricName, options)
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

function renderAssistantFixture(fixturePath, siteBaseUrl) {
  const payload = loadJsonFile(fixturePath);
  const output = renderAssistantOutput(payload, siteBaseUrl);
  const contractMeta = parseContractMetaLine(output);

  return {
    output,
    hasContractMetaLine: Boolean(contractMeta),
    contractMeta,
  };
}

module.exports = {
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
  summarizeNamedMetric,
};
