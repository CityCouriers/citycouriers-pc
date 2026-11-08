# Proces pracy w repozytorium

Opis tego, jak zgłoszenia, etykiety, audyt, Pull Requesty i kontrole CI składają się w jedną całość. Mechanizm jest
wspólny dla repozytoriów CityCouriers (ten sam układ ma `cc-google-rating-badge`), więc różnice zapisujemy tutaj.

## Zgłoszenia, triage i etykiety

- Jedno zgłoszenie to jedna zmiana. Zgłoszenie o bramce dotyczy **jednej bramki i jednego narzędzia**.
- Każde otwarte zgłoszenie ma dokładnie jeden priorytet `P0`-`P4` i jedno ryzyko `T1`-`T3` oraz co najmniej jeden `area:*`
  (rubryka w [.github/LABELS.md](../.github/LABELS.md), źródło prawdy w `.github/labels.json`).
- Brak `P` lub `T` w tabeli „Triage” w treści zgłoszenia daje etykietę `needs-triage`. Workflow **Issue audit gate**
  uzupełnia etykiety z tabeli, a gdy to nie wystarcza, nakłada `needs-triage`.
- Etykiety zmienia się wyłącznie przez `labels.json` w PR. Workflow **Sync GitHub labels** nakłada je po scaleniu.
- Zgłoszenia są publiczne: bez sekretów, ścieżek z maszyn, adresów e-mail i danych z prywatnych repozytoriów.

## Audyt specyfikacji

Zgłoszenie z priorytetem `P0`-`P2` albo ryzykiem `T2`-`T3` ma stan audytu: `audit:pending`, `audit:ok` albo `audit:changes`.
Recenzent (strona inna niż autor treści) rozstrzyga komentarzem `/audit-ok` albo `/audit-changes`. Edycja treści cofa
stan do `audit:pending`. Etykietami `audit:*` zarządza workflow, nie ręka. Bramka jest konwencją, a nie zamkiem:
GitHub nie potrafi zablokować pracy nad zgłoszeniem, a etykieta jest sygnałem dla człowieka i agenta.

Bramka działa od północy UTC w dniu założenia repo (2026-11-08); data startu jest w
`.github/workflows/issue-audit.yml` i nie wolno jej cofać (cichy backfill całego archiwum). Zgłoszenie wchodzi do
bramki, gdy jest w zakresie i zostało zmienione po starcie, albo gdy recenzent rozstrzygnie je komendą.

## Pull requesty

- Do `main` wchodzi się przez PR (ochrona gałęzi). Wymagane kontrole: **`quality-gate`** i **`pr-title`**.
- Tytuł PR to Conventional Commits z tematem małą literą. Squash przenosi go do historii `main`.
- Commit przez hook (`composer hooks`), bez `--no-verify`.
- PR w Draft do czasu zielonego CI na bieżącym HEAD. Po „Ready for review” workflow startuje od nowa.

## Kontrole CI

| Job | Co sprawdza |
| --- | --- |
| `composer` | `composer validate --strict` |
| `pint` | Format kodu PHP |
| `phpstan` | Analiza statyczna, poziom max |
| `phpmd` | Surowy zestaw reguł PHPMD |
| `tests` | Testy Pest |
| `negative-controls` | Każda bramka zatrzymuje znany błąd ([bramki.md](bramki.md)) |
| `privacy` | Brak ścieżek maszyn, katalogów domowych i adresów e-mail |
| `secret-scan` | gitleaks na historii, z kontrolą na sztucznym tokenie |
| `process-tests` | Testy skryptów procesu: bramka audytu, etykiety, `quality-gate` |
| `quality-gate` | Zbiorczo: każdy powyższy job musi mieć wynik `success` |

Nowy job kontrolny dopisujemy do `needs` w `quality-gate` i do `scripts/quality/quality-gate.js`; test
`test/quality-gate.test.js` pilnuje zgodności z `ci.yml`.

## Wydania

Schemat SemVer dla bramek i proces wydania: [wydania.md](wydania.md).

## Pochodzenie wspólnych elementów

Etykiety, bramka audytu zgłoszeń (`scripts/quality/issue-audit-*.js` i ich testy), job zbiorczy `quality-gate`, workflow
`pr-title` i `Sync GitHub labels` pochodzą z `CityCouriers/wordpress-automation` (MIT, ten sam właściciel praw) przez
`CityCouriers/cc-google-rating-badge`. Dostosowano listę obszarów, listę jobów i datę startu bramki. Numery `#NNN` w
komentarzach skopiowanych skryptów oznaczają zgłoszenia i PR z tamtych repozytoriów, a nie z tego.
