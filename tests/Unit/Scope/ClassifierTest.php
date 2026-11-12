<?php

declare(strict_types=1);

use CityCouriers\Pc\Tests\Support\ScopeSupport;
use CityCouriers\Pc\Scope\Classifier;
use CityCouriers\Pc\Scope\FullCause;
use CityCouriers\Pc\Scope\Matcher;
use CityCouriers\Pc\Scope\ParsedDiff;
use CityCouriers\Pc\Scope\RuleSetLoader;
use CityCouriers\Pc\Scope\ScopeException;
use CityCouriers\Pc\Scope\ScopeRule;

/**
 * @param list<string> $paths
 *
 * @return list<string>
 */
function scopeGates(array $paths): array
{
    return ScopeSupport::classify($paths)->gates;
}

/**
 * Tabela „plik → bramki” spisana niezależnie od reguł w kodzie i w JSON.
 *
 * @return array<string, array{string, list<string>}>
 */
function scopeTable(): array
{
    $github = ['privacy', 'yamllint', 'actionlint', 'schema', 'pinact', 'zizmor'];
    $php = ['privacy', 'lint', 'stan', 'smells', 'test', 'negative'];
    $rows = [
        ['README.md', ['privacy']],
        ['docs/bramki.md', ['privacy']],
        ['docs/diagram.svg', ['privacy']],
        ['CHANGELOG.md', ['privacy']],
        ['LICENSE', ['privacy']],
        ['.gitignore', ['privacy']],
        ['.yamllint.yaml', $github],
        ['.github/zizmor.yml', $github],
        ['.github/dependabot.yml', ['privacy', 'yamllint', 'schema', 'zizmor', 'process']],
        ['.github/ISSUE_TEMPLATE/config.yml', ['privacy', 'yamllint', 'schema', 'process']],
        ['scripts/ci/requirements-lint.txt', ['privacy', 'yamllint', 'schema', 'zizmor']],
        ['tests/Fixtures/negative-github/pinact-bad.yml', ['privacy', 'shellcheck', 'yamllint', 'actionlint', 'schema', 'pinact', 'zizmor']],
        ['src/Application.php', $php],
        ['bin/pc', $php],
        ['composer.json', $php],
        ['rulesets/phpmd.xml', ['privacy', 'smells', 'negative']],
        ['tests/Unit/ApplicationTest.php', ['privacy', 'lint', 'stan', 'test']],
        ['tests/Fixtures/valid-pc.json', ['privacy', 'test', 'negative']],
        ['docs/schema/pc-run-1.json', ['privacy', 'test']],
        ['tests/Fixtures/negative/bad-type.php', ['privacy', 'test', 'negative']],
        ['scripts/ci/check-release.sh', ['privacy', 'shellcheck', 'test', 'negative']],
        ['scripts/quality/issue-audit-gate.js', ['privacy', 'process']],
        ['test/labels.test.js', ['privacy', 'process']],
        ['.github/workflows/ci.yml', [...$github, 'process']],
        ['.github/labels.json', ['privacy', 'process']],
        ['package.json', ['privacy', 'process']],
    ];
    $table = [];
    foreach ($rows as [$file, $expected]) {
        $table[$file] = [$file, $expected];
    }

    return $table;
}

/**
 * @param list<string> $gates
 *
 * @return list<string> bramki w kolejności uruchamiania
 */
function scopeOrdered(array $gates): array
{
    return array_values(array_filter(ScopeSupport::rules()->gates, static fn(string $gate): bool => in_array($gate, $gates, true)));
}

it('tabela: plik uruchamia dokładnie te bramki', function (string $file, array $expected): void {
    expect(scopeGates([$file]))->toBe(scopeOrdered(ScopeSupport::strings($expected)));
})->with(scopeTable());

it('suma bramek dla kilku plików, w kolejności uruchamiania', function (): void {
    expect(scopeGates(['README.md', 'test/labels.test.js']))->toBe(['privacy', 'process'])
        ->and(scopeGates(['docs/a.md', '.github/dependabot.yml']))->toBe(['privacy', 'yamllint', 'schema', 'zizmor', 'process'])
        ->and(scopeGates(['docs/a.md', 'rulesets/phpmd.xml']))->toBe(['privacy', 'smells', 'negative']);
});

