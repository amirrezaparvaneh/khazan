<?php
/**
 * SEO title guard (pure): shapes a product SEO title into
 * «[keyword + model] | [benefit + emoji] | [brand]» within 60 characters.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Seo;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class SeoTitleGuard {

    const EMOJI_PATTERN = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B50}\x{2B55}]\x{FE0F}?/u';

    /**
     * ایموجی مناسب نوع کالا از روی متن (عنوان، دسته‌بندی)
     */
    public static function emoji_for($text) {
        $text = self::norm($text);
        foreach (Settings::SEO_TITLE_EMOJIS as $entry) {
            foreach ($entry[1] as $needle) {
                if (mb_strpos($text, self::norm($needle)) !== false) return $entry[0];
            }
        }
        return Settings::SEO_TITLE_DEFAULT_EMOJI;
    }

    /**
     * عنوان را به قالب سه‌بخشی می‌رساند: برند در انتها، دقیقاً یک ایموجی در پایان بخش دوم، حداکثر ۶۰ کاراکتر.
     * برای رعایت سقف، ابتدا کلمات انتهای بخش مزیت و سپس کلمات انتهایی بخش اول (نه دو کلمه نخست) حذف می‌شوند؛ برند هرگز حذف نمی‌شود.
     */
    public static function fit($title, $brand, $hint = '') {
        $title = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $title)));
        $brand = trim(preg_replace('/\s+/u', ' ', (string) $brand));
        if ($title === '') return '';

        preg_match_all(self::EMOJI_PATTERN, $title, $found);
        $emoji = !empty($found[0]) ? $found[0][0] : '';
        $title = trim(preg_replace('/\s+/u', ' ', preg_replace(self::EMOJI_PATTERN, '', $title)));

        $parts = array();
        foreach (preg_split('/\s*[|｜]\s*/u', $title) as $part) {
            $part = trim($part);
            if ($part === '' || ($brand !== '' && self::norm($part) === self::norm($brand))) continue;
            $parts[] = $part;
        }
        if (!$parts) return $brand;
        $first = array_shift($parts);
        $benefit = implode(' ', $parts);
        if ($emoji === '') $emoji = self::emoji_for($hint . ' ' . $first);

        $max = Settings::SEO_TITLE_MAX_CHARS;
        $first_words = preg_split('/\s+/u', $first);
        $benefit_words = $benefit === '' ? array() : preg_split('/\s+/u', $benefit);
        while (true) {
            $built = self::build(implode(' ', $first_words), implode(' ', $benefit_words), $emoji, $brand);
            if (mb_strlen($built) <= $max) return $built;
            if ($benefit_words) { array_pop($benefit_words); continue; }
            if (count($first_words) > 2) { array_pop($first_words); continue; }
            return $built;
        }
    }

    public static function build($first, $benefit, $emoji, $brand) {
        $second = trim($benefit . ' ' . $emoji);
        $parts = array($first);
        // بدون مزیت، ایموجی کنار بخش اول می‌نشیند تا بخش دوم خالی نماند
        if ($benefit === '') { $parts = array(trim($first . ' ' . $emoji)); } else { $parts[] = $second; }
        if ($brand !== '') $parts[] = $brand;
        return implode(Settings::SEO_TITLE_SEPARATOR, $parts);
    }

    public static function is_valid($title, $brand) {
        $n = mb_strlen((string) $title);
        preg_match_all(self::EMOJI_PATTERN, (string) $title, $found);
        return $n <= Settings::SEO_TITLE_MAX_CHARS && count($found[0]) === 1
            && ($brand === '' || mb_substr((string) $title, -mb_strlen($brand)) === $brand);
    }

    private static function norm($text) {
        return mb_strtolower(str_replace(array("\u{200c}", 'ي', 'ك'), array('', 'ی', 'ک'), (string) $text));
    }
}
