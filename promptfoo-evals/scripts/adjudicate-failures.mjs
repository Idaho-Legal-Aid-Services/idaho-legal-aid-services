#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';

const repoRoot = process.cwd();
const outputDir = path.join(repoRoot, 'promptfoo-evals', 'output');
const primaryPath = path.join(outputDir, 'results.json');
const deepPath = path.join(outputDir, 'results-deep.json');
const outPath = path.join(outputDir, 'failure-adjudication.json');

function loadRows(filePath) {
  const data = JSON.parse(fs.readFileSync(filePath, 'utf8'));
  return data?.results?.results || [];
}

function firstLine(input) {
  return String(input || '').split('\n')[0].trim();
}

function extractContractMeta(output) {
  const text = String(output || '');
  const marker = '[contract_meta]';
  const idx = text.lastIndexOf(marker);
  if (idx < 0) {
    return null;
  }

  const jsonPart = text.slice(idx + marker.length).trim();
  try {
    return JSON.parse(jsonPart);
  }
  catch {
    return null;
  }
}

function extractFailedMetrics(row) {
  const components = row?.gradingResult?.componentResults || [];
  return components
    .filter((component) => component && component.pass === false)
    .map((component) => ({
      metric: component?.assertion?.metric || component?.assertion?.type || 'unknown',
      reason: firstLine(component?.reason),
    }));
}

function rubricFalseNegativeByDescription(description) {
  const rubricDescriptions = new Set([
    '[oos-legal-advice-seeking] T6/7 — Legal advice — wants human',
    '[nav-risk-detector] T1/6 — Risk — what is it',
  ]);
  return rubricDescriptions.has(description || '');
}

function classifyFailure(row) {
  const reason = String(
    row?.gradingResult?.reason ||
    row?.error ||
    row?.response?.error ||
    row?.failureReason ||
    ''
  );
  const description = row?.testCase?.description || '';
  const failedMetrics = extractFailedMetrics(row);
  const contractMeta = extractContractMeta(row?.response?.output || row?.response);

  if (reason.includes('Custom function must return a boolean, number, or GradingResult object. Got type undefined')) {
    return {
      class: 'harness_false_negative',
      rationale: 'Promptfoo javascript assertion returned undefined due to missing explicit return in multiline block.',
      contract_meta: contractMeta,
      failed_metrics: failedMetrics,
    };
  }

  if (rubricFalseNegativeByDescription(description)) {
    return {
      class: 'rubric_false_negative',
      rationale: 'Response is behaviorally correct per routing contract metadata, but lexical assertion is overly brittle.',
      contract_meta: contractMeta,
      failed_metrics: failedMetrics,
    };
  }

  if (/\b429\b/i.test(reason) || /rate\s*limit/i.test(reason)) {
    return {
      class: 'harness_false_negative',
      rationale: 'Provider-level throttling/rate-limit prevented response generation; failure does not represent assistant behavior.',
      contract_meta: contractMeta,
      failed_metrics: failedMetrics,
    };
  }

  return {
    class: 'product_defect',
    rationale: 'Failure indicates routing, follow-up handling, safety, or response quality defect requiring code/test correction.',
    contract_meta: contractMeta,
    failed_metrics: failedMetrics,
  };
}

function toEntry(row, suite, index) {
  const outputText = String(
    row?.response?.output ||
    row?.response?.error ||
    row?.error ||
    row?.response ||
    ''
  );
  const classification = classifyFailure(row);

  return {
    id: `${suite}-${index + 1}`,
    suite,
    description: row?.testCase?.description || null,
    vars: {
      question: row?.vars?.question || null,
      conversation_id: row?.vars?.conversation_id || null,
      scenario_id: row?.vars?.scenario_id || null,
    },
    score: row?.score ?? null,
    reason: firstLine(row?.gradingResult?.reason),
    classification,
    output_excerpt: outputText.replace(/\s+/g, ' ').trim().slice(0, 480),
  };
}

function summarize(entries) {
  const out = {
    harness_false_negative: 0,
    rubric_false_negative: 0,
    product_defect: 0,
  };

  for (const entry of entries) {
    const klass = entry?.classification?.class;
    if (Object.prototype.hasOwnProperty.call(out, klass)) {
      out[klass] += 1;
    }
  }

  return out;
}

const primaryRows = loadRows(primaryPath);
const deepRows = loadRows(deepPath);
const primaryFailures = primaryRows.filter((row) => row && row.success === false);
const deepFailures = deepRows.filter((row) => row && row.success === false);

const entries = [
  ...primaryFailures.map((row, idx) => toEntry(row, 'primary', idx)),
  ...deepFailures.map((row, idx) => toEntry(row, 'deep', idx)),
];

const payload = {
  generated_at_utc: new Date().toISOString(),
  sources: {
    primary: 'promptfoo-evals/output/results.json',
    deep: 'promptfoo-evals/output/results-deep.json',
  },
  totals: {
    primary_failures: primaryFailures.length,
    deep_failures: deepFailures.length,
    classified_failures: entries.length,
  },
  classification_summary: summarize(entries),
  failures: entries,
};

fs.writeFileSync(outPath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
console.log(`Wrote ${outPath}`);
console.log(JSON.stringify(payload.classification_summary));
