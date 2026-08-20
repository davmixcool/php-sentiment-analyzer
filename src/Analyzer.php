<?php

namespace Sentiment;

use Sentiment\Config\Config;
use Sentiment\Exceptions\InvalidLexiconException;
use Sentiment\Exceptions\InvalidLexiconTermException;
use Sentiment\Procedures\SentiText;

/*
    Give a sentiment intensity score to sentences.
*/

class Analyzer
{
    private string $lexicon_file = "";

    private string $emoji_lexicon = "";

    /** @var array<string, mixed> Term => valence, as read from the lexicon file. */
    private array $lexicon = [];

    /** @var array<string, string> Emoji character => description. */
    private array $emojis = [];

    private ?SentiText $current_sentitext = null;

    public function __construct(string $lexicon_file = "Lexicons/vader_sentiment_lexicon.txt", string $emoji_lexicon = 'Lexicons/emoji_utf8_lexicon.txt')
    {
        //Not sure about this as it forces lexicon file to be in the same directory as executing script
        $this->lexicon_file = __DIR__ . DIRECTORY_SEPARATOR . $lexicon_file;
        $this->lexicon = $this->make_lex_dict();

        $this->emoji_lexicon = __DIR__ . DIRECTORY_SEPARATOR .$emoji_lexicon;

        $this->emojis = $this->make_emoji_dict();
    }

    /*
        Determine if input contains negation words
    */
    private function IsNegated(string $wordToTest, bool $include_nt = true): bool
    {
        $wordToTest = strtolower($wordToTest);
        if (in_array($wordToTest, Config::NEGATE)) {
            return true;
        }

        if ($include_nt) {
            if (strpos($wordToTest, "n't")) {
                return true;
            }
        }

        return false;
    }

    /*
        Convert lexicon file to a dictionary
    */
    private function make_lex_dict(): array
    {
        $lex_dict = [];
        $fp = @fopen($this->lexicon_file, "r");
        if (!$fp) {
            throw InvalidLexiconException::unreadable($this->lexicon_file);
        }

        while (($line = fgets($fp, 4096)) !== false) {
            list($word, $measure) = explode("\t", trim($line));
            $lex_dict[$word] = (float) $measure;
        }

        return $lex_dict;
    }


    private function make_emoji_dict(): array {
        $emoji_dict = [];
        $fp = @fopen($this->emoji_lexicon, "r");
        if (!$fp) {
            throw InvalidLexiconException::unreadable($this->emoji_lexicon);
        }

        while (($line = fgets($fp, 4096)) !== false) {
            list($emoji, $description) = explode("\t", trim($line));
            //.strip().split('\t')[0:2]
            $emoji_dict[$emoji] = $description;
            //lex_dict[word] = float(measure)
        }
        return $emoji_dict;
    }

    public function updateLexicon($arr)
    {
        if(!is_array($arr)) return [];
        foreach ($arr as $word => $valence) {
            $this->lexicon[strtolower($word)] = is_numeric($valence)? $valence : 0;
        }
    }

    private function IsBoosterWord(string $word): bool
    {
        return array_key_exists(strtolower($word), Config::BOOSTER_DICT);
    }

    private function getBoosterScaler($word)
    {
        return Config::BOOSTER_DICT[strtolower($word)];
    }

    private function IsUpperCaseWord(string $word): bool
    {
        return ctype_upper($word);
    }

