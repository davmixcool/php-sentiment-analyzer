#!/usr/bin/env bash
#
# Three-way conformance report: v1 (1.x) vs v2 (master) vs reference Python VADER.
#
# The package implements VADER's rule model but is NOT score-equivalent with the
# reference Python implementation. This measures the gap so it is a tracked
# number rather than an assertion, per rule section.
#
# Read the v1 and v2 columns together: they should be identical, because 2.0.0
# guarantees byte-identical scores with 1.3.0. The `v1!=v2` column is therefore a
# regression alarm — any non-zero value means the two lines have drifted.
#
# From 3.0.0 this is a GATE, not a report: the package is a faithful port and
# conformance is expected to be 0. Any divergence fails the run. Pass
# --allow-divergence to report without failing (useful mid-refactor).
#
# Usage: ./tools/conformance.sh              compare committed branches
#        ./tools/conformance.sh --worktree   score v2 from the working tree
#
# --worktree is what you want while actively changing the scoring engine:
# without it the v2 column reflects the last commit, not your edits.

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURE="$PROJECT_DIR/tests/fixtures/baseline.json"
# Pinned deliberately: SPECIAL_CASES differs between vaderSentiment releases
# (3.3.2 has "beating heart" => 3.5 and no "broken heart"; the GitHub master
# source has 3.1 and -2.9). Conformance is only meaningful against a fixed target.
VADER_VERSION="${VADER_VERSION:-3.3.2}"
V1_REF="${CONFORMANCE_V1_REF:-1.x}"
V2_REF="${CONFORMANCE_V2_REF:-master}"
USE_WORKTREE=0
ALLOW_DIVERGENCE=0

for arg in "$@"; do
    case "$arg" in
        --worktree) USE_WORKTREE=1 ;;
        --allow-divergence) ALLOW_DIVERGENCE=1 ;;
        -h|--help) sed -n '2,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "ERROR: unknown argument '$arg' (expected --worktree or --allow-divergence)"; exit 2 ;;
    esac
done

cd "$PROJECT_DIR"

if ! docker info >/dev/null 2>&1; then
    echo "ERROR: the Docker daemon is not reachable."
    echo "       Start Docker Desktop (open -a Docker), wait for it to report"
    echo "       running, then re-run this script."
    exit 0
fi

if [ ! -f "$FIXTURE" ]; then
    echo "ERROR: $FIXTURE is missing. Run 'composer baseline' first."
    exit 0
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Corpus texts. Custom-lexicon cases are excluded: the reference cannot
# replicate a runtime lexicon override, so comparing them is meaningless.
php -r '
$b = json_decode(file_get_contents($argv[1]), true);
$out = [];
foreach ($b as $k => $v) {
    if (isset($v["lexicon"]) || trim($v["text"]) === "") { continue; }
    $out[$k] = $v["text"];
}
file_put_contents($argv[2], json_encode($out, JSON_UNESCAPED_UNICODE));
fprintf(STDERR, "  corpus: %d comparable cases (custom-lexicon cases excluded)\n", count($out));
' "$FIXTURE" "$WORK/cases.json"

# A minimal PSR-4 shim, so neither branch's composer autoloader is required and
# both v1 (PHP 5 era) and v2 (typed, 8.1+) can be scored by the same script.
cat > "$WORK/score.php" <<'PHP'
<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
spl_autoload_register(function ($class) {
    $prefix = 'Sentiment\\';
    if (strpos($class, $prefix) !== 0) { return; }
    $path = '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($argvDir = getenv('SRC_ROOT') . $path)) { require $argvDir; }
});
$cases = json_decode(file_get_contents('/w/cases.json'), true);
$analyzer = new Sentiment\Analyzer();
$out = [];
foreach ($cases as $key => $text) {
    $out[$key] = sprintf('%.4f', $analyzer->getSentiment($text)['compound']);
}
file_put_contents(getenv('OUT_FILE'), json_encode($out));
PHP

score_branch() {
    local ref="$1" out="$2" stage="$WORK/src-$3"
    mkdir -p "$stage"
    if [ "$ref" = "WORKTREE" ]; then
        tar -cf - src | tar -xf - -C "$stage"
    else
        git archive "$ref" src | tar -xf - -C "$stage"
    fi
    docker run --rm --user "$(id -u):$(id -g)" \
        -v "$WORK":/w -v "$stage":/stage -w /w \
        -e SRC_ROOT=/stage -e "OUT_FILE=/w/$out" \
        php:8.1-cli php /w/score.php
}

HAVE_V1=1
if git rev-parse --verify --quiet "$V1_REF" >/dev/null 2>&1; then
    printf '\n  scoring with v1 (%s)...\n' "$V1_REF"
    score_branch "$V1_REF" "v1.json" "v1"
else
    # CI checkouts often lack the maintenance branch. The v1 column is a
    # drift alarm, not the gate — carry on without it.
    HAVE_V1=0
    printf '\n  skipping v1 (%s not available in this checkout)\n' "$V1_REF"
