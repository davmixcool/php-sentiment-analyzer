<?php

namespace Sentiment;

use Sentiment\Config\Config;
use Sentiment\Procedures\SentiText;

/*
    Give a sentiment intensity score to sentences.
*/

class Analyzer
{
    private $lexicon_file = "";
    private $lexicon = "";

    private $current_sentitext = null;

    public function __construct($lexicon_file = "Lexicons/vader_sentiment_lexicon.txt",$emoji_lexicon='Lexicons/emoji_utf8_lexicon.txt')
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
    public function IsNegated($wordToTest, $include_nt = true)
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
    public function make_lex_dict()
    {
        $lex_dict = [];
        $fp = fopen($this->lexicon_file, "r");
        if (!$fp) {
            die("Cannot load lexicon file");
        }

        while (($line = fgets($fp, 4096)) !== false) {
            list($word, $measure) = explode("\t", trim($line));
            //.strip().split('\t')[0:2]
            $lex_dict[$word] = $measure;
            //lex_dict[word] = float(measure)
        }

        return $lex_dict;
    }


    public function make_emoji_dict() {
        $emoji_dict = [];
        $fp = fopen($this->emoji_lexicon, "r");
        if (!$fp) {
            die("Cannot load emoji lexicon file");
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
        $lexicon = [];
        foreach ($arr as $word => $valence) {
            $this->lexicon[strtolower($word)] = is_numeric($valence)? $valence : 0;
        }
    }

    private function IsKindOf($firstWord, $secondWord)
    {
        return "kind" === strtolower($firstWord) && "of" === strtolower($secondWord);
    }

    private function IsBoosterWord($word)
    {
        return array_key_exists(strtolower($word), Config::BOOSTER_DICT);
    }

    private function getBoosterScaler($word)
    {
        return Config::BOOSTER_DICT[strtolower($word)];
    }

    private function IsInLexicon($word)
    {
        $lowercase = strtolower($word);

        return array_key_exists($lowercase, $this->lexicon);
    }

    private function IsUpperCaseWord($word)
    {
        return ctype_upper($word);
    }

    private function getValenceFromLexicon($word)
    {
        return $this->lexicon[strtolower($word)];
    }

    private function getTargetWordFromContext($wordInContext)
    {
        return $wordInContext[count($wordInContext)-1];
    }

    /*
        Gets the precedding two words to check for emphasis
    */
    private function getWordInContext($wordList, $currentWordPosition)
    {
        $precedingWordList =[];

        //push the actual word on to the context list
        array_unshift($precedingWordList, $wordList[$currentWordPosition]);
        //If the word position is greater than 2 then we know we are not going to overflow
        if (($currentWordPosition-1)>=0) {
            array_unshift($precedingWordList, $wordList[$currentWordPosition-1]);
        } else {
            array_unshift($precedingWordList, "");
        }

        if (($currentWordPosition-2)>=0) {
            array_unshift($precedingWordList, $wordList[$currentWordPosition-2]);
        } else {
            array_unshift($precedingWordList, "");
        }

        if (($currentWordPosition-3)>=0) {
            array_unshift($precedingWordList, $wordList[$currentWordPosition-3]);
        } else {
            array_unshift($precedingWordList, "");
        }

        return $precedingWordList;
    }

    /*
        Return a float for sentiment strength based on the input text.
        Positive values are positive valence, negative value are negative
        valence.
    */
    public function getSentiment($text)
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
        $words_and_emoticons = $this->current_sentitext->words_and_emoticons;

        for ($i=0; $i<=count($words_and_emoticons)-1; $i++) {
            $valence = 0.0;
            $wordBeingTested = $words_and_emoticons[$i];

            //If this is a booster word add a 0 valances then go to next word as it does not express sentiment directly
           /* if ($this->IsBoosterWord($wordBeingTested)){
                echo "\t\tThe word is a booster word: setting sentiment to 0.0\n";
            }*/
 //var_dump($i);
            //If the word is not in the Lexicon then it does not express sentiment. So just ignore it.
            if ($this->IsInLexicon($wordBeingTested)) {

                //Special case because kind is in the lexicon so the modifier kind of needs to be skipped
                if ("kind" !=$words_and_emoticons[$i] && "of" != $words_and_emoticons[$i]) {
                    $valence = $this->getValenceFromLexicon($wordBeingTested);

                    $wordInContext = $this->getWordInContext($words_and_emoticons, $i);
                    //If we are here then we have a word that enhance booster words
                    $valence = $this->adjustBoosterSentiment($wordInContext, $valence);
                }
            }
            array_push($sentiments, $valence);
        }
        //Once we have a sentiment for each word adjust the sentimest if but is present
        $sentiments = $this->_but_check($words_and_emoticons, $sentiments);

        return $this->score_valence($sentiments, $text);
    }


