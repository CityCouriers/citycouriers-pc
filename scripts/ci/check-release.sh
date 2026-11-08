#!/usr/bin/env bash
# Sprawdza, czy tag wydania jest spójny: format SemVer, zgodność z Version::CURRENT i wpis w CHANGELOG.md.
# Użycie: check-release.sh vX.Y.Z[-pre]. Zmienna PC_REPO_ROOT wskazuje inny korzeń repo (testy).
set -euo pipefail

tag="${1:?użycie: check-release.sh vX.Y.Z}"

script_dir="$(cd "$(dirname "$0")" && pwd)"
cd "${PC_REPO_ROOT:-$script_dir/../..}"

if ! printf '%s' "$tag" | grep -q -E '^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?$'; then
    echo "BŁĄD: tag '$tag' nie jest wersją SemVer w postaci vX.Y.Z." >&2
    exit 1
fi

version="${tag#v}"
declared="$(sed -n -E "s/.*CURRENT = '([^']+)'.*/\1/p" src/Version.php)"

if [ "$declared" != "$version" ]; then
    echo "BŁĄD: tag $tag, a Version::CURRENT to '$declared'. Zmień wersję w commicie wydania." >&2
    exit 1
fi

bash "$script_dir/changelog-section.sh" "$version" >/dev/null

echo "OK: tag $tag zgodny z Version::CURRENT i CHANGELOG.md."
