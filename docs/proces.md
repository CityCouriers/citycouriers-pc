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

**Warunek działania:** członkostwo osoby audytującej w organizacji musi być **publiczne**. Przy ukrytym członkostwie
`GITHUB_TOKEN` (nie członek) widzi autora komentarza jako `CONTRIBUTOR`, a bramka przyjmuje tylko `OWNER`, `MEMBER`
i `COLLABORATOR`, więc poprawny werdykt jest ignorowany. Listy nie rozszerzamy o `CONTRIBUTOR`, bo to także każdy, kogo PR
został scalony. Powód odrzucenia jest wypisany w logu przebiegu i w podsumowaniu (`pominięto N komend z powodu
author_association`).

Bramka działa od północy UTC w dniu założenia repo (2026-11-08); data startu jest w
`.github/workflows/issue-audit.yml` i nie wolno jej cofać (cichy backfill całego archiwum). Zgłoszenie wchodzi do
bramki, gdy jest w zakresie i zostało zmienione po starcie, albo gdy recenzent rozstrzygnie je komendą.

## Pull requesty

- Do `main` wchodzi się przez PR (ochrona gałęzi). Wymagane kontrole: **`quality-gate`** i **`pr-title`**.
- Tytuł PR to Conventional Commits z tematem małą literą. Squash przenosi go do historii `main`.
- Commit przez hook (`composer hooks`), bez `--no-verify`. Hook uruchamia bramki dla typów zmienionych plików (`docs/bramki.md`, „Zakres plików bramki”); `PC_HOOK_SCOPE=all` wymusza pełny zestaw.
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
| `actionlint` | Workflow GitHub Actions: składnia, wyrażenia, skrypty `run:` przez ShellCheck |
| `zizmor` | Bezpieczeństwo workflow (persona pedantic, z tokenem: audyty online) |
| `yamllint` | Styl i poprawność YAML (`.yamllint.yaml`) |
| `schema` | check-jsonschema: workflow, `dependabot.yml`, konfiguracja zgłoszeń |
| `pinact` | Akcje przypięte do pełnego SHA z komentarzem wersji |
| `shellcheck` | Skrypty `.sh` i hooki |
| `scope` | Klasyfikator zakresu w profilu `enforce-all` na pełnej historii (CI niczego nie zawęża) |
| `process-tests` | Testy skryptów procesu: bramka audytu, etykiety, `quality-gate` |
| `quality-gate` | Zbiorczo: każdy powyższy job musi mieć wynik `success` |

Nowy job kontrolny dopisujemy do `needs` w `quality-gate` i do `scripts/quality/quality-gate.js`; test
`test/quality-gate.test.js` pilnuje zgodności z `ci.yml`.

## Aktualizacja narzędzi

Narzędzia deweloperskie (`require-dev`) są w **najnowszych wydanych wersjach stabilnych**. Wyjątki są dozwolone, ale każdy jest
jawnie uzasadniony. W dokumentacji i zgłoszeniach nie wpisujemy numerów wersji (zmieniają się co tydzień): źródłem prawdy są
`composer.json` i wynik `composer outdated`.

### Rytm

- **Composer i npm: ręcznie, w każdą sobotę, jednym PR-em na cykl.** Dependabot dla tych ekosystemów jest wyłączony (otwierałby
  równolegle własne PR-y na te same wersje, a każdy uruchamiałby pełne CI).
- **GitHub Actions: automatycznie przez Dependabota**, raz w tygodniu w sobotę (`.github/dependabot.yml`: grupa minor i patch,
  etykieta `dependencies`, limit otwartych PR, `cooldown` 7 dni; cooldown zostaje, bo audyt `dependabot-cooldown` w `zizmor`
  zgłasza jego brak). Tygodniowy rytm nie kosztuje, bo `pc` jest publiczne i minuty CI są darmowe.

### Wykonawca

Cykl prowadzi rola **opiekun narzędzi** (rola, nie osoba). Rolę wskazuje właściciel repozytorium; zastępstwo i przekazanie roli
to komentarz w stałym zgłoszeniu cyklu („Aktualizacja narzędzi”) z nazwą przejmującego, a do czasu takiego wpisu rolę pełni właściciel.

### Procedura cyklu

