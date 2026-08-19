# PHP Sentiment Analyzer

PHP Sentiment Analyzer is a lexicon and rule-based sentiment analysis tool that is used to understand sentiments in a sentence using VADER \(Valence Aware Dictionary and sentiment Reasoner\).

[![CI](https://img.shields.io/github/actions/workflow/status/davmixcool/php-sentiment-analyzer/ci.yml?branch=1.x&label=CI)](https://github.com/davmixcool/php-sentiment-analyzer/actions/workflows/ci.yml) [![Latest Version](https://img.shields.io/packagist/v/davmixcool/php-sentiment-analyzer?label=latest)](https://packagist.org/packages/davmixcool/php-sentiment-analyzer) [![PHP Version](https://img.shields.io/packagist/php-v/davmixcool/php-sentiment-analyzer/1.x-dev?label=php)](https://packagist.org/packages/davmixcool/php-sentiment-analyzer) [![Total Downloads](https://img.shields.io/packagist/dt/davmixcool/php-sentiment-analyzer)](https://packagist.org/packages/davmixcool/php-sentiment-analyzer) [![License](https://img.shields.io/packagist/l/davmixcool/php-sentiment-analyzer)](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/LICENCE.txt) [![Stars](https://img.shields.io/github/stars/davmixcool/php-sentiment-analyzer)](https://github.com/davmixcool/php-sentiment-analyzer/stargazers) [![Forks](https://img.shields.io/github/forks/davmixcool/php-sentiment-analyzer)](https://github.com/davmixcool/php-sentiment-analyzer/network/members)

## Features

* Text
* Emoticon
* Emoji

## Requirements

* PHP 5.5 and above

## Contents

* [Install](#install)
* [Simple Usage](#simple-usage)
* [Advanced Usage](#advanced-usage)
* [Upgrading](#upgrading)
* [License](#license)
* [Reference](#reference)

## Documentation

* [Changelog](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/CHANGELOG.md) — release history, including scoring changes
* [Known divergences](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/KNOWN-DIVERGENCES.md) — behaviour that differs from reference VADER, documented and pinned by the test suite

### Install

**Composer**

Run the following to include this via Composer

```text
composer require davmixcool/php-sentiment-analyzer
```

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

### License

The package's source code is licensed under the
[MIT license](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/LICENCE.txt).

The bundled sentiment and emoji lexicons in `src/Lexicons/` are **third-party
data**, redistributed from [cjhutto/vaderSentiment](https://github.com/cjhutto/vaderSentiment)
under its own MIT license (Copyright (c) 2016 C.J. Hutto). Full attribution and
license text are in [NOTICE.md](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/NOTICE.md).

### Reference

Hutto, C.J. & Gilbert, E.E. \(2014\). VADER: A Parsimonious Rule-based Model for Sentiment Analysis of Social Media Text. Eighth International Conference on Weblogs and Social Media \(ICWSM-14\). Ann Arbor, MI, June 2014.