    /*
        Return a float for sentiment strength based on the input text.
        Positive values are positive valence, negative value are negative
        valence.
    */
    public function getSentiment(string $text): array
    {

        $text_no_emoji = '';
        $prev_space = true;

        foreach($this->str_split_unicode($text) as $unichr ) {
            if (array_key_exists($unichr, $this->emojis)) {
                $description = $this->emojis[$unichr];
                if (!($prev_space)) {
                    $text_no_emoji .= ' ';
                }
                $text_no_emoji .= $description;
                $prev_space = false;
            }
            else {
                $text_no_emoji .= $unichr;
                $prev_space = ($unichr == ' ');
            }
        }
        $text = trim($text_no_emoji);

        $this->current_sentitext = new SentiText($text);

        $sentiments = [];
        $words_and_emoticons = $this->current_sentitext->getWordsAndEmoticons();

        for ($i = 0; $i <= count($words_and_emoticons) - 1; $i++) {
            $itemLower = strtolower($words_and_emoticons[$i]);

            // Lexicon words that act as modifiers or negations score 0 themselves.
            if (array_key_exists($itemLower, Config::BOOSTER_DICT)) {
                $sentiments[] = 0.0;
                continue;
            }

            // "kind" only when followed by "of" — the previous implementation
            // skipped every "kind" and every "of", zeroing "he is a kind person".
            if ($i < count($words_and_emoticons) - 1
                && $itemLower === "kind"
                && strtolower($words_and_emoticons[$i + 1]) === "of") {
                $sentiments[] = 0.0;
                continue;
            }

            $sentiments[] = $this->sentimentValence($words_and_emoticons, $i);
        }
        //Once we have a sentiment for each word adjust the sentimest if but is present
        $sentiments = $this->_but_check($words_and_emoticons, $sentiments);

        return $this->score_valence($sentiments, $text);
    }


    /**
     * Analyze one piece of text.
     *
     * Delegates to getSentiment(), so scores are identical to the legacy method
     * by construction — enforced across the whole characterization corpus by
     * CharacterizationTest::testAnalyzeAgreesWithGetSentimentAcrossTheBaseline().
     */
    public function analyze(string $text): SentimentResult
    {
        return SentimentResult::fromScores($this->getSentiment($text));
    }

    /**
     * Analyze many texts at once.
     *
     * Input keys are preserved so callers can correlate results with their
     * source rows.
     *
     * @param iterable<array-key, string> $texts
     * @return array<array-key, SentimentResult>
     */
    public function analyzeMany(iterable $texts): array
    {
        $results = [];

        foreach ($texts as $key => $text) {
            $results[$key] = $this->analyze($text);
        }

        return $results;
    }

    /**
     * Return a NEW analyzer with the given terms applied over the lexicon.
     *
     * Immutable: the receiver is untouched. Cloning rather than constructing
     * avoids re-parsing ~11,000 lines of lexicon files, and PHP arrays are
     * copy-on-write so the copy is cheap.
     *
     * Custom terms override defaults; across calls, last write wins.
     *
     * Unlike the legacy updateLexicon(), this rejects bad input instead of
     * silently coercing it.
     *
     * @param array<string, float|int|string> $terms
     * @throws InvalidLexiconTermException
     */
    public function withLexicon(array $terms): static
    {
        $clone = clone $this;

        foreach ($terms as $term => $valence) {
            $term = (string) $term;

            if (preg_match('/\s/u', $term) === 1) {
                throw InvalidLexiconTermException::multiWord($term);
            }

            if (!is_numeric($valence)) {
                throw InvalidLexiconTermException::nonNumeric($term, $valence);
            }

            $clone->lexicon[strtolower($term)] = (float) $valence;
        }

        return $clone;
    }

    /**
     * $current_sentitext is transient per-call state; a clone must not share it.
     */
    public function __clone(): void
    {
        $this->current_sentitext = null;
    }

