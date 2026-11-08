# Dokumentacja citycouriers/pc

Dokumentacja `pc` żyje w tym repo i tu jest dalej uzupełniana. Opisy bramek przenoszonych z repo, w którym
powstały, trafiają tu razem z kodem (zob. [plan przeniesienia](plan-przeniesienia.md)).

| Dokument | Zawartość |
| --- | --- |
| [bramki.md](bramki.md) | Kontrakt bramek: lista, tryby, kontrola ujemna |
| [konfiguracja.md](konfiguracja.md) | Plik `pc.json`, moduły, instalacja w projekcie z niższym PHP |
| [wydania.md](wydania.md) | SemVer dla bramek, proces wydania, tag próbny |
| [plan-przeniesienia.md](plan-przeniesienia.md) | Fazy przenoszenia bramek do paczki |

Zasada jednej bazy dokumentacji: opis mechanizmu istnieje w jednym miejscu. Repo-konsument opisuje
tylko swoją integrację (wartości w `pc.json`, wyjątki, własne dodatki) i odsyła tutaj.
