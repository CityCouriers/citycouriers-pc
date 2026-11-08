# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/pl/1.1.0/). Wersjonowanie: SemVer z regułą dla bramek
(major = nowa bramka blokująca albo ostrzejsze reguły, minor = nowa bramka raportująca, patch = poprawka).
Pełny opis: [docs/wydania.md](docs/wydania.md).

## [Unreleased]

### Changed

- Repozytorium nazywa się `pc` (wcześniej `citycouriers-pc`), zgodnie z konwencją krótkich nazw repozytoriów CityCouriers. Nazwa pakietu Composera (`citycouriers/pc`) bez zmian. Stary adres przekierowuje.
- Start bramki audytu zgłoszeń: 2026-11-08T00:00:00Z.

### Added

- Proces repozytorium jak w pozostałych repo CityCouriers: etykiety `P0`-`P4`, `T1`-`T3`, `area:*` (`.github/labels.json`,
  workflow `Sync GitHub labels`), triage i bramka audytu zgłoszeń (`issue-audit`), `pr-title`, szablony zgłoszeń i PR,
  job zbiorczy `quality-gate` (zastępuje `ci-gate`), gitleaks z przypiętą wersją i sumą SHA-256, testy skryptów procesu
  (`npm test`), `docs/proces.md`.

## [0.1.0] - 2026-11-08

Wydanie próbne: szkielet paczki, bez bramek przeniesionych z repo źródłowego.

### Added

- Paczka Composera `citycouriers/pc` (typ `library`, PHP `^8.4`, `bin/pc` jako `vendor/bin/pc`).
- `pc --version`, `pc --help`, `pc config:validate [plik]`.
- Kontrakt konfiguracji repo-konsumenta `pc.json` (schemat 1): bramki, ścieżki, standard kodowania
  (`pint` albo `phpcs`), zestawy reguł, moduły (`laravel`, `wordpress`).
- Surowy zestaw reguł PHPMD `rulesets/phpmd.xml`.
- CI: Pint, PHPStan (poziom max), PHPMD, Pest, kontrole ujemne bramek, skan prywatności, gitleaks.
- Skrypty wydania: `scripts/ci/check-release.sh`, `scripts/ci/changelog-section.sh`.
