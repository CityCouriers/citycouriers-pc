# Taksonomia etykiet GitHub

`labels.json` to deklaratywne źródło prawdy o etykietach zarządzanych przez to repozytorium. Model pochodzi z `CityCouriers/wordpress-automation` (przez `CityCouriers/cc-google-rating-badge`) i zachowuje rodziny, które są tu przydatne.

Action **Sync GitHub labels** uzgadnia nazwy, kolory i opisy po wejściu manifestu do `main`; można go też uruchomić ręcznie. Celowo nie jest destrukcyjny: etykiety spoza manifestu zostają w spokoju. Jawne jednorazowe usunięcia żyją w `migrations.delete`.

## Zasady klasyfikacji

| Rodzina | Zasada | Znaczenie |
| --- | --- | --- |
| Priorytet `P0`-`P4` | dokładnie 1 na otwarte zgłoszenie | Kolejność pracy. |
| Ryzyko `T1`-`T3` | dokładnie 1, gdy praca może zmienić wynik bramki u konsumentów | Koszt pomyłki w zmianie, nie nakład implementacji. |
| `area:*` | 1 lub więcej | Gdzie praca należy. |
| Stan | 0 lub więcej | `blocked`, `needs-triage`, `monitoring`. |
| Typ zmiany | 0 lub więcej | Zgodny z Conventional Commits. |
| `policy:*` | 0 lub więcej | Przekrojowa polityka, której praca dotyczy. |
| Zakres pracy | 0 lub 1 | `bramka-pc`, `bramka-repo`, `silnik`, `epik`. |
| Pochodzenie kodu | 0 lub 1 | `kod-z-nexus` (przegląd pochodzenia i licencji przed importem) albo `nowy-kod`. |
| `audit:*` | 0 lub 1 | Stan audytu specyfikacji; ustawia go workflow `issue-audit`, nie ręka. |
| `by:agent` | 0 lub 1 | Specyfikację napisał agent lokalny; brak etykiety znaczy „autorem jest recenzent zewnętrzny”. |

Priorytet i ryzyko są niezależne. Priorytet żyje w etykiecie, nie w tytule zgłoszenia.

## Obszary

Zamknięty zbiór: `area:core`, `area:gates`, `area:ci`, `area:tests`, `area:docs`, `area:release`, `area:github`, `area:security`, `area:wordpress`, `area:laravel`. Nowy obszar dodawaj tylko wtedy, gdy istniejący uczyniłby filtrowanie mylącym. Nie twórz etykiet ad hoc w interfejsie GitHuba; zmień `labels.json` w zrecenzowanym PR.

## Zmiana taksonomii

1. Edytuj `.github/labels.json` na gałęzi.
2. Uruchom `npm test`; `test/labels.test.js` sprawdza kontrakt manifestu.
3. Otwórz PR i przejdź normalną bramkę.
4. Po merge'u **Sync GitHub labels** nakłada zrecenzowany stan na repozytorium.

Nie włączaj niejawnego usuwania. Usunięcie etykiety to migracja i musi być nazwane jawnie.