    /**
     * Score one token in context. Mirrors sentiment_valence() in reference VADER.
     *
     * The previous implementation walked a precomputed [i-3, i-2, i-1, i] window
     * by array position, which inverted the distance damping, omitted the
     * "preceding word not in the lexicon" gate, and applied negation and idiom
     * checks once outside the loop instead of per step. This follows the
     * reference structure directly.
     *
     * @param array<int, string> $words
     */
    private function sentimentValence(array $words, int $i): float
    {
        $item = $words[$i];
        $itemLower = strtolower($item);

        if (!array_key_exists($itemLower, $this->lexicon)) {
            return 0.0;
        }

        $valence = (float) $this->lexicon[$itemLower];

        // "no" as a negator for an adjacent lexicon item, vs "no" standing alone.
        if ($itemLower === "no"
            && $i !== count($words) - 1
            && array_key_exists(strtolower($words[$i + 1]), $this->lexicon)) {
            $valence = 0.0;
        }

        if (($i > 0 && strtolower($words[$i - 1]) === "no")
            || ($i > 1 && strtolower($words[$i - 2]) === "no")
            || ($i > 2 && strtolower($words[$i - 3]) === "no"
                && in_array(strtolower($words[$i - 1]), ["or", "nor"], true))) {
            $valence = (float) $this->lexicon[$itemLower] * Config::N_SCALAR;
        }

        $valence = $this->applyValenceCapsBoost($item, $valence);

        for ($startI = 0; $startI < 3; $startI++) {
            $precedingIndex = $i - ($startI + 1);

            if ($i <= $startI
                || array_key_exists(strtolower($words[$precedingIndex]), $this->lexicon)) {
                continue;
            }

            $scalar = $this->boosterScaleAdjustment($words[$precedingIndex], $valence);

            if ($startI === 1 && $scalar !== 0.0) {
                $scalar *= 0.95;
            }

            if ($startI === 2 && $scalar !== 0.0) {
                $scalar *= 0.9;
            }

            $valence += $scalar;
            $valence = $this->_negation_check($valence, $words, $startI, $i);

            if ($startI === 2) {
                $valence = $this->_idioms_check($valence, $words, $i);
            }
        }

        return $this->_least_check($valence, $words, $i);
    }

    /** @return array<int, string> */
    private function str_split_unicode(string $str): array
    {
        return preg_split("//u", $str, -1, PREG_SPLIT_NO_EMPTY);
    }


    private function applyValenceCapsBoost($targetWord, $valence)
    {
        if ($this->IsUpperCaseWord($targetWord) && $this->current_sentitext->isCapDifferential()) {
            if ($valence > 0) {
                $valence += Config::C_INCR;
            } else {
                $valence -= Config::C_INCR;
            }
        }

        return $valence;
    }

    /*
        Check if the preceding words increase, decrease, or negate/nullify the
        valence
     */
    private function boosterScaleAdjustment($word, $valence)
    {
        $scalar = 0.0;
        if (!$this->IsBoosterWord($word)) {
            return $scalar;
        }

        $scalar = $this->getBoosterScaler($word);

        if ($valence < 0) {
            $scalar *= -1;
        }
        //check if booster/dampener word is in ALLCAPS (while others aren't)
        $scalar = $this->applyValenceCapsBoost($word, $scalar);

        return $scalar;
    }

    // dampen the scalar modifier of preceding words and emoticons
    // (excluding the ones that immediately preceed the item) based
    // on their distance from the current item.
    /**
     * Mirrors _least_check() in reference VADER, including the
     * "preceding word not in the lexicon" gate that was missing here.
     *
     * @param array<int, string> $words
     */
    private function _least_check(float $valence, array $words, int $i): float
    {
        $prev = $i > 0 ? strtolower($words[$i - 1]) : '';
        $prevInLexicon = $i > 0 && array_key_exists($prev, $this->lexicon);

        if ($i > 1 && !$prevInLexicon && $prev === "least") {
            if (strtolower($words[$i - 2]) !== "at" && strtolower($words[$i - 2]) !== "very") {
                $valence *= Config::N_SCALAR;
            }
        } elseif ($i > 0 && !$prevInLexicon && $prev === "least") {
            $valence *= Config::N_SCALAR;
        }

        return $valence;
    }

    private function _but_check(array $words_and_emoticons, array $sentiments): array
    {
        // check for modification in sentiment due to contrastive conjunction 'but'
        $bi = array_search("but", $words_and_emoticons);
        if (!$bi) {
            $bi = array_search("BUT", $words_and_emoticons);
        }
        if ($bi) {
            for ($si=0; $si<count($sentiments); $si++) {
                if ($si<$bi) {
                    $sentiments[$si] = $sentiments[$si]*0.5;
                } else if ($si>$bi) {
                    $sentiments[$si] = $sentiments[$si]*1.5;
                }
            }
        }

        return $sentiments;
    }

