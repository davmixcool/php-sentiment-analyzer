<?php

namespace Sentiment\Procedures;

/*
    Identify sentiment-relevant string-level properties of input text.

    @internal This class is an implementation detail of Analyzer and is not
    covered by the package's backward-compatibility guarantees.
*/

class SentiText
{

    private string $text = "";

    /** @var array<int, string> */
    private array $words_and_emoticons = [];

    private bool $is_cap_diff = false;

    const PUNC_LIST = [".", "!", "?", ",", ";", ":", "-", "'", "\"",
             "!!", "!!!", "??", "???", "?!?", "!?!", "?!?!", "!?!?"];


    public function __construct(string $text)
    {
        //checking that is string
        //if (!isinstance(text, str)){
        //    text = str(text.encode('utf-8'));
        //}
        $this->text = $text;
        $this->words_and_emoticons = $this->_words_and_emoticons();
        // doesn't separate words from\
        // adjacent punctuation (keeps emoticons & contractions)
        $this->is_cap_diff = $this->allcap_differential($this->words_and_emoticons);
    }

    /** @return array<int, string> */
    public function getWordsAndEmoticons(): array
    {
        return $this->words_and_emoticons;
    }

    public function isCapDifferential(): bool
    {
        return $this->is_cap_diff;
    }

    /*
        Remove all punctation from a string
    */
    public function strip_punctuation(string $string): string
    {
        //$string = strtolower($string);
        return preg_replace("/[[:punct:]]+/", "", $string);
    }

    public function array_count_values_of(array $haystack, string $needle): int
    {
        if (!in_array($needle, $haystack, true)) {
            return 0;
        }
        $counts = array_count_values($haystack);
        return $counts[$needle];
    }

    /*
        Check whether just some words in the input are ALL CAPS

        :param list words: The words to inspect
        :returns: `True` if some but not all items in `words` are ALL CAPS
    */
    private function allcap_differential(array $words): bool
    {

        $is_different = false;
        $allcap_words = 0;
        foreach ($words as $word) {
            //ctype is affected by the local of the processor see manual for more details
            if (ctype_upper($word)) {
                $allcap_words += 1;
            }
        }
        $cap_differential = count($words) - $allcap_words;
        if ($cap_differential > 0 && $cap_differential < count($words)) {
            $is_different = true;
        }
        return $is_different;
    }

    public function _words_only(): array
    {
        $text_mod = $this->strip_punctuation($this->text);
        // removes punctuation (but loses emoticons & contractions)
        $words_only = preg_split('/\s+/', $text_mod);
        # get rid of empty items or single letter "words" like 'a' and 'I'
        $works_only = array_filter($words_only, function ($word) {
            return strlen($word) > 1;
        });
        return $words_only;
    }

    public function _words_and_emoticons(): array
    {

        $wes = preg_split('/\s+/', $this->text);

        # get rid of residual empty items or single letter words
        $wes = array_filter($wes, function ($word) {
            return strlen($word) > 1;
        });
        //Need to remap the indexes of the array
        $wes = array_values($wes);
        $words_only = $this->_words_only();

        foreach ($words_only as $word) {
            foreach (self::PUNC_LIST as $punct) {
                //replace all punct + word combinations with word
                $pword = $punct .$word;


                $x1 = $this->array_count_values_of($wes, $pword);
                while ($x1 > 0) {
                    $i = array_search($pword, $wes, true);
                    unset($wes[$i]);
                    array_splice($wes, $i, 0, $word);
                    $x1 = $this->array_count_values_of($wes, $pword);
                }
                //Do the same as above but word then punct
                $wordp = $word . $punct;
                $x2 = $this->array_count_values_of($wes, $wordp);
                while ($x2 > 0) {
                    $i = array_search($wordp, $wes, true);
                    unset($wes[$i]);
                    array_splice($wes, $i, 0, $word);
                    $x2 = $this->array_count_values_of($wes, $wordp);
                }
            }
        }

        return $wes;
    }
}
