# PHP Sentiment Analyzer

PHP Sentiment Analyzer is a lexicon and rule-based sentiment analysis tool that is used to understand sentiments in a sentence using VADER (Valence Aware Dictionary and sentiment Reasoner).

[![GitHub license](https://img.shields.io/github/license/davmixcool/php-sentiment-analyzer.svg)](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/LICENSE)
[![GitHub issues](https://img.shields.io/github/issues/davmixcool/php-sentiment-analyzer.svg)](https://github.com/davmixcool/php-sentiment-analyzer/issues)
[![Twitter](https://img.shields.io/twitter/url/https/github.com/davmixcool/php-sentiment-analyzer.svg?style=social)](https://twitter.com/intent/tweet?text=Wow:&url=https%3A%2F%2Fgithub.com%2Fdavmixcool%2Fphp-sentiment-analyzer)

## Features

* Text
* Emoticon
* Emoji

## Requirements

- PHP 5.5 and above

## Steps:

* [Install](#install)
* [Usage](#usage)
* [License](#license)
* [Reference](#reference)

### Install

**Composer**

Run the following to include this via Composer

```shell
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

```
David is smart, handsome, and funny. ---------------- ['neg'=> 0.0, 'neu'=> 0.337, 'pos'=> 0.663, 'compound'=> 0.7096]

😁 ------------------- ['neg' => 0, 'neu' => 0.5, 'pos' => 0.5, 'compound' => 0.4588]

Aproko doctor made me 🤣 ------------- ['neg' => 0, 'neu' => 0.714, 'pos' =>  0.286, 'compound' => 0.4939]

```



### Advance Usage

You can now dynamically update the VADER (Valence) lexicon on the fly for words that are not in the dictionary. See Example below:


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


### Advance Outputs


```

Weather today is rubbish  ------------- {"neg":0.455,"neu":0.545,"pos":0,"compound":-0.3612} 

This cake looks amazing  ------------- {"neg":0,"neu":0.441,"pos":0.559,"compound":0.5859}

His skills are mediocre  ------------- {"neg":0.4,"neu":0.6,"pos":0,"compound":-0.25}

He is very talented  ------------- {"neg":0,"neu":0.457,"pos":0.543,"compound":0.552}

She is seemingly very agressive  ------------- {"neg":0.338,"neu":0.662,"pos":0,"compound":-0.2598}

Marie was enthusiastic about the upcoming trip. Her brother was also passionate about her leaving - he would finally have the house for himself.  ------------- {"neg":0,"neu":0.761,"pos":0.239,"compound":0.765}

String: To be or not to be?  ------------- {"neg":0,"neu":1,"pos":0,"compound":0}

```


### License

This package is licensed under the [MIT license](https://github.com/davmixcool/php-sentiment-analyzer/blob/master/LICENSE).

### Reference

Hutto, C.J. & Gilbert, E.E. (2014). VADER: A Parsimonious Rule-based Model for Sentiment Analysis of Social Media Text. Eighth International Conference on Weblogs and Social Media (ICWSM-14). Ann Arbor, MI, June 2014. 