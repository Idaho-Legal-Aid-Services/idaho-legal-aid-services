const assert = require('node:assert/strict');
const path = require('node:path');
const test = require('node:test');

const {
  evaluateMetricSet,
  findStructuredError,
  parseResultsPassRate,
  renderAssistantFixture,
  summarizeDiagnosticResults,
} = require('../../lib/gate-metrics');

function fixturePath(name) {
  return path.join(__dirname, '..', 'fixtures', name);
}

test('renderAssistantFixture emits strict contract_meta without counting derived links as citations', () => {
  const rendered = renderAssistantFixture(fixturePath('assistant-response-derived-citations.json'));

  assert.equal(rendered.hasContractMetaLine, true);
  assert.equal(rendered.hasProviderMetaLine, true);
  assert.match(rendered.output, /\[contract_meta\]/);
  assert.match(rendered.output, /\[ilas_provider_meta\]/);
  const requiredKeys = [
    'citations_count',
    'confidence',
    'decision_reason',
    'derived_citation_count',
    'grounded',
    'grounding_status',
    'lexical_citation_count',
    'lexical_result_count',
    'reason_code',
    'response_mode',
    'response_type',
    'result_source_classes',
    'supported_citations_count',
    'vector_citation_count',
    'vector_result_count',
  ];
  for (const key of requiredKeys) {
    assert.equal(Object.prototype.hasOwnProperty.call(rendered.contractMeta, key), true, key);
  }
  for (const key of ['results_count', 'result_urls', 'citation_urls', 'fallback_used', 'vector_used']) {
    assert.equal(Object.prototype.hasOwnProperty.call(rendered.contractMeta, key), true, key);
  }
  assert.equal(rendered.contractMeta.citations_count, 0);
  assert.equal(rendered.contractMeta.supported_citations_count, 0);
  assert.equal(rendered.contractMeta.derived_citation_count, 3);
  assert.equal(rendered.contractMeta.grounded, false);
  assert.equal(rendered.contractMeta.grounding_status, 'unsupported_link_or_result_only');
  assert.equal(rendered.contractMeta.results_count, 2);
  assert.equal(rendered.contractMeta.response_type, 'search_results');
  assert.equal(rendered.contractMeta.response_mode, 'grounded_answer');
  assert.equal(rendered.contractMeta.reason_code, 'retrieval_match');
  assert.equal(rendered.contractMeta.decision_reason, 'ranked_search_match');
  assert.deepEqual(rendered.contractMeta.result_source_classes, []);
  assert.equal(rendered.contractMeta.vector_result_count, 0);
  assert.equal(rendered.contractMeta.lexical_result_count, 0);
  assert.equal(rendered.contractMeta.vector_citation_count, 0);
  assert.equal(rendered.contractMeta.lexical_citation_count, 0);
  assert.deepEqual(rendered.contractMeta.result_urls, [
    'https://idaholegalaid.org/issues/family-law',
    'https://idaholegalaid.org/forms/court-assistance',
  ]);
  assert.deepEqual(rendered.contractMeta.citation_urls, []);
  assert.equal(rendered.contractMeta.vector_used, false);
  assert.equal(rendered.providerMeta.grounded, false);
  assert.equal(rendered.providerMeta.supported_citations_count, 0);
});

test('evaluateMetricSet passes when retrieval thresholds meet score and count floors', () => {
  const report = evaluateMetricSet(
    fixturePath('gate-results-rag-pass.json'),
    ['rag-contract-meta-present', 'rag-citation-coverage', 'rag-low-confidence-refusal'],
    { threshold: 90, minCount: 10 }
  );

  assert.equal(report.fail, false);
  assert.deepEqual(
    report.metrics.map((metric) => ({
      metric: metric.metricName,
      fail: metric.fail,
      countFail: metric.countFail,
    })),
    [
      { metric: 'rag-contract-meta-present', fail: false, countFail: false },
      { metric: 'rag-citation-coverage', fail: false, countFail: false },
      { metric: 'rag-low-confidence-refusal', fail: false, countFail: false },
    ]
  );
});

test('evaluateMetricSet fails when a retrieval metric falls below threshold', () => {
  const report = evaluateMetricSet(
    fixturePath('gate-results-rag-low-rate.json'),
    ['rag-contract-meta-present', 'rag-citation-coverage', 'rag-low-confidence-refusal'],
    { threshold: 90, minCount: 10 }
  );

  assert.equal(report.fail, true);
  const citationMetric = report.metrics.find((metric) => metric.metricName === 'rag-citation-coverage');
  assert.equal(citationMetric.rate, 70);
  assert.equal(citationMetric.countFail, false);
  assert.equal(citationMetric.fail, true);
});

