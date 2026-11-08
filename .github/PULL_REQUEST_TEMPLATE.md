<!--
Tytuł: Conventional Commits (feat: / fix: / docs: / chore: / ci: / test: / refactor:), temat małą literą.
Pola, które nie dotyczą zmiany: „N/A (powód)”. Nie zostawiaj placeholderów.
Check `pr-title` pilnuje tytułu; `quality-gate` zbiera wyniki Pint, PHPStan, PHPMD, testów, kontroli ujemnych,
skanu prywatności, gitleaks i testów skryptów procesu.
-->

## Kontekst

- **Dlaczego:** jedno zdanie o wartości tej zmiany.
- **Problem:** co dziś jest źle albo czego brakuje.
- **Rozwiązanie:** co się zmieniło i jak to rozwiązuje problem.
- **Szczegóły (opcjonalnie):** ryzyka, przypadki brzegowe, decyzje.

## Dowody

<!-- Odhacz z krótkim potwierdzeniem albo N/A z powodem. -->

- [ ] **Testy:** `composer check` i `npm test` zielone; nowe zachowanie ma test pozytywny i negatywny
- [ ] **Kontrola ujemna:** nowa lub zmieniona bramka ma plik z błędem w `tests/Fixtures/negative/`, który ją zatrzymuje, albo N/A
- [ ] **Bezpieczeństwo i prywatność:** `composer privacy` zielony; brak kluczy, ścieżek z maszyn, adresów e-mail i danych z prywatnych repo, albo N/A
- [ ] **SemVer:** wpis w `CHANGELOG.md` z klasyfikacją wg `docs/wydania.md` (nowa bramka blokująca lub ostrzejsza reguła to major), albo N/A
- [ ] **Pochodzenie kodu:** kod przenoszony z repo źródłowego przeszedł przegląd pochodzenia i licencji (zgłoszenie z etykietą `kod-z-nexus`), albo N/A
- [ ] **Audyt:** zgłoszenie ma werdykt `audit:ok` strony przeciwnej autorowi treści (bramka `issue-audit`), albo N/A (powód)

## Linki

Fixes: #`<nr>` albo Refs: #`<nr>`

## Przed merge

- [ ] Tytuł zgodny z konwencją
- [ ] `quality-gate` i `pr-title` zielone
- [ ] Follow-upy wypisane wyżej albo `brak`
