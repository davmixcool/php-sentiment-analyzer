# PHP Sentiment Analyzer

PHP Sentiment Analyzer is a lexicon and rule-based sentiment analysis tool for PHP, built on the VADER \(Valence Aware Dictionary and sEntiment Reasoner\) sentiment lexicon.

[![CI](https://img.shields.io/github/actions/workflow/status/davmixcool/php-sentiment-analyzer/ci.yml?branch=master&label=CI)](https://github.com/davmixcool/php-sentiment-analyzer/actions/workflows/ci.yml) [![Latest Version](https://img.shields.io/packagist/v/davmixcool/php-sentiment-analyzer?label=latest)](https://packagist.org/packages/davmixcool/php-sentiment-analyzer) [![PHP Version](https://img.shields.io/packagist/php-v/davmixcool/php-sentiment-analyzer/dev-master?label=php)](https://packagist.org/packages/davmixcool/php-sentiment-analyzer) [![Total Downloads](https://img.shields.io/packagist/dt/davmixcool/php-sentiment-analyzer)](https://packagist.org/packages/davmixcool/php-sentiment-analyzer) [![License](https://img.shields.io/packagist/l/davmixcool/php-sentiment-analyzer)](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/LICENCE.txt) [![Stars](https://img.shields.io/github/stars/davmixcool/php-sentiment-analyzer)](https://github.com/davmixcool/php-sentiment-analyzer/stargazers) [![Forks](https://img.shields.io/github/forks/davmixcool/php-sentiment-analyzer)](https://github.com/davmixcool/php-sentiment-analyzer/network/members)

## Features

* Text
* Emoticon
* Emoji

## Requirements

* PHP 8.1 and above

> Using PHP below 8.1? Install the `1.x` line instead — it is maintained and
> produces the same scores:
> `composer require davmixcool/php-sentiment-analyzer:^1.3`

## Contents

* [Install](#install)
* [Modern API](#modern-api)
* [Simple Usage](#simple-usage)
* [Advanced Usage](#advanced-usage)
* [Upgrading](#upgrading)
* [Relationship to VADER](#relationship-to-vader)
* [License](#license)
* [Reference](#reference)

## Documentation

* [Changelog](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/CHANGELOG.md) — release history, including scoring changes
* [Migrating from 1.x to 2.0](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/MIGRATION.md) — breaking changes, and why your scores do not move
* [Known divergences](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/KNOWN-DIVERGENCES.md) — behaviour that differs from reference VADER, documented and pinned by the test suite

### Install

**Composer**

Run the following to include this via Composer

```text
composer require davmixcool/php-sentiment-analyzer
```

### Modern API

Available from 2.0. Returns an immutable result object instead of a bare array.

```php
use Sentiment\Analyzer;

$analyzer = new Analyzer();
$result   = $analyzer->analyze('This update is really good!');

$result->compound();    // 0.5355
$result->label();       // 'positive'
$result->isPositive();  // true
$result->positive();    // 0.463
$result->toArray();     // ['positive' => 0.463, 'negative' => 0.0, 'neutral' => 0.537, 'compound' => 0.5355, 'label' => 'positive']
```

Labels follow the VADER convention and are exposed as constants, so you can
reclassify without hardcoding: `compound >= 0.05` is positive, `<= -0.05` is
negative, and anything between is neutral.

**Batch analysis** preserves your input keys, so results line up with their
source rows:

```php
$results = $analyzer->analyzeMany([
    'ticket-1' => 'This update is really good!',
    'ticket-2' => 'This product is terrible.',
    'ticket-3' => 'It works fine.',
]);

$results['ticket-2']->label();     // 'negative'
$results['ticket-2']->compound();  // -0.4767
```

**Custom lexicons** return a *new* analyzer — the original is untouched:

```php
$slang = $analyzer->withLexicon([
    'slaps' => 2.2,
    'mid'   => -1.7,
]);

$slang->analyze('that beat slaps')->compound();    //  0.4939
$slang->analyze('the update is mid')->compound();  // -0.4019
$analyzer->analyze('that beat slaps')->compound(); //  0.0 — unchanged
```

`withLexicon()` rejects multi-word terms and non-numeric values rather than
coercing them. The older `updateLexicon()` below stays lenient and unchanged.

### Simple Usage

```php
Use Sentiment\Analyzer;
$analyzer = new Analyzer(); 

$output_text = $analyzer->getSentiment("David is smart, handsome, and funny.");

$output_emoji = $analyzer->getSentiment("😁");

$output_text_with_emoji = $analyzer->getSentiment("Aproko doctor made me 🤣.");

print_r($output_text);
print_r($output_emoji);
print_r($output_text_with_emoji);
```

### Simple Outputs

```text
David is smart, handsome, and funny. ---------------- ['neg'=> 0.0, 'neu'=> 0.254, 'pos'=> 0.746, 'compound'=> 0.8316]

😁 ------------------- ['neg' => 0, 'neu' => 0.5, 'pos' => 0.5, 'compound' => 0.4588]

Aproko doctor made me 🤣 ------------- ['neg' => 0, 'neu' => 0.714, 'pos' =>  0.286, 'compound' => 0.4939]
```

### Advanced Usage

You can now dynamically update the VADER \(Valence\) lexicon on the fly for words that are not in the dictionary. See the Example below:

```php
Use Sentiment\Analyzer;

$sentiment = new Sentiment\Analyzer();

$strings = [
    'Weather today is rubbish',
    'This cake looks amazing',
    'His skills are mediocre',
    'He is very talented',
    'She is seemingly very agressive',
    'Marie was enthusiastic about the upcoming trip. Her brother was also passionate about her leaving - he would finally have the house for himself.',
    'To be or not to be?',
];

//new words not in the dictionary
$newWords = [
    'rubbish'=> '-1.5',
    'mediocre' => '-1.0',
    'agressive' => '-0.5'
];

//Dynamically update the dictionary with the new words
$sentiment->updateLexicon($newWords);

//Print results
foreach ($strings as $string) {
    // calculations:
    $scores = $sentiment->getSentiment($string);
    // output:
    echo "String: $string\n";
    print_r(json_encode($scores));
    echo "<br>";
}
```

### Advanced Outputs

```text
Weather today is rubbish  ------------- {"neg":0.455,"neu":0.545,"pos":0,"compound":-0.3612} 

This cake looks amazing  ------------- {"neg":0,"neu":0.441,"pos":0.559,"compound":0.5859}

His skills are mediocre  ------------- {"neg":0.4,"neu":0.6,"pos":0,"compound":-0.25}

He is very talented  ------------- {"neg":0,"neu":0.457,"pos":0.543,"compound":0.552}

She is seemingly very agressive  ------------- {"neg":0.338,"neu":0.662,"pos":0,"compound":-0.2598}

Marie was enthusiastic about the upcoming trip. Her brother was also passionate about her leaving - he would finally have the house for himself.  ------------- {"neg":0,"neu":0.761,"pos":0.239,"compound":0.765}

String: To be or not to be?  ------------- {"neg":0,"neu":1,"pos":0,"compound":0}
```

### Upgrading

**1.3.0 changes scores for some text.** `_never_check()` previously zeroed the
sentiment of any word within two tokens of "so" or "this", so ordinary phrasing
returned neutral. That is fixed:

| Input | Before | After |
| --- | --- | --- |
| `this is good` | 0.0000 | +0.4404 |
| `this is bad` | 0.0000 | -0.5423 |
| `so good` | 0.0000 | +0.4877 |

The genuine "never" behaviour is unchanged: `never so good` still scores -0.2385.

If you store sentiment scores or compare them against thresholds, re-score any
affected text after upgrading. Full details in the
[changelog](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/CHANGELOG.md).

### Relationship to VADER

This package uses the **VADER sentiment lexicon verbatim** — the dictionary files
in `src/Lexicons/` are byte-identical to
[cjhutto/vaderSentiment](https://github.com/cjhutto/vaderSentiment), and lexicon
and emoji lookups match the reference implementation exactly.

**The rule engine is an independent port, and it is not score-equivalent with
reference Python VADER.** Measured across a 336-case corpus, scores differ on
**47% of cases**. The two largest causes are negation strength and the ordering
of booster damping:

| Input | This package | Python VADER |
| --- | --- | --- |
| `aint good` | -0.1423 | -0.3412 |
| `very good` | 0.4877 | 0.4927 |
| `I have never been so happy` | -0.2699 | 0.6948 |

If you need results that match Python VADER exactly, this package will not give
them to you today. If you need a self-contained, dependency-free, deterministic
sentiment scorer for PHP, it does that well — and its behaviour is pinned by a
355-case characterization suite, so it does not drift between releases.

Run `composer conformance` to reproduce the comparison yourself. Full detail,
including which rules diverge and why, is in
[KNOWN-DIVERGENCES.md](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/KNOWN-DIVERGENCES.md).

### 3.0.0 resolves this

The scoring engine was rewritten as a faithful port and now matches reference
VADER exactly — verified by `composer conformance`, which fails CI on any
divergence.

```bash
composer require davmixcool/php-sentiment-analyzer:^3.0
```

**Your scores will change** — roughly 45% of cases move, most importantly
negation, which this line applies at about a third of its intended strength. See
[MIGRATION.md](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/MIGRATION.md).

This 2.x line remains maintained for anyone who needs score stability, so
upgrading is a choice rather than a deadline.

### License

The package's source code is licensed under the
[MIT license](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/LICENCE.txt).

The bundled sentiment and emoji lexicons in `src/Lexicons/` are **third-party
data**, redistributed from [cjhutto/vaderSentiment](https://github.com/cjhutto/vaderSentiment)
under its own MIT license (Copyright (c) 2016 C.J. Hutto). Full attribution and
license text are in [NOTICE.md](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/NOTICE.md).

### Reference

Hutto, C.J. & Gilbert, E.E. \(2014\). VADER: A Parsimonious Rule-based Model for Sentiment Analysis of Social Media Text. Eighth International Conference on Weblogs and Social Media \(ICWSM-14\). Ann Arbor, MI, June 2014.

