#!/usr/bin/env bash
#
# Runs the characterization suite and the baseline-reproducibility check across
# every PHP version in the CI matrix, locally, via Docker.
#
# Mirrors .github/workflows/ci.yml so cross-version drift is caught before a
# push rather than after. v2.0 guarantees byte-identical scores with v1, and
# that guarantee is only meaningful if it holds on every supported runtime.
#
# The project is copied to a staging directory before mounting, for two reasons:
#   1. Docker Desktop on macOS only shares a fixed set of host paths, and this
#      repo may live outside them (/opt/workspace is not shared by default).
#   2. Containers then cannot mutate the real working tree.
#
# vendor/ is copied rather than reinstalled per version: PHPUnit is pure PHP and
# Composer's platform_check.php only requires 8.1, so one host install runs
# correctly on all four versions. CI still does a fresh per-version composer
# install, and that remains the authoritative check.
#
# Usage: ./tools/test-matrix.sh   (or: composer matrix)

set -euo pipefail

VERSIONS=(8.1 8.2 8.3 8.4)
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURE_REL="tests/fixtures/baseline.json"
FIXTURE="$PROJECT_DIR/$FIXTURE_REL"

cd "$PROJECT_DIR"

if ! docker info >/dev/null 2>&1; then
    echo "ERROR: the Docker daemon is not reachable."
    echo "       Start Docker Desktop (open -a Docker), wait for it to report"
    echo "       running, then re-run this script."
    exit 1
fi

if [ ! -f vendor/bin/phpunit ]; then
    echo "ERROR: vendor/ is missing. Run 'composer install' first."
    exit 1
fi

if [ ! -f "$FIXTURE" ]; then
    echo "ERROR: $FIXTURE_REL is missing. Run 'composer baseline' first."
    exit 1
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

STAGE="$WORK/app"
SCRATCH="$WORK/scratch"
mkdir -p "$STAGE" "$SCRATCH"

# Everything the suite needs; .git and caches excluded to keep the copy cheap.
tar -cf - \
    --exclude='.git' \
    --exclude='.phpunit.cache' \
    src tests tools vendor composer.json phpunit.xml \
    | tar -xf - -C "$STAGE"

docker_run() {
    local image="$1"; shift
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        -v "$STAGE":/app \
        -v "$SCRATCH":/scratch \
        -w /app \
        "$image" "$@"
}

failed=0

printf '\n%s\n' "Matrix against $FIXTURE_REL"
printf '%s\n\n' "------------------------------------------------------------"

for version in "${VERSIONS[@]}"; do
    image="php:${version}-cli"

    printf 'PHP %-5s ' "$version"

    if ! docker image inspect "$image" >/dev/null 2>&1; then
        printf 'pulling... '
        if ! docker pull --quiet "$image" >/dev/null 2>&1; then
            printf 'FAILED to pull %s\n' "$image"
            failed=1
            continue
        fi
    fi

    # Analyzer splits UTF-8 with mbstring; fail loudly if the image lacks it.
    # NB: capture first, then match with a here-string. Piping straight into
    # `grep -q` races under `set -o pipefail` — grep exits on first match,
    # php -m takes SIGPIPE, and the pipeline reports failure intermittently.
    modules="$(docker_run "$image" php -m 2>/dev/null || true)"
    if ! grep -qi '^mbstring$' <<<"$modules"; then
        printf 'FAILED — mbstring missing from %s\n' "$image"
        failed=1
        continue
    fi

    # 1. Test suite
    if test_output="$(docker_run "$image" php vendor/bin/phpunit 2>&1)"; then
        summary="$(grep -E '^Tests:' <<<"$test_output" | tail -1 || true)"
        printf 'tests: %-46s ' "${summary:-OK}"
    else
        printf 'tests: FAILED\n'
        grep -E '^[0-9]+\) |Failed asserting|^Tests:' <<<"$test_output" \
            | head -8 | sed 's/^/           /' || true
        failed=1
        continue
    fi

    # 2. Baseline reproducibility
    if ! docker_run "$image" php tools/generate-baseline.php \
            "/scratch/baseline-${version}.json" >/dev/null 2>&1; then
        printf 'baseline: GENERATION FAILED\n'
        failed=1
        continue
    fi

    if diff -q "$FIXTURE" "$SCRATCH/baseline-${version}.json" >/dev/null 2>&1; then
        printf 'baseline: identical\n'
    else
        printf 'baseline: DIFFERS\n'
        diff "$FIXTURE" "$SCRATCH/baseline-${version}.json" | head -20 | sed 's/^/           /'
        failed=1
    fi
done

printf '\n%s\n' "------------------------------------------------------------"

if [ "$failed" -eq 0 ]; then
    printf 'All versions agree — safe to push.\n\n'
else
    printf 'Matrix FAILED — do not push until resolved.\n\n'
fi

exit "$failed"