it('commit z samą dokumentacją pomija ciężkie bramki i mówi dlaczego', function (): void {
    $result = ScopeSupport::classify(['docs/a.md', 'README.md']);

    expect($result->full)->toBeFalse()
        ->and($result->cause)->toBe(FullCause::None)
        ->and($result->gates)->toBe(['privacy'])
        ->and(array_map(static fn($skip): string => $skip->gate, $result->skipped))->toBe(['shellcheck', 'yamllint', 'actionlint', 'schema', 'pinact', 'zizmor', 'lint', 'stan', 'smells', 'test', 'negative', 'process']);
    foreach ($result->skipped as $skip) {
        expect($skip->reason)->toContain('brak plików wyzwalających');
    }
});

it('kontrola ujemna: każda niepewność daje pełny zestaw, pustą listę pominiętych i powód', function (array $paths, bool $special, FullCause $cause): void {
    $result = ScopeSupport::classify(ScopeSupport::strings($paths), $special);

    expect($result->full)->toBeTrue()
        ->and($result->gates)->toBe(ScopeSupport::rules()->gates)
        ->and($result->skipped)->toBe([])
        ->and($result->cause)->toBe($cause)
        ->and($result->reason)->not->toBe('');
})->with([
    'pusta lista (amend, pusty commit)' => [[], false, FullCause::EmptyList],
    'plik bez reguły' => [['nieznany/plik.txt'], false, FullCause::NoRule],
    'docs/x.php nie jest dokumentacją' => [['docs/skrypt.php'], false, FullCause::NoRule],
    'docs/x.json nie jest dokumentacją' => [['docs/dane.json'], false, FullCause::NoRule],
    'docs/schema/x.php' => [['docs/schema/skrypt.php'], false, FullCause::NoRule],
    'docs/schema/głębiej/x.json' => [['docs/schema/glebiej/x.json'], false, FullCause::NoRule],
    'README.MD (wielkość liter)' => [['README.MD'], false, FullCause::NoRule],
    'rozszerzenie .yaml poza .github' => [['.github-poza.yaml'], false, FullCause::NoRule],
    'plik .php poza src i tests' => [['plik.php'], false, FullCause::NoRule],
    'jeden nieznany unieważnia zwolnienia całej zmiany' => [['docs/a.md', 'cos.php'], false, FullCause::NoRule],
    'ścieżka z backslashem' => [['README.md', 'a\\b.md'], false, FullCause::OddPath],
    'ścieżka z ..' => [['README.md', '../x.md'], false, FullCause::OddPath],
    'ścieżka z .. w środku' => [['README.md', 'docs/../x.md'], false, FullCause::OddPath],
    'ścieżka absolutna' => [['README.md', '/abs.md'], false, FullCause::OddPath],
    'litera dysku' => [['README.md', 'C:/x.md'], false, FullCause::OddPath],
    'pusta ścieżka' => [['README.md', ''], false, FullCause::OddPath],
    'pusty segment' => [['README.md', 'docs//x.md'], false, FullCause::OddPath],
    'segment kropka' => [['README.md', './x.md'], false, FullCause::OddPath],
    'dowiązanie symboliczne albo submoduł' => [['docs/a.md'], true, FullCause::SpecialMode],
    'hook (mechanizm)' => [['.githooks/pre-commit'], false, FullCause::Mechanism],
    'reguły zakresu (mechanizm)' => [['scripts/quality/scope-rules.json'], false, FullCause::Mechanism],
    'klasyfikator (mechanizm)' => [['src/Scope/Classifier.php'], false, FullCause::Mechanism],
    'test klasyfikatora (mechanizm)' => [['tests/Unit/Scope/ClassifierTest.php'], false, FullCause::Mechanism],
    'skrypt bramek GitHub (mechanizm)' => [['scripts/ci/github-gate.sh'], false, FullCause::Mechanism],
]);

it('wymuszenie pełnego zestawu jest zawsze dostępne dla wywołującego i niczego nie wyłącza', function (): void {
    $classifier = new Classifier(ScopeSupport::rules());
    $forced = $classifier->forced(FullCause::ForcedAll, 'PC_HOOK_SCOPE=all');

    expect($forced->full)->toBeTrue()
        ->and($forced->gates)->toBe(ScopeSupport::rules()->gates)
        ->and($forced->skipped)->toBe([])
        ->and($classifier->classify(new ParsedDiff(['README.md'], false))->gates)->toBe(['privacy']);
});

