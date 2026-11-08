# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/pl/1.1.0/). Wersjonowanie: SemVer z regułą dla bramek
(major = nowa bramka blokująca albo ostrzejsze reguły, minor = nowa bramka raportująca, patch = poprawka).
Pełny opis: [docs/wydania.md](docs/wydania.md).

## [Unreleased]

Szkielet paczki (przyszłe wydanie próbne 0.1.0), bez bramek przeniesionych z repo źródłowego.

### Added

- Paczka Composera `citycouriers/pc` (typ `library`, PHP `^8.4`, `bin/pc` jako `vendor/bin/pc`).
- `pc --version`, `pc --help`, `pc config:validate [plik]`.
- Kontrakt konfiguracji repo-konsumenta `pc.json` (schemat 1): bramki, ścieżki, standard kodowania
  (`pint` albo `phpcs`), zestawy reguł, moduły (`laravel`, `wordpress`).
- Surowy zestaw reguł PHPMD `rulesets/phpmd.xml`.
- CI: Pint, PHPStan (poziom max), PHPMD, Pest, kontrole ujemne bramek, skan prywatności, gitleaks.
- Skrypty wydania: `scripts/ci/check-release.sh`, `scripts/ci/changelog-section.sh`.
