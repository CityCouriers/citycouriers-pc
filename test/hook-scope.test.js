'use strict';

/**
 * Zakres bramek hooka (scripts/quality/hook-scope.js). Tabela oczekiwań jest spisana niezależnie od reguł w kodzie,
 * a testy ujemne pilnują fail-closed: zwolnienie z bramki musi być jawne, a każda niepewność daje pełny zestaw.
 */

const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const scope = require('../scripts/quality/hook-scope.js');

const REPO = path.join(__dirname, '..');
const ALL = [...scope.GATES];
const gates = paths => scope.classify(paths).gates;

describe('tabela: plik → bramki', () => {
  const cases = [
    ['README.md', ['privacy']],
    ['docs/bramki.md', ['privacy']],
    ['docs/diagram.svg', ['privacy']],
    ['CHANGELOG.md', ['privacy']],
    ['LICENSE', ['privacy']],
    ['.gitignore', ['privacy']],
    ['.yamllint.yaml', ['privacy']],
    ['.github/zizmor.yml', ['privacy', 'process']],
    ['src/Application.php', ['privacy', 'lint', 'stan', 'smells', 'test', 'negative']],
    ['bin/pc', ['privacy', 'lint', 'stan', 'smells', 'test', 'negative']],
    ['composer.json', ['privacy', 'lint', 'stan', 'smells', 'test', 'negative']],
    ['rulesets/phpmd.xml', ['privacy', 'smells', 'negative']],
    ['tests/Unit/ApplicationTest.php', ['privacy', 'lint', 'stan', 'test']],
    ['tests/Fixtures/valid-pc.json', ['privacy', 'test', 'negative']],
    ['tests/Fixtures/negative/bad-type.php', ['privacy', 'test', 'negative']],
    ['scripts/ci/check-release.sh', ['privacy', 'test', 'negative']],
    ['scripts/quality/issue-audit-gate.js', ['privacy', 'process']],
    ['test/labels.test.js', ['privacy', 'process']],
    ['.github/workflows/ci.yml', ['privacy', 'process']],
    ['.github/labels.json', ['privacy', 'process']],
    ['package.json', ['privacy', 'process']],
  ];
  for (const [file, expected] of cases) {
    test(`${file}`, () => assert.deepEqual(gates([file]), expected));
  }

  test('suma bramek dla kilku plików, w kolejności uruchamiania', () => {
    assert.deepEqual(gates(['README.md', 'test/labels.test.js']), ['privacy', 'process']);
    assert.deepEqual(gates(['docs/a.md', 'rulesets/phpmd.xml']), ['privacy', 'smells', 'negative']);
  });

  test('commit z samą dokumentacją pomija wszystkie ciężkie bramki i mówi, dlaczego', () => {
    const result = scope.classify(['docs/a.md', 'README.md']);
    assert.deepEqual(result.gates, ['privacy']);
    assert.deepEqual(result.skipped.map(item => item.gate), ['lint', 'stan', 'smells', 'test', 'negative', 'process']);
    assert.ok(result.skipped.every(item => /brak plików wyzwalających/.test(item.reason)));
    assert.match(scope.describe(result).join('\n'), /pominięte \(nic tu nie sprawdzą\): lint \[/);
  });
});

describe('fail-closed (kontrole ujemne): niepewność daje pełny zestaw', () => {
  const expectFull = (result, pattern) => {
    assert.deepEqual(result.gates, ALL);
    assert.equal(result.full, true);
    assert.deepEqual(result.skipped, []);
    assert.match(result.reason, pattern);
  };

  test('pusta lista (amend bez zmian) to NIE brak bramek', () => {
    expectFull(scope.classify([]), /pusty stage/);
    expectFull(scope.classify(undefined), /pusty stage/);
  });

  test('plik bez reguły', () => {
    expectFull(scope.classify(['nieznany/plik.txt']), /brak reguły/);
    expectFull(scope.classify(['docs/skrypt.php']), /brak reguły/);
    expectFull(scope.classify(['docs/dane.json']), /brak reguły/);
    expectFull(scope.classify(['README.MD']), /brak reguły/);
    expectFull(scope.classify(['.github-poza.yaml']), /brak reguły/);
    expectFull(scope.classify(['plik.php']), /brak reguły/);
  });

  test('jeden nieznany plik unieważnia zwolnienia całej zmiany', () => {
    expectFull(scope.classify(['docs/a.md', 'cos.php']), /brak reguły/);
  });

  test('nietypowe ścieżki', () => {
    for (const bad of ['a\\b.md', '../x.md', 'docs/../x.md', '/abs.md', 'C:/x.md', '', 'docs//x.md', './x.md']) {
      expectFull(scope.classify(['README.md', bad]), /nietypowa ścieżka/);
    }
  });

  test('dowiązanie symboliczne i submoduł', () => {
    expectFull(scope.classify(['docs/a.md'], { specialModes: true }), /dowiązanie symboliczne/);
  });

  test('zmiana mechanizmu bramek uruchamia wszystko', () => {
    for (const file of ['.githooks/pre-commit', 'scripts/quality/hook-scope.js', 'test/hook-scope.test.js']) {
      expectFull(scope.classify([file]), /zmiana mechanizmu/);
    }
  });

  test('PC_HOOK_SCOPE=all wymusza pełny zestaw, a żadna wartość niczego nie wyłącza', () => {
    expectFull(scope.classify(['README.md'], { forceAll: true }), /PC_HOOK_SCOPE=all/);
    const run = env => {
      const out = [];
      scope.main(['--stdin'], env, { out: line => out.push(line), err: () => {}, readStdin: () => 'README.md\0' });
      return out[0];
    };
    assert.equal(run({}), 'privacy');
    assert.equal(run({ PC_HOOK_SCOPE: 'all' }), ALL.join(' '));
    assert.equal(run({ PC_HOOK_SCOPE: 'none' }), 'privacy');
  });
});

describe('rozbiór git diff --raw -z', () => {
  const entry = (oldMode, newMode, status, file) => `:${oldMode} ${newMode} aaaaaaa bbbbbbb ${status}\0${file}\0`;

  test('ścieżki dodane, zmienione i usunięte', () => {
    const parsed = scope.parseRaw(entry('000000', '100644', 'A', 'a.md') + entry('100644', '000000', 'D', 'b.md'));
    assert.deepEqual(parsed, { paths: ['a.md', 'b.md'], specialModes: false });
  });

  test('tryby 120000 i 160000 po dowolnej stronie', () => {
    assert.equal(scope.parseRaw(entry('100644', '120000', 'T', 'link')).specialModes, true);
    assert.equal(scope.parseRaw(entry('160000', '160000', 'M', 'sub')).specialModes, true);
  });

  test('uszkodzone wejście daje ścieżkę, która wymusza pełny zestaw', () => {
    const parsed = scope.parseRaw('śmieci\0plik\0');
    assert.deepEqual(scope.classify(parsed.paths).gates, ALL);
  });
});

describe('zgodność z repo', () => {
  const tracked = () => spawnSync('git', ['ls-files'], { cwd: REPO, encoding: 'utf8' }).stdout.split('\n').filter(Boolean);

  test('każda bramka z hooka istnieje w skrypcie i odwrotnie', () => {
    const hook = fs.readFileSync(path.join(REPO, '.githooks', 'pre-commit'), 'utf8');
    for (const gate of scope.GATES) {
      assert.match(hook, new RegExp(`^\\s+${gate}\\)`, 'm'), `hook nie obsługuje bramki ${gate}`);
    }
    const handled = [...hook.matchAll(/^\s+([a-z]+)\) /gm)].map(match => match[1]).filter(name => name !== 'all');
    assert.deepEqual(handled.sort(), [...scope.GATES].sort());
  });

  test('pliki repo bez reguły są świadomym wyjątkiem (nowy plik bez reguły spowalnia hook, więc wymusza decyzję)', () => {
    const unmatched = tracked().filter(file => !scope.RULES.some(rule => rule.match(file)));
    assert.deepEqual(unmatched, []);
  });

  test('wyzwalacze są opisane dla każdej bramki', () => {
    for (const gate of scope.GATES) assert.ok(scope.TRIGGERS[gate], gate);
  });
});
