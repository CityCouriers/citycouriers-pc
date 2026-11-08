# citycouriers/pc

`pc` to lokalne bramki jakości dla projektów PHP, zbierane w jednym poleceniu `vendor/bin/pc`:
analiza statyczna (PHPStan), zapachy kodu (PHPMD), formatowanie (Pint albo PHPCS), testy, mutacje
i kontrole procesu. Ma działać tak samo w wielu repo: wspólny kod bramek w paczce, a wybór bramek,
ścieżek i wyjątków w małym pliku `pc.json` repo-konsumenta.

> **Status: szkielet (0.1.0).** Paczka ma wejście `bin/pc`, kontrakt konfiguracji i CI. Bramki są
> przenoszone z repo, w którym powstały, według [planu przeniesienia](docs/plan-przeniesienia.md).
> Do czasu wydania 1.0.0 interfejs może się zmieniać.

## Użycie

```bash
composer require --dev citycouriers/pc
vendor/bin/pc --version
vendor/bin/pc config:validate pc.json
```

Paczka wymaga PHP `^8.4`. Projekt z niższym `require.php` (np. wtyczka WordPress) instaluje `pc`
w osobnym katalogu narzędzi, żeby nie podnosić wymagań własnej paczki: zob.
[docs/konfiguracja.md](docs/konfiguracja.md#instalacja-w-projekcie-z-nizszym-php).

Przykład `pc.json`:

```json
{
    "version": 1,
    "standard": "phpcs",
    "paths": { "src": ["src"], "tests": ["tests"] },
    "gates": { "format": true, "phpstan": true, "phpmd": true, "mutation": false },
    "rulesets": { "phpmd": "vendor/citycouriers/pc/rulesets/phpmd.xml" },
    "modules": { "wordpress": true, "laravel": false }
}
```

## Zasady

- **Rdzeń nie zależy od frameworka.** Moduły (`laravel`, `wordpress`) włącza konfiguracja konsumenta.
- **Jeden surowy zestaw PHPMD** dla całego kodu. Odstępstwa zapisuje konsument w swoim repo, z uzasadnieniem.
- **Bramka bez kontroli ujemnej nie liczy się jako bramka.** Zielone CI samo nie dowodzi, że bramka cokolwiek
  wykrywa, więc każda bramka ma test, który musi ją zatrzymać ([docs/bramki.md](docs/bramki.md)).
- **Wersjonowanie SemVer z regułą dla bramek:** major = nowa bramka blokująca albo ostrzejsze reguły,
  minor = nowa bramka raportująca, patch = poprawka ([docs/wydania.md](docs/wydania.md)).

## Dokumentacja

| Dokument | Zawartość |
| --- | --- |
| [docs/index.md](docs/index.md) | Spis dokumentacji |
| [docs/bramki.md](docs/bramki.md) | Kontrakt bramek i kontroli ujemnych |
| [docs/konfiguracja.md](docs/konfiguracja.md) | `pc.json`, moduły, instalacja |
| [docs/proces.md](docs/proces.md) | Zgłoszenia, etykiety P/T, audyt, PR i kontrole CI |
| [docs/wydania.md](docs/wydania.md) | SemVer dla bramek, proces wydania |
| [docs/plan-przeniesienia.md](docs/plan-przeniesienia.md) | Co i kiedy trafia do paczki |

## Rozwój

```bash
composer install
composer hooks    # włącza hook pre-commit
composer check    # Pint, PHPStan, PHPMD, testy, kontrole ujemne
npm test          # testy skryptów procesu (bramka audytu, etykiety, quality-gate)
composer privacy  # skan ścieżek maszyn i adresów e-mail
```

Zmiany idą przez Pull Request z zielonym `quality-gate`. Commit przez hook, bez `--no-verify`.

## Licencja

[MIT](LICENSE), © 2026 CityCouriers.
