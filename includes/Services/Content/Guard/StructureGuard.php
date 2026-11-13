<?php
/**
 * Deterministic structure fixes for stored product copy so the audit passes without an AI call:
 * the keyphrase in the first 100 words and in one H2 (never more than the standard allows), and no section longer
 * than the standard without a subheading.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content\Guard;

use Khazan\Services\Content\ContentAudit;
use Khazan\Services\Content\SeoStandards;
use Khazan\Services\Seo\YoastRules;

if (!defined('ABSPATH')) {
    exit;
}

final class StructureGuard {

    const L = '(?<![\p{L}\x{200c}])';          // start of a word
    const R = '(?![\p{L}\x{200c}])';           // end of a word
    /** A bare product-type word may become the full keyphrase only before one of these (the sentence stays grammatical). */
    const AFTER = '(?=\s*(?:[،.؟!:؛)]|$|(?:را|است|هست|می|هم|نیز|برای|با|در|از|به|که|و|یا|بیشتر|کمتر)(?![\p{L}\x{200c}])))';

    public static function apply($html, $keyword) {
        $keyword = trim((string) $keyword);
        if ($keyword === '') return $html;
        $html = self::split_long_sections($html, $keyword);
        $html = self::keyphrase_in_first_words($html, $keyword);
        $html = self::keyphrase_in_h2($html, $keyword);
        return self::limit_h2_keyphrase($html, $keyword);
    }

    private static function type_word($keyword) {
        $w = preg_split('/\s+/u', trim($keyword), -1, PREG_SPLIT_NO_EMPTY);
        return $w ? $w[0] : '';
    }

    /** Tokens of the html: tags and text apart. */
    private static function tokens($html) {
        return preg_split('~(<[^>]+>)~', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    }

    /** Positions of a bare type word / «این X» in a text token that may be replaced by the keyphrase. */
    public static function spots($text, $keyword) {
        $type = self::type_word($keyword);
        $out = array();
        if ($type === '') return $out;
        $t = preg_quote($type, '~');
        if (preg_match_all('~' . self::L . 'این\s+(?:' . $t . '|محصول|مدل|کالا)' . self::R . '~u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $x) $out[] = array($x[1], strlen($x[0]), 0);
        }
        if (preg_match_all('~' . self::L . $t . self::R . self::AFTER . '~u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $x) {
                if (substr($text, $x[1], strlen($keyword)) === $keyword) continue;                  // already the keyphrase
                if (preg_match('~این\s*$~u', substr($text, max(0, $x[1] - 12), min(12, $x[1])))) continue;   // «این X» is the other kind of spot
                $out[] = array($x[1], strlen($x[0]), 1);
            }
        }
        usort($out, function ($a, $b) { return $a[2] === $b[2] ? $a[0] - $b[0] : $a[2] - $b[2]; });
        return $out;
    }

    /** The keyphrase must stand in the first N words of the text (the standard's limit). */
    public static function keyphrase_in_first_words($html, $keyword) {
        $limit = (int) SeoStandards::get('keyword_in_first');
        if (ContentAudit::phrase_in_first($html, $keyword, $limit)) return $html;
        $tokens = self::tokens($html);
        $skip = 0; $words = 0;
        foreach ($tokens as $i => $token) {
            if ($token[0] === '<') {
                if (preg_match('~^<(?:a|h[1-6])\b~i', $token)) $skip++;
                elseif (preg_match('~^</(?:a|h[1-6])>~i', $token)) $skip = max(0, $skip - 1);
                continue;
            }
            $count = count(preg_split('/\s+/u', trim($token), -1, PREG_SPLIT_NO_EMPTY));
            if ($skip === 0) {
                foreach (self::spots($token, $keyword) as $s) {
                    $try = $tokens;
                    $try[$i] = substr($token, 0, $s[0]) . $keyword . substr($token, $s[0] + $s[1]);
                    $new = implode('', $try);
                    if (ContentAudit::phrase_in_first($new, $keyword, $limit)) return $new;
                }
            }
            $words += $count;
            if ($words > $limit) break;
        }
        return $html;
    }

    private static function is_faq($text) {
        return (bool) preg_match('~سوال|سؤال|پرسش|متداول~u', $text);
    }

    /** At least one H2 carries the keyphrase: the first plain H2 (not the FAQ) gets it. */
    public static function keyphrase_in_h2($html, $keyword) {
        $has = false;
        if (preg_match_all('~<h2\b[^>]*>(.*?)</h2>~is', (string) $html, $m)) {
            foreach ($m[1] as $h) if (ContentAudit::phrase_count($h, $keyword) > 0) $has = true;
        }
        if ($has) return $html;
        $done = false;
        return preg_replace_callback('~(<h2\b[^>]*>)([^<]*)(</h2>)~i', function ($m) use (&$done, $keyword) {
            $text = trim($m[2]);
            if ($done || $text === '' || self::is_faq($text)) return $m[0];
            $done = true;
            $type = self::type_word($keyword);
            $new = $type !== '' ? preg_replace('~' . self::L . preg_quote($type, '~') . self::R . '~u', $keyword, $text, 1, $n) : $text;
            if (empty($n)) $new = $text . ' ' . $keyword;
            return $m[1] . $new . $m[3];
        }, (string) $html);
    }

    /** More H2 with the keyphrase than the standard allows: the later ones name the product type instead. */
    public static function limit_h2_keyphrase($html, $keyword) {
        $max = (int) SeoStandards::get('h2_keyword_max');
        $type = self::type_word($keyword);
        if ($type === '') return $html;
        $seen = 0;
        return preg_replace_callback('~(<h2\b[^>]*>)([^<]*)(</h2>)~i', function ($m) use (&$seen, $max, $keyword, $type) {
            if (ContentAudit::phrase_count($m[2], $keyword) < 1) return $m[0];
            if (++$seen <= $max) return $m[0];
            return $m[1] . trim(str_replace($keyword, $type, $m[2])) . $m[3];
        }, (string) $html);
    }

    /** A section longer than the standard gets a subheading in front of the paragraph that crosses its middle. */
    public static function split_long_sections($html, $keyword) {
        $max = (int) SeoStandards::get('section_words_max');
        $parts = preg_split('~(<h[1-6]\b[^>]*>.*?</h[1-6]>)~is', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!$parts) return $html;
        $type = self::type_word($keyword);
        $generic = array('نکته‌های تکمیلی', 'جزئیات بیشتر', 'نکته‌های کاربردی');
        $made = 0; $changed = false;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1 || ContentAudit::word_count($part) <= $max) continue;
            $blocks = preg_split('~(<p\b[^>]*>.*?</p>)~is', $part, -1, PREG_SPLIT_DELIM_CAPTURE);
            $total = ContentAudit::word_count($part);
            $run = 0; $at = null;
            foreach ($blocks as $k => $block) {
                if ($k % 2 === 1 && $run >= $total / 2 - 20 && $total - $run >= 50 && $run >= 50) { $at = $k; break; }
                $run += ContentAudit::word_count($block);
            }
            if ($at === null) continue;
            $text = $generic[$made % count($generic)] . ($type !== '' ? ' ' . $type : '');
            $heading = '<h3>' . $text . '</h3>';
            // a keyphrase heading when the headings would otherwise fall under Yoast's share
            $stats = YoastRules::subheadings(implode('', $parts), $keyword);
            if ($stats['count'] + 1 > 0 && $stats['matches'] < (int) ceil(($stats['count'] + 1) * YoastRules::SUBHEADING_MIN_SHARE)) $heading = '<h3>' . $keyword . '</h3>';
            $blocks[$at] = $heading . $blocks[$at];
            $parts[$i] = implode('', $blocks);
            $made++; $changed = true;
        }
        return $changed ? implode('', $parts) : $html;
    }
}
