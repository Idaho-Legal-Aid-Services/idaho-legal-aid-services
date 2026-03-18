const assert = require('node:assert/strict');
const path = require('node:path');
const test = require('node:test');

const {
  evaluateMetricSet,
  findStructuredError,
  parseResultsPassRate,
  renderAssistantFixture,
} = require('../../lib/gate-metrics');

function fixturePath(name) {
  return path.join(__dirname, '..', 'fixtures', name);
}

test('renderAssistantFixture emits contract_meta with required fields and derived citation count', () => {
  const rendered = renderAssistantFixture(fixturePath('assistant-response-derived-citations.json'));

  assert.equal(rendered.hasContractMetaLine, true);
  assert.match(rendered.output, /\[contract_meta\]/);
  assert.deepEqual(
    Object.keys(rendered.contractMeta).sort(),
    [
      'citations_count',
      'confidence',
      'decision_reason',
      'lexical_citation_count',
      'lexical_result_count',
      'reason_code',
      'response_mode',
      'response_type',
      'result_source_classes',
      'vector_citation_count',
      'vector_result_count',
    ]
  );
  assert.equal(rendered.contractMeta.citations_count, 3);
  assert.equal(rendered.contractMeta.response_type, 'search_results');
  assert.equal(rendered.contractMeta.response_mode, 'grounded_answer');
  assert.equal(rendered.contractMeta.reason_code, 'retrieval_match');
  assert.equal(rendered.contractMeta.decision_reason, 'ranked_search_match');
  assert.deepEqual(rendered.contractMeta.result_source_classes, []);
  assert.equal(rendered.contractMeta.vector_result_count, 0);
  assert.equal(rendered.contractMeta.lexical_result_count, 0);
  assert.equal(rendered.contractMeta.vector_citation_count, 0);
  assert.equal(rendered.contractMeta.lexical_citation_count, 0);
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
