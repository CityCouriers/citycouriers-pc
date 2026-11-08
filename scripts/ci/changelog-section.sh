#!/usr/bin/env bash
# Wypisuje sekcję `## [WERSJA]` z CHANGELOG.md (do notatek wydania). Kod 1, gdy sekcji nie ma.
set -euo pipefail

version="${1:?użycie: changelog-section.sh WERSJA (bez prefiksu v)}"

cd "${PC_REPO_ROOT:-$(dirname "$0")/../..}"

section="$(awk -v header="## [${version}]" '
    index($0, header) == 1 { found = 1; next }
    found && /^## \[/ { exit }
    found { print }
' CHANGELOG.md)"

if [ -z "$(printf '%s' "$section" | tr -d '[:space:]')" ]; then
    echo "Brak niepustej sekcji [${version}] w CHANGELOG.md." >&2
    exit 1
fi

printf '%s\n' "$section"
