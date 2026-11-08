# Konfiguracja repo-konsumenta

Repo-konsument ma jeden plik `pc.json` w korzeniu. Wybiera bramki, ścieżki, standard kodowania, zestawy
reguł i moduły. Poprawność sprawdza `vendor/bin/pc config:validate`.

## Schemat 1

| Pole | Typ | Opis |
| --- | --- | --- |
| `version` | `1` | Wymagane. Wersja schematu |
| `standard` | `"pint"` albo `"phpcs"` | Standard kodowania: Pint (np. aplikacje Laravel), PHPCS z WPCS (wtyczki WordPress) |
| `paths.src`, `paths.tests` | lista ścieżek | Ścieżki kodu i testów, względne, bez `..` |
| `gates` | obiekt nazwa → `true`/`false` | Włączenie bramki. Nazwy: [bramki.md](bramki.md) |
| `rulesets` | obiekt `phpmd`, `phpstan`, `phpcs` → ścieżka | Zestawy reguł, np. z `vendor/citycouriers/pc/rulesets/` |
| `modules` | obiekt `laravel`, `wordpress` → `true`/`false` | Moduły opcjonalne |

Ścieżki muszą być względne i bez `..`, a katalogi domowe i dyski są odrzucane. Konfiguracja trafia do
publicznych repo i nie może nieść ścieżek z maszyny autora.

## Moduły

Rdzeń `pc` nie zależy od Laravela ani od WordPressa. Reguły projektowe są dodatkami włączanymi w repo konsumenta:

- `laravel`: bramki Blade, testy przeglądarkowe, Larastan, testy architektury, polecenie `artisan`;
- `wordpress`: PHPCS z WPCS i PHPCompatibility.

Kod modułów dochodzi z przenoszeniem bramek ([plan](plan-przeniesienia.md)). Wartości w konfiguracji są już
częścią kontraktu.

## Instalacja w projekcie z niższym PHP

Paczka wymaga PHP `^8.4`. Projekt, który deklaruje niższe `require.php` (np. wtyczka z `>=7.4`), nie może mieć
`citycouriers/pc` w zwykłym `require-dev`, bo Composer rozwiązywałby zależności pod PHP 8.4 i wymusił podniesienie
wymagań paczki. Zalecenie:

1. Zainstaluj `pc` w osobnym katalogu narzędzi, np. `tools/pc/composer.json` z `require-dev` na `citycouriers/pc`,
   i uruchamiaj `tools/pc/vendor/bin/pc`.
2. W CI uruchamiaj bramki w jobie z PHP 8.4. Zgodność kodu projektu z niższymi wersjami PHP sprawdza
   PHPCompatibility (bramka `phpcompat`), a nie wersja PHP, na której działa `pc`.
