# Migration guide

Start with the section for the upgrade you are making. The 2.x to 3.0 move
changes scores; the 1.x to 2.0 move does not.

---

## Migrating from 2.x to 3.0

**Your sentiment scores change.** 3.0.0 makes the package a faithful port of
reference Python `vaderSentiment` 3.3.2 — 157 of 352 shared test cases moved,
roughly 45%.

**There are no API changes.** `getSentiment()`, `updateLexicon()`, `analyze()`,
`analyzeMany()`, `withLexicon()` and `SentimentResult` are all unchanged. Only
the numbers they return are different, and they are different because they were
wrong.

### What you need to do

**Re-score any stored sentiment values, and revisit any tuned thresholds.** If
you have a rule like "flag anything below -0.3", it will now behave differently —
in most cases it will catch more genuinely negative text than before.

If you cannot re-score right now, stay on 2.x:

```bash
composer require davmixcool/php-sentiment-analyzer:^2.0
```

`2.x` remains maintained with security fixes, and its scores are frozen.

### What changed and why

| Input | 2.x | 3.0 |
|---|---|---|
| `aint good` | -0.1423 | **-0.3412** |
| `not good` | -0.1423 | **-0.3412** |
| `I have never been so happy` | -0.2699 | **+0.6948** |
| `never so good` | -0.2385 | **+0.5777** |
| `without doubt good` | -0.0302 | **+0.6136** |
| `good!!!!` | 0.0000 | **+0.6209** |
| `good????` | 0.0000 | **+0.5940** |
| `he is a kind person` | 0.0000 | **+0.5267** |
| `kind of good` | 0.1116 | **+0.4404** |
| `very good` | 0.4877 | **+0.4927** |
| `the shit` | +0.6124 | **-0.5574** |

The most consequential fix is **negation**. 2.x scaled negated phrases by
`-0.293` where VADER specifies `-0.74`, so every negation was roughly 2.5x too
weak and the package systematically under-detected negative sentiment. If you
were compensating for that with a lower threshold, you can stop.

Three cases worth understanding because they look like regressions:

- **`"the shit"` and `"the bomb"` now score negative.** VADER only applies idiom
  values when the sentiment word sits at index >= 3, so these short phrases score
  from their constituent words. Reference does the same.
- **`"never so good"` flipped from negative to positive.** VADER treats
  `never so` / `never this` as an intensifier, not a negation.
- **`"good!!!!"` was returning 0.0000.** The old tokenizer only stripped
  punctuation runs it had a literal entry for, so four exclamation marks left the
  word unrecognised.

### Verifying it yourself

```bash
composer conformance
```

Scores a 350-case corpus with both this package and Python `vaderSentiment`
3.3.2, and fails if any case differs. It runs in CI on every push, so parity
cannot silently regress.

---

## Migrating from 1.x to 2.0

**Your sentiment scores do not change.** 2.0 produces byte-identical `neg`,
`neu`, `pos` and `compound` values to `1.3.0` across the full 355-case
characterization suite, verified on PHP 8.1, 8.2, 8.3 and 8.4. This is enforced
in CI, not asserted by hand.

If you are upgrading from `1.2.2` or earlier, scores **do** change — but that
belongs to `1.3.0`, not to 2.0. See the "Coming from 1.2.2 or earlier" section.

---

### 1. PHP 8.1 is required

```json
"require": { "php": "^8.1" }
```

This is the breaking change that matters. Composer will not resolve 2.0 on an
older runtime, so no amount of API compatibility helps there.

**If you cannot upgrade PHP:** stay on `1.x`. It remains installable, receives
security fixes, and carries the same test suite. `composer require
davmixcool/php-sentiment-analyzer:^1.3` pins you to it.

### 2. What has NOT changed

These are frozen and enforced by `tests/ApiContractTest.php`:

```php
$analyzer = new Analyzer();                    // same two optional path arguments
$scores   = $analyzer->getSentiment($text);    // same ['neg','neu','pos','compound']
$analyzer->updateLexicon(['rubbish' => -1.5]); // same lowercasing, same coercion
```

- `getSentiment()` returns the **same plain array**, same keys, same order, same
  rounding. It does not return an object.
- `updateLexicon()` keeps lowercasing keys, keeps coercing non-numeric values to
  `0`, and keeps ignoring non-array input.
- The constructor keeps resolving lexicon paths relative to the package's `src/`
  directory.

### 2b. The new API (optional)

