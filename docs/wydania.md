# Schemat wydań

## SemVer dla bramek

Wersja ma postać `MAJOR.MINOR.PATCH`. Dla `pc` konsument aktualizuje paczkę i oczekuje, że zielone dotąd repo
nie zrobi się czerwone bez jego wiedzy. Stąd reguła:

| Zmiana | Wydanie |
| --- | --- |
| Nowa bramka **blokująca** | **major** |
| Ostrzejsza reguła istniejącej bramki blokującej (niższy próg, nowa reguła w zestawie PHPMD, wyższy poziom PHPStan) | **major** |
| Usunięcie albo zmiana nazwy bramki, pola `pc.json` albo polecenia | **major** |
| Nowa bramka **raportująca** (nie zatrzymuje commita) | **minor** |
| Nowe opcjonalne pole `pc.json`, nowe polecenie, nowy moduł wyłączony domyślnie | **minor** |
| Poprawka błędu, luźniejszy próg, dokumentacja, zależności deweloperskie | **patch** |

Przejście bramki raportującej na blokującą to zmiana major. Wersje `0.x` mogą zmieniać interfejs w wydaniu minor,
ale zasada dla bramek obowiązuje już od `0.1.0`: każda zmiana, która może zaczerwienić konsumenta, jest opisana
w changelogu pod nagłówkiem **Changed (breaking)**.

## Proces wydania

1. Zmiany idą przez Pull Request z zielonym `quality-gate`.
2. Commit wydania zmienia `Version::CURRENT` w `src/Version.php` na wersję bez sufiksu `-dev` i przenosi wpisy z
   `[Unreleased]` do sekcji `## [X.Y.Z] - RRRR-MM-DD` w `CHANGELOG.md`.
3. Tag `vX.Y.Z` na tym commicie. Workflow `Release` uruchamia `scripts/ci/check-release.sh` (format SemVer, zgodność
   z `Version::CURRENT`, niepusta sekcja changelogu) i tworzy GitHub Release z notatkami z changelogu.
4. Po wydaniu `Version::CURRENT` wraca do kolejnej wersji z sufiksem `-dev`.

Tag z sufiksem (`v0.1.0-rc.1`) tworzy wydanie oznaczone jako wstępne.

## Tag próbny v0.1.0

`v0.1.0` służy do sprawdzenia całego schematu na pustym szkielecie: check-release przechodzi dla poprawnego
tagu, a dla tagu niespójnego z wersją albo bez wpisu w changelogu kończy się błędem (testy w `tests/Integration/ReleaseScriptsTest.php`).

## Dystrybucja

Na starcie paczka jest dostępna z repozytorium typu `vcs` (`composer config repositories.pc vcs https://github.com/CityCouriers/pc`).
Publikacja na Packagist to osobna decyzja przy pierwszym wydaniu stabilnym.
