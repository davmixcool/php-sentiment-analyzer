# Migrating from 1.x to 2.0

**Your sentiment scores do not change.** 2.0 produces byte-identical `neg`,
`neu`, `pos` and `compound` values to `1.3.0` across the full 355-case
characterization suite, verified on PHP 8.1, 8.2, 8.3 and 8.4. This is enforced
in CI, not asserted by hand.

If you are upgrading from `1.2.2` or earlier, scores **do** change — but that
belongs to `1.3.0`, not to 2.0. See the "Coming from 1.2.2 or earlier" section.

---

## 1. PHP 8.1 is required

```json
"require": { "php": "^8.1" }
```

This is the breaking change that matters. Composer will not resolve 2.0 on an
older runtime, so no amount of API compatibility helps there.

**If you cannot upgrade PHP:** stay on `1.x`. It remains installable, receives
security fixes, and carries the same test suite. `composer require
davmixcool/php-sentiment-analyzer:^1.3` pins you to it.

## 2. What has NOT changed

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

## 3. Accepted breaks

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

## 4. Coming from 1.2.2 or earlier

`1.3.0` fixed a defect in `_never_check()` that zeroed the sentiment of any word
within two tokens of "so" or "this":

| Input | 1.2.2 | 1.3.0 and 2.0 |
|---|---|---|
| `this is good` | 0.0000 | +0.4404 |
| `this is bad` | 0.0000 | -0.5423 |
| `so good` | 0.0000 | +0.4877 |

**Re-score any stored text** containing those words near sentiment terms. This
change is attributable to `1.3.0`; 2.0 inherits it unchanged.

## 5. What has not been fixed

2.0 is a modernization, not a scoring release. Known divergences from reference
VADER — most notably **15 of 21 idioms that never fire** — are reproduced
exactly and remain documented in `KNOWN-DIVERGENCES.md`. Fixing them will be its
own release with its own changelog entry, because it changes output.
