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
# INFORMATIONAL ONLY. Always exits 0, and is deliberately NOT wired into CI:
# it measures a known, accepted gap, and gating builds on a number we have
# consciously chosen not to fix yet would block every build.
#
# Usage: ./tools/conformance.sh   (or: composer conformance)

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURE="$PROJECT_DIR/tests/fixtures/baseline.json"
V1_REF="${CONFORMANCE_V1_REF:-1.x}"
V2_REF="${CONFORMANCE_V2_REF:-master}"

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
    git archive "$ref" src | tar -xf - -C "$stage"
    docker run --rm --user "$(id -u):$(id -g)" \
        -v "$WORK":/w -v "$stage":/stage -w /w \
        -e SRC_ROOT=/stage -e "OUT_FILE=/w/$out" \
        php:8.1-cli php /w/score.php
}

printf '\n  scoring with v1 (%s)...\n' "$V1_REF"
score_branch "$V1_REF" "v1.json" "v1"

printf '  scoring with v2 (%s)...\n' "$V2_REF"
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
    -e HOME=/tmp -e PYTHONDONTWRITEBYTECODE=1 \
    python:3.11-slim sh -c 'pip install -q --no-cache-dir vaderSentiment >/dev/null 2>&1 && python ref.py'

REF_VERSION="$(docker run --rm python:3.11-slim sh -c \
    'pip install -q --no-cache-dir vaderSentiment >/dev/null 2>&1 && pip show vaderSentiment 2>/dev/null | awk "/^Version/{print \$2}"' || echo '?')"

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

if ($t["drift"] === 0) {
    printf("  v1 and v2 agree on every case — the 1.3.0/2.0.0 parity guarantee holds.\n");
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
' "$WORK/v1.json" "$WORK/v2.json" "$WORK/ref.json"

printf "\n  reference: Python vaderSentiment %s\n" "$REF_VERSION"
printf "  informational only — this does not gate CI\n\n"
