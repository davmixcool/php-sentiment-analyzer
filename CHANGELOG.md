# Changelog

All notable changes to this project are documented here.

This project follows [Semantic Versioning](https://semver.org/).

## [2.0.1] - 2026-08-20

### Scores are unchanged

Documentation and tooling only. No source file was modified and every pinned
score is byte-identical to 2.0.0. **No need to re-score stored text.**

### Changed

- **Corrected the package description.** It previously read "…using VADER
  (Valence Aware Dictionary and sentiment Reasoner)", which implied score
  equivalence with the reference Python implementation. Measurement shows scores
  differ on 47% of a 336-case corpus, so the claim is now scoped to what is
  provably true: the package is *built on the VADER sentiment lexicon*, which it
  uses verbatim.

### Added

- **`composer conformance`** (`tools/conformance.sh`) — a three-way comparison of
  this package's 1.x line, its 2.x line, and reference Python `vaderSentiment`,
  reporting divergence per rule section. Informational; it does not gate CI.
- A **"Relationship to VADER"** section in the README, and a new section 0 in
  `KNOWN-DIVERGENCES.md` documenting the measured divergence and its causes —
  chiefly negation being ~2.5x too weak and booster damping being applied in
  reverse order.

### Fixed — documentation

- `KNOWN-DIVERGENCES.md` section 2 previously claimed "15 of 21 idioms do not
  fire", measured against the idiom tables. That framing was incorrect: reference
  VADER only consults idioms in specific positions, and `SENTIMENT_LADEN_IDIOMS`
  is explicitly unimplemented upstream, so most of those cases were parity rather
  than defects. Rewritten against reference behaviour.

## [2.0.0] - 2026-08-19

### Scores are unchanged

**2.0.0 produces byte-identical scores to 1.3.0** across all 355 pinned cases,
verified on PHP 8.1–8.4 in CI. This release modernizes the codebase; it does not
touch the scoring path. See `MIGRATION.md`.

### Added — new API

- `Analyzer::analyze(string): SentimentResult` — an immutable result object with
  `compound()`, `positive()`, `negative()`, `neutral()`, `label()`,
  `isPositive()`/`isNegative()`/`isNeutral()` and `toArray()`.
- `Analyzer::analyzeMany(iterable): SentimentResult[]` — preserves input keys.
- `Analyzer::withLexicon(array): static` — immutable; returns a new analyzer.
  Stricter than `updateLexicon()`: rejects multi-word terms and non-numeric
  values instead of silently coercing or ignoring them.
- `SentimentResult::POSITIVE_THRESHOLD` / `NEGATIVE_THRESHOLD` (±0.05, the VADER
  convention) so callers can reclassify without hardcoding.

`getSentiment()` is unaffected and returns the same array as always. Note that
`SentimentResult::toArray()` uses `positive`/`negative`/`neutral` where the
legacy array uses `pos`/`neg`/`neu` — see `MIGRATION.md`.

`explain()` is not included; it is scheduled for 2.2.

### Changed — BREAKING

- **PHP 8.1+ is now required** (`^8.1`). Users on older runtimes stay on `1.x`,
  which remains supported.
- Twelve internal methods are now `private`: `IsNegated`, `make_lex_dict`,
  `make_emoji_dict`, `score_valence`, `_least_check`, `_but_check`,
  `_idioms_check`, `_never_check`, `_punctuation_emphasis`, `_amplify_ep`,
  `_amplify_qm`, `_sift_sentiment_scores`.
- `SentiText` is `@internal`; its public properties are now private with
  `getWordsAndEmoticons()` / `isCapDifferential()` accessors.
- A missing lexicon file throws `Sentiment\Exceptions\InvalidLexiconException`
  instead of calling `die()`.
- Removed `_sentiment_laden_idioms_check()`, which was public and never called.
  No behavioural change — it is why `SENTIMENT_LADEN_IDIOMS` never fired.

### Fixed

- Dynamic property creation (`$emoji_lexicon`, `$emojis`), deprecated since PHP
  8.2, now declared. The package emits no deprecation notices, enforced by
  `failOnDeprecation="true"`.

### Added

- Full parameter, return and property types across `Analyzer`, `SentiText` and
  `Config`.
- PHPStan at level 5, wired into CI.
- `MIGRATION.md`.
- `NOTICE.md` and `src/Lexicons/README.md` — attribution and full MIT license
  text for the bundled VADER sentiment and emoji lexicons, which are
  third-party data from [cjhutto/vaderSentiment](https://github.com/cjhutto/vaderSentiment)
  (Copyright (c) 2016 C.J. Hutto). Both ship in the release tarball, as the
  license requires. The data itself is unchanged.

### Fixed — documentation

- The README's MIT license links pointed at `/blob/master/LICENSE`, which 404s;
  the file is `LICENCE.txt`.

## [1.3.1] - 2026-08-19

### Scores are unchanged

Documentation only. No source file was modified, and every pinned score in the
characterization suite is byte-identical to 1.3.0. **There is no need to
re-score stored text for this release.**

### Added

- `NOTICE.md` and `src/Lexicons/README.md` — attribution and the full MIT
  license text for the bundled VADER sentiment and emoji lexicons, which are
  third-party data from [cjhutto/vaderSentiment](https://github.com/cjhutto/vaderSentiment)
  (Copyright (c) 2016 C.J. Hutto). Both ship in the release tarball, as the
  license requires. The lexicon data itself is unchanged.

### Fixed

- The README's MIT license links pointed at `/blob/master/LICENSE`, which 404s;
  the file is `LICENCE.txt`.

## [1.3.0] - 2026-08-19

### Fixed

- **Sentiment is no longer zeroed after "so" or "this".** `_never_check()`
  initialised its modifier to `0` and applied it whenever `"so"` or `"this"`
  appeared within two tokens of a sentiment word, even when `"never"` was
  absent — multiplying the valence by zero. Common phrasing was affected:

  | Input | Before | After |
  |---|---|---|
  | `this is good` | 0.0000 | +0.4404 |
  | `this is bad` | 0.0000 | -0.5423 |
  | `so good` | 0.0000 | +0.4877 |
  | `this is GOOD` | 0.0000 | +0.5622 |

  The genuine "never" behaviour is unchanged: `never so good` and
  `never this good` still score -0.2385 and -0.2108.

  **This changes output.** If you store scores or compare against thresholds,
  re-score any affected text. Released as a minor rather than a patch for that
  reason. See `KNOWN-DIVERGENCES.md` §1.

- Corrected the first README example, which documented `compound 0.7096` for
  *"David is smart, handsome, and funny."*. The analyzer produces `0.8316`,
  matching reference VADER; the documentation was stale, not the code.

### Added

- Characterization test suite: 355 pinned cases covering negation, boosters,
  idioms, emoji, capitalisation, punctuation, but-clauses, and edge cases.
- `tools/test-matrix.sh` — runs the suite and verifies baseline reproducibility
  across PHP 8.1–8.4 in Docker (`composer matrix`, or `--fresh` to install
  dependencies inside each container as CI does).
- GitHub Actions CI across PHP 8.1–8.4.
- `KNOWN-DIVERGENCES.md` — catalogue of behaviour pinned as-is because it is
  what the code does, including 15 of 21 idioms that do not fire.
- `.gitattributes` — development files are excluded from release tarballs, and
  the lexicon data is opted out of line-ending normalisation to protect scores.

### Note

No changes to the supported PHP range. This release still supports PHP >= 5.5.9.
The `1.x` line remains the maintenance branch for runtimes below PHP 8.1.
