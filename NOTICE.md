# Third-Party Notices

This package redistributes data files that are **not** its own work. They are
included in every release so that sentiment analysis works offline, with no
network access at runtime.

The package's own source code is licensed separately under `LICENCE.txt`.

---

## VADER Sentiment Lexicon and Emoji Lexicon

**Files**

- `src/Lexicons/vader_sentiment_lexicon.txt`
- `src/Lexicons/emoji_utf8_lexicon.txt`

**Source:** [cjhutto/vaderSentiment](https://github.com/cjhutto/vaderSentiment)

**Modifications:** none of substance. The data is upstream's, with no term
added, removed, or revalued. `emoji_utf8_lexicon.txt` is byte-identical.
`vader_sentiment_lexicon.txt` differs from upstream only by a UTF-8 byte-order
mark on its first line and the absence of a trailing newline — both accidents of
copying, not edits to the data. The byte-order mark has a known side effect, and
is documented in `KNOWN-DIVERGENCES.md`.

**License:** MIT, reproduced in full below as required.

```
The MIT License (MIT)

Copyright (c) 2016 C.J. Hutto

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.```

**Citation**

> Hutto, C.J. & Gilbert, E.E. (2014). VADER: A Parsimonious Rule-based Model for
> Sentiment Analysis of Social Media Text. Eighth International Conference on
> Weblogs and Social Media (ICWSM-14). Ann Arbor, MI, June 2014.

---

## This package

Everything outside `src/Lexicons/` is the work of this package's authors and is
licensed under the MIT License in `LICENCE.txt`. The two licenses are separate:
`LICENCE.txt` does not grant rights to the lexicon data, and the notice above
does not cover this package's code.