test('evaluateMetricSet fails when required retrieval metric counts are missing', () => {
  const report = evaluateMetricSet(
    fixturePath('gate-results-rag-missing-count.json'),
    ['rag-contract-meta-present', 'rag-citation-coverage', 'rag-low-confidence-refusal'],
    { threshold: 90, minCount: 10 }
  );

  assert.equal(report.fail, true);
  const contractMetaMetric = report.metrics.find((metric) => metric.metricName === 'rag-contract-meta-present');
  const citationMetric = report.metrics.find((metric) => metric.metricName === 'rag-citation-coverage');

  assert.equal(contractMetaMetric.count, 0);
  assert.equal(contractMetaMetric.countFail, true);
  assert.equal(contractMetaMetric.fail, true);
  assert.equal(citationMetric.count, 0);
  assert.equal(citationMetric.countFail, true);
  assert.equal(citationMetric.fail, true);
});

test('evaluateMetricSet skips dimensions the running config does not plan', () => {
  const report = evaluateMetricSet(
    fixturePath('gate-results-rag-missing-count.json'),
    ['rag-contract-meta-present', 'rag-citation-coverage'],
    {
      threshold: 90,
      minCount: 10,
      plannedCounts: { 'rag-contract-meta-present': 0, 'rag-citation-coverage': 0 },
    }
  );

  assert.equal(report.fail, false);
  for (const metric of report.metrics) {
    assert.equal(metric.skipped, true);
    assert.equal(metric.countFail, false);
    assert.equal(metric.fail, false);
  }
});

test('evaluateMetricSet caps the required count at the planned count', () => {
  // gate-results-rag-pass.json carries 12 cases per rag metric; a config
  // planning only 12 must pass even though minCount would allow more, and a
  // config planning fewer than minCount must require exactly its plan.
  const report = evaluateMetricSet(
    fixturePath('gate-results-rag-pass.json'),
    ['rag-contract-meta-present'],
    {
      threshold: 90,
      minCount: 10,
      plannedCounts: { 'rag-contract-meta-present': 5 },
    }
  );

  const metric = report.metrics[0];
  assert.equal(metric.skipped, false);
  assert.equal(metric.requiredCount, 5);
  assert.equal(metric.countFail, false);
  assert.equal(report.fail, false);
});

test('evaluateMetricSet still fails when observed count falls below the plan', () => {
  const report = evaluateMetricSet(
    fixturePath('gate-results-rag-missing-count.json'),
    ['rag-contract-meta-present'],
    {
      threshold: 90,
      minCount: 10,
      plannedCounts: { 'rag-contract-meta-present': 5 },
    }
  );

  const metric = report.metrics[0];
  assert.equal(metric.skipped, false);
  assert.equal(metric.count, 0);
  assert.equal(metric.countFail, true);
  assert.equal(metric.fail, true);
  assert.equal(report.fail, true);
});

test('countPlannedMetricCases counts metric labels across config test files', () => {
  const { countPlannedMetricCases } = require('../../lib/gate-metrics');
  const path = require('node:path');
  const configPath = path.join(__dirname, '..', '..', 'promptfooconfig.quality.yaml');
  const planned = countPlannedMetricCases(configPath, [
    'rag-contract-meta-present',
    'p2del04-contract-meta-present',
  ]);

  assert.equal(planned['rag-contract-meta-present'] > 0, true);
  assert.equal(planned['p2del04-contract-meta-present'], 0);
});

test('gate metrics helper reports pass-rate summaries and structured eval errors', () => {
  const passRate = parseResultsPassRate(fixturePath('gate-results-rag-low-rate.json'));
  const structuredError = findStructuredError(fixturePath('gate-results-rag-missing-count.json'));

  assert.deepEqual(passRate, { rate: 80, total: 10, passed: 8 });
  assert.deepEqual(structuredError, {
    kind: 'eval',
    code: 'missing_metric_count',
    message: 'retrieval threshold metric count missing',
  });
});

test('diagnostic summary includes grouped quality dimensions', () => {
  const summary = summarizeDiagnosticResults([fixturePath('gate-results-rag-pass.json')], {
    config_file: 'promptfooconfig.quality.yaml',
  });

  const retrievalGroup = summary.metric_groups.find((group) => group.group === 'retrieval_quality');
  const groundingGroup = summary.metric_groups.find((group) => group.group === 'grounding_quality');

  assert.ok(retrievalGroup);
  assert.ok(groundingGroup);
  assert.equal(retrievalGroup.count > 0, true);
  assert.equal(groundingGroup.count > 0, true);
});
