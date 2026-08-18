<?php

/**
 * Milestone 0 — characterization baseline generator.
 *
 * Runs the CURRENT implementation over a systematically generated corpus and
 * pins every result to tests/fixtures/baseline.json.
 *
 * This captures behaviour as it is, NOT as it ought to be. Several pinned
 * values encode known bugs — see KNOWN-DIVERGENCES.md. Do not "correct" them
 * here; v2.0 guarantees byte-identical parity with these numbers.
 *
 * Usage: composer baseline
 *        php tools/generate-baseline.php [output-path]
 *
 * The optional output path lets tools/test-matrix.sh generate a fixture per PHP
 * version into scratch space and diff it, without touching the committed one.
 */

require __DIR__ . '/../vendor/autoload.php';

use Sentiment\Analyzer;
use Sentiment\Config\Config;

// v1 creates dynamic properties (deprecated in PHP 8.2+). Silence them here so
// they cannot corrupt generator output; they are catalogued, not fixed, in M0.
error_reporting(E_ALL & ~E_DEPRECATED);

const LEXICON_PATH = __DIR__ . '/../src/Lexicons/vader_sentiment_lexicon.txt';
const EMOJI_PATH   = __DIR__ . '/../src/Lexicons/emoji_utf8_lexicon.txt';
const OUTPUT_PATH  = __DIR__ . '/../tests/fixtures/baseline.json';

/**
 * Pin scores as fixed-precision strings, mirroring the rounding already applied
 * in Analyzer::score_valence() (3dp for neg/neu/pos, 4dp for compound).
 * Strings compared with assertSame remove float-comparison brittleness across
 * platforms and keep fixture diffs readable.
 */
function pin(array $scores): array
{
    return [
        'neg'      => sprintf('%.3f', $scores['neg']),
        'neu'      => sprintf('%.3f', $scores['neu']),
        'pos'      => sprintf('%.3f', $scores['pos']),
        'compound' => sprintf('%.4f', $scores['compound']),
    ];
}

/** Deterministic sample of $count items spread evenly across $items. */
function sampleEvenly(array $items, int $count): array
{
    $total = count($items);
    if ($total <= $count) {
        return $items;
    }

    $stride  = intdiv($total, $count);
    $sampled = [];
    for ($i = 0; count($sampled) < $count && $i < $total; $i += $stride) {
        $sampled[] = $items[$i];
    }

    return $sampled;
}

/** Read "term<TAB>valence<TAB>..." lines, stripping any BOM on the first line. */
function readVaderTerms(): array
{
    $terms = [];
    $lines = file(LEXICON_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $i => $line) {
        if ($i === 0) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        }
        $parts = explode("\t", $line);
        if (count($parts) < 2 || !is_numeric($parts[1])) {
            continue;
        }
        $terms[] = ['term' => $parts[0], 'valence' => (float) $parts[1]];
    }

    return $terms;
}

/** Read "emoji<TAB>description" lines. */
function readEmoji(): array
{
    $emoji = [];
    foreach (file(EMOJI_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 2) {
            continue;
        }
        $emoji[] = $parts[0];
    }

    return $emoji;
}

// ---------------------------------------------------------------------------
// Corpus construction
// ---------------------------------------------------------------------------

/** @var array<string, array{text: string, lexicon?: array}> $corpus */
$corpus = [];

// -- Negation: one case per NEGATE entry ------------------------------------
foreach (Config::NEGATE as $negator) {
    $corpus["negation/{$negator}"] = ['text' => "{$negator} good"];
}

// -- Boosters: one case per BOOSTER_DICT entry ------------------------------
foreach (array_keys(Config::BOOSTER_DICT) as $booster) {
    $corpus["booster/{$booster}"] = ['text' => "{$booster} good"];
}

// -- Idioms: verbatim and in a sentence, from both tables -------------------
// NOTE: 15 of 21 do not currently fire. Pinned as-is. See KNOWN-DIVERGENCES.md.
$idioms = array_merge(
    array_keys(Config::SPECIAL_CASE_IDIOMS),
    array_keys(Config::SENTIMENT_LADEN_IDIOMS)
);
foreach (array_unique($idioms) as $idiom) {
    $corpus["idiom/{$idiom}"]             = ['text' => $idiom];
    $corpus["idiom_sentence/{$idiom}"]    = ['text' => "it was {$idiom} today"];
}

// -- Emoji: deterministic sample --------------------------------------------
foreach (sampleEvenly(readEmoji(), 25) as $char) {
    $corpus["emoji/{$char}"]          = ['text' => $char];
    $corpus["emoji_sentence/{$char}"] = ['text' => "this is {$char} really"];
}

