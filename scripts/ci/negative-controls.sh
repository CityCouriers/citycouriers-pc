#!/usr/bin/env bash
# Kontrola ujemna bramek: każda bramka musi zatrzymać znany błąd. Zielone CI samo w sobie nie dowodzi,
# że bramka cokolwiek wykrywa (zob. docs/bramki.md). Dla każdej bramki sprawdzamy dwa warunki:
#   1. kod wyjścia jest niezerowy,
#   2. w wyjściu jest nazwa pliku testowego, czyli bramka faktycznie go przeanalizowała
#      (a nie padła z innego powodu albo nie objęła pliku).
set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

status=0

expect_fail() {
    local name="$1" needle="$2"
    shift 2
    local output rc
    output="$("$@" 2>&1)"
    rc=$?

    if [ "$rc" -eq 0 ]; then
        echo "BŁĄD [$name]: bramka przepuściła znany błąd (kod 0)."
        status=1
        return
    fi

    if ! printf '%s' "$output" | grep -q -- "$needle"; then
        echo "BŁĄD [$name]: kod $rc, ale w wyjściu brak '$needle'. Bramka padła z innego powodu albo nie objęła pliku."
        status=1
        return
    fi

    echo "OK [$name]: bramka zatrzymała błąd (kod $rc)."
}

# Pint: kopia pliku poza repo, żeby `notPath` z pint.json nie wykluczył pliku testowego.
style_dir="$(mktemp -d)"
trap 'rm -rf "$style_dir"' EXIT
cp tests/Fixtures/negative/bad-style.php "$style_dir/bad-style.php"

expect_fail "pint" "bad-style.php" vendor/bin/pint --test --preset per "$style_dir"
expect_fail "phpstan" "bad-type.php" vendor/bin/phpstan analyse -c scripts/ci/phpstan-negative.neon --no-progress tests/Fixtures/negative/bad-type.php
expect_fail "phpmd" "bad-smell.php" vendor/bin/phpmd analyze tests/Fixtures/negative/bad-smell.php --format text --ruleset rulesets/phpmd.xml
expect_fail "config:validate" "Nieznana bramka" php bin/pc config:validate tests/Fixtures/negative/invalid-pc.json

exit "$status"
