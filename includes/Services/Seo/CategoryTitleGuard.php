<?php
/**
 * SEO title guard for product categories (pure): «[exact category name] | [purchase hook + emoji] | [brand]»
 * within 60 characters. The focus keyphrase (the exact category name) always opens the title and the single emoji sits
 * right before a « | » separator or at the very end - never first. The hook always keeps the purchase words
 * «خرید» and «قیمت»; when space is short the hook is shortened first, then the brand is dropped; the name is never cut.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Seo;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class CategoryTitleGuard {

    public static function fit($title, $focus, $brand = '', $hint = '') {
        $focus = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $focus)));
        if ($focus === '') return '';
        $title = SeoTitleGuard::strip_variables(strip_tags((string) $title));
        $brand = trim(preg_replace('/\s+/u', ' ', (string) $brand));

        preg_match(SeoTitleGuard::EMOJI_PATTERN, $title, $found);
        $emoji = $found ? $found[0] : self::emoji_for($hint . ' ' . $focus);
        $hook = self::hook_from($title, $focus, $brand);

        $max = Settings::SEO_TITLE_MAX_CHARS;
        foreach ($brand === '' ? array('') : array($brand, '') as $use_brand) {
            foreach (self::hook_ladder($hook) as $candidate) {
                $built = SeoTitleGuard::build_article($focus, $candidate, $emoji, $use_brand);
                if (mb_strlen($built) <= $max) return $built;
            }
        }
        return SeoTitleGuard::build_article($focus, '', $emoji, '');
    }

    /** True when the title opens with the exact focus keyphrase, carries purchase words and one well-placed emoji. */
    public static function is_valid($title, $focus) {
        $title = (string) $title;
        return mb_strpos($title, trim((string) $focus)) === 0
            && mb_strlen($title) <= Settings::SEO_TITLE_MAX_CHARS
            && SeoTitleGuard::emoji_position_ok($title)
            && mb_strpos($title, 'خرید') !== false;
    }

    /** The model's own hook when it already speaks purchase language, otherwise the standard one. */
    private static function hook_from($title, $focus, $brand) {
        $plain = trim(preg_replace(SeoTitleGuard::EMOJI_PATTERN, '', $title));
        foreach (preg_split('/\s*[|｜–]\s*/u', $plain) as $part) {
            $part = trim(preg_replace('/\s+/u', ' ', $part));
            if ($part === '' || $part === $brand) continue;
            // the name itself never belongs to the hook: it is already the first part of the title
            $part = trim(preg_replace('/\s+/u', ' ', str_replace($focus, '', $part)));
            $part = implode(' ', array_slice(preg_split('/\s+/u', $part), 0, 6));
            if (mb_strpos($part, 'خرید') !== false && mb_strpos($part, 'قیمت') !== false) return $part;
        }
        return Settings::CATEGORY_TITLE_HOOK;
    }

    /** Hook candidates from longest to shortest; every one keeps «خرید» and «قیمت». */
    private static function hook_ladder($hook) {
        $words = preg_split('/\s+/u', $hook);
        $ladder = array();
        for ($n = count($words); $n >= 3; $n--) {
            $candidate = implode(' ', array_slice($words, 0, $n));
            if (mb_strpos($candidate, 'خرید') !== false && mb_strpos($candidate, 'قیمت') !== false) $ladder[] = $candidate;
        }
        $ladder[] = Settings::CATEGORY_TITLE_HOOK_MIN;
        return array_values(array_unique($ladder));
    }

    private static function emoji_for($text) {
        $text = str_replace("\u{200c}", '', (string) $text);
        foreach (Settings::SEO_TITLE_EMOJIS as $entry) {
            foreach ($entry[1] as $needle) {
                if (mb_strpos($text, str_replace("\u{200c}", '', $needle)) !== false) return $entry[0];
            }
        }
        return Settings::CATEGORY_TITLE_EMOJI_DEFAULT;
    }
}