// -- Lexicon terms: deterministic sample spread across the valence range -----
$terms = readVaderTerms();
usort($terms, static fn (array $a, array $b): int => $a['valence'] <=> $b['valence']);
foreach (sampleEvenly($terms, 40) as $entry) {
    $corpus["lexicon/{$entry['term']}"] = ['text' => $entry['term']];
}

// -- Capitalisation ----------------------------------------------------------
foreach ([
    'caps/lower'          => 'good',
    'caps/upper'          => 'GOOD',
    'caps/mixed'          => 'GoOd',
    'caps/upper_sentence' => 'THIS IS GOOD',
    'caps/mixed_sentence' => 'this is GOOD',
    'caps/great_upper'    => 'GREAT',
    'caps/negated_upper'  => 'NOT GOOD',
    'caps/negated_lower'  => 'not good',
] as $key => $text) {
    $corpus[$key] = ['text' => $text];
}

// -- Punctuation emphasis ----------------------------------------------------
foreach ([
    'punctuation/bare'        => 'good',
    'punctuation/ep1'         => 'good!',
    'punctuation/ep2'         => 'good!!',
    'punctuation/ep3'         => 'good!!!',
    'punctuation/ep4'         => 'good!!!!',
    'punctuation/qm2'         => 'good??',
    'punctuation/qm3'         => 'good???',
    'punctuation/qm4'         => 'good????',
    'punctuation/mixed'       => 'good?!?!',
    'punctuation/caps_ep'     => 'GREAT!!!',
    'punctuation/negative_ep' => 'terrible!!!',
    'punctuation/only'        => '!!!',
] as $key => $text) {
    $corpus[$key] = ['text' => $text];
}

// -- But-clause rebalancing --------------------------------------------------
foreach ([
    'but_clause/pos_then_neg'  => 'good but terrible',
    'but_clause/neg_then_pos'  => 'terrible but good',
    'but_clause/upper'         => 'good BUT terrible',
    'but_clause/sentence'      => 'the food was great but the service was awful',
    'but_clause/sentence_rev'  => 'the food was awful but the service was great',
    'but_clause/no_but'        => 'good terrible',
    'but_clause/double'        => 'good but terrible but good',
    'but_clause/trailing'      => 'it is good but',
] as $key => $text) {
    $corpus[$key] = ['text' => $text];
}

// -- least / never special paths ---------------------------------------------
foreach ([
    'least_never/at_least'        => 'at least it works',
    'least_never/least_bare'      => 'least good',
    'least_never/least_sentence'  => 'this is the least good option',
    'least_never/never_bare'      => 'never good',
    'least_never/never_so'        => 'never so good',
    'least_never/never_this'      => 'never this good',
    'least_never/never_sentence'  => 'I have never been so happy',
    'least_never/without_doubt'   => 'without doubt good',
] as $key => $text) {
    $corpus[$key] = ['text' => $text];
}

// -- The "kind"/"of" skip case inside getSentiment() -------------------------
foreach ([
    'kind_of/kind_of_good' => 'kind of good',
    'kind_of/kind_bare'    => 'kind',
    'kind_of/kind_person'  => 'he is a kind person',
    'kind_of/sort_of_good' => 'sort of good',
] as $key => $text) {
    $corpus[$key] = ['text' => $text];
}

// -- _never_check: the "so"/"this" zeroing bug ------------------------------
// $neverModifier initialises to 0 and the so/this branch multiplies by it even
// when "never" is absent, so any sentiment word within two tokens of "so" or
// "this" is zeroed. Pinned as-is. See KNOWN-DIVERGENCES.md.
foreach ([
    'never_check/control_good'      => 'good',
    'never_check/this_good'         => 'this good',
    'never_check/this_is_good'      => 'this is good',
    'never_check/this_is_bad'       => 'this is bad',
    'never_check/so_good'           => 'so good',
    'never_check/so_is_good'        => 'so it is good',
    'never_check/that_is_good'      => 'that is good',
    'never_check/it_is_good'        => 'it is good',
    'never_check/good_this'         => 'good this',
    'never_check/never_so_good'     => 'never so good',
    'never_check/never_this_good'   => 'never this good',
    'never_check/never_good'        => 'never good',
    'never_check/this_far_amazing'  => 'this cake looks amazing',
    'never_check/so_far_amazing'    => 'so the cake looks amazing',
] as $key => $text) {
    $corpus[$key] = ['text' => $text];
}