    /**
     * Mirrors _special_idioms_check() in reference VADER.
     *
     * Only ever called at startI === 2, matching the reference — which is why
     * short phrases such as "bad ass" do not pick up their idiom value in either
     * implementation.
     *
     * @param array<int, string> $words
     */
    private function _idioms_check(float $valence, array $words, int $i): float
    {
        $lower = array_map('strtolower', $words);

        $onezero     = sprintf("%s %s", $lower[$i - 1], $lower[$i]);
        $twoonezero  = sprintf("%s %s %s", $lower[$i - 2], $lower[$i - 1], $lower[$i]);
        $twoone      = sprintf("%s %s", $lower[$i - 2], $lower[$i - 1]);
        $threetwoone = sprintf("%s %s %s", $lower[$i - 3], $lower[$i - 2], $lower[$i - 1]);
        $threetwo    = sprintf("%s %s", $lower[$i - 3], $lower[$i - 2]);

        foreach ([$onezero, $twoonezero, $twoone, $threetwoone, $threetwo] as $seq) {
            if (array_key_exists($seq, Config::SPECIAL_CASE_IDIOMS)) {
                $valence = (float) Config::SPECIAL_CASE_IDIOMS[$seq];
                break;
            }
        }

        $lastIndex = count($lower) - 1;

        if ($lastIndex > $i) {
            $zeroone = sprintf("%s %s", $lower[$i], $lower[$i + 1]);
            if (array_key_exists($zeroone, Config::SPECIAL_CASE_IDIOMS)) {
                $valence = (float) Config::SPECIAL_CASE_IDIOMS[$zeroone];
            }
        }

        if ($lastIndex > $i + 1) {
            $zeroonetwo = sprintf("%s %s %s", $lower[$i], $lower[$i + 1], $lower[$i + 2]);
            if (array_key_exists($zeroonetwo, Config::SPECIAL_CASE_IDIOMS)) {
                $valence = (float) Config::SPECIAL_CASE_IDIOMS[$zeroonetwo];
            }
        }

        // Booster/dampener n-grams such as "sort of" or "kind of" — applied once
        // per matching n-gram, not once per sequence-loop iteration as before.
        foreach ([$threetwoone, $threetwo, $twoone] as $nGram) {
            if (array_key_exists($nGram, Config::BOOSTER_DICT)) {
                $valence += Config::BOOSTER_DICT[$nGram];
            }
        }

        return $valence;
    }

    /**
     * Mirrors _negation_check() in reference VADER.
     *
     * NOTE the startI === 2 branch reproduces an operator-precedence quirk in the
     * reference: the trailing "so"/"this" test binds loosely, so it fires whether
     * or not "never" is present. Deliberate — 3.0 targets parity with reference,
     * quirks included.
     *
     * Previously this package multiplied by B_DECR (-0.293) rather than
     * N_SCALAR (-0.74), making every negation roughly 2.5x too weak.
     *
     * @param array<int, string> $words
     */
    private function _negation_check(float $valence, array $words, int $startI, int $i): float
    {
        $lower = array_map('strtolower', $words);

        if ($startI === 0 && $this->IsNegated($lower[$i - 1])) {
            $valence *= Config::N_SCALAR;
        }

        if ($startI === 1) {
            if ($lower[$i - 2] === "never"
                && ($lower[$i - 1] === "so" || $lower[$i - 1] === "this")) {
                $valence *= 1.25;
            } elseif ($lower[$i - 2] === "without" && $lower[$i - 1] === "doubt") {
                $valence = $valence;
            } elseif ($this->IsNegated($lower[$i - 2])) {
                $valence *= Config::N_SCALAR;
            }
        }

        if ($startI === 2) {
            if (($lower[$i - 3] === "never"
                    && ($lower[$i - 2] === "so" || $lower[$i - 2] === "this"))
                || ($lower[$i - 1] === "so" || $lower[$i - 1] === "this")) {
                $valence *= 1.25;
            } elseif ($lower[$i - 3] === "without"
                && ($lower[$i - 2] === "doubt" || $lower[$i - 1] === "doubt")) {
                $valence = $valence;
            } elseif ($this->IsNegated($lower[$i - 3])) {
                $valence *= Config::N_SCALAR;
            }
        }

        return $valence;
    }

