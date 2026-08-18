# Known Divergences

Behaviour that is **pinned in `tests/fixtures/baseline.json` because it is what
the code currently does — not because it is correct.**

v2.0 guarantees byte-identical scores with v1 (see the Scoring Parity section of
the v2 PRD). Everything below is therefore reproduced exactly in v2.0 and fixed
in a later minor release, each with a `CHANGELOG.md` entry.

**Do not "fix" any of these while making the characterization suite pass.** A
failing case here means a score moved, which is a regression during v2.0 — even
if the new number looks more correct.

This file is the worklist for the post-2.0 scoring-fix release.

---

## 1. `_never_check` zeroes sentiment after "so" or "this"

**Impact: high.** Affects everyday phrasing.

`Analyzer::_never_check()` (`src/Analyzer.php:419`) initialises `$neverModifier`
to `0`, then applies it whenever `"so"` or `"this"` appears within two tokens of
a sentiment word — **regardless of whether `"never"` is present**:

```php
$neverModifier = 0;
if ("never" == $wordInContext[0]) { $neverModifier = 1.25; }
else if ("never" == $wordInContext[1]) { $neverModifier = 1.5; }

if ("so" == $wordInContext[1] || "so" == $wordInContext[2]
 || "this" == $wordInContext[1] || "this" == $wordInContext[2]) {
    $valance *= $neverModifier;   // multiplies by 0 when "never" is absent
}
```

Reference VADER applies the 1.25/1.5 boost only when `"never"` is present. Here
the multiplication runs unconditionally, so the valence is silently destroyed.

| Input | Pinned | Expected |
|---|---|---|
| `good` (control) | `0.4404` | `0.4404` |
| `this good` | `0.0000` | ~`0.4404` |
| `this is good` | `0.0000` | ~`0.4404` |
| `this is bad` | `0.0000` | ~negative |
| `so good` | `0.0000` | ~`0.4404` |
| `it is good` (control) | `0.4404` | `0.4404` |
| `that is good` (control) | `0.4404` | `0.4404` |

Only the two-token context window is affected — `this cake looks amazing`
scores `0.5859` correctly because `this` is four tokens from `amazing`.

Corpus section: `never_check/*`.

**Suggested fix:** guard the `so`/`this` branch on `$neverModifier !== 0`.

---

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

**Note for v2:** this interacts with the PRD's custom-lexicon design. Multi-word
keys passed to `withLexicon()` land in the term lexicon, not the idiom table, so
they cannot work until the idiom matcher does.

---

## 3. Dynamic property deprecations (PHP 8.2+)

`Analyzer::__construct()` assigns `$this->emoji_lexicon` and `$this->emojis`
without declaring them (`src/Analyzer.php:25` and `:27`). Deprecated since PHP
8.2; two notices fire on every instantiation.

`phpunit.xml` therefore sets `failOnDeprecation="false"`.

**Scheduled for v2.0**, where declaring the properties is part of the
modernization. `ApiContractTest::testKnownDynamicPropertyDeprecationsStillPresent()`
fails once they are declared — that failure is the signal to flip
`failOnDeprecation` to `true` and delete the test.

---

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
