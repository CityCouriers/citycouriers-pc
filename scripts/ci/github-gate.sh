#!/usr/bin/env bash
# Bramki plików GitHub: jedna bramka, jedno narzędzie. Użycie:
#   github-gate.sh <actionlint|zizmor|yamllint|schema|pinact|shellcheck>
#
# To jedyne źródło komend tych bramek: wołają je hook pre-commit i joby CI (ten sam zestaw lokalnie i w CI).
# Każde wywołanie robi dwie rzeczy:
#   1. sprawdza narzędziem prawdziwe pliki repo (kontrola zerowa: lista plików nie może być pusta, bo „nic nie
#      przeskanowano” nie jest zielonym wynikiem),
#   2. uruchamia to samo narzędzie na pliku z celowym błędem (tests/Fixtures/negative-github/) i wymaga, by bramka
#      go zatrzymała: kod niezerowy ORAZ nazwa pliku w wyjściu (inaczej mogła paść z innego powodu).
# Narzędzia: z PATH. Brak narzędzia to głośny błąd (kod 3) z instrukcją instalacji, nigdy cichy pass.
set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

gate="${1:-}"
fixtures="tests/Fixtures/negative-github"

install_hint() {
    case "$1" in
        actionlint) echo "winget install --id rhysd.actionlint --exact" ;;
        zizmor) echo "winget install --id zizmor.zizmor --exact" ;;
        pinact) echo "winget install --id suzuki-shunsuke.pinact --exact" ;;
        shellcheck) echo "winget install --id koalaman.shellcheck --exact" ;;
        yamllint) echo "uv tool install yamllint==1.38.0" ;;
        check-jsonschema) echo "uv tool install check-jsonschema==0.39.0" ;;
        *) echo "zob. docs/bramki.md" ;;
    esac
}

require() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "BŁĄD [$gate]: brak narzędzia '$1' w PATH. Instalacja: $(install_hint "$1")" >&2
        exit 3
    fi
}

# Kontrola zerowa: bramka musi mieć co sprawdzać.
require_files() {
    if [ "$#" -eq 0 ]; then
        echo "BŁĄD [$gate]: brak plików do sprawdzenia (nie przeskanowano), to nie jest wynik zielony." >&2
        exit 1
    fi
}

status=0

# Kontrola ujemna: komenda MUSI zawieść i wymienić plik testowy w wyjściu.
expect_fail() {
    local needle="$1" output rc
    shift
    output="$("$@" 2>&1)"
    rc=$?
    if [ "$rc" -eq 0 ]; then
        echo "BŁĄD [$gate]: kontrola ujemna przepuściła znany błąd (kod 0). Bramka nic nie wykrywa." >&2
        status=1
        return
    fi
    if ! printf '%s' "$output" | grep -q -- "$needle"; then
        echo "BŁĄD [$gate]: kontrola ujemna padła (kod $rc), ale w wyjściu brak '$needle'. Inna przyczyna albo plik nieobjęty." >&2
        status=1
        return
    fi
    echo "OK [$gate]: kontrola ujemna zatrzymała znany błąd (kod $rc)."
}

# Token do audytów online (zizmor) i API (pinact): z środowiska, a lokalnie z `gh`, jeśli jest.
use_token() {
    if [ -z "${GH_TOKEN:-}" ] && [ -z "${GITHUB_TOKEN:-}" ] && command -v gh >/dev/null 2>&1; then
        GH_TOKEN="$(gh auth token 2>/dev/null || true)"
        export GH_TOKEN
    fi
    if [ -z "${GITHUB_TOKEN:-}" ] && [ -n "${GH_TOKEN:-}" ]; then
        export GITHUB_TOKEN="$GH_TOKEN"
    fi
}

shopt -s nullglob
workflows=(.github/workflows/*.yml .github/workflows/*.yaml)

case "$gate" in
    actionlint)
        require actionlint
        require_files "${workflows[@]}"
        actionlint "${workflows[@]}" || status=1
        expect_fail "actionlint-bad.yml" actionlint "$fixtures/actionlint-bad.yml"
        ;;
    zizmor)
        require zizmor
        require_files "${workflows[@]}"
        use_token
        zizmor --no-progress --persona pedantic . || status=1
        # zizmor czyta workflow tylko spod .github/workflows, więc plik testowy kopiujemy do katalogu tymczasowego.
        scratch="$(mktemp -d)"
        trap 'rm -rf "$scratch"' EXIT
        mkdir -p "$scratch/.github/workflows"
        cp "$fixtures/zizmor-bad.yml" "$scratch/.github/workflows/zizmor-bad.yml"
        expect_fail "zizmor-bad.yml" zizmor --no-progress --persona pedantic --offline "$scratch"
        ;;
    yamllint)
        require yamllint
        yamllint --strict .github .yamllint.yaml || status=1
        expect_fail "yamllint-bad.yml" yamllint --strict "$fixtures/yamllint-bad.yml"
        ;;
    schema)
        require check-jsonschema
        require_files "${workflows[@]}"
        check-jsonschema --builtin-schema vendor.github-workflows "${workflows[@]}" || status=1
        check-jsonschema --builtin-schema vendor.dependabot .github/dependabot.yml || status=1
        check-jsonschema --builtin-schema vendor.github-issue-config .github/ISSUE_TEMPLATE/config.yml || status=1
        expect_fail "dependabot-bad.yml" check-jsonschema --builtin-schema vendor.dependabot "$fixtures/dependabot-bad.yml"
        ;;
    pinact)
        require pinact
        require_files "${workflows[@]}"
        use_token
        if [ -n "${GITHUB_TOKEN:-}" ]; then
            pinact run --check --verify-comment || status=1
        else
            echo "[$gate] brak tokenu: tylko kontrola składniowa przypięcia (--no-api), bez weryfikacji komentarzy wersji." >&2
            pinact run --fix=false --no-api || status=1
        fi
        expect_fail "pinact-bad.yml" pinact run --fix=false --no-api "$fixtures/pinact-bad.yml"
        ;;
    shellcheck)
        require shellcheck
        scripts=(scripts/ci/*.sh .githooks/pre-commit)
        require_files "${scripts[@]}"
        shellcheck "${scripts[@]}" || status=1
        expect_fail "shellcheck-bad.sh" shellcheck "$fixtures/shellcheck-bad.sh"
        ;;
    *)
        echo "Użycie: github-gate.sh <actionlint|zizmor|yamllint|schema|pinact|shellcheck>" >&2
        exit 2
        ;;
esac

exit "$status"
