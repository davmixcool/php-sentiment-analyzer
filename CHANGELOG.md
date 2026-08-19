# Changelog

All notable changes to this project are documented here.

This project follows [Semantic Versioning](https://semver.org/).

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
