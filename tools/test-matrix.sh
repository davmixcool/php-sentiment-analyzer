#!/usr/bin/env bash
#
# Runs the characterization suite and the baseline-reproducibility check across
# every PHP version in the CI matrix, locally, via Docker.
#
# Mirrors .github/workflows/ci.yml so cross-version drift is caught before a
# push rather than after. v2.0 guarantees byte-identical scores with v1, and
# that guarantee is only meaningful if it holds on every supported runtime.
#
# MODES
#   (default)  Reuse the host's vendor/. PHPUnit is pure PHP and Composer's
#              platform_check.php only requires 8.1, so one host install runs
#              correctly on all four versions. Fast — a few seconds per version.
#
#   --fresh    Run `composer install` inside each container, exactly as CI does.
#              Also verifies dependency RESOLUTION per platform, which the
#              default mode cannot: a dev dependency that quietly requires 8.2
#              would resolve fine on the host and fail only on 8.1. Slower
#              (installs unzip and downloads packages per version) and needs
#              network access.
#
# The project is copied to a staging directory before mounting, for two reasons:
#   1. Docker Desktop on macOS only shares a fixed set of host paths, and this
#      repo may live outside them (/opt/workspace is not shared by default).
#   2. Containers cannot then mutate the real working tree.
#
# Usage: ./tools/test-matrix.sh [--fresh]   (or: composer matrix / composer matrix:fresh)

set -euo pipefail

VERSIONS=(8.1 8.2 8.3 8.4)
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURE_REL="tests/fixtures/baseline.json"
FIXTURE="$PROJECT_DIR/$FIXTURE_REL"

# Composer cache shared across containers AND across runs. Without it each run
# issues ~26 package downloads per PHP version straight at api.github.com, which
# rate-limits and returns 504s; Composer then falls back to a source download.
# Persisting the cache takes the steady state to zero network requests.
CACHE_DIR="${XDG_CACHE_HOME:-$HOME/.cache}/openlexicon-matrix-composer"

FRESH=0
for arg in "$@"; do
    case "$arg" in
        --fresh) FRESH=1 ;;
        -h|--help)
            sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "ERROR: unknown argument '$arg' (expected --fresh)"
            exit 2
            ;;
    esac
done

cd "$PROJECT_DIR"

if ! docker info >/dev/null 2>&1; then
    echo "ERROR: the Docker daemon is not reachable."
    echo "       Start Docker Desktop (open -a Docker), wait for it to report"
    echo "       running, then re-run this script."
    exit 1
fi

if [ ! -f "$FIXTURE" ]; then
    echo "ERROR: $FIXTURE_REL is missing. Run 'composer baseline' first."
    exit 1
fi

if [ "$FRESH" -eq 0 ] && [ ! -f vendor/bin/phpunit ]; then
    echo "ERROR: vendor/ is missing. Run 'composer install' first, or use --fresh."
    exit 1
fi

COMPOSER_BIN=""
if [ "$FRESH" -eq 1 ]; then
    COMPOSER_BIN="$(command -v composer || true)"
    if [ -z "$COMPOSER_BIN" ]; then
        echo "ERROR: --fresh needs the composer binary on PATH to copy into containers."
        exit 1
    fi
fi

WORK="$(mktemp -d)"
trap 'chmod -R u+w "$WORK" 2>/dev/null || true; rm -rf "$WORK"' EXIT

SCRATCH="$WORK/scratch"
mkdir -p "$SCRATCH" "$CACHE_DIR"

# Stage a clean copy of the project. In --fresh mode vendor/ and composer.lock
# are deliberately omitted: CI checks out a tree without them (composer.lock is
# gitignored), so including them here would test something CI never does.
stage_project() {
    local dest="$1"
    mkdir -p "$dest"

    local paths=(src tests tools composer.json phpunit.xml)
    if [ "$FRESH" -eq 0 ]; then
        paths+=(vendor)
    fi

    tar -cf - --exclude='.git' --exclude='.phpunit.cache' "${paths[@]}" \
        | tar -xf - -C "$dest"

    if [ "$FRESH" -eq 1 ]; then
        cp "$COMPOSER_BIN" "$dest/composer.phar"
    fi
}

