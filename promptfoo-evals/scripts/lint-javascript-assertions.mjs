#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import yaml from 'js-yaml';

const repoRoot = process.cwd();
const testsDir = path.join(repoRoot, 'promptfoo-evals', 'tests');

function listYamlFiles(explicitArgs) {
  if (explicitArgs.length > 0) {
    return explicitArgs.map((arg) => path.isAbsolute(arg) ? arg : path.join(repoRoot, arg));
  }

  return fs.readdirSync(testsDir)
    .filter((name) => name.endsWith('.yaml') || name.endsWith('.yml'))
    .map((name) => path.join(testsDir, name));
}

function isMultiline(text) {
  return String(text || '').includes('\n');
}

function hasExplicitReturn(jsCode) {
  const lines = String(jsCode || '')
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '' && !line.startsWith('//'));

  return lines.some((line) => /\breturn\b/.test(line));
}

function lintFile(filePath) {
  const content = fs.readFileSync(filePath, 'utf8');
  const parsed = yaml.load(content);
  const tests = Array.isArray(parsed) ? parsed : [];
  const errors = [];

  tests.forEach((test, testIndex) => {
    const asserts = Array.isArray(test?.assert) ? test.assert : [];
    asserts.forEach((assertion, assertIndex) => {
      if ((assertion?.type || '').toLowerCase() !== 'javascript') {
        return;
      }

      const value = String(assertion?.value || '');
      if (!isMultiline(value)) {
        return;
      }

      if (!hasExplicitReturn(value)) {
        errors.push({
          file: path.relative(repoRoot, filePath),
          test_index: testIndex,
          assert_index: assertIndex,
          description: test?.description || null,
          metric: assertion?.metric || null,
          message: 'Multiline javascript assertion must contain an explicit return statement.',
        });
      }
    });
  });

  return errors;
}

const args = process.argv.slice(2);
const yamlFiles = listYamlFiles(args);
const allErrors = yamlFiles.flatMap((filePath) => lintFile(filePath));

if (allErrors.length > 0) {
  console.error('JavaScript assertion lint failed:\n');
  for (const error of allErrors) {
    console.error(`${error.file} [test ${error.test_index}, assert ${error.assert_index}] metric=${error.metric || 'n/a'} ${error.description ? `desc="${error.description}"` : ''}`.trim());
    console.error(`  ${error.message}`);
  }
  process.exit(1);
}

console.log(`JavaScript assertion lint passed (${yamlFiles.length} files).`);
