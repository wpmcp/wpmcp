<?php

namespace WPMCP\Tools\Analysis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Stopword lists for keyword extraction (issue #295).
 *
 * English is always applied, because English function words turn up in
 * almost every site's copy (menus, buttons, quoted product names) whatever
 * the site language. On top of it the SITE locale's list applies when one
 * ships here (German, French, Spanish, Italian, Portuguese, Dutch). A
 * language without a list still works: frequency ranking and the phrase
 * edge rule do most of the job, and the `wpmcp_keyword_stopwords` filter
 * lets a site add its own words for any language.
 */
class Keyword_Stopwords
{
    private const EN = [
        'a', 'about', 'above', 'after', 'again', 'against', 'all', 'also', 'am', 'an', 'and', 'any',
        'are', 'aren\'t', 'as', 'at', 'be', 'because', 'been', 'before', 'being', 'below', 'between',
        'both', 'but', 'by', 'can', 'can\'t', 'cannot', 'could', 'couldn\'t', 'did', 'didn\'t', 'do',
        'does', 'doesn\'t', 'doing', 'don\'t', 'down', 'during', 'each', 'even', 'ever', 'every', 'few',
        'for', 'from', 'further', 'get', 'gets', 'got', 'had', 'hadn\'t', 'has', 'hasn\'t', 'have',
        'haven\'t', 'having', 'he', 'he\'d', 'he\'ll', 'her', 'here', 'hers', 'herself', 'him',
        'himself', 'his', 'how', 'i', 'i\'d', 'i\'ll', 'i\'m', 'i\'ve', 'if', 'in', 'into', 'is',
        'isn\'t', 'it', 'its', 'itself', 'just', 'let', 'let\'s', 'like', 'many', 'may', 'me', 'might',
        'more', 'most', 'much', 'must', 'mustn\'t', 'my', 'myself', 'no', 'nor', 'not', 'now', 'of',
        'off', 'often', 'on', 'once', 'one', 'only', 'or', 'other', 'ought', 'our', 'ours',
        'ourselves', 'out', 'over', 'own', 'same', 'shall', 'she', 'she\'d', 'she\'ll', 'should',
        'shouldn\'t', 'so', 'some', 'such', 'than', 'that', 'the', 'their', 'theirs', 'them',
        'themselves', 'then', 'there', 'these', 'they', 'they\'d', 'they\'ll', 'they\'re', 'they\'ve',
        'this', 'those', 'through', 'to', 'too', 'under', 'until', 'up', 'upon', 'us', 'use', 'used',
        'using', 'very', 'via', 'was', 'wasn\'t', 'we', 'we\'d', 'we\'ll', 'we\'re', 'we\'ve', 'were',
        'weren\'t', 'what', 'when', 'where', 'whether', 'which', 'while', 'who', 'whom', 'whose', 'why',
        'will', 'with', 'within', 'without', 'won\'t', 'would', 'wouldn\'t', 'yet', 'you', 'you\'d',
        'you\'ll', 'you\'re', 'you\'ve', 'your', 'yours', 'yourself', 'yourselves',
    ];

    /** @var array<string,string[]> ISO 639-1 code => list. */
    private const BY_LANGUAGE = [
        'de' => [
            'aber', 'als', 'am', 'an', 'auch', 'auf', 'aus', 'bei', 'bin', 'bis', 'bist', 'da', 'damit',
            'dann', 'das', 'dass', 'dein', 'deine', 'dem', 'den', 'denn', 'der', 'des', 'dich', 'die',
            'dir', 'doch', 'dort', 'du', 'durch', 'ein', 'eine', 'einem', 'einen', 'einer', 'eines', 'er',
            'es', 'euch', 'euer', 'für', 'gegen', 'hat', 'hatte', 'haben', 'hier', 'ich', 'ihm', 'ihn',
            'ihr', 'ihre', 'im', 'in', 'ist', 'ja', 'jede', 'jeder', 'jedes', 'kann', 'kein', 'keine',
            'können', 'man', 'mehr', 'mein', 'meine', 'mich', 'mir', 'mit', 'muss', 'nach', 'nicht',
            'noch', 'nun', 'nur', 'ob', 'oder', 'ohne', 'schon', 'sehr', 'sein', 'seine', 'sich', 'sie',
            'sind', 'so', 'um', 'und', 'uns', 'unser', 'unsere', 'unter', 'vom', 'von', 'vor', 'war',
            'waren', 'was', 'weil', 'wenn', 'wer', 'werden', 'wie', 'wir', 'wird', 'wo', 'zu', 'zum',
            'zur', 'über',
        ],
        'fr' => [
            'à', 'au', 'aux', 'avec', 'ce', 'ces', 'cette', 'dans', 'de', 'des', 'du', 'elle', 'elles',
            'en', 'est', 'et', 'être', 'eux', 'il', 'ils', 'je', 'la', 'le', 'les', 'leur', 'leurs', 'lui',
            'ma', 'mais', 'me', 'même', 'mes', 'moi', 'mon', 'ne', 'nos', 'notre', 'nous', 'on', 'ont',
            'ou', 'où', 'par', 'pas', 'plus', 'pour', 'qu', 'que', 'qui', 'sa', 'sans', 'se', 'ses',
            'son', 'sont', 'sur', 'ta', 'te', 'tes', 'toi', 'ton', 'tous', 'tout', 'très', 'tu', 'un',
            'une', 'vos', 'votre', 'vous', 'y', 'été', 'était', 'c\'est', 'd\'un', 'd\'une', 'l\'un',
        ],
        'es' => [
            'a', 'al', 'algo', 'como', 'con', 'cual', 'de', 'del', 'desde', 'donde', 'el', 'él', 'ella',
            'ellas', 'ellos', 'en', 'entre', 'era', 'es', 'esa', 'ese', 'eso', 'esta', 'está', 'están',
            'este', 'esto', 'fue', 'ha', 'han', 'hay', 'la', 'las', 'le', 'les', 'lo', 'los', 'más', 'me',
            'mi', 'muy', 'ni', 'no', 'nos', 'o', 'para', 'pero', 'por', 'que', 'qué', 'se', 'ser', 'si',
            'sí', 'sin', 'sobre', 'son', 'su', 'sus', 'también', 'te', 'tu', 'tus', 'un', 'una', 'uno',
            'unos', 'unas', 'y', 'ya', 'yo',
        ],
        'it' => [
            'a', 'ad', 'al', 'alla', 'alle', 'anche', 'che', 'chi', 'ci', 'come', 'con', 'da', 'dal',
            'dalla', 'dei', 'del', 'della', 'delle', 'di', 'e', 'è', 'ed', 'gli', 'ha', 'hanno', 'i', 'il',
            'in', 'io', 'la', 'le', 'lei', 'lo', 'loro', 'lui', 'ma', 'mi', 'mio', 'ne', 'nel', 'nella',
            'noi', 'non', 'nostro', 'o', 'per', 'più', 'quale', 'quando', 'questa', 'questo', 'se', 'si',
            'sono', 'su', 'sua', 'sul', 'suo', 'ti', 'tra', 'tu', 'tuo', 'un', 'una', 'uno', 'voi',
        ],
        'pt' => [
            'a', 'ao', 'aos', 'as', 'com', 'como', 'da', 'das', 'de', 'do', 'dos', 'e', 'é', 'ela', 'ele',
            'eles', 'em', 'entre', 'era', 'essa', 'esse', 'esta', 'está', 'este', 'eu', 'foi', 'há', 'isso',
            'isto', 'já', 'lhe', 'mais', 'mas', 'me', 'mesmo', 'meu', 'minha', 'muito', 'na', 'nas', 'não',
            'nem', 'no', 'nos', 'nós', 'num', 'numa', 'o', 'os', 'ou', 'para', 'pela', 'pelo', 'por',
            'qual', 'que', 'se', 'sem', 'ser', 'seu', 'sua', 'são', 'também', 'te', 'tem', 'um', 'uma',
            'você', 'vocês',
        ],
        'nl' => [
            'aan', 'al', 'als', 'bij', 'dan', 'dat', 'de', 'die', 'dit', 'door', 'een', 'en', 'er', 'had',
            'heb', 'hebben', 'heeft', 'het', 'hier', 'hij', 'hoe', 'hun', 'ik', 'in', 'is', 'je', 'jij',
            'kan', 'maar', 'me', 'met', 'mij', 'mijn', 'na', 'naar', 'niet', 'nog', 'nu', 'of', 'om',
            'onder', 'ons', 'onze', 'ook', 'op', 'over', 'te', 'tot', 'u', 'uit', 'uw', 'van', 'veel',
            'voor', 'was', 'wat', 'we', 'wel', 'werd', 'wie', 'wij', 'wordt', 'zal', 'ze', 'zich', 'zij',
            'zijn', 'zo', 'zou',
        ],
    ];

    /** The site locale's two or three letter language code, lowercased. */
    public static function site_language(): string
    {
        $locale = strtolower((string) get_locale());
        $parts  = explode('_', $locale);
        return '' !== $parts[0] ? $parts[0] : 'en';
    }

    /** @return array<string,true> lowercased stopword => true, for fast lookups. */
    public static function for_language(string $language): array
    {
        $words = self::EN;
        if (isset(self::BY_LANGUAGE[ $language ])) {
            $words = array_merge($words, self::BY_LANGUAGE[ $language ]);
        }

        /**
         * Filter the stopwords keyword extraction drops.
         *
         * @param string[] $words    English plus the site language's built-in list, if any.
         * @param string   $language The site locale's language code (for example 'de').
         */
        $words = (array) apply_filters('wpmcp_keyword_stopwords', $words, $language);

        $out = [];
        foreach ($words as $word) {
            if (is_string($word) && '' !== $word) {
                $out[ mb_strtolower($word, 'UTF-8') ] = true;
            }
        }
        return $out;
    }
}
