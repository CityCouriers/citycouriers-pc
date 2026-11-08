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

Wersja 0.1.0 wykonuje tylko `config:validate`. Pozostałe nazwy są zarezerwowane w kontrakcie konfiguracji,
a ich kod dochodzi według [planu przeniesienia](plan-przeniesienia.md). Nowa nazwa bramki to zmiana kontraktu.

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
| `actionlint` | `.github/workflows/*.yml` i `*.yaml` | wszystko inne | sam klasyfikator, workflow |
| `shellcheck` | `.sh`, `.githooks/*` | wszystko inne | konfiguracja ShellCheck |
| `gitleaks`, `composer audit` | zawsze (sekrety i podatności nie mają „typu pliku”) | nic | nie dotyczy |
| `composer normalize` | `composer.json` | wszystko inne | nie dotyczy |
| `mutation` | `.php` w `src/` | testy, dokumentacja, konfiguracja | testy zmienionego kodu |
| `coverage`, `changed-lines` | `.php` w `src/` | testy, dokumentacja | polityka pokrycia |
| `commit-msg` | każdy commit | nic | nie dotyczy |

Tabela jest wstępną propozycją. Ostateczne wyzwalacze każdej bramki ustala jej zgłoszenie i test zakresu.
