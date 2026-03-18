#!/usr/bin/env node

const {
  evaluateMetricSet,
  formatDiagnosticSummaryText,
  findStructuredError,
  parseResultsPassRate,
  renderAssistantFixture,
  summarizeDiagnosticResults,
  summarizeNamedMetric,
} = require('../lib/gate-metrics');

function usage() {
  process.stderr.write(
    'Usage: gate-metrics.js <pass-rate|structured-error|metric-rate|evaluate-thresholds|diagnostic-summary|diagnostic-summary-text|render-output> ...\n'
  );
}

function requireArg(value, message) {
  if (!value) {
    process.stderr.write(`${message}\n`);
    usage();
    process.exit(2);
  }
}

function formatRate(value) {
  return Number(value || 0).toFixed(1);
}

function parseDiagnosticSummaryArgs(rawArgs) {
  const context = {};
  const files = [];

  for (let index = 0; index < rawArgs.length; index += 1) {
    const value = rawArgs[index];
    if (!value.startsWith('--')) {
      files.push(value);
      continue;
    }

    const key = value.slice(2);
    const next = rawArgs[index + 1];
    if (typeof next === 'undefined') {
      process.stderr.write(`missing value for --${key}\n`);
      process.exit(2);
    }

    context[key.replace(/-/g, '_')] = next;
    index += 1;
  }

  return { context, files };
}

const [command, ...args] = process.argv.slice(2);

switch (command) {
  case 'pass-rate': {
    const [resultsFile] = args;
    requireArg(resultsFile, 'results file is required');
    const { rate, total, passed } = parseResultsPassRate(resultsFile);
    process.stdout.write(`${formatRate(rate)} ${total} ${passed}\n`);
    break;
  }

  case 'structured-error': {
    const [resultsFile] = args;
    requireArg(resultsFile, 'results file is required');
    const parsed = findStructuredError(resultsFile);
    if (parsed) {
      process.stdout.write(`${parsed.kind} ${parsed.code}\n`);
    } else {
      process.stdout.write('\n');
    }
    break;
  }

  case 'metric-rate': {
    const [resultsFile, metricName] = args;
    requireArg(resultsFile, 'results file is required');
    requireArg(metricName, 'metric name is required');
    const { rate, score, count } = summarizeNamedMetric(resultsFile, metricName);
    process.stdout.write(`${formatRate(rate)} ${score} ${count}\n`);
    break;
  }

  case 'evaluate-thresholds': {
    const [resultsFile, thresholdRaw, minCountRaw, ...metricNames] = args;
    requireArg(resultsFile, 'results file is required');
    requireArg(thresholdRaw, 'threshold is required');
    requireArg(minCountRaw, 'min count is required');
    if (metricNames.length === 0) {
      requireArg('', 'at least one metric name is required');
    }

    const report = evaluateMetricSet(resultsFile, metricNames, {
      threshold: Number(thresholdRaw),
      minCount: Number(minCountRaw),
    });

    for (const metric of report.metrics) {
      process.stdout.write(
        [
          'metric',
          metric.metricName,
          formatRate(metric.rate),
          metric.score,
          metric.count,
          metric.countFail ? 'yes' : 'no',
          metric.fail ? 'yes' : 'no',
        ].join('|') + '\n'
      );
    }

    process.stdout.write(`overall|${report.fail ? 'yes' : 'no'}\n`);
    break;
  }

  case 'diagnostic-summary': {
    const { context, files } = parseDiagnosticSummaryArgs(args);
    if (files.length === 0) {
      requireArg('', 'at least one results file is required');
    }
    const summary = summarizeDiagnosticResults(files, context);
    process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
    break;
  }

  case 'diagnostic-summary-text': {
    const { context, files } = parseDiagnosticSummaryArgs(args);
    if (files.length === 0) {
      requireArg('', 'at least one results file is required');
    }
    const summary = summarizeDiagnosticResults(files, context);
    process.stdout.write(formatDiagnosticSummaryText(summary));
    break;
  }

  case 'render-output': {
    const [fixturePath, siteBaseUrl] = args;
    requireArg(fixturePath, 'fixture path is required');
    const rendered = renderAssistantFixture(fixturePath, siteBaseUrl);
    process.stdout.write(`${JSON.stringify(rendered)}\n`);
    break;
  }

  default:
    usage();
    process.exit(2);
}
