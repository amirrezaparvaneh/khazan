<?php
/**
 * Deterministic fixes so generated copy passes the Yoast checks without another AI call:
 * keyphrase in enough subheadings, keyphrase count inside Yoast's recommended range, fewer "passive" sentences.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content\Guard;

use Khazan\Services\Seo\YoastRules;

if (!defined('ABSPATH')) {
    exit;
}

final class YoastGuard {

    /** Words that never form a compound with the following verb (so they are not glued to it). */
    const NO_COMPOUND = array('برای', 'از', 'به', 'با', 'در', 'تا', 'و', 'که', 'هنگام', 'موقع', 'بدون', 'قبل', 'بعد', 'این', 'آن', 'را', 'یا', 'هم', 'هر', 'اگر', 'ولی', 'اما', 'پس', 'نیز', 'است', 'هست', 'بود', 'شما', 'ما', 'آن‌ها', 'خود', 'باید', 'می‌توان', 'می‌توانید', 'بتوانید');

    public static function apply($html, $keyword) {
        $keyword = trim((string) $keyword);
        if ($keyword === '') return self::soften_passive($html, $keyword);
        $html = self::ensure_subheadings($html, $keyword);
        $html = self::cap_keyphrase($html, $keyword);
        return self::soften_passive($html, $keyword);
    }

    /** The product type of a keyphrase: its first word («بوت چرم زنانه برشکا» -> «بوت»). */
    private static function type_word($keyword) {
        $words = preg_split('/\s+/u', trim($keyword), -1, PREG_SPLIT_NO_EMPTY);
        return $words ? $words[0] : '';
    }

    /**
     * Yoast wants 30-75 % of the H2/H3 to reflect the keyphrase. While too few do, a FAQ question that says
     * «این بوت / این محصول / این مدل» names the product instead.
     */
    public static function ensure_subheadings($html, $keyword) {
        $type = self::type_word($keyword);
        $stats = YoastRules::subheadings($html, $keyword);
        $need = $stats['min'] - $stats['matches'];
        if ($need <= 0 || $type === '') return $html;
        $pattern = '~(?<![\p{L}\x{200c}])این\s+(?:' . preg_quote($type, '~') . '|محصول|مدل|کالا)(?![\p{L}\x{200c}])~u';
        return preg_replace_callback('~(<h3\b[^>]*>)(.*?)(</h3>)~is', function ($m) use (&$need, $keyword, $pattern) {
            if ($need <= 0 || YoastRules::reflects($m[2], $keyword) || strpos($m[2], '<') !== false) return $m[0];
            $new = preg_replace($pattern, $keyword, $m[2], 1, $done);
            if (!$done) return $m[0];
            $need--;
            return $m[1] . $new . $m[3];
        }, $html);
    }

    /**
     * Keeps the keyphrase count inside Yoast's recommended maximum: surplus mentions in body paragraphs and list
     * items (never headings, links, the first or the last mention) become «این بوت» / «این مدل», taken evenly from the text.
     */
    public static function cap_keyphrase($html, $keyword, $max = null) {
        $type = self::type_word($keyword);
        if ($type === '') return $html;
        if ($max === null) {
            list(, $max) = YoastRules::keyphrase_bounds(YoastRules::word_count($html), $keyword);
        }
        $excess = YoastRules::keyphrase_count($html, $keyword) - (int) $max;
        if ($excess <= 0) return $html;

        $tokens = preg_split('~(<[^>]+>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $spots = array();   // [token index, occurrence index inside the token]
        $anchor = 0; $heading = 0; $total = 0;
        foreach ($tokens as $i => $token) {
            if ($token[0] === '<') {
                if (preg_match('~^<a\b~i', $token)) $anchor++;
                elseif (preg_match('~^</a>~i', $token)) $anchor = max(0, $anchor - 1);
                elseif (preg_match('~^<h[1-6]\b~i', $token)) $heading++;
                elseif (preg_match('~^</h[1-6]>~i', $token)) $heading = max(0, $heading - 1);
                continue;
            }
            $n = substr_count($token, $keyword);
            for ($k = 0; $k < $n; $k++) {
                $total++;
                if ($anchor === 0 && $heading === 0) $spots[] = array($i, $k, $total);
            }
        }
        // the first and the last mention of the whole text stay (introduction and closing)
        $spots = array_values(array_filter($spots, function ($s) use ($total) { return $s[2] !== 1 && $s[2] !== $total; }));
        if (!$spots) return $html;
        $take = min($excess, count($spots));
        $chosen = array();
        for ($j = 0; $j < $take; $j++) {
            $spot = $spots[(int) floor(($j + 0.5) * count($spots) / $take)];
            $chosen[$spot[0]][$spot[1]] = true;
        }
        $alt = 0;
        foreach ($chosen as $i => $which) {
            $pieces = explode($keyword, $tokens[$i]);
            $out = $pieces[0];
            for ($k = 1; $k < count($pieces); $k++) {
                if (isset($which[$k - 1])) {
                    $word = $alt++ % 2 === 0 ? $type : 'مدل';
                    $out .= (preg_match('/(?:^|\s)این\s*$/u', $out) ? '' : 'این ') . $word;
                } else {
                    $out .= $keyword;
                }
                $out .= $pieces[$k];
            }
            $tokens[$i] = $out;
        }
        return implode('', $tokens);
    }

    /**
     * Yoast calls a Persian sentence passive when one of its listed words stands alone in it. Outside links and
     * headings: real rewrites where they are safe («دیده می‌شود» -> «به چشم می‌آید», «خریدن» -> «خرید») and the standard
     * half-space spelling for compounds («طراحی شده» -> «طراحی‌شده», «قدم زدن» -> «قدم‌زدن»).
     */
    public static function soften_passive($html, $keyword = '') {
        $tokens = preg_split('~(<[^>]+>)~', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $skip = 0;
        foreach ($tokens as $i => $token) {
            if ($token[0] === '<') {
                if (preg_match('~^<(?:a|h[1-6])\b~i', $token)) $skip++;
                elseif (preg_match('~^</(?:a|h[1-6])>~i', $token)) $skip = max(0, $skip - 1);
                continue;
            }
            if ($skip > 0) continue;
            $tokens[$i] = self::soften_text($token);
        }
        return implode('', $tokens);
    }

    private static function soften_text($text) {
        $b = '(?![\p{L}\p{N}\x{200c}])';          // end of a word
        $a = '(?<![\p{L}\p{N}\x{200c}])';         // start of a word
        $swap = array(
            "~{$a}دیده\s+می‌شوند{$b}~u"   => 'به چشم می‌آیند',
            "~{$a}دیده\s+می‌شود{$b}~u"    => 'به چشم می‌آید',
            "~{$a}دیده\s+نمی‌شود{$b}~u"   => 'به چشم نمی‌آید',
            "~{$a}دیده\s+شوند{$b}~u"      => 'به چشم بیایند',
            "~{$a}دیده\s+شود{$b}~u"       => 'به چشم بیاید',
            "~{$a}خریدن{$b}~u"            => 'خرید',
            "~{$a}پوشیدن{$b}~u"           => 'استفاده',
            "~{$a}آماده(?=\s+(?:است|هست|نیست|باش|می‌|شد|کرد|کن|بود))~u" => 'مهیا',
            "~{$a}آماده\s+(?=[\p{L}])~u"  => 'مهیای ',
        );
        $text = preg_replace(array_keys($swap), array_values($swap), $text);

        // «X شده» -> «X‌شده»: the compound spelling keeps «شده» from standing alone
        $text = preg_replace_callback("~{$a}([\p{L}\x{200c}]+)\s+شده{$b}~u", function ($m) {
            return in_array($m[1], self::NO_COMPOUND, true) ? $m[0] : $m[1] . "\u{200c}شده";
        }, $text);

        // noun + listed infinitive («قدم زدن», «واکس زدن», «تصمیم گرفتن») is one compound verb
        $infinitives = array_filter(YoastRules::PARTICIPLES, function ($w) { return (bool) preg_match('/(?:دن|تن)$/u', $w); });
        $alt = implode('|', array_map(function ($w) { return preg_quote($w, '~'); }, $infinitives));
        $text = preg_replace_callback("~{$a}([\p{L}\x{200c}]+)\s+({$alt}){$b}~u", function ($m) {
            return in_array($m[1], self::NO_COMPOUND, true) ? $m[0] : $m[1] . "\u{200c}" . $m[2];
        }, $text);
        return $text;
    }
}
