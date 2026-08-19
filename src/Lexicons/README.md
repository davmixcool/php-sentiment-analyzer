# Lexicon data

These files are **third-party data, not this package's work**. See
[`NOTICE.md`](../../NOTICE.md) in the repository root for the full license text
and attribution.

| File | Lines | Source |
|---|---|---|
| `vader_sentiment_lexicon.txt` | 7,519 | [cjhutto/vaderSentiment](https://github.com/cjhutto/vaderSentiment) (MIT) |
| `emoji_utf8_lexicon.txt` | 3,569 | [cjhutto/vaderSentiment](https://github.com/cjhutto/vaderSentiment) (MIT) |

Both are tab-separated. The sentiment lexicon is
`term<TAB>valence<TAB>standard deviation<TAB>raw ratings`; only the first two
columns are read. The emoji lexicon is `emoji<TAB>description`.

## Do not add comments or headers to these files

The parser (`Analyzer::make_lex_dict()`) splits **every** line on tabs and reads
the first two fields. A comment line has no tab, so it produces
`Warning: Undefined array key 1` — and the test suite runs with
`failOnWarning="true"`, so it will fail the build. Provenance belongs in this
README, not in the data.

## Do not "clean up" the byte-order mark

`vader_sentiment_lexicon.txt` begins with a UTF-8 BOM. It is not harmless: it
makes the first entry parse as `\xEF\xBB\xBF$:` rather than `$:`, so that one
emoticon is unreachable.

Removing it would make the term match and **change sentiment scores** for any
text containing `$:`. That is a scoring change, and this package guarantees
byte-identical scores within a release line. It is catalogued in
`KNOWN-DIVERGENCES.md` and will be fixed in a release that says so.

`.gitattributes` marks these files `-text` to keep any future line-ending
normalisation from rewriting them, which would shift every score in the package.

## Changing the data

Don't edit these files to customise sentiment. Use the runtime API instead:

```php
$analyzer = $analyzer->withLexicon(['slaps' => 2.2]);  // 2.0+
$analyzer->updateLexicon(['slaps' => 2.2]);            // legacy, all versions
```

If the data genuinely must change, regenerate the characterization baseline
(`composer baseline`) and review every score that moves.