SHARED_STAGE=""
if [ "$FRESH" -eq 0 ]; then
    SHARED_STAGE="$WORK/app"
    stage_project "$SHARED_STAGE"
fi

docker_run() {
    local image="$1" stage="$2"; shift 2
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        -e COMPOSER_HOME=/composer \
        -v "$CACHE_DIR":/composer \
        -v "$stage":/app \
        -v "$SCRATCH":/scratch \
        -w /app \
        "$image" "$@"
}

# Fresh mode runs everything inside the container against a container-LOCAL copy
# of the project. The source mount is read-only and only /scratch is written.
#
# This matters: installing directly into the macOS bind mount makes Composer's
# parallel extraction fail intermittently ("Install of phpunit/php-code-coverage
# failed ... Failed to open directory"). A container-local filesystem avoids the
# race entirely, needs no chown of the mount, and is markedly faster.
#
# Official php:*-cli images ship neither unzip nor git; both are installed —
# unzip for --prefer-dist, git so a failed dist download can fall back to source
# instead of aborting the run.
docker_fresh() {
    local image="$1" stage="$2" version="$3"
    docker run --rm \
        -e COMPOSER_HOME=/composer \
        -v "$CACHE_DIR":/composer \
        -v "$stage":/src:ro \
        -v "$SCRATCH":/scratch \
        -w / \
        "$image" \
        sh -c "set -e
            apt-get update -qq >/dev/null 2>&1
            # git is the fallback Composer uses when a dist download fails;
            # without it a single GitHub 504 aborts the whole install.
            apt-get install -y -qq --no-install-recommends unzip git >/dev/null 2>&1
            cp -a /src /build
            cd /build
            php composer.phar install --prefer-dist --no-interaction --no-progress
            echo '###PHPUNIT###'
            php vendor/bin/phpunit
            echo '###BASELINE###'
            php tools/generate-baseline.php /scratch/baseline-${version}.json
            chown $(id -u):$(id -g) /scratch/baseline-${version}.json 2>/dev/null || true"
}

failed=0

printf '\n%s\n' "Matrix against $FIXTURE_REL$([ "$FRESH" -eq 1 ] && echo '  [--fresh: composer install per container]')"
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

    if [ "$FRESH" -eq 1 ]; then
        stage="$WORK/app-$version"
        stage_project "$stage"
    else
        stage="$SHARED_STAGE"
    fi

    # Analyzer splits UTF-8 with mbstring; fail loudly if the image lacks it.
    # NB: capture first, then match with a here-string. Piping straight into
    # `grep -q` races under `set -o pipefail` — grep exits on first match,
    # php -m takes SIGPIPE, and the pipeline reports failure intermittently.
    modules="$(docker_run "$image" "$stage" php -m 2>/dev/null || true)"
    if ! grep -qi '^mbstring$' <<<"$modules"; then
        printf 'FAILED — mbstring missing from %s\n' "$image"
        failed=1
        continue
    fi

    if [ "$FRESH" -eq 1 ]; then
        # Install + test + generate in one container (see docker_fresh).
        if out="$(docker_fresh "$image" "$stage" "$version" 2>&1)"; then
            printf 'install: ok  '
            summary="$(grep -E '^Tests:' <<<"$out" | tail -1 || true)"
            printf 'tests: %-46s ' "${summary:-OK}"
        else
            if grep -q '###PHPUNIT###' <<<"$out"; then
                printf 'install: ok  tests: FAILED\n'
                grep -E '^[0-9]+\) |Failed asserting|^Tests:' <<<"$out" \
                    | head -8 | sed 's/^/           /' || true
            else
                printf 'install: FAILED\n'
                tail -12 <<<"$out" | sed 's/^/           /'
            fi
            failed=1
            continue
        fi
    else
        # 1. Test suite
        if test_output="$(docker_run "$image" "$stage" php vendor/bin/phpunit 2>&1)"; then
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
        if ! docker_run "$image" "$stage" php tools/generate-baseline.php \
                "/scratch/baseline-${version}.json" >/dev/null 2>&1; then
            printf 'baseline: GENERATION FAILED\n'
            failed=1
            continue
        fi
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