    private function str_split_unicode($str, $l = 0) {
        if ($l > 0) {
            $ret = array();
            $len = mb_strlen($str, "UTF-8");
            for ($i = 0; $i < $len; $i += $l) {
                $ret[] = mb_substr($str, $i, $l, "UTF-8");
            }
            return $ret;
        }
        return preg_split("//u", $str, -1, PREG_SPLIT_NO_EMPTY);
    }


    private function applyValenceCapsBoost($targetWord, $valence)
    {
        if ($this->IsUpperCaseWord($targetWord) && $this->current_sentitext->is_cap_diff) {
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
    private function dampendBoosterScalerByPosition($booster, $position)
    {
        if (0===$booster) {
            return $booster;
        }

        if (1==$position) {
            return $booster*0.95;
        }

        if (2==$position) {
            return $booster*0.9;
        }

        return $booster;
    }

    private function adjustBoosterSentiment($wordInContext, $valence)
    {
        //The target word is always the last word
        $targetWord = $this->getTargetWordFromContext($wordInContext);

        //check if sentiment laden word is in ALL CAPS (while others aren't) and apply booster
        $valence = $this->applyValenceCapsBoost($targetWord, $valence);

        $valence = $this->modifyValenceBasedOnContext($wordInContext, $valence);

        return $valence;
    }

    private function modifyValenceBasedOnContext($wordInContext, $valence)
    {
        $wordToTest = $this->getTargetWordFromContext($wordInContext);
            //if($this->IsInLexicon($wordToTest)){
            //  continue;
            //}
        for ($i=0; $i<count($wordInContext)-1; $i++) {
            $scalarValue = $this->boosterScaleAdjustment($wordInContext[$i], $valence);
            $scalarValue = $this->dampendBoosterScalerByPosition($scalarValue, $i);
            $valence = $valence+$scalarValue;
        }

        $valence = $this->_never_check($wordInContext, $valence);

        $valence = $this->_idioms_check($wordInContext, $valence);

        // future work: consider other sentiment-laden idioms
        // other_idioms =
        // {"back handed": -2, "blow smoke": -2, "blowing smoke": -2,
        //  "upper hand": 1, "break a leg": 2,
        //  "cooking with gas": 2, "in the black": 2, "in the red": -2,
        //  "on the ball": 2,"under the weather": -2}

        $valence = $this->_least_check($wordInContext, $valence);

        return $valence;
    }

    public function _least_check($wordInContext, $valence)
    {
        // check for negation case using "least"
        //if the previous word is least"
        if (strtolower($wordInContext[2]) == "least") {
            //but not "at least {word}" "very least {word}"
            if (strtolower($wordInContext[1]) != "at" && strtolower($wordInContext[1]) != "very") {
                $valence = $valence*Config::N_SCALAR;
            }
        }

        return $valence;
    }

    public function _but_check($words_and_emoticons, $sentiments)
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

    public function _idioms_check($wordInContext, $valence)
    {
        $onezero = sprintf("%s %s", $wordInContext[2], $wordInContext[3]);

        $twoonezero = sprintf("%s %s %s", $wordInContext[1], $wordInContext[2], $wordInContext[3]);

        $twoone = sprintf("%s %s", $wordInContext[1], $wordInContext[2]);

        $threetwoone = sprintf("%s %s %s", $wordInContext[0], $wordInContext[1], $wordInContext[2]);

        $threetwo = sprintf("%s %s", $wordInContext[0], $wordInContext[1]);

        $zeroone = sprintf("%s %s", $wordInContext[3], $wordInContext[2]);

        $zeroonetwo = sprintf("%s %s %s", $wordInContext[3], $wordInContext[2], $wordInContext[1]);

        $sequences = [$onezero, $twoonezero, $twoone, $threetwoone, $threetwo];

        foreach ($sequences as $seq) {
            $key = strtolower($seq);
            if (array_key_exists($key, Config::SPECIAL_CASE_IDIOMS)) {
                $valence = Config::SPECIAL_CASE_IDIOMS[$key];
                break;
            }

/*
            Positive idioms check.  Not implementing it yet
            if(count($words_and_emoticons)-1 > $i){
                $zeroone = sprintf("%s %s",$words_and_emoticons[$i], $words_and_emoticons[$i+1]);
               if (in_array($zeroone, Config::SPECIAL_CASE_IDIOMS)){
                    $valence = Config::SPECIAL_CASE_IDIOMS[$zeroone];
                }
            }
            if(count($words_and_emoticons)-1 > $i+1){
                $zeroonetwo = sprintf("%s %s %s",$words_and_emoticons[$i], $words_and_emoticons[$i+1], $words_and_emoticons[$i+2]);
                if (in_array($zeroonetwo, Config::SPECIAL_CASE_IDIOMS)){
                    $valence = Config::SPECIAL_CASE_IDIOMS[$zeroonetwo];
                }
            }
*/

            // check for booster/dampener bi-grams such as 'sort of' or 'kind of'
            if ($this->IsBoosterWord($threetwo) || $this->IsBoosterWord($twoone)) {
                $valence = $valence+Config::B_DECR;
            }
        }

        return $valence;
    }

    public function _never_check($wordInContext, $valance)
    {
        //If the sentiment word is preceded by never so/this we apply a modifier
        $neverModifier = 0;
        if ("never" == $wordInContext[0]) {
            $neverModifier = 1.25;
        } else if ("never" == $wordInContext[1]) {
            $neverModifier = 1.5;
        }
        if ("so" == $wordInContext[1] || "so"== $wordInContext[2] || "this" == $wordInContext[1] || "this" == $wordInContext[2]) {
            $valance *= $neverModifier;
        }

        //if any of the words in context are negated words apply negative scaler
        foreach ($wordInContext as $wordToCheck) {
            if ($this->IsNegated($wordToCheck)) {
                $valance *= Config::B_DECR;
            }
        }

        return $valance;
    }

    public function _sentiment_laden_idioms_check($valence, $senti_text_lower){
        # Future Work
        # check for sentiment laden idioms that don't contain a lexicon word
        $idioms_valences = [];
        foreach (Config::SENTIMENT_LADEN_IDIOMS as $idiom) {
             if(in_array($idiom, $senti_text_lower)){
                //print($idiom, $senti_text_lower)
                $valence = Config::SENTIMENT_LADEN_IDIOMS[$idiom];
                $idioms_valences[] = $valence;
            }
        }

        if ((strlen($idioms_valences) > 0)) {
            $valence = ( array_sum( explode( ',', $idioms_valences ) ) / floatval(strlen($idioms_valences)));
        }
        return $valence;
    }

    public function _punctuation_emphasis($sum_s, $text)
    {
        // add emphasis from exclamation points and question marks
        $ep_amplifier = $this->_amplify_ep($text);
        $qm_amplifier = $this->_amplify_qm($text);
        $punct_emph_amplifier = $ep_amplifier+$qm_amplifier;

        return $punct_emph_amplifier;
    }

    public function _amplify_ep($text)
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

    public function _amplify_qm($text)
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

    public function _sift_sentiment_scores($sentiments)
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

    public function score_valence($sentiments, $text)
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