Nothing below is required. `getSentiment()` keeps working exactly as before; the
new API is opt-in and layered over it, returning the same numbers.

```php
$result = $analyzer->analyze('This update is really good!');

$result->compound();    // 0.6892
$result->label();       // 'positive'
$result->isPositive();  // true
$result->toArray();     // ['positive' => …, 'negative' => …, 'neutral' => …, 'compound' => …, 'label' => …]
```

**`toArray()` keys differ from `getSentiment()` on purpose.** The legacy shape
(`neg`/`neu`/`pos`) is frozen and cannot be renamed; the new one spells the words
out. Do not mix them up — `SentimentResult` does not implement `ArrayAccess`, so
the two can never be swapped silently.

Labels use the VADER convention, exposed as constants:
`compound >= 0.05` is positive, `<= -0.05` negative, neutral between.

```php
$results = $analyzer->analyzeMany(['a' => 'great', 'b' => 'awful']); // keys preserved

$custom = $analyzer->withLexicon(['slaps' => 2.2]);  // returns a NEW analyzer
```

`withLexicon()` is **immutable** — assign the return value; the original is
unchanged. It is also stricter than the legacy `updateLexicon()`, which stays
lenient:

| Input | `updateLexicon()` (legacy) | `withLexicon()` (new) |
|---|---|---|
| `['good' => 'abc']` | coerced to `0` | throws `InvalidLexiconTermException` |
| `['cut the mustard' => 3]` | silently does nothing | throws `InvalidLexiconTermException` |
| `['GOOD' => 1.5]` | lowercased | lowercased |

Multi-word terms are rejected rather than routed into the idiom table, because
that matcher has known defects (`KNOWN-DIVERGENCES.md` §2) and would apply them
only in some positions. A clear error beats a feature that works sometimes.

`explain()` is not in 2.0 — it is scheduled for 2.2.

### 3. Accepted breaks

### 3.1 Internal methods are now private

Twelve methods that were `public` but plainly internal are now `private`:

`IsNegated`, `make_lex_dict`, `make_emoji_dict`, `score_valence`,
`_least_check`, `_but_check`, `_idioms_check`, `_never_check`,
`_punctuation_emphasis`, `_amplify_ep`, `_amplify_qm`, `_sift_sentiment_scores`

They were never part of the intended API — they were internals of the VADER port
that happened to be reachable. If you called one directly, open an issue
describing what for; that is a real use case worth designing an API around.

### 3.2 `_sentiment_laden_idioms_check()` has been removed

It was `public`, and it was **never called** — zero call sites in 1.x. Its
absence is exactly why the 12 `SENTIMENT_LADEN_IDIOMS` entries never affected
any score. Removing it changes no behaviour; it only stops the code implying a
feature that does not exist. The underlying divergence remains and is documented
in `KNOWN-DIVERGENCES.md` §2.

### 3.3 `SentiText` is encapsulated

`SentiText::$words_and_emoticons` and `$is_cap_diff` were public properties and
are now private, with `getWordsAndEmoticons()` and `isCapDifferential()`
accessors. The class is marked `@internal`.

### 3.4 A missing lexicon file now throws instead of calling `die()`

```php
use Sentiment\Exceptions\InvalidLexiconException;

try {
    $analyzer = new Analyzer('Lexicons/custom.txt');
} catch (InvalidLexiconException $e) {
    // handle it
}
```

v1 called `die()`, terminating the host process — behaviour a library should
never impose on the application embedding it. If you passed a custom lexicon
path and relied on the process dying, you now need a `catch`.

### 4. Coming from 1.2.2 or earlier

`1.3.0` fixed a defect in `_never_check()` that zeroed the sentiment of any word
within two tokens of "so" or "this":

| Input | 1.2.2 | 1.3.0 and 2.0 |
|---|---|---|
| `this is good` | 0.0000 | +0.4404 |
| `this is bad` | 0.0000 | -0.5423 |
| `so good` | 0.0000 | +0.4877 |

**Re-score any stored text** containing those words near sentiment terms. This
change is attributable to `1.3.0`; 2.0 inherits it unchanged.

### 5. What has not been fixed

2.0 is a modernization, not a scoring release. Known divergences from reference
VADER — most notably **15 of 21 idioms that never fire** — are reproduced
exactly and remain documented in `KNOWN-DIVERGENCES.md`. Fixing them will be its
own release with its own changelog entry, because it changes output.
