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

## 0. Conformance with reference VADER — 47% of cases differ

The package implements VADER's rule model but is **not score-equivalent** with
the reference Python implementation. Measured with `composer conformance`
against Python `vaderSentiment` 3.3.2 over 336 corpus cases:

| Section | v1 differs | v2 differs | v1 vs v2 | total |
|---|---|---|---|---|
| booster | 70 | 70 | 0 | 70 |
| negation | 59 | 59 | 0 | 59 |
| idiom | 7 | 7 | 0 | 21 |
| least_never | 5 | 5 | 0 | 8 |
| never_check | 5 | 5 | 0 | 14 |
| kind_of | 4 | 4 | 0 | 4 |
| caps | 2 | 2 | 0 | 8 |
| edge | 2 | 2 | 0 | 18 |
| idiom_sentence | 2 | 2 | 0 | 21 |
| punctuation | 2 | 2 | 0 | 12 |
| but_clause | 0 | 0 | 0 | 8 |
| emoji | 0 | 0 | 0 | 25 |
| emoji_sentence | 0 | 0 | 0 | 25 |
| lexicon | 0 | 0 | 0 | 40 |
| readme | 0 | 0 | 0 | 3 |
| **TOTAL** | **158** | **158** | **0** | **336** |

Two observations before the causes.

**The dictionary half is exact.** All 40 lexicon lookups, 50 emoji cases, 8
but-clause cases and the 3 README examples match reference precisely. The
lexicon files are byte-identical to upstream.

**v1 and v2 agree on every case.** The divergence is inherited from the original
port; the 2.0.0 modernization introduced none of it. That column is also a
regression alarm — a non-zero value means the two release lines have drifted.

### Cause 1 — negation is ~2.5x too weak

`_never_check()` multiplies by `B_DECR (-0.293)` where reference uses
`N_SCALAR (-0.74)`.

```
"aint good"    this package: -0.1423     reference: -0.3412
```

Because every negated phrase is scaled by roughly a third of the intended
amount, the package **systematically under-detects negative sentiment**. This is
the single highest-impact divergence: it affects all 59 negation cases.

### Cause 2 — booster damping is inverted

`getWordInContext()` returns `[i-3, i-2, i-1, i]`, but the distance damping
(none / x0.95 / x0.9) is applied by array position. The result is that the
*nearest* modifier is damped most and the *most distant* not at all — the
opposite of the intent.

```
"very good"    this package: 0.4877      reference: 0.4927
```

### Cause 3 — "never so/this" flips sign

Reference treats `never so` / `never this` as an intensifier (x1.25 / x1.5)
without negating. The port negates as well, which can invert the sign of an
ordinary sentence:

```
"I have never been so happy"    this package: -0.2699    reference: +0.6948
```

### Status

**Not scheduled.** Correcting these would move roughly half of all scores, which
is a major-version change for a package with a substantial install base. It is
documented here, measurable via `composer conformance`, and deliberately left
alone rather than fixed piecemeal.

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

## 2. Idioms rarely fire — mostly matching reference

An earlier version of this file claimed "15 of 21 idioms do not fire" and
measured each idiom against its value in `src/Config/Config.php`. **That framing
was wrong**, and it is corrected here.

The idiom tables were never the contract. Reference VADER gates
`_special_idioms_check()` on `start_i == 2`, so an idiom is only consulted when
the sentiment word sits at index >= 3 with a non-lexicon word three positions
back. Short phrases therefore never trigger it — in reference VADER itself:

```
"bad ass"              reference: -0.7906   (idiom value +1.5 not applied)
"the shit"             reference: -0.5574   (idiom value +3 not applied)
"it was a bad ass"     reference: +0.6124   (idiom applied)
```

So "the idiom didn't fire" is usually correct behaviour, not a defect.

**`SENTIMENT_LADEN_IDIOMS` is unimplemented on purpose.** Upstream marks it
*"future work, not yet implemented"*, never calls its own
`_sentiment_laden_idioms_check()`, and leaves a debug `print()` inside it. The
9 laden-only idioms (`under the weather`, `break a leg`, `in the red`, ...)
scoring 0.0000 is **parity with reference**, not a bug. The dead method was
removed in 2.0.0.

What remains is a genuine but small gap: 7 of 21 `idiom/*` cases differ from
reference, because the port does not implement the forward-looking checks and
does not apply the `start_i == 2` gate. It is folded into the overall 47%
figure in section 0 rather than tracked separately.

### A fix was attempted and rejected

A targeted change restoring the forward-looking checks was implemented and
**abandoned**: without also replicating the `start_i == 2` gate it made idioms
fire far more often than reference, taking overall divergence from 158 to 166
cases. Recorded here so it is not retried in isolation — idiom behaviour cannot
be corrected independently of the surrounding context loop.

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
