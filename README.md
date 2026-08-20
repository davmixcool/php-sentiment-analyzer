# PHP Sentiment Analyzer

PHP Sentiment Analyzer is a lexicon and rule-based sentiment analysis tool for PHP using VADER \(Valence Aware Dictionary and sEntiment Reasoner\), matching the reference Python implementation exactly.

[![CI](https://img.shields.io/github/actions/workflow/status/davmixcool/php-sentiment-analyzer/ci.yml?branch=master&label=CI)](https://github.com/davmixcool/php-sentiment-analyzer/actions/workflows/ci.yml) [![Latest Version](https://img.shields.io/packagist/v/davmixcool/php-sentiment-analyzer?label=latest)](https://packagist.org/packages/davmixcool/php-sentiment-analyzer) [![PHP Version](https://img.shields.io/packagist/php-v/davmixcool/php-sentiment-analyzer/dev-master?label=php)](https://packagist.org/packages/davmixcool/php-sentiment-analyzer) [![Total Downloads](https://img.shields.io/packagist/dt/davmixcool/php-sentiment-analyzer)](https://packagist.org/packages/davmixcool/php-sentiment-analyzer) [![License](https://img.shields.io/packagist/l/davmixcool/php-sentiment-analyzer)](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/LICENCE.txt) [![Stars](https://img.shields.io/github/stars/davmixcool/php-sentiment-analyzer)](https://github.com/davmixcool/php-sentiment-analyzer/stargazers) [![Forks](https://img.shields.io/github/forks/davmixcool/php-sentiment-analyzer)](https://github.com/davmixcool/php-sentiment-analyzer/network/members)

## Features

* Text
* Emoticon
* Emoji

## Relationship to VADER

**This package matches reference Python
[vaderSentiment](https://github.com/cjhutto/vaderSentiment) 3.3.2 exactly.**

The lexicon files are byte-identical to upstream, and the rule engine is a
faithful port — including reference VADER's own quirks, so that scores agree
rather than merely being close. Conformance is verified, not asserted:

```bash
composer conformance
```

That scores a 350-case corpus with both implementations and fails if a single
case differs. It runs in CI on every push.

Before 3.0.0 this was not true — the port diverged from reference on 47% of
cases, most importantly by applying negation at roughly a third of its intended
strength. See [MIGRATION.md](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/MIGRATION.md)
if you are upgrading from 1.x or 2.x, because **your scores will change**.

## Requirements

* PHP 8.1 and above

> Using PHP below 8.1? Install the `1.x` line instead — it is maintained and
> produces the same scores:
> `composer require davmixcool/php-sentiment-analyzer:^1.3`

## Contents

* [Relationship to VADER](#relationship-to-vader)
* [Install](#install)
* [Modern API](#modern-api)
* [Simple Usage](#simple-usage)
* [Advanced Usage](#advanced-usage)
* [Upgrading](#upgrading)
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

$result->compound();    // 0.5400
$result->label();       // 'positive'
$result->isPositive();  // true
$result->positive();    // 0.466
$result->toArray();     // ['positive' => 0.466, 'negative' => 0.0, 'neutral' => 0.534, 'compound' => 0.5400, 'label' => 'positive']
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

😁 ------------------- ['neg' => 0, 'neu' => 0.571, 'pos' => 0.429, 'compound' => 0.4588]

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

He is very talented  ------------- {"neg":0,"neu":0.455,"pos":0.545,"compound":0.5563}

She is seemingly very agressive  ------------- {"neg":0.31,"neu":0.69,"pos":0,"compound":-0.2006}

Marie was enthusiastic about the upcoming trip. Her brother was also passionate about her leaving - he would finally have the house for himself.  ------------- {"neg":0,"neu":0.769,"pos":0.231,"compound":0.765}

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

### License

The package's source code is licensed under the
[MIT license](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/LICENCE.txt).

The bundled sentiment and emoji lexicons in `src/Lexicons/` are **third-party
data**, redistributed from [cjhutto/vaderSentiment](https://github.com/cjhutto/vaderSentiment)
under its own MIT license (Copyright (c) 2016 C.J. Hutto). Full attribution and
license text are in [NOTICE.md](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/NOTICE.md).

### Reference

Hutto, C.J. & Gilbert, E.E. \(2014\). VADER: A Parsimonious Rule-based Model for Sentiment Analysis of Social Media Text. Eighth International Conference on Weblogs and Social Media \(ICWSM-14\). Ann Arbor, MI, June 2014.

