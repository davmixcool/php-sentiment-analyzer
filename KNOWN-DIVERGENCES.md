# Known Divergences

Behaviour that is **pinned in `tests/fixtures/baseline.json` because it is what
the code currently does — not because it is correct.**

v2.0 guarantees byte-identical scores with v1. Everything still listed as
outstanding below is therefore reproduced exactly in v2.0 and fixed in a later
release, each with a `CHANGELOG.md` entry.

Items marked FIXED were corrected deliberately, with their pinned cases re-based
in the same commit and the movement documented here.

**Do not "fix" any of these while making the characterization suite pass.** A
failing case here means a score moved, which is a regression during v2.0 — even
if the new number looks more correct.

This file is the worklist for the post-2.0 scoring-fix release.

---

## 1. `_never_check` zeroed sentiment after "so" or "this" — FIXED in 1.3.0

`Analyzer::_never_check()` initialised `$neverModifier` to `0` and then applied
it whenever `"so"` or `"this"` appeared within two tokens of a sentiment word,
**regardless of whether `"never"` was present** — multiplying the valence by
zero and silently destroying it.

Fixed by guarding the branch on `$neverModifier != 0`. Reference VADER applies
the 1.25/1.5 boost only when `"never"` is present, which is now the behaviour.

Eight pinned cases moved, every one from `0.0000` to a sensible value:

| Case | Before | After |
|---|---|---|
| `never_check/this_is_good` (`this is good`) | 0.0000 | +0.4404 |
| `never_check/this_good` (`this good`) | 0.0000 | +0.4404 |
| `never_check/this_is_bad` (`this is bad`) | 0.0000 | -0.5423 |
| `never_check/so_good` (`so good`) | 0.0000 | +0.4877 |
| `booster/so` (`so good`) | 0.0000 | +0.4877 |
| `caps/mixed_sentence` (`this is GOOD`) | 0.0000 | +0.5622 |
| `emoji_sentence/😀` (`this is 😀 really`) | 0.0000 | +0.3612 |
| `emoji_sentence/🖐️` (`this is 🖐️ really`) | 0.0000 | +0.4939 |

The genuine "never" paths were unaffected: `never so good` and `never this good`
still score -0.2385 and -0.2108. No other pinned case moved.

**This is the one deliberate scoring change in the 1.x line.** Everything below
remains pinned as-is.

## 2. 15 of 21 idioms do not fire

`_idioms_check()` scores the constituent words instead of the idiom. Nine never
match at all; six resolve with the **opposite sign** to their own table value in
`src/Config/Config.php:56` and `:63`.

| Idiom | Table | Pinned | Failure |
|---|---|---|---|
| `bad ass` | +1.5 | -0.2500 | sign inverted |
| `yeah right` | -2.0 | 0.2960 | sign inverted |
| `cut the mustard` | +2.0 | -0.2732 | sign inverted |
| `kiss of death` | -1.5 | 0.0772 | sign inverted |
| `hand to mouth` | -2.0 | 0.4939 | sign inverted |
| `to die for` | +3.0 | -0.5994 | sign inverted |
| `back handed` | -2.0 | 0.0000 | never matches |
| `blow smoke` | -2.0 | 0.0000 | never matches |
| `blowing smoke` | -2.0 | 0.0000 | never matches |
| `break a leg` | +2.0 | 0.0000 | never matches |
| `cooking with gas` | +2.0 | 0.0000 | never matches |
| `in the black` | +2.0 | 0.0000 | never matches |
| `in the red` | -2.0 | 0.0000 | never matches |
| `on the ball` | +2.0 | 0.0000 | never matches |
| `under the weather` | -2.0 | 0.0000 | never matches |

The six that resolve with the right sign: `the shit` (+3.0 → 0.6124),
`the bomb` (+3.0 → 0.6124), `beating heart` (+3.1 → 0.2732), `broken heart`
(-2.9 → -0.7906), `upper hand` (+1.0 → 0.4939), and `bus stop` (0.0 → 0.0000,
which only agrees trivially because its table value is zero).

Corpus sections: `idiom/*`, `idiom_sentence/*`.

**Note for v2:** this constrains custom lexicons. Multi-word keys passed to
`withLexicon()` would land in the term lexicon, not the idiom table, so they
cannot work until the idiom matcher does — which is why `withLexicon()` rejects
them outright rather than accepting them and doing nothing.

---

## 3. Dynamic property deprecations (PHP 8.2+) — FIXED in 2.0.0

`Analyzer::__construct()` assigned `$this->emoji_lexicon` and `$this->emojis`
without declaring them (`src/Analyzer.php`). Deprecated since PHP 8.2; two
notices fired on every instantiation. PHP 8.1 emitted nothing, as dynamic
properties were not deprecated there.

Both are now declared and typed. `phpunit.xml` sets `failOnDeprecation="true"`,
so any new deprecation fails the build, and the
`testKnownDynamicPropertyDeprecationsStillPresent()` tripwire has been removed —
it had done its job.

Fixed on the `2.x` line only. The `1.x` maintenance line still emits these
notices on PHP 8.2+, which is expected: it exists to support PHP < 8.1.

## 4. VADER lexicon file carries a UTF-8 BOM

`src/Lexicons/vader_sentiment_lexicon.txt` begins with `EF BB BF`, so the first
term is read as `\xEF\xBB\xBF$:` rather than `$:`. One lexicon entry is
effectively unreachable. Harmless in practice; noted for completeness.

---

## 5. README first example was wrong (fixed)

The README documented `neu 0.337 / pos 0.663 / compound 0.7096` for
*"David is smart, handsome, and funny."*. Actual output is
`neu 0.254 / pos 0.746 / compound 0.8316`, which matches reference Python VADER.

Corrected in this milestone — the code was right and the documentation was
stale. The other eight README examples were verified correct and are pinned as
`readme/*` and `readme_advanced/*`.
