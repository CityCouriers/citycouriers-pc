# Kontrakt bramek

## Czym jest bramka

Bramka to jedna kontrola z jednoznacznym wynikiem: przeszła albo zatrzymała zmianę. Każda ma nazwę
(klucz w `gates` w `pc.json`), tryb i opis w tym dokumencie.

| Tryb | Znaczenie | Skutek wydania |
| --- | --- | --- |
| **blokująca** | Niezerowy wynik zatrzymuje commit | Dodanie albo zaostrzenie to wydanie major |
| **raportująca** | Wynik jest wypisany, ale nie zatrzymuje | Dodanie to wydanie minor |

## Znane nazwy bramek (schemat konfiguracji 1)

`env`, `stash`, `forbidden`, `composer`, `format`, `phpstan`, `phpmd`, `phpcpd`, `phpcompat`, `tests`,
`mutation`, `coverage`, `changed-lines`, `timeguard`, `secrets`.

Silnik `pc run` (interfejs bramki, runner, raport, kody wyjścia) opisuje sekcja poniżej. Nazwy bramek są zarezerwowane w kontrakcie
konfiguracji, a kod poszczególnych bramek dochodzi według [planu przeniesienia](plan-przeniesienia.md); do tego czasu włączona
bramka bez kodu daje `not_scanned` / `gate_not_implemented` (kod 4), nigdy zielone. Nowa nazwa bramki to zmiana kontraktu.

## Silnik `pc run`: kontrakt wyniku

Ta sekcja jest źródłem prawdy dla autorów bramek i konsumentów wyniku. Kod: `src/Engine/`. Definiuje ją zgłoszenie #3; wygląd
wyjścia w terminalu (kolory, `NO_COLOR`, raport HTML) projektuje #37, a decyzję „co uruchomić” dla danych plików klasyfikator zakresu #40.

```bash
vendor/bin/pc run                       # wszystkie włączone bramki, pełny zakres
vendor/bin/pc run --staged              # pliki ze stage'a (pusty stage = pełny zakres)
vendor/bin/pc run --format=json         # jeden dokument JSON na stdout (domyślnie text)
vendor/bin/pc run --base=<rewizja>      # baza diffu dla bramek liczących zmiany (podaje ją CI)
vendor/bin/pc run --config=<plik>       # inny plik konfiguracji (domyślnie pc.json)
```

### Stany wyniku bramki

Zbiór zamknięty. Każda bramka włączona w `pc.json` ma w raporcie dokładnie jeden wpis z jednym stanem, nawet gdy nie biegła.
Bramka wyłączona (`false`) nie ma wpisu.