it('każda bramka ma opis wyzwalaczy, a każda reguła wskazuje tylko znane bramki', function (): void {
    $rules = ScopeSupport::rules();
    foreach ($rules->gates as $gate) {
        expect($rules->triggers[$gate] ?? '')->not->toBe('');
    }
    foreach ($rules->rules as $rule) {
        expect(array_diff($rule->gates, $rules->gates))->toBe([]);
    }
});

it('pliki repo bez reguły to świadomy wyjątek: nowy plik bez reguły wymusza decyzję', function (): void {
    $repo = realpath(__DIR__ . '/../../..');
    $files = [];
    exec('git -C ' . escapeshellarg((string) $repo) . ' ls-files -z', $lines, $code);
    expect($code)->toBe(0);
    $files = array_filter(explode("\0", implode("\0", $lines)));
    // Pliki ze stage'a, które jeszcze nie weszły do HEAD, też muszą mieć regułę (ls-files pokazuje indeks).
    expect($files)->not->toBe([]);
    $without = array_values(array_filter($files, static fn(string $file): bool => ScopeSupport::rules()->ruleFor($file) === null));

    expect($without)->toBe([]);
});

it('zestaw reguł: błędny plik to ScopeException, nigdy ciche pominięcie', function (string $json, string $message): void {
    $path = tempnam(sys_get_temp_dir(), 'scope');
    file_put_contents((string) $path, $json);

    try {
        expect(fn() => (new RuleSetLoader())->load((string) $path))->toThrow(ScopeException::class, $message);
    } finally {
        unlink((string) $path);
    }
})->with([
    'nie JSON' => ['{', 'nie jest poprawnym JSON'],
    'zła wersja' => ['{"version":2}', 'version: 1'],
    'bramka bez opisu' => ['{"version":1,"gates":["a"],"triggers":{},"rules":[]}', 'nie ma opisu wyzwalaczy'],
    'duplikat bramki' => ['{"version":1,"gates":["a","a"],"triggers":{"a":"x"},"rules":[]}', 'duplikaty'],
    'reguła z nieznaną bramką' => ['{"version":1,"gates":["a"],"triggers":{"a":"x"},"rules":[{"name":"r","gates":["b"],"match":{"exact":["f"]}}]}', 'nieznaną bramkę'],
    'reguła bez warunków' => ['{"version":1,"gates":["a"],"triggers":{"a":"x"},"rules":[{"name":"r","gates":["a"],"match":{}}]}', 'pusta'],
    'nieznany rodzaj warunku' => ['{"version":1,"gates":["a"],"triggers":{"a":"x"},"rules":[{"name":"r","gates":["a"],"match":{"glob":["f"]}}]}', 'nieznany warunek'],
    'zły regex' => ['{"version":1,"gates":["a"],"triggers":{"a":"x"},"rules":[{"name":"r","gates":["a"],"match":{"regex":["("]}}]}', 'wyrażenie regularne'],
]);

it('brak pliku reguł to ScopeException', function (): void {
    expect(fn() => (new RuleSetLoader())->load(sys_get_temp_dir() . '/nie-ma-takiego-pliku.json'))->toThrow(ScopeException::class);
});

it('warunki dopasowania: exact, prefix, suffix i regex rozróżniają wielkość liter i są kotwiczone', function (): void {
    expect((new Matcher('exact', 'a.md'))->matches('a.md'))->toBeTrue()
        ->and((new Matcher('exact', 'a.md'))->matches('x/a.md'))->toBeFalse()
        ->and((new Matcher('prefix', 'docs/'))->matches('docs/x'))->toBeTrue()
        ->and((new Matcher('prefix', 'docs/'))->matches('Docs/x'))->toBeFalse()
        ->and((new Matcher('suffix', '.md'))->matches('README.MD'))->toBeFalse()
        ->and((new Matcher('regex', '^a\\.md$'))->matches("a.md\n"))->toBeFalse();
    expect(fn() => new Matcher('exact', ''))->toThrow(ScopeException::class)
        ->and(fn() => new Matcher('inny', 'x'))->toThrow(ScopeException::class);
});

it('reguła bez dopasowania nie obejmuje pliku', function (): void {
    $rule = new ScopeRule('r', ['a'], [new Matcher('exact', 'x')]);

    expect($rule->matches('y'))->toBeFalse()->and($rule->matches('x'))->toBeTrue();
});
