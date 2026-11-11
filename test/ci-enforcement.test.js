'use strict';

/**
 * #40: CI egzekwuje wszystko. Zakres plików skraca tylko lokalny hook (profil local-narrow), a workflow nie może
 * zawęzić kontroli po cichu: ani filtrem ścieżek wyzwalacza, ani warunkiem `if:` zależnym od zmienionych plików.
 * Sprawdzenie jest tekstowe (bez parsera YAML), więc dwa fixtury ujemne dowodzą, że naruszenia dają czerwony wynik.
 */

const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');

const gate = require('../scripts/quality/quality-gate.js');

const REPO = path.join(__dirname, '..');
const CI = fs.readFileSync(path.join(REPO, '.github', 'workflows', 'ci.yml'), 'utf8');

/** Wzorce, po których workflow mógłby uzależnić kontrolę od zmienionych plików. */
const CHANGE_DEPENDENT = /needs\.changes|steps\.changes|dorny\/paths-filter|tj-actions\/changed-files|changed-files|paths-filter|git diff --name-only/i;

/**
 * @param {string} workflow treść ci.yml
 * @returns {string[]} opisy naruszeń (pusta lista = zgodne)
 */
function violations(workflow) {
  const found = [];
  const lines = workflow.split(/\r?\n/);
  const onEnd = lines.findIndex(line => /^permissions:/.test(line));
  const trigger = lines.slice(0, onEnd === -1 ? lines.length : onEnd).filter(line => !/^\s*#/.test(line));
  if (trigger.some(line => /^\s+(paths|paths-ignore):/.test(line))) found.push('wyzwalacz ma filtr paths/paths-ignore');
  const jobsAt = lines.findIndex(line => /^jobs:/.test(line));
  const jobs = lines.slice(jobsAt + 1);
  let current = '';
  for (const line of jobs) {
    const id = /^ {2}([a-z][a-z0-9-]*):\s*$/.exec(line);
    if (id) current = id[1];
    if (/^ {4}if:/.test(line) && current !== 'quality-gate') found.push(`job ${current} ma warunek if:`);
    if (/^\s*if:/.test(line) && CHANGE_DEPENDENT.test(line)) found.push(`warunek zależny od zmienionych plików w jobie ${current}`);
    if (!/^\s*#/.test(line) && CHANGE_DEPENDENT.test(line)) found.push(`kontrola zmienionych plików w jobie ${current}`);
  }
  const unique = [...new Set(found)];
  const needsMatch = /^ {4}needs:\s*\[([^\]]*)\]/m.exec(workflow.slice(workflow.indexOf('\n  quality-gate:')));
  const needs = (needsMatch ? needsMatch[1] : '').split(',').map(s => s.trim()).filter(Boolean).sort();
  if (JSON.stringify(needs) !== JSON.stringify([...gate.REQUIRED_JOBS].sort())) unique.push('needs quality-gate różni się od REQUIRED_JOBS');
  return unique;
}

describe('#40: egzekwowanie w CI', () => {
  test('ci.yml: brak filtrów ścieżek, brak warunków zależnych od zmienionych plików, needs = REQUIRED_JOBS', () => {
    assert.deepEqual(violations(CI), []);
  });

  test('wyzwalacze pull_request i push działają dla każdego pliku (też samego *.md)', () => {
    assert.match(CI, /^on:\s*\n {2}pull_request:\s*\n {4}types: \[[^\]]*\]\s*\n {2}push:\s*\n {4}branches: \[main\]/m);
  });

  test('job scope biegnie w profilu enforce-all i nie zawęża niczego', () => {
    const section = CI.slice(CI.indexOf('\n  scope:'), CI.indexOf('\n  actionlint:')).replace(/^\s*#.*$/gm, '');
    assert.match(section, /--profile=enforce-all/);
    assert.doesNotMatch(section, /local-narrow/);
    assert.match(section, /fetch-depth: 0/);
  });

  test('ci.yml nie wywołuje profilu local-narrow (to profil wyłącznie lokalny)', () => {
    assert.doesNotMatch(CI.replace(/^\s*#.*$/gm, ''), /local-narrow/);
  });

  test('kontrola ujemna: paths-ignore w wyzwalaczu jest wykryte', () => {
    const bad = CI.replace('  pull_request:\n', "  pull_request:\n    paths-ignore: ['**.md']\n");
    assert.notEqual(bad, CI);
    assert.ok(violations(bad).some(v => v.includes('paths')), violations(bad).join('; '));
  });

  test('kontrola ujemna: filtr paths w wyzwalaczu push jest wykryty', () => {
    const bad = CI.replace('    branches: [main]\n', "    branches: [main]\n    paths: ['src/**']\n");
    assert.notEqual(bad, CI);
    assert.ok(violations(bad).some(v => v.includes('paths')));
  });

  test('kontrola ujemna: job z warunkiem zależnym od zmienionych plików jest wykryty', () => {
    const bad = CI.replace('  privacy:\n    name: Skan prywatności\n', "  privacy:\n    name: Skan prywatności\n    if: needs.changes.outputs.code == 'true'\n");
    assert.notEqual(bad, CI);
    const found = violations(bad);
    assert.ok(found.some(v => v.includes('if:')), found.join('; '));
    assert.ok(found.some(v => v.includes('zmienionych plików')), found.join('; '));
  });

  test('kontrola ujemna: job usunięty z needs agregatu jest wykryty', () => {
    const bad = CI.replace(', scope]', ']');
    assert.notEqual(bad, CI);
    assert.ok(violations(bad).some(v => v.includes('needs')));
  });
});
