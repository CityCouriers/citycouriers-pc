# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/pl/1.1.0/). Wersjonowanie: SemVer z regułą dla bramek
(major = nowa bramka blokująca albo ostrzejsze reguły, minor = nowa bramka raportująca, patch = poprawka).
Pełny opis: [docs/wydania.md](docs/wydania.md).

## [Unreleased]

### Changed

- Repozytorium nazywa się `pc` (wcześniej `citycouriers-pc`), zgodnie z konwencją krótkich nazw repozytoriów CityCouriers. Nazwa pakietu Composera (`citycouriers/pc`) bez zmian. Stary adres przekierowuje.
- Start bramki audytu zgłoszeń: 2026-11-08T00:00:00Z.
- Testy: Pest 4 → 5 (PHPUnit 13) i zaostrzony `phpunit.xml.dist`: `executionOrder="random"`, `resolveDependencies`, `beStrictAboutTestsThatDoNotTestAnything`, `beStrictAboutOutputDuringTests` (obok dotychczasowych `failOn*`). Zmiana dotyczy tylko zależności deweloperskich, bez zmiany SemVer paczki (zgłoszenie #53).
- Narzędzia deweloperskie: cotygodniowa aktualizacja w sobotę (sekcja „Aktualizacja narzędzi” w `docs/proces.md`), minima w `composer.json` równe najnowszym stabilnym, `phpmd` i `pdepend` ze stabilnych wydań zamiast `3.x-dev`, skrypt `composer bounds` (dolne granice, `scripts/ci/tool-bounds.php`) z testem z kontrolą ujemną. Dependabot tylko dla GitHub Actions (weekly, sobota). Bez zmiany SemVer paczki (zgłoszenie #71).

### Fixed

- Workflow GitHub Actions po przeglądzie narzędziami (actionlint, zizmor w trybie pedantic, yamllint, check-jsonschema,
  pinact, ShellCheck): `persist-credentials: false` w checkoutach, akcje przypięte do SHA z komentarzem wersji,
  uprawnienia na poziomie jobów z uzasadnieniem, nazwy jobów, `concurrency` dla `pr-title` i `Release`, cooldown
  Dependabota, brak `template-injection` w `issue-audit.yml`, `cd ... || exit 1` w skryptach CI. Konfiguracje:
  `.yamllint.yaml`, `.github/zizmor.yml` (jeden wyjątek `dangerous-triggers` z uzasadnieniem).

### Added

- Silnik bramek (zgłoszenie #3): `pc run` i `pc run --staged` z opcjami `--format=text|json`, `--base=<rewizja>` i `--config=<plik>`. Interfejs `Gate`
  (tryb, bramka wstępna, jednostka `checked`, wyzwalacze, opcjonalna faza `finalize`), runner, rejestr, walidacja wyniku, raport zbiorczy.
  Cztery stany wyniku (`passed`, `failed`, `skipped`, `not_scanned`) z zamkniętym zbiorem kodów powodu, kody wyjścia 0, 1, 2, 3, 4, 5, 130 i 143
  z jawną regułą pierwszeństwa, reguły fail-closed (brak narzędzia, wyjątek bramki, bramka bez kodu, zero sprawdzonych jednostek i pusty stage
  nie dają zielonego), bramka raportująca nigdy nie zmienia kodu, ale wynik niepełny jest głośny ("NIEPEŁNY", `complete: false`).
  Wersjonowany schemat JSON (`docs/schema/pc-run-1.json`, `schema_version: 1`) z polami zawsze obecnymi i `null` dla braku danych.
  Kontrakt: `docs/bramki.md`. Rejestr nie ma jeszcze żadnej bramki, więc włączone bramki dają `not_scanned` / `gate_not_implemented` (kod 4).
  Testy Pest (kontrakt, wyniki mieszane, przypadki brzegowe, schemat, git, SIGINT w podprocesie) i kontrole ujemne w `scripts/ci/negative-controls.sh`.
- Bramki plików GitHub, po jednej na narzędzie (zgłoszenia #5, #6, #43, #44, #45, #46): actionlint 1.7.12, ShellCheck,
  zizmor 1.32.0 (persona pedantic), yamllint 1.38.0, check-jsonschema 0.39.0, pinact 5.0.2. Wspólne źródło komend
  `scripts/ci/github-gate.sh` dla hooka i CI, kontrola ujemna każdego narzędzia (`tests/Fixtures/negative-github/`),
  binarki w CI z sumą SHA-256, paczki Pythona z hashami (`scripts/ci/requirements-lint.txt`). Wpięte w `quality-gate`
  i w klasyfikator zakresu hooka.
- Zakres plików bramek (#40): wspólny klasyfikator w silniku (`src/Scope/`, reguły `scripts/quality/scope-rules.json`)
  zastępuje prototyp `hook-scope.js`. Fail-closed (pusta lista, brak reguły, dziwna ścieżka, symlink, submoduł, zmiana
  mechanizmu dają pełny zestaw), jawny tryb commita i baza porównania (zwykły, amend, pierwszy commit, merge i spółka),
  profile `local-narrow` i `enforce-all`, polecenie `pc scope`, opcja `pc run --profile=`, job CI `scope`,
  `test/ci-enforcement.test.js`. Hook bez Node jawnie nie skanuje bramki `process`. Opis: `docs/bramki.md` („Pusty stage”).
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
