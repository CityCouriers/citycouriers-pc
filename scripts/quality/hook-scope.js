#!/usr/bin/env node
'use strict';

/**
 * Zakres bramek hooka pre-commit: które bramki mają sens dla zmienionych plików.
 *
 * Cel: czas jednego commita. Commit z samym `.md` nie uruchamia PHPStan, PHPMD ani testów, bo nic w nich nie sprawdzą.
 * Minuty CI nie są tu ograniczeniem (repo publiczne), więc skracamy tylko hook.
 *
 * Zasady (docs/bramki.md, sekcja „Zakres plików bramki”):
 *  - ALLOWLISTA zwolnień, nie denylista: bramka biegnie, chyba że jawna, zamknięta reguła mówi, że nie ma co sprawdzać.
 *    Plik bez reguły, pusta lista, dziwna ścieżka (`\`, `..`, absolutna, pusty segment), dowiązanie symboliczne
 *    i submoduł dają PEŁNY zestaw bramek (fail-closed).
 *  - Pominięcie jest widoczne: skrypt wypisuje, co pominął i dlaczego. Nigdy nie udaje, że bramka przeszła.
 *  - Zmiany samego mechanizmu (hook, ten skrypt, jego test) uruchamiają wszystko.
 *  - Tylko do skracania: `PC_HOOK_SCOPE=all` wymusza pełny zestaw. Nie ma zmiennej, która wyłącza bramkę.
 *
 * Użycie: node scripts/quality/hook-scope.js          (czyta zmiany ze stage'a, gity wypisuje na stdout nazwy bramek)
 *         node scripts/quality/hook-scope.js --stdin  (lista ścieżek rozdzielona znakiem NUL na stdin; testy)
 */

/** Wszystkie bramki hooka, w kolejności uruchamiania (od najszybszych). */
const GATES = ['privacy', 'lint', 'stan', 'smells', 'test', 'negative', 'process'];

/** Bramki zależne od kodu PHP paczki. */
const PHP_CHAIN = ['lint', 'stan', 'smells', 'test', 'negative'];

/** Co wyzwala daną bramkę (opis do komunikatu o pominięciu). */
const TRIGGERS = {
  privacy: 'każda zmiana (tania, czyta listę plików repo)',
  lint: '.php, pint.json, composer.json',
  stan: '.php, phpstan.neon, composer.json',
  smells: 'src/, bin/pc, rulesets/, composer.json',
  test: '.php, tests/Fixtures/, scripts/ci/*.sh, phpunit.xml.dist, composer.json',
  negative: 'src/, bin/pc, rulesets/, tests/Fixtures/, scripts/ci/*.sh, pint.json, phpstan.neon, composer.json',
  process: 'scripts/quality/, test/, .github/, package.json',
};

const DOCS_EXTENSIONS = ['.md', '.txt', '.png', '.jpg', '.jpeg', '.gif', '.svg'];
const PHP_CONFIG = ['composer.json', 'pint.json', 'phpstan.neon', 'phpunit.xml.dist', 'scripts/ci/phpstan-negative.neon'];
const FUSE_FILES = ['scripts/quality/hook-scope.js', 'test/hook-scope.test.js'];
const SPECIAL_MODES = new Set(['120000', '160000']);

const hasExtension = (path, extensions) => extensions.some(extension => path.endsWith(extension));

/**
 * Reguły w kolejności: pierwsza pasująca decyduje. Kolejność ma znaczenie (fixtures przed tests-php).
 * Każda reguła to jawne zwolnienie z bramek spoza listy `gates`.
 */
const RULES = [
  { name: 'fuse', gates: GATES, match: path => path.startsWith('.githooks/') || FUSE_FILES.includes(path) },
  { name: 'php-config', gates: [...PHP_CHAIN, 'privacy'], match: path => PHP_CONFIG.includes(path) },
  { name: 'src-bin', gates: [...PHP_CHAIN, 'privacy'], match: path => path.startsWith('src/') || path === 'bin/pc' },
  { name: 'rulesets', gates: ['smells', 'negative', 'privacy'], match: path => path.startsWith('rulesets/') },
  { name: 'fixtures', gates: ['test', 'negative', 'privacy'], match: path => path.startsWith('tests/Fixtures/') },
  { name: 'tests-php', gates: ['lint', 'stan', 'test', 'privacy'], match: path => path.startsWith('tests/') && path.endsWith('.php') },
  { name: 'ci-shell', gates: ['test', 'negative', 'privacy'], match: path => /^scripts\/ci\/[^/]+\.sh$/.test(path) },
  {
    name: 'process',
    gates: ['process', 'privacy'],
    match: path => path.startsWith('scripts/quality/') || path.startsWith('test/') || path.startsWith('.github/')
      || path === 'package.json' || path === 'package-lock.json',
  },
  // Dokumentacja: tylko znane rozszerzenia. `docs/x.php` ani `docs/x.json` nie są dokumentacją.
  {
    name: 'docs',
    gates: ['privacy'],
    match: path => path === 'LICENSE' || hasExtension(path, ['.md']) || (path.startsWith('docs/') && hasExtension(path, DOCS_EXTENSIONS)),
  },
  // Skan prywatności czyta listę plików repo przez git, więc zmiana ignorowanych plików go dotyczy.
  // `.yamllint.yaml` konfiguruje bramkę yamllint, której w hooku jeszcze nie ma (zgłoszenie o yamllint); do czasu jej
  // wdrożenia zmiana konfiguracji dotyczy tylko skanu prywatności.
  { name: 'git-meta', gates: ['privacy'], match: path => ['.gitignore', '.gitattributes', '.yamllint.yaml'].includes(path) },
];