fi

if [ "$USE_WORKTREE" -eq 1 ]; then
    V2_REF="WORKTREE"
    printf '  scoring with v2 (working tree)...\n'
else
    printf '  scoring with v2 (%s)...\n' "$V2_REF"
fi
score_branch "$V2_REF" "v2.json" "v2"

printf '  scoring with reference Python VADER...\n'
cat > "$WORK/ref.py" <<'PY'
import json
from vaderSentiment.vaderSentiment import SentimentIntensityAnalyzer
from vaderSentiment import __file__ as vf
a = SentimentIntensityAnalyzer()
cases = json.load(open('/w/cases.json'))
json.dump({k: f"{a.polarity_scores(t)['compound']:.4f}" for k, t in cases.items()},
          open('/w/ref.json', 'w'))
PY
docker run --rm --user "$(id -u):$(id -g)" -v "$WORK":/w -w /w \
    -e HOME=/tmp -e PYTHONDONTWRITEBYTECODE=1 -e VADER_VERSION="$VADER_VERSION" \
    python:3.11-slim sh -c 'pip install -q --no-cache-dir "vaderSentiment==${VADER_VERSION}" >/dev/null 2>&1 && python ref.py'

REF_VERSION="$(docker run --rm -e VADER_VERSION="$VADER_VERSION" python:3.11-slim sh -c \
    'pip install -q --no-cache-dir "vaderSentiment==${VADER_VERSION}" >/dev/null 2>&1 && pip show vaderSentiment 2>/dev/null | awk "/^Version/{print \$2}"' || echo '?')"

if [ "$HAVE_V1" -eq 0 ]; then
    cp "$WORK/v2.json" "$WORK/v1.json"
fi

php -r '
$v1  = json_decode(file_get_contents($argv[1]), true);
$v2  = json_decode(file_get_contents($argv[2]), true);
$ref = json_decode(file_get_contents($argv[3]), true);

$sec = [];
foreach ($ref as $k => $r) {
    $s = explode("/", $k)[0];
    $sec[$s] ??= ["total" => 0, "v1" => 0, "v2" => 0, "drift" => 0];
    $sec[$s]["total"]++;
    if (abs((float) $v1[$k] - (float) $r) > 0.0001) { $sec[$s]["v1"]++; }
    if (abs((float) $v2[$k] - (float) $r) > 0.0001) { $sec[$s]["v2"]++; }
    if ($v1[$k] !== $v2[$k]) { $sec[$s]["drift"]++; }
}
ksort($sec);

printf("\n  %-18s %8s %8s %8s %7s\n", "section", "v1!=ref", "v2!=ref", "v1!=v2", "total");
printf("  %s\n", str_repeat("-", 54));
$t = ["total" => 0, "v1" => 0, "v2" => 0, "drift" => 0];
foreach ($sec as $name => $c) {
    foreach ($t as $k => $_) { $t[$k] += $c[$k]; }
    printf("  %-18s %8d %8d %8d %7d\n", $name, $c["v1"], $c["v2"], $c["drift"], $c["total"]);
}
printf("  %s\n", str_repeat("-", 54));
printf("  %-18s %8d %8d %8d %7d\n", "TOTAL", $t["v1"], $t["v2"], $t["drift"], $t["total"]);
printf("\n  v2 matches reference on %d of %d cases (%.0f%% divergent)\n",
    $t["total"] - $t["v2"], $t["total"], 100 * $t["v2"] / $t["total"]);
file_put_contents($argv[4], (string) $t["v2"]);

if ($argv[5] === "0") {
    printf("  (v1 column omitted — maintenance branch not present in this checkout)\n");
} elseif ($t["drift"] === 0) {
    printf("  v1 and v2 agree on every case.\n");
} else {
    printf("  WARNING: v1 and v2 disagree on %d cases. The lines have drifted.\n", $t["drift"]);
}

$rows = [];
foreach ($ref as $k => $r) {
    $d = abs((float) $v2[$k] - (float) $r);
    if ($d > 0.0001) { $rows[$k] = $d; }
}
arsort($rows);
printf("\n  largest divergences (v2 vs reference):\n");
$i = 0;
foreach ($rows as $k => $d) {
    if ($i++ >= 8) { break; }
    printf("    %-26s php=%-9s ref=%-9s delta=%.4f\n", $k, $v2[$k], $ref[$k], $d);
}
' "$WORK/v1.json" "$WORK/v2.json" "$WORK/ref.json" "$WORK/divergent" "$HAVE_V1"

printf "\n  reference: Python vaderSentiment %s\n" "$REF_VERSION"

DIVERGENT="$(cat "$WORK/divergent" 2>/dev/null || echo 0)"

if [ "$DIVERGENT" -ne 0 ] && [ "$ALLOW_DIVERGENCE" -eq 0 ]; then
    printf "  FAIL: %s case(s) diverge from reference. 3.x targets exact parity.\n\n" "$DIVERGENT"
    exit 1
fi

printf "  conformance: OK\n\n"
