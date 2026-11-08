# Plan przeniesienia bramek do paczki

`pc` powstał jako zestaw lokalnych bramek w pierwszej aplikacji CityCouriers (Laravel, PHP 8.4). To repo jest
jego docelowym domem. Kod bramek jest przenoszony etapami, po zatwierdzeniu planu przez właścicieli. Do tego czasu
paczka zawiera szkielet, kontrakt konfiguracji, CI i zestaw reguł PHPMD.

## Fazy

| Faza | Zawartość | Stan |
| --- | --- | --- |
| 0 | Szkielet: `bin/pc`, kontrakt `pc.json`, CI, surowy zestaw PHPMD, schemat wydań | gotowe (0.1.0) |
| 1 | Rdzeń: wykonywanie poleceń, izolacja zmian (stash), Pint/PHPCS, PHPStan, PHPMD, PHPCPD, dobór i bieg testów (Pest), pokrycie zmienionych linii, Strażnik Czasu | po zatwierdzeniu planu |
| 1b | Mutacje: kontrola zerowa, pieczęć, katalog biegu | po fazie 1 |
| 2 | Moduł procesu (podpis recenzji, dziennik dowodów): opcjonalny, ścieżki z konfiguracji | osobna decyzja |

## Zasady przenoszenia

- **Jedna żywa kopia.** Od dnia przeniesienia kod w repo źródłowym jest zamrożony, a repo źródłowe pobiera
  paczkę przez Composera. Zmiany idą do tego repo.
- **Zależności frameworka nie wchodzą do rdzenia.** Ścieżki i nazwy projektu wędrują do `pc.json`, a bramki
  Laravela i WordPressa do modułów opcjonalnych.
- **Przegląd pochodzenia i licencji** każdego przenoszonego modułu i testu przed pierwszym publicznym
  commitem z jego kodem. Skan sekretów i ścieżek tego nie zastępuje.
- **Bez historii prywatnego repo.** Kod trafia jako nowe commity z tabelą pochodzenia w `CHANGELOG.md`, chyba że
  właściciele zdecydują inaczej po audycie historii.
- **Mutacje w CI** jako macierz kawałków uruchamianych szeregowo, z kontrolą zerową w każdym kawałku.
  Obowiązują limity Actions z dokumentacji GitHuba: 256 jobów w macierzy, 6 godzin na job.
- **Kopie kodu osób trzecich** (np. poprawione klasy wtyczki Pest) dostają notę licencyjną w nagłówku.