// -- Edge cases --------------------------------------------------------------
foreach ([
    'edge/empty'           => '',
    'edge/space'           => ' ',
    'edge/single_char'     => 'a',
    'edge/integer'         => '123',
    'edge/float'           => '3.14',
    'edge/float_percent'   => '3.14 is 100% good',
    'edge/numbers_only'    => '1 2 3 4 5',
    'edge/accented'        => 'café good',
    'edge/unicode_mixed'   => 'naïve résumé good',
    'edge/emoji_only'      => '😁😁😁',
    'edge/emoji_mixed'     => 'This is REALLY good!!! 😁 but not terrible',
    'edge/punctuation_mix' => '.,;:-\'"',
    'edge/newlines'        => "good\nbad\ngood",
    'edge/tabs'            => "good\tbad",
    'edge/repeated'        => str_repeat('good ', 50),
    'edge/long_sentence'   => str_repeat('This product is amazing and I love it. ', 10),
    'edge/no_sentiment'    => 'the table has four legs',
    'edge/contraction'     => "isn't good",
    'edge/possessive'      => "the dog's good",
    'edge/url'             => 'see https://example.com/good for good stuff',
] as $key => $text) {
    $corpus[$key] = ['text' => $text];
}

// -- README examples (public contract) ---------------------------------------
foreach ([
    'readme/simple_text'       => 'David is smart, handsome, and funny.',
    'readme/simple_emoji'      => '😁',
    'readme/text_with_emoji'   => 'Aproko doctor made me 🤣.',
] as $key => $text) {
    $corpus[$key] = ['text' => $text];
}

// -- Custom lexicon via updateLexicon() --------------------------------------
$readmeLexicon = ['rubbish' => '-1.5', 'mediocre' => '-1.0', 'agressive' => '-0.5'];

foreach ([
    'readme_advanced/rubbish'    => 'Weather today is rubbish',
    'readme_advanced/amazing'    => 'This cake looks amazing',
    'readme_advanced/mediocre'   => 'His skills are mediocre',
    'readme_advanced/talented'   => 'He is very talented',
    'readme_advanced/agressive'  => 'She is seemingly very agressive',
    'readme_advanced/marie'      => 'Marie was enthusiastic about the upcoming trip. Her brother was also passionate about her leaving - he would finally have the house for himself.',
    'readme_advanced/to_be'      => 'To be or not to be?',
] as $key => $text) {
    $corpus[$key] = ['text' => $text, 'lexicon' => $readmeLexicon];
}

// updateLexicon() semantics frozen by the BC contract (PRD §3).
$corpus['custom_lexicon/new_term']        = ['text' => 'it was slaps',   'lexicon' => ['slaps' => 2.2]];
$corpus['custom_lexicon/override']        = ['text' => 'it was good',    'lexicon' => ['good' => -3.0]];
$corpus['custom_lexicon/uppercase_key']   = ['text' => 'it was snappy',  'lexicon' => ['SNAPPY' => 1.8]];
$corpus['custom_lexicon/uppercase_text']  = ['text' => 'it was SNAPPY',  'lexicon' => ['snappy' => 1.8]];
$corpus['custom_lexicon/non_numeric']     = ['text' => 'it was clunky',  'lexicon' => ['clunky' => 'not-a-number']];
$corpus['custom_lexicon/numeric_string']  = ['text' => 'it was clunky',  'lexicon' => ['clunky' => '-2.0']];
$corpus['custom_lexicon/zero']            = ['text' => 'it was mid',     'lexicon' => ['mid' => 0]];
$corpus['custom_lexicon/multi_word']      = ['text' => 'it was cut the mustard', 'lexicon' => ['cut the mustard' => 3.0]];
$corpus['custom_lexicon/negative']        = ['text' => 'it was mid',     'lexicon' => ['mid' => -1.7]];
$corpus['custom_lexicon/multiple_terms']  = ['text' => 'slaps but mid',   'lexicon' => ['slaps' => 2.2, 'mid' => -1.7]];

// ---------------------------------------------------------------------------
// Scoring
// ---------------------------------------------------------------------------

$shared   = new Analyzer();
$baseline = [];

foreach ($corpus as $key => $case) {
    if (isset($case['lexicon'])) {
        // updateLexicon() mutates, so each lexicon case needs a fresh analyzer.
        $analyzer = new Analyzer();
        $analyzer->updateLexicon($case['lexicon']);
    } else {
        $analyzer = $shared;
    }

    $entry = ['text' => $case['text']];
    if (isset($case['lexicon'])) {
        $entry['lexicon'] = $case['lexicon'];
    }

    $baseline[$key] = $entry + pin($analyzer->getSentiment($case['text']));
}

ksort($baseline);

$json = json_encode(
    $baseline,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);

$outputPath = $argv[1] ?? OUTPUT_PATH;

file_put_contents($outputPath, $json . "\n");

printf("Wrote %d pinned cases to %s\n", count($baseline), realpath($outputPath));