/** Czy ścieżka z gita jest bezpieczna do klasyfikacji (nie ufamy dziwnym ścieżkom). */
function isSaneRelativePath(path) {
  if (typeof path !== 'string' || path === '' || path.includes('\\') || path.startsWith('/') || /^[A-Za-z]:/.test(path)) return false;
  return path.split('/').every(segment => segment !== '' && segment !== '.' && segment !== '..');
}

function ordered(gates) {
  return GATES.filter(gate => gates.has(gate));
}

function full(reason) {
  return { gates: [...GATES], skipped: [], full: true, reason };
}

/**
 * @param {string[]} paths zmienione ścieżki (dodane, zmienione, usunięte; po obu stronach przemianowania)
 * @param {{specialModes?: boolean, forceAll?: boolean}} [options]
 * @returns {{gates: string[], skipped: {gate: string, reason: string}[], full: boolean, reason: string}}
 */
function classify(paths, { specialModes = false, forceAll = false } = {}) {
  if (forceAll) return full('PC_HOOK_SCOPE=all');
  if (!Array.isArray(paths) || paths.length === 0) return full('pusta lista plików (np. amend bez zmian): pusty stage nie oznacza braku bramek');
  if (specialModes) return full('dowiązanie symboliczne albo submoduł w zmianie');

  const selected = new Set();
  for (const path of paths) {
    if (!isSaneRelativePath(path)) return full(`nietypowa ścieżka: ${JSON.stringify(path)}`);
    const rule = RULES.find(candidate => candidate.match(path));
    if (!rule) return full(`brak reguły dla pliku ${path}`);
    if (rule.name === 'fuse') return full(`zmiana mechanizmu bramek: ${path}`);
    for (const gate of rule.gates) selected.add(gate);
  }

  const gates = ordered(selected);
  const skipped = GATES.filter(gate => !selected.has(gate)).map(gate => ({ gate, reason: `brak plików wyzwalających (${TRIGGERS[gate]})` }));
  return { gates, skipped, full: false, reason: 'zakres z reguł' };
}

/** Rozbiór `git diff --cached --raw -z --no-renames`: ścieżki i informacja o trybach specjalnych. */
function parseRaw(output) {
  const tokens = String(output).split('\0').filter(token => token !== '');
  const paths = [];
  let specialModes = false;
  for (let index = 0; index < tokens.length; index += 2) {
    const meta = tokens[index];
    const path = tokens[index + 1];
    if (!meta.startsWith(':') || path === undefined) return { paths: [''], specialModes: false };
    const [oldMode, newMode] = meta.slice(1).split(' ');
    if (SPECIAL_MODES.has(oldMode) || SPECIAL_MODES.has(newMode)) specialModes = true;
    paths.push(path);
  }
  return { paths, specialModes };
}

function describe(result) {
  if (result.full) return [`[hook-scope] pełny zestaw bramek: ${result.reason}`];
  const lines = [`[hook-scope] bramki: ${result.gates.join(' ')}`];
  if (result.skipped.length) {
    lines.push(`[hook-scope] pominięte (nic tu nie sprawdzą): ${result.skipped.map(item => `${item.gate} [${item.reason}]`).join('; ')}`);
  }
  return lines;
}

function main(argv = process.argv.slice(2), env = process.env, io = { out: console.log, err: console.error }) {
  const { spawnSync } = require('node:child_process');
  let parsed;
  if (argv.includes('--stdin')) {
    const input = io.readStdin ? io.readStdin() : require('node:fs').readFileSync(0, 'utf8');
    parsed = { paths: input.split('\0').filter(item => item !== ''), specialModes: false };
  } else {
    const diff = spawnSync('git', ['diff', '--cached', '--raw', '-z', '--no-renames'], { encoding: 'utf8' });
    if (diff.status !== 0) {
      io.err('[hook-scope] git diff nie powiódł się, uruchamiam pełny zestaw bramek');
      io.out(GATES.join(' '));
      return 0;
    }
    parsed = parseRaw(diff.stdout);
  }
  const result = classify(parsed.paths, { specialModes: parsed.specialModes, forceAll: env.PC_HOOK_SCOPE === 'all' });
  for (const line of describe(result)) io.err(line);
  io.out(result.gates.join(' '));
  return 0;
}

if (require.main === module) process.exit(main());

module.exports = { GATES, PHP_CHAIN, RULES, TRIGGERS, classify, describe, isSaneRelativePath, main, parseRaw };