1. **Stan wyjściowy:** czysty `main`, zielone bramki, spis otwartych alertów bezpieczeństwa.
2. **Alerty:** alerty Dependabota, `composer audit` i `npm audit`.
3. **Przegląd wersji:** `composer outdated --direct` i `npm outdated`. Dla każdej bezpośredniej zależności dev dwa warunki:
   zainstalowana wersja jest najnowszą stabilną **oraz** dolna granica w `composer.json` jest równa najnowszej stabilnej
   (`composer outdated` pokazuje tylko pierwszy warunek). Warunek sprawdza skrypt `composer bounds`
   (`scripts/ci/tool-bounds.php`, pobiera najnowsze stabilne z Packagist): kod niezerowy i wskazanie pakietu, gdy któraś wartość jest
   niższa, a pakiet nie ma wpisu w `scripts/ci/tool-bounds-exceptions.json` (pakiet => powód; pusty powód nie zwalnia).
   Wynik to tabela (pakiet, najnowsza stabilna, zainstalowana, dolna granica, docelowe minimum, wyjątek) w opisie PR.
4. **Minima** w `composer.json` podnosimy do najnowszej stabilnej, potem `composer update`.
5. **Jeden PR na cykl** z wszystkimi aktualizacjami, w **Draft** do zielonych bramek lokalnych (`composer check` i bramki hooka).
6. **Czerwony build nie osłabia bramek:** nie luzujemy reguł, progów ani testów, żeby przepuścić aktualizację. Naprawiamy kod albo
   odkładamy pakiet z opisem w PR.
7. **Narzędzie zmieniające wyniki bramek** (Pint, PHPStan, PHPMD): w opisie PR wynik bramki przed i po aktualizacji.
8. **Wersje wstępne i deweloperskie** (`*-dev`, alpha, beta, RC) tylko z wpisem wyjątku i uzasadnieniem w opisie PR: dlaczego nie
   stabilna i kiedy wracamy do stabilnej.
9. **Ślad:** w stałym zgłoszeniu cyklu **w każdym tygodniu**, także gdy nic się nie zmieniło, komentarz z datą, wynikiem
   `composer outdated --direct`, `npm outdated` (brak pakietów zapisujemy jawnie: „brak pakietów do aktualizacji”), tabelą dolnych
   granic i decyzją („zaktualizowano: PR …” albo „bez aktualizacji: powód”). PR cyklu dołącza do zgłoszenia przez `Refs`, bez zamykania.
   Pominięty tydzień niczego nie kumuluje: następny cykl zaczyna od stanu bieżącego.

### Ścieżka pilna

Alert o ważności HIGH lub CRITICAL nie czeka na sobotę: idzie osobnym, szybkim PR-em z tą samą zasadą braku luzowania bramek.
W stałym zgłoszeniu cyklu zostaje wzmianka (`Refs`).

### Wyjątki

Wpis w `scripts/ci/tool-bounds-exceptions.json` jest dozwolony tylko z powodem (np. brak wydania stabilnego, niezgodność z
wymaganiem PHP paczki) i jest powtórnie oceniany w każdym cyklu. Powód trafia też do opisu PR. Zasada ogólna dla narzędzia
`phpcpd` (zgłoszenie bramki duplikatów): tylko narzędzie **utrzymywane** w wersji **stabilnej**; pakiety oznaczone na Packagist
jako porzucone nie wchodzą, chyba że z uzasadnionym wyjątkiem.

### Wykrycie pominięcia cyklu

Zaplanowany workflow poniedziałkowy, który sprawdza ślad z ostatnich 8 dni w stałym zgłoszeniu cyklu, jest osobną zmianą
(kontrola ujemna w jego testach); do czasu jego wdrożenia pominięcie wykrywa opiekun narzędzi przy poniedziałkowym przeglądzie zgłoszeń.

## Wydania

Schemat SemVer dla bramek i proces wydania: [wydania.md](wydania.md).

## Pochodzenie wspólnych elementów

Etykiety, bramka audytu zgłoszeń (`scripts/quality/issue-audit-*.js` i ich testy), job zbiorczy `quality-gate`, workflow
`pr-title` i `Sync GitHub labels` pochodzą z `CityCouriers/wordpress-automation` (MIT, ten sam właściciel praw) przez
`CityCouriers/cc-google-rating-badge`. Dostosowano listę obszarów, listę jobów i datę startu bramki. Numery `#NNN` w
komentarzach skopiowanych skryptów oznaczają zgłoszenia i PR z tamtych repozytoriów, a nie z tego.

## Testy w prawdziwej konsoli (Windows)

