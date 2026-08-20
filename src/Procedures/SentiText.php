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

    /** Python's string.punctuation, used for token trimming. */
    const PUNCTUATION = "!\"#$%&'()*+,-./:;<=>?@[\\]^_`{|}~";


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

    /**
     * Mirrors SentiText._words_and_emoticons() in reference VADER.
     *
     * Split on whitespace, then strip leading/trailing punctuation from each
     * token — unless what remains is two characters or fewer, which means the
     * token was probably an emoticon and is kept intact.
     *
     * The previous implementation dropped every single-character token and only
     * stripped punctuation runs that appeared literally in PUNC_LIST. That
     * shifted token indices (breaking every position-dependent rule) and left
     * "good!!!!" untokenized, scoring it 0.0000.
     *
     * @return array<int, string>
     */
    public function _words_and_emoticons(): array
    {
        $tokens = preg_split('/\s+/u', trim($this->text), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        return array_map([$this, 'stripPuncIfWord'], $tokens);
    }

    /**
     * Python's str.strip(string.punctuation) equivalent, with the reference's
     * "<= 2 characters means it was an emoticon" guard.
     */
    private function stripPuncIfWord(string $token): string
    {
        $stripped = trim($token, self::PUNCTUATION);

        if (mb_strlen($stripped, 'UTF-8') <= 2) {
            return $token;
        }

        return $stripped;
    }
}
