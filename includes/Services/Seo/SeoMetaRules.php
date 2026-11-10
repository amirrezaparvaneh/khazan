<?php
/**
 * SEO title and meta description rules of the project (pure functions):
 *   title: 50-60 characters, starts with the focus keyword, ends with « | brand », no emoji, no «ارسال رایگان»;
 *   meta: 140-155 characters, contains the focus keyword once, no emoji.
 * A model title/meta that already satisfies the rules is kept (so every page stays unique); otherwise the title is
 * rebuilt from the focus keyword with a ladder of commercial tails and the meta is only trimmed, never padded with
 * canned text - a meta that is still too short is reported by ContentAudit and repaired by the model.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Seo;

use Khazan\Services\Content\ContentAudit;
use Khazan\Services\Content\SeoStandards;

if (!defined('ABSPATH')) {
    exit;
}

final class SeoMetaRules {

    /** Tails from the most to the least descriptive; the first one that fits the range wins. */
    const TITLE_TAILS = array(
        'خرید انواع مدل، قیمت و اقساط',
        'خرید انواع مدل و اقساط',
        'خرید انواع مدل، قیمت',
        'خرید و قیمت انواع مدل',
        'خرید و قیمت',
        'خرید اقساطی',
    );

    const EMOJI = '/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u';

    public static function clean($text) {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8'))));
        return trim(preg_replace(self::EMOJI, '', $text));
    }

    public static function title_ok($title, $focus, $brand) {
        $std = SeoStandards::all();
        $title = (string) $title;
        $len = mb_strlen($title);
        if ($len < $std['title_min'] || $len > $std['title_max']) return false;
        if ($brand !== '' && mb_substr($title, -mb_strlen(' | ' . $brand)) !== ' | ' . $brand) return false;
        if (preg_match(self::EMOJI, $title)) return false;
        foreach ((array) $std['title_forbidden'] as $bad) if ($bad !== '' && mb_strpos($title, $bad) !== false) return false;
        $f = ContentAudit::normalize($focus);
        return $f !== '' && mb_strpos(ContentAudit::normalize($title), $f) === 0;
    }

    /** @return string title within the rules (the best effort when the focus keyword itself is very long) */
    public static function fit_title($model_title, $focus, $brand) {
        $focus = self::clean($focus);
        $brand = self::clean($brand);
        if ($focus === '') return '';
        $model_title = self::clean($model_title);
        if (self::title_ok($model_title, $focus, $brand)) return $model_title;

        $std = SeoStandards::all();
        $suffix = $brand === '' ? '' : ' | ' . $brand;
        $best = '';
        foreach (self::TITLE_TAILS as $tail) {
            $candidate = $focus . '؛ ' . $tail . $suffix;
            $len = mb_strlen($candidate);
            if ($len >= $std['title_min'] && $len <= $std['title_max']) return $candidate;
            if ($len <= $std['title_max'] && mb_strlen($best) < $len) $best = $candidate;
        }
        return $best !== '' ? $best : $focus . $suffix;
    }

    public static function meta_ok($meta, $focus) {
        $std = SeoStandards::all();
        $len = mb_strlen((string) $meta);
        return $len >= $std['meta_min'] && $len <= $std['meta_max'] && !preg_match(self::EMOJI, (string) $meta) && ContentAudit::phrase_count($meta, $focus) >= 1;
    }

    /** Trims a too-long meta at a word boundary (never adds text); too-short metas are returned as they are. */
    public static function fit_meta($meta, $focus) {
        $std = SeoStandards::all();
        $meta = self::clean($meta);
        if (mb_strlen($meta) <= $std['meta_max']) return $meta;
        $cut = mb_substr($meta, 0, $std['meta_max']);
        $space = mb_strrpos($cut, ' ');
        $cut = $space !== false && $space > $std['meta_min'] ? mb_substr($cut, 0, $space) : $cut;
        return rtrim($cut, " ،,؛;:-") . '.';
    }
}
