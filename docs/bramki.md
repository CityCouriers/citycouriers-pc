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