CI biegnie na Linuksie, a wynik przez pipe (narzędzia, agenci) nie ma konsoli, więc trzy klasy błędów wychodzą dopiero
w interaktywnej konsoli. Przed oddaniem zmiany w testach procesów, powłoki albo sygnałów uruchom `php vendor/bin/pest`
w zwykłej konsoli (Windows Terminal z PowerShell, cmd, Git Bash/mintty) i sprawdź, że bieg kończy się linią `Tests: … passed`
bez `failed` i `skipped`:

- **Zdarzenia konsoli.** `CTRL_C`/`CTRL_BREAK` bez numeru grupy trafia do wszystkich procesów konsoli, a nie do jednego PID. Test sygnału
  uruchamia podproces jako lidera własnej grupy (`create_process_group`) i wysyła `CTRL_BREAK` z numerem tej grupy (PID podprocesu), nigdy z 0, inaczej bieg się urywa. Własna konsola (`create_new_console`) otwierała migające okno.
- **`bash` z PATH.** W PowerShell i cmd pierwszy `bash` bywa wrapperem WSL (WindowsApps). Testy skryptów używają
  `Tests\Support\PosixShell` (bash z Gita) zamiast gołego `bash`.
- **`Path` i `PATH`.** PowerShell podaje `Path`; `GitRepo::environment()` nie dopuszcza dwóch kluczy różniących się wielkością liter.

- **stdin procesu potomnego.** Proces bez jawnego stdin dziedziczy terminal. Msys bash (Git for Windows) pod mintty zmienia wtedy
  stan terminala i urywa bieg Pesta (kod wyjścia 0, brak podsumowania), a w PowerShell i cmd tego nie widać. Każdy proces
  uruchamiany przez `pc` (`src/Process/EmptyInput`) i przez testy (`Tests\Support\Child`) dostaje pusty stdin; pilnuje tego
  `tests/Unit/ChildProcessPolicyTest.php` (każdy `proc_open` ma deskryptor 0, brak `shell_exec`/`exec`).

Ręczne sprawdzenie w mintty (Git Bash). Uruchom w oknie Git Bash, nie przez pipe ani narzędzie:

```bash
php vendor/bin/pest --log-junit /tmp/pest-mintty.xml; echo "exit=$?"
```

Poprawny wynik: ostatnie linie to `Tests: N passed (…)` i `Duration: …`, potem `exit=0`; plik `/tmp/pest-mintty.xml` ma niezerowy rozmiar
i w nagłówku `failures="0"`. Objawy błędu: wyjście urwane w środku (przed podsumowaniem), ESC widoczny jako `←[`, a `exit=0` mimo
braku podsumowania albo pusty plik XML.

Regresje: `tests/Integration/PosixShellTest.php`. Te klasy błędów nie są widoczne w CI na Linuksie.

## Testy: Unit i Integration

Testy Pesta są w dwóch zestawach (`phpunit.xml.dist`):

| Zestaw | Katalog | Co tu trafia | Próg |
| --- | --- | --- | --- |
| `Unit` | `tests/Unit` | czysta logika w pamięci: bez procesów, bez prawdziwego gita, bez powłoki, bez sygnałów, bez zapisu poza katalogiem tymczasowym | pojedynczy test do ok. 0,3 s; cały zestaw poniżej kilku sekund |
| `Integration` | `tests/Integration` | wszystko, co tworzy repozytorium git, uruchamia proces (php, bash, `git`), hook, sygnał albo czyta repozytorium | bez progu, ale każdy test zaczyna od prawdziwego procesu |

Test, który przekroczy próg albo uruchamia proces, przenosimy do `tests/Integration` (ten sam podkatalog i te same
helpery z `tests/Support`). Plik z mieszanką dzielimy: logika zostaje w `Unit`, część procesowa idzie do `Integration`.
Pomiar: `vendor/bin/pest --testsuite=Unit --log-junit czasy.xml` (atrybut `time` każdego przypadku).

```bash
composer test:unit          # szybki zestaw Unit
composer test:integration   # wolny zestaw Integration (prawdziwy git, hook, procesy)
composer test               # oba zestawy po kolei (to uruchamia hook pre-commit i `composer check`)
```

CI uruchamia oba zestawy osobnymi krokami (z nazwą zestawu w logu), potem w losowej kolejności i każdy plik osobno.
Hook `pre-commit` uruchamia `composer test`, czyli oba zestawy: skrócenie go do samego `Unit` osłabiłoby bramkę (hook i klasyfikator
zakresu mają testy tylko w `Integration`), więc tego nie robimy.
