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

## 0. Conformance with reference VADER — RESOLVED in 3.0.0

**The package now matches reference Python `vaderSentiment` 3.3.2 exactly**, on
all 350 comparable corpus cases. Verified by `composer conformance`, which fails
the build on any divergence and runs in CI.

Before 3.0.0 the port diverged on **158 of 336 cases (47%)**. The causes, all
fixed:

| Cause | Effect before 3.0.0 |
|---|---|
| Negation used `B_DECR (-0.293)` instead of `N_SCALAR (-0.74)` | `"aint good"` scored -0.1423, not -0.3412 — negation ~2.5x too weak |
| Booster damping applied in reverse distance order | `"very good"` scored 0.4877, not 0.4927 |
| `never so`/`never this` negated instead of intensifying | `"I have never been so happy"` scored **-0.2699**, not **+0.6948** |
| Tokenizer dropped single-character tokens and only stripped punctuation runs listed in `PUNC_LIST` | `"good!!!!"` scored **0.0000**; token indices were shifted, breaking every positional rule |
| Any token equal to `"kind"` or `"of"` was skipped | `"he is a kind person"` scored **0.0000**, not 0.5267 |
| Idiom checks ran unconditionally, and the booster n-gram was applied up to five times | `"kind of good"` scored 0.1116, not 0.4404 |
| `BOOSTER_DICT` was missing 15 entries and carried a non-upstream one (`seemingly`); `SPECIAL_CASE_IDIOMS` diverged from 3.3.2 | assorted |

### Why the reference version is pinned

`composer conformance` pins `vaderSentiment==3.3.2` deliberately. The tables
differ between releases — 3.3.2 has `"beating heart" => 3.5` and no
`"broken heart"`, while the GitHub master source has `3.1` and `-2.9`. Parity is
only meaningful against a fixed target. Override with `VADER_VERSION=` if you
need to compare against another release.

### Deliberate quirks

Being a faithful port means reproducing reference behaviour that looks wrong:

- **`SENTIMENT_LADEN_IDIOMS` is unimplemented.** Upstream marks it *"future work,
  not yet implemented"* and never calls it, so `"under the weather"` and
  `"break a leg"` score 0.0000 in both implementations.
- **Idioms only apply at token index >= 3.** Reference gates the idiom check on
  `start_i == 2`, so `"bad ass"` alone scores -0.7906 while
  `"it was a bad ass"` scores +0.6124 — in reference too.
- **An operator-precedence quirk** in `_negation_check` at `start_i == 2` makes
  the `so`/`this` intensifier fire whether or not `never` is present. Reproduced.

These are noted so they are not "fixed" into a divergence.

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

## 2. Idioms rarely fire — matching reference

Idioms seldom affect a score, and that is correct. Reference VADER gates
`_special_idioms_check()` on `start_i == 2`, so an idiom only applies when the
sentiment word sits at index >= 3 with a non-lexicon word three positions back:

```
"bad ass"              -0.7906   (idiom value +1.5 not applied)
"the shit"             -0.5574   (idiom value +3 not applied)
"it was a bad ass"     +0.6124   (idiom applied)
```

Both implementations agree on all of these as of 3.0.0.

An earlier version of this file claimed "15 of 21 idioms do not fire" and
measured each against its value in `Config.php`. That framing was wrong: the
tables were never the contract, and most of those cases were parity with
reference rather than defects.

**`SENTIMENT_LADEN_IDIOMS` remains unimplemented**, matching upstream, which
marks it *"future work, not yet implemented"* and never calls it. The constant is
retained so the data is not lost, but nothing reads it.

### A piecemeal fix was attempted and rejected

Before 3.0.0, a targeted change restoring only the forward-looking idiom checks
was implemented and **abandoned**: without the `start_i == 2` gate it made idioms
fire far more often than reference, taking overall divergence from 158 to 166
cases. That is why 3.0.0 rewrote the scoring loop as a whole rather than patching
rules individually — they interact.

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
