<?php

namespace Sentiment\Config;

/**
 * Class Config.
 */
class Config
{
   

    // (empirically derived mean sentiment intensity rating increase for booster words)
    const B_INCR = 0.293;
    const B_DECR = -0.293;

    // (empirically derived mean sentiment intensity rating increase for using
    // ALLCAPs to emphasize a word)
    const C_INCR = 0.733;

    const N_SCALAR =  -0.74;
    // for removing punctuation
    //const REGEX_REMOVE_PUNCTUATION = re.compile('[%s]' % re.escape(string.punctuation))
             
    const NEGATE = ["aint", "arent", "cannot", "cant", "couldnt", "darent", "didnt", "doesnt",
        "ain't", "aren't", "can't", "couldn't", "daren't", "didn't", "doesn't",
        "dont", "hadnt", "hasnt", "havent", "isnt", "mightnt", "mustnt", "neither",
        "don't", "hadn't", "hasn't", "haven't", "isn't", "mightn't", "mustn't",
        "neednt", "needn't", "never", "none", "nope", "nor", "not", "nothing", "nowhere",
        "oughtnt", "shant", "shouldnt", "uhuh", "wasnt", "werent",
        "oughtn't", "shan't", "shouldn't", "uh-uh", "wasn't", "weren't",
        "without", "wont", "wouldnt", "won't", "wouldn't", "rarely", "seldom", "despite"];

    //booster/dampener 'intensifiers' or 'degree adverbs'
    //http://en.wiktionary.org/wiki/Category:English_degree_adverbs

    const BOOSTER_DICT = ["absolutely"=> self::B_INCR, "amazingly"=> self::B_INCR, "awfully"=> self::B_INCR, "completely"=> self::B_INCR, "considerably"=> self::B_INCR,
     "decidedly"=> self::B_INCR, "deeply"=> self::B_INCR, "effing"=> self::B_INCR,"enormous"=> self::B_INCR, "enormously"=> self::B_INCR,
     "entirely"=> self::B_INCR, "especially"=> self::B_INCR, "exceptionally"=> self::B_INCR, "extremely"=> self::B_INCR,
     "fabulously"=> self::B_INCR, "flipping"=> self::B_INCR, "flippin"=> self::B_INCR,
     "fricking"=> self::B_INCR, "frickin"=> self::B_INCR, "frigging"=> self::B_INCR, "friggin"=> self::B_INCR, "fully"=> self::B_INCR, "fucking"=> self::B_INCR,
     "greatly"=> self::B_INCR, "hella"=> self::B_INCR, "highly"=> self::B_INCR, "hugely"=> self::B_INCR, "incredibly"=> self::B_INCR,
     "intensely"=> self::B_INCR, "majorly"=> self::B_INCR, "more"=> self::B_INCR, "most"=> self::B_INCR, "particularly"=> self::B_INCR,
     "purely"=> self::B_INCR, "quite"=> self::B_INCR, "seemingly" => self::B_INCR, "really"=> self::B_INCR, "remarkably"=> self::B_INCR,
     "so"=> self::B_INCR, "substantially"=> self::B_INCR,
     "thoroughly"=> self::B_INCR, "totally"=> self::B_INCR, "tremendous"=> self::B_INCR, "tremendously"=> self::B_INCR,
     "uber"=> self::B_INCR, "unbelievably"=> self::B_INCR, "unusually"=> self::B_INCR, "utterly"=> self::B_INCR,
     "very"=> self::B_INCR,
     "almost"=> self::B_DECR, "barely"=> self::B_DECR, "hardly"=> self::B_DECR, "just enough"=> self::B_DECR,
     "kind of"=> self::B_DECR, "kinda"=> self::B_DECR, "kindof"=> self::B_DECR, "kind-of"=> self::B_DECR,
     "less"=> self::B_DECR, "little"=> self::B_DECR, "marginally"=> self::B_DECR, "occasional"=> self::B_DECR, "occasionally"=> self::B_DECR, "partly"=> self::B_DECR,
     "scarcely"=> self::B_DECR, "slightly"=> self::B_DECR, "somewhat"=> self::B_DECR,
     "sort of"=> self::B_DECR, "sorta"=> self::B_DECR, "sortof"=> self::B_DECR, "sort-of"=> self::B_DECR];


     # check for sentiment laden idioms that do not contain lexicon words (future work, not yet implemented)
    const SENTIMENT_LADEN_IDIOMS = ["cut the mustard"=> 2, "hand to mouth"=> -2,
                          "back handed"=> -2, "blow smoke"=> -2, "blowing smoke"=> -2,
                          "upper hand"=> 1, "break a leg"=> 2,
                          "cooking with gas"=> 2, "in the black"=> 2, "in the red"=> -2,
                          "on the ball"=> 2, "under the weather"=> -2];

    // check for special case idioms using a sentiment-laden keyword known to SAGE
    const SPECIAL_CASE_IDIOMS = ["the shit"=> 3, "the bomb"=> 3, "bad ass"=> 1.5, "bus stop"=> 0.0, "yeah right"=> -2, "cut the mustard"=> 2, "kiss of death"=> -1.5, "hand to mouth"=> -2, "beating heart"=> 3.1,"broken heart"=> -2.9,  "to die for"=> 3];
    ##Static methods##

    /*
        Normalize the score to be between -1 and 1 using an alpha that
        approximates the max expected value
    */
    public static function normalize($score, $alpha = 15)
    {
        $norm_score = $score/sqrt(($score*$score) + $alpha);
        return $norm_score;
    }
}