| `status` | Nazwa | Znaczenie | `reason` |
| --- | --- | --- | --- |
| `passed` | przeszła | Sprawdziła i nic nie znalazła | `null` |
| `failed` | zatrzymała (blokująca) albo uwagi (raportująca) | Sprawdziła i znalazła naruszenia; `diagnostics` niepusta | `null` |
| `skipped` | pominięta | **Decyzja zakresu** (reguła klasyfikatora #40, wyłączenie w konfiguracji): bramka celowo nie biegła | wymagany |
| `not_scanned` | nie przeskanowano | Powinna była sprawdzić, ale nie mogła albo nie dowiodła, że sprawdziła | wymagany |

`skipped` wynika z decyzji, `not_scanned` z niemożności lub braku dowodu. Powód skanowania, który nie jest decyzją zakresu,
nigdy nie jest `skipped`. Silnik odrzuca taki wynik jako błąd (kod 5).

Zamknięty zbiór kodów powodu (`reason.code`); dodanie kodu to zmiana kontraktu:

- dla `skipped`: `no_matching_files`, `disabled_in_config`;
- dla `not_scanned`: `tool_missing` (z instrukcją instalacji w `reason.message`), `tool_failed`, `gate_crashed`,
  `gate_not_implemented`, `missing_input`, `nothing_checked`, `timeout`, `interrupted`, `precondition_failed`
  (z nazwą bramki wstępnej w `reason.blocked_by`).

### Tryb bramki

| Tryb | Wpływ na kod wyjścia |
| --- | --- |
| `blocking` | `failed` i `not_scanned` zmieniają kod (1 i 4) |
| `reporting` | Nigdy. `failed` ma w terminalu etykietę „uwagi”. `not_scanned` jest równie głośne jak w blokującej, `complete` w JSON jest `false`, a ostatnia linia terminala zaczyna się od „NIEPEŁNY”, nie od „OK” |

Bramka bez kodu (nazwa jest w kontrakcie konfiguracji, a implementacji jeszcze nie ma) jest raportowana jako blokująca
`not_scanned` / `gate_not_implemented`, nigdy `passed` ani brak wpisu.

### Kody wyjścia

Każdy kod ma jedną wartość `status` najwyższego poziomu w JSON, a `exit_code` w dokumencie równa się kodowi procesu.

| Kod | `status` | Kiedy |
| --- | --- | --- |
| 0 | `passed` | Żadna bramka blokująca nie ma `failed` ani `not_scanned`. `skipped` (z powodem) i dowolne wyniki bramek raportujących nie zmieniają kodu |
| 1 | `failed` | Co najmniej jedna bramka blokująca ma `failed` |
| 2 | `usage_error` | Nieznana opcja lub argument, nieznana wartość `--format`, `--staged` poza repozytorium git, `--base` razem z `--staged`, nierozwiązywalna rewizja `--base` (`error.code: "invalid_base"`) |
| 3 | `config_error` | Brak `pc.json`, nieczytelny albo niepoprawny plik, niezgodność ze schematem, nieznana nazwa bramki, brak włączonej bramki. Żadna bramka nie biegnie |
| 4 | `incomplete` | Brak `failed` w bramkach blokujących, ale co najmniej jedna blokująca ma `not_scanned` |
| 5 | `error` | Błąd silnika (nie bramki): nie da się odczytać stage'a, niespójny wynik bramki odrzucony przez walidację (w tym ujemne `checked.count`), poprawa wyniku w `finalize`, wyjątek w `finalize`. Bieg przerwany, nieuruchomione bramki są `not_scanned` / `interrupted` |
| 130, 143 | `interrupted` | SIGINT (130) albo SIGTERM (143). Nieuruchomione bramki: `not_scanned` / `interrupted` |

Reguła pierwszeństwa przy wynikach mieszanych, od najwyższego: przerwanie (130/143) > błąd silnika (5) > `failed` w bramce
blokującej (1) > `not_scanned` w bramce blokującej (4) > 0. Kolejność wykrywania błędów: użycie opcji (2), konfiguracja (3),
repozytorium i `--base` (2), dopiero potem bieg.

`config:validate` zachowuje kody 0 / 1 / 2 z wersji 0.1.0 (kod 3 dotyczy tylko `pc run`).

### Reguły fail-closed silnika

Egzekwuje je runner, nie pojedyncza bramka:

1. `passed` z `checked.count == 0` (gdy `checked` nie jest `null`) staje się `not_scanned` / `nothing_checked`.
2. Wyjątek w bramce daje `not_scanned` / `gate_crashed`, a reszta bramek biegnie dalej.
3. Bramka bez kodu to `not_scanned` / `gate_not_implemented`.
4. Pusta lista plików (`--staged` bez zmian) nie oznacza „brak bramek”: kontekst ma `files: null` (pełny zakres), a rozstrzygnięcie
   na `skipped` należy wyłącznie do klasyfikatora #40.
5. `skipped` powstaje tylko z jawnej decyzji zakresu i zawsze ma `reason`.
6. **Bramka wstępna** (`precondition`, tylko blokująca; pierwsza będzie `env`) biegnie przed resztą. Gdy kończy się `failed` albo
   `not_scanned`, kolejne bramki nie biegną i mają `not_scanned` / `precondition_failed` z `reason.blocked_by`. Kod wynika z bramki
   wstępnej (1 albo 4). Wstępna `skipped` nie przerywa biegu. Rejestracja odrzuca raportującą bramkę wstępną.
7. **Faza `finalize`** (opcjonalny interfejs `FinalizingGate`, dla bramek zmieniających dane użytkownika, np. `stash`). Wywoływana raz
   dla każdej bramki, której faza główna została wywołana (także gdy rzuciła wyjątek), w kolejności odwrotnej do rozpoczęcia, po
   wszystkich fazach głównych: także po wyjątku w innej bramce, po SIGINT/SIGTERM i po błędzie silnika. Bramka niewywołana (np.
   `precondition_failed`, `interrupted` przed startem, `gate_not_implemented`) i bramka z wynikiem `skipped` jej nie dostają. Wejście: wynik fazy
   głównej i przyczyna końca biegu (`completed`, `gate_exception`, `interrupted`, `engine_error`), ustalona raz przed pierwszym
   `finalize`. Wynik wolno tylko **pogorszyć** (`passed` < `not_scanned` < `failed`); poprawa to błąd silnika (kod 5). Wyjątek w
   `finalize` to osobny, widoczny fakt: bieg kończy się kodem 5 (`error.code` `engine_error`, komunikat nazywa bramkę i wyjątek, bo
   przywrócenie stanu mogło się nie dokonać). `failed` zostaje `failed` (dochodzi diagnostyka z regułą `finalize_crashed`), `not_scanned`
   dostaje powód `gate_crashed` z wcześniejszym powodem w komunikacie, pozostałe stany stają się `not_scanned` / `gate_crashed`.
   Pozostałe `finalize` się wykonują. Awaria `finalize` trafia do `error.message` także wtedy, gdy kod wyjścia wyznacza sygnał (130 / 143)
   albo wcześniejszy błąd silnika, więc jest widoczna w JSON i na stderr; kod wyjścia się wtedy nie zmienia. Sygnał w
   trakcie `finalize` jest odroczony do jego końca (silnik tylko go zapamiętuje). SIGKILL i utrata zasilania są poza zasięgiem silnika:
   bramka odpowiada za trwały zapis i odzyskiwanie. Kod wyjścia liczy się po `finalize`, a po przerwaniu pozostaje 130 / 143.

Wynik `passed` ustalony w chwili przerwania (SIGINT w trakcie bramki) jest niewiarygodny, więc staje się `not_scanned` / `interrupted`.

### Baza diffu (`--base`)

Bramki liczące zmiany (np. `changed-lines`) potrzebują punktu odniesienia, a na czystym checkoucie CI pusty diff nie może oznaczać
„nic do sprawdzenia”. Wywołujący (CI) podaje rewizję, np. wynik `git merge-base`; silnik jej nie liczy. Rozwiązuje ją raz
(`git rev-parse --verify <rewizja>^{commit}`) do pełnego OID i przekazuje bramkom w kontekście jako `diffBase` oraz w JSON jako `diff_base`.
Bez `--base` pole to `null`, a bramka, która bazy wymaga, zwraca `not_scanned` / `missing_input`. Rewizja nierozwiązywalna
(literówka, płytki klon) to kod 2 z `error.code: "invalid_base"` i wskazówką `fetch-depth: 0`. `--base` i `--staged` wykluczają się.
Z której listy plików korzysta klasyfikator w `--scope=ci`, rozstrzyga #40.

### Schemat JSON (`schema_version: 1`)

Plik JSON Schema: [schema/pc-run-1.json](schema/pc-run-1.json). Testy sprawdzają zgodność wyjścia dla każdego kodu wyjścia.

- Na stdout trafia **dokładnie jeden dokument** (jedna linia UTF-8), tekst dla człowieka idzie na stderr. Dla kodu 2 dokument jest
  wypisany, gdy `--format=json` udało się rozpoznać; w przeciwnym razie stdout jest pusty.
- Wszystkie pola są zawsze obecne; brak danych to `null` (nigdy brak klucza, pusty ciąg ani `0` w zastępstwie).
- Enumy (`status`, `mode`, `severity`, `reason.code`, `error.code`) są zamknięte i angielskie. Polskie etykiety należą do warstwy prezentacji.
- Nowe pole opcjonalne nie zmienia `schema_version`; konsument ignoruje pola, których nie zna. Usunięcie lub zmiana pola, typu,
  semantyki albo dodanie wartości enum podnosi `schema_version` i po 1.0.0 jest zmianą major.

| Pole dokumentu | Opis |
| --- | --- |
| `schema_version` | Wersja schematu (`1`) |
| `tool` | `name` (`"pc"`) i `version` |
| `command` | `"run"` |
| `scope` | `"full"`, `"staged"` albo `null` (bieg zakończony przed ustaleniem zakresu: kody 2, 3) |
| `diff_base` | Pełny OID z `--base` albo `null` |
| `status`, `exit_code` | Stan i kod wyjścia (tabela wyżej) |
| `complete` | `false`, gdy jakakolwiek bramka (także raportująca) ma `not_scanned`, bieg został przerwany albo zakończył się błędem |
| `summary` | Liczniki `gates`, `passed`, `failed`, `skipped`, `not_scanned`, `blocking_failed`, `reporting_failed` |
| `gates` | Wpis na każdą włączoną bramkę (pusta tylko przy kodach 2 i 3) |
| `error` | `code`, `message`, `hint` dla kodów 2, 3, 5, 130, 143; w pozostałych `null` |

Wpis bramki: `name`, `mode`, `status`, `reason` (`code`, `message`, `blocked_by`), `checked` (`unit`: `files`, `tests`, `checks`; `count`),
`duration_ms` (`null` dla bramki, która nie biegła) i `diagnostics`. Diagnostyka: `rule`, `file`, `line`, `column`, `severity`
(`error`, `warning`), `message`, `hint`; `file` ma ścieżkę względną ze znakami `/` albo `null`.

Kolejność i stabilna semantyka: `gates` w kolejności wykonania, wynikającej z rejestru silnika (kanoniczna lista nazw z tego dokumentu,
bramki wstępne pierwsze), a nie z kolejności kluczy w `pc.json` ani z czasu zakończenia; `diagnostics` posortowane po `file`
(porządek bajtowy, `null` na początku), `line`, `column`, `rule`, `message`. Dwa biegi na tym samym stanie repo dają identyczny JSON z
dokładnością do `duration_ms`.

### Wyjście tekstowe

Wygląd projektuje #37. Silnik gwarantuje zgodność z JSON: po jednej linii na bramkę (`nazwa: przeszła | zatrzymała | uwagi | pominięta |
NIE PRZESKANOWANO`, z powodem i diagnostykami), a słowo „OK” pojawia się wyłącznie w ostatniej linii, gdy `status` to `passed`
i `complete` to `true`. Inaczej ostatnia linia zaczyna się od „ZATRZYMANO”, „NIEPEŁNY” albo „BŁĄD”.

## Interfejs bramki (dla autorów bramek)

Bramka implementuje `CityCouriers\Pc\Engine\Gate` i rejestruje się w `GateRegistry`. Interfejs wymusza deklarację:

| Metoda | Znaczenie |
| --- | --- |
| `name()` | Nazwa z `pc.json` (jedna ze znanych nazw) |
| `mode()` | `GateMode::Blocking` albo `GateMode::Reporting` |
| `precondition()` | Bramka wstępna (reguła 6); tylko blokująca |
| `checkedUnit()` | `CheckedUnit` (`Files`, `Tests`, `Checks`) albo `null` = jawny brak jednostki |
| `triggers()` | `GateTriggers`: allowlista rozszerzeń i ścieżek, pliki konfiguracji wymuszające pełny zakres, powód pominięcia. Decyzję podejmuje klasyfikator #40 |
| `run(GateContext)` | Wykonanie. Kontekst: `scope`, `files` (`null` = pełny zakres), `diffBase`, `root`, `config` |

Opcjonalnie `FinalizingGate::finalize(GateContext, GateResult, EndCause)` (reguła 7). Wynik budują `ResultFactory` (`passed`,
`failed`, `skipped`, `notScanned`) albo `new GateResult(...)`. Zasady dla autora:

- `passed` musi nieść `checked` z liczbą większą od zera (gdy bramka deklaruje jednostkę); `checked` musi mieć tę samą jednostkę, co `checkedUnit()`.
- `failed` ma niepustą listę `Diagnostic`; `passed`, `skipped` i `not_scanned` mają pustą.
- `skipped` tylko z decyzji zakresu (`no_matching_files`, `disabled_in_config`), `not_scanned` dla całej reszty (brak narzędzia, błąd narzędzia, brak wejścia).
- Bramka nie łapie własnych wyjątków do „zielonego”: wyjątek to `gate_crashed`, a nie `passed`.
- Każda bramka ma test pozytywny i negatywny (kontrola ujemna, zob. wyżej) oraz atrapę w testach silnika, jeśli zmienia kontrakt.

## Kontrola ujemna

Zielony wynik nic nie mówi, jeśli bramka nie ma szansy zrobić się czerwona. Dlatego:

1. Każda bramka ma plik testowy z celowym błędem w `tests/Fixtures/negative/`.
2. `scripts/ci/negative-controls.sh` uruchamia bramkę na tym pliku i wymaga **dwóch** rzeczy: niezerowego
   kodu wyjścia oraz nazwy pliku testowego w wyjściu. Drugi warunek wyklucza fałszywy dowód: bramka mogła paść
   z innego powodu albo nie objąć pliku wcale.
3. Skaner sekretów ma własną kontrolę w jobie `secret-scan`: generuje sztuczny token i wymaga, by został wykryty.
4. Plik testowy z błędem jest wyłączony z właściwego biegu tej samej bramki (`notPath`, `excludePaths`), żeby
   nie psuł CI.

Nowa bramka bez kontroli ujemnej nie przechodzi review.

## Surowy zestaw PHPMD

`rulesets/phpmd.xml` jest jednym zestawem dla całego kodu: aplikacji, narzędzi i testów. Nie ma łagodniejszego
zestawu „dla skryptów”, bo taki zestaw poluzowuje bramkę dokładnie tam, gdzie kod rośnie najszybciej.
Odstępstwa konsument zapisuje w swoim repo jako listę wyjątków z uzasadnieniem, a bramka powinna zgłaszać
wpisy, które przestały być potrzebne.

## Kolejność i zasady wspólne

- Szybka ścieżka „tylko dokumentacja” i dobór testów do zmienionych plików są cechami bramek, nie dowodem:
  pełny bieg sprawdza całość.
- Bramka nie może przepuścić wyniku „nie przeskanowano” jako zielonego: pusta lista plików i brak narzędzia to
  osobne, głośne wyniki.

## Zakres plików bramki

Bramka, która nic nie sprawdzi, tylko wydłuża commit. PHPStan na commicie z samym `.md` albo `.yml` to stracone sekundy
przy każdej zmianie. Minuty CI nie są tu ograniczeniem (repo publiczne), więc skracamy **czas jednego commita**.
Zasady wzięte z doświadczeń pozostałych repo CityCouriers (selektor zakresu CI i szybka ścieżka hooka) i ich błędów.

### Ocena przy wprowadzaniu każdej bramki

Zgłoszenie i PR nowej bramki odpowiadają na cztery pytania (pole „Zakres plików” w szablonach):

1. **Wyzwalacze:** jakie typy plików i ścieżki uruchamiają bramkę (rozszerzenia i katalogi, nie „kod”).
2. **Pomijane:** jakie typy plików ją pomijają i dlaczego nic by tam nie sprawdziła.
3. **Konfiguracja i zależności:** które pliki zmieniają jej wynik dla całego repo (np. `phpstan.neon`, `composer.json`,
   zestaw reguł). Zmiana takiego pliku uruchamia bramkę **w pełnym zakresie**, nie tylko na zmienionych plikach.
4. **Test zakresu:** kontrprzykłady, które muszą ją uruchomić mimo pozorów (np. `docs/x.php`, `docs/x.json`, `.yaml`).

### Zasady

- **Allowlista zwolnień, nie denylista.** Bramka biegnie, chyba że jawna, zamknięta reguła mówi, że nie ma co sprawdzać.
  Brak reguły, pusta lista plików, dziwna ścieżka (`\`, `..`, absolutna, pusty segment), dowiązanie symboliczne
  i submoduł dają **pełny zestaw**. Nie zakładamy, że `.json`, `.yml`, `.neon` albo `.env` to dokumentacja.
- **Pominięcie jest widoczne.** Wynik to `SKIPPED (powód)`, nigdy `OK`. Bramka, która pomija po cichu, wygląda jak bramka,
  która sprawdziła (w jednym z naszych repo bramka przez dwa miesiące zwracała OK, nic nie sprawdzając). Bramka sprawdza też, że coś
  faktycznie zrobiła: liczba plików lub testów większa od zera.
- **Pusty stage to nie brak bramek.** `git commit --amend` bez zmian i podobne przypadki dają pustą listę plików, więc
  uruchamiamy wszystko. Jest to znany koszt, a doprecyzowanie (różnica `HEAD^` i indeks) to osobne zgłoszenie.
- **Mechanizm sam siebie nie zwalnia.** Zmiana hooka, klasyfikatora zakresu i jego testów uruchamia wszystkie bramki.
  Skaner sekretów i skan prywatności biegną zawsze (tanie, a pomyłka jest nieodwracalna).
- **Wyłączyć można tylko przez konfigurację z uzasadnieniem, nigdy zmienną środowiskową.** `PC_HOOK_SCOPE=all` może tylko
  **rozszerzyć** zakres do pełnego. Nie ma zmiennej, która wyłącza bramkę.
- **Jedno źródło prawdy.** Jedna klasyfikacja plików dla hooka i CI, bez kopii w kilku miejscach. Dziś (prototyp)
  żyje w `scripts/quality/hook-scope.js` dla hooka tego repo; silnik `pc` przejmie ją jako wspólny klasyfikator.
- **Lokalnie i w CI ten sam zestaw testów.** Bramka, której test biegnie tylko w CI, wychodzi dopiero po wypchnięciu.

### Test klasyfikatora

Tabela „plik → bramki” jest spisana niezależnie od reguł w kodzie. Testy ujemne pilnują fail-closed (pusta lista,
nieznany plik, `docs/x.php`, `README.MD`, ścieżka z `\`, symlink). Test porównuje bramki z hooka z klasyfikatorem
i wymusza decyzję o każdym nowym pliku w repo bez reguły (`test/hook-scope.test.js`).

### Wyzwalacze bramek (obecne w tym repo i propozycje dla planowanych)

| Bramka | Uruchamiają | Pomijają | Pełny zakres po zmianie |
| --- | --- | --- | --- |
| `lint` (Pint) | `.php` | `.md`, `.yml`, `.json`, `.sh` | `pint.json`, `composer.json` |
| `stan` (PHPStan) | `.php` | `.md`, `.yml`, `.json`, `.sh`, `.xml` | `phpstan.neon`, `composer.json` |
| `smells` (PHPMD) | `.php` w `src/` i `bin/` | testy, dokumentacja, konfiguracja CI | `rulesets/`, `composer.json` |
| `test` (Pest) | `.php`, fikstury testów, `scripts/ci/*.sh` | dokumentacja, `.github/` | `phpunit.xml.dist`, `composer.json` |
| `negative` (kontrole ujemne) | `src/`, `bin/pc`, `rulesets/`, `tests/Fixtures/`, `scripts/ci/*.sh` | dokumentacja, testy jednostkowe | `pint.json`, `phpstan.neon`, `composer.json` |
| `process` (testy skryptów procesu) | `scripts/quality/`, `test/`, `.github/`, `package.json` | `.php`, dokumentacja | `package.json` |
| `privacy` (ścieżki, e-maile) | każda zmiana | nic | `.gitignore`, `.gitattributes` |
| `phpcs` (WPCS), `phpcompat` | `.php` | `.md`, `.yml`, `.json`, `.js` | zestaw reguł PHPCS, `composer.json` |
| `prettier` | `.json`, `.yml`, `.md`, `.css` | `.php` | konfiguracja Prettiera |
| `actionlint` | `.github/workflows/*.yml` i `*.yaml` | wszystko inne | `.yamllint.yaml`, `.github/zizmor.yml`, sam klasyfikator |
| `zizmor` (pedantic) | `.github/workflows/*.y(a)ml`, `.github/dependabot.yml` | wszystko inne | `.github/zizmor.yml`, `requirements-lint` |
| `yamllint` | `.yml` i `.yaml` w `.github/` | `.php`, `.md`, `.json` | `.yamllint.yaml` |
| `schema` (check-jsonschema) | workflow, `dependabot.yml`, `ISSUE_TEMPLATE/config.yml` | wszystko inne | pliki schematów |
| `pinact` | `.github/workflows/*.y(a)ml` | wszystko inne | konfiguracja pinact |
| `shellcheck` | `.sh`, `.githooks/*` | wszystko inne | konfiguracja ShellCheck |
| `gitleaks`, `composer audit` | zawsze (sekrety i podatności nie mają „typu pliku”) | nic | nie dotyczy |
| `composer normalize` | `composer.json` | wszystko inne | nie dotyczy |
| `mutation` | `.php` w `src/` | testy, dokumentacja, konfiguracja | testy zmienionego kodu |
| `coverage`, `changed-lines` | `.php` w `src/` | testy, dokumentacja | polityka pokrycia |
| `commit-msg` | każdy commit | nic | nie dotyczy |

Bramki plików GitHub (`shellcheck`, `yamllint`, `actionlint`, `schema`, `pinact`, `zizmor`) mają jedno źródło komend,
`scripts/ci/github-gate.sh`, które woła hook i CI. Każde wywołanie sprawdza prawdziwe pliki repo, a potem to samo
narzędzie na pliku z celowym błędem z `tests/Fixtures/negative-github/` (kontrola ujemna: kod niezerowy i nazwa pliku w
wyjściu). Brak narzędzia to głośny błąd z instrukcją instalacji, a pusta lista plików to „nie przeskanowano”, nie wynik
zielony.

Tabela jest wstępną propozycją. Ostateczne wyzwalacze każdej bramki ustala jej zgłoszenie i test zakresu.