    private function _punctuation_emphasis($sum_s, string $text): float
    {
        // add emphasis from exclamation points and question marks
        $ep_amplifier = $this->_amplify_ep($text);
        $qm_amplifier = $this->_amplify_qm($text);
        $punct_emph_amplifier = $ep_amplifier+$qm_amplifier;

        return $punct_emph_amplifier;
    }

    private function _amplify_ep(string $text): float
    {
        // check for added emphasis resulting from exclamation points (up to 4 of them)
        $ep_count = substr_count($text, "!");
        if ($ep_count > 4) {
            $ep_count = 4;
        }
        # (empirically derived mean sentiment intensity rating increase for
        # exclamation points)
        $ep_amplifier = $ep_count*0.292;

        return $ep_amplifier;
    }

    private function _amplify_qm(string $text): float
    {
        # check for added emphasis resulting from question marks (2 or 3+)
        $qm_count = substr_count($text, "?");
        $qm_amplifier = 0;
        if ($qm_count > 1) {
            if ($qm_count <= 3) {
                # (empirically derived mean sentiment intensity rating increase for
                # question marks)
                $qm_amplifier = $qm_count*0.18;
            } else {
                $qm_amplifier = 0.96;
            }
        }

        return $qm_amplifier;
    }

    private function _sift_sentiment_scores(array $sentiments): array
    {
        # want separate positive versus negative sentiment scores
        $pos_sum = 0.0;
        $neg_sum = 0.0;
        $neu_count = 0;
        foreach ($sentiments as $sentiment_score) {
            if ($sentiment_score > 0) {
                $pos_sum += $sentiment_score +1; # compensates for neutral words that are counted as 1
            }
            if ($sentiment_score < 0) {
                $neg_sum += $sentiment_score -1; # when used with math.fabs(), compensates for neutrals
            }
            if ($sentiment_score == 0) {
                $neu_count += 1;
            }
        }

        return [$pos_sum, $neg_sum, $neu_count];
    }

    private function score_valence(array $sentiments, string $text): array
    {
        if ($sentiments) {
            $sum_s = array_sum($sentiments);
            # compute and add emphasis from punctuation in text
            $punct_emph_amplifier = $this->_punctuation_emphasis($sum_s, $text);
            if ($sum_s > 0) {
                $sum_s += $punct_emph_amplifier;
            } elseif ($sum_s < 0) {
                $sum_s -= $punct_emph_amplifier;
            }

            $compound = Config::normalize($sum_s);
            # discriminate between positive, negative and neutral sentiment scores
            list($pos_sum, $neg_sum, $neu_count) = $this->_sift_sentiment_scores($sentiments);

            if ($pos_sum > abs($neg_sum)) {
                $pos_sum += $punct_emph_amplifier;
            } elseif ($pos_sum < abs($neg_sum)) {
                $neg_sum -= $punct_emph_amplifier;
            }

            $total = $pos_sum + abs($neg_sum) + $neu_count;
            $pos =abs($pos_sum / $total);
            $neg = abs($neg_sum / $total);
            $neu = abs($neu_count / $total);
        } else {
            $compound = 0.0;
            $pos = 0.0;
            $neg = 0.0;
            $neu = 0.0;
        }

        $sentiment_dict =
            ["neg" => round($neg, 3),
             "neu" => round($neu, 3),
             "pos" => round($pos, 3),
             "compound" => round($compound, 4)];

        return $sentiment_dict;
    }
}
