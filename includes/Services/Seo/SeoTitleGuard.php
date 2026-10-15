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
     * متغیرهای قالب یوست/رنک‌مث (%sep%، %sitedesc%، %%title%% …) را از رشته حذف می‌کند تا به عنوان نچسبند
     */
    public static function strip_variables($text) {
        $text = preg_replace('/%%?[a-z_0-9]+%%?/i', '', (string) $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * عنوان را الزاماً به قالب «[کلیدواژه کانونی دقیق] | [مزیت + یک ایموجی] | [نام کوتاه برند]» می‌رساند.
     * اگر خروجی AI سه‌بخشی نبود، بخش اول دقیقاً کلیدواژه کانونی نبود، ایموجی جای اشتباه بود یا بخش آخر دقیقاً برند نبود
     * (مثلاً شعار سایت چسبیده بود) عنوان از نو ساخته می‌شود: sprintf('%s | %s %s | %s', focus, feature, emoji, brand).
     * سقف ۶۰ کاراکتر: ابتدا کلمات انتهای بخش مزیت حذف می‌شوند، سپس (فقط در آخرین راه) کلمات انتهای بخش اول؛ برند هرگز.
     */
    public static function fit($title, $brand, $hint = '', $focus = '', $tagline = '') {
        $title = self::strip_variables(strip_tags((string) $title));
        $brand = trim(preg_replace('/\s+/u', ' ', (string) $brand));
        $focus = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $focus)));
        if ($title === '' && $focus === '') return '';

        $raw_parts = array_values(array_filter(array_map('trim', preg_split('/\s*[|｜]\s*/u', $title)), 'strlen'));
        $compliant = self::is_compliant($raw_parts, $brand, $focus);

        $plain = preg_replace(self::EMOJI_PATTERN, '', $title);
        $parts = array();
        foreach (preg_split('/\s*[|｜]\s*/u', $plain) as $part) {
            $part = trim(preg_replace('/\s+/u', ' ', $part));
            if ($part !== '') $parts[] = $part;
        }
        if (!$parts && $focus === '') return $brand;
        $first = $focus !== '' ? $focus : (isset($parts[0]) ? $parts[0] : '');
        $emoji = self::emoji_for($hint . ' ' . $first);

        if ($compliant && $focus !== '') {
            $benefit = $parts[1];
        } else {
            $benefit = self::feature_from(array_slice($parts, 1), $first, $brand, $tagline);
            if ($focus === '' && count($parts) < 2) $benefit = '';
        }

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

    /**
     * مزیت بخش دوم از میان بخش‌های AI: برند و شعار سایت کنار گذاشته می‌شوند، کلمات عمومی فروشگاهی حذف و حداکثر ۴ کلمه می‌ماند؛
     * اگر چیزی نماند مزیت پیش‌فرض (Settings::seo_title_feature) می‌آید.
     */
    private static function feature_from(array $candidates, $first, $brand, $tagline) {
        $stop = array_flip(array_map(array(__CLASS__, 'norm'), Settings::SEO_TITLE_FEATURE_STOPWORDS));
        $skip = array(self::flat($brand), self::flat($first), self::flat($tagline));
        foreach ($candidates as $candidate) {
            $flat = self::flat($candidate);
            if ($flat === '' || in_array($flat, $skip, true)) continue;
            if (self::flat_contains($candidate, $first)) continue;
            $words = array_values(array_filter(preg_split('/\s+/u', $candidate), function ($w) use ($stop) { return !isset($stop[self::norm($w)]); }));
            if ($words) return implode(' ', array_slice($words, 0, 4));
        }
        return Settings::seo_title_feature();
    }

    private static function is_compliant(array $parts, $brand, $focus) {
        if (count($parts) !== 3) return false;
        if (preg_match(self::EMOJI_PATTERN, $parts[0]) || preg_match(self::EMOJI_PATTERN, $parts[2])) return false;
        if ($focus !== '' && self::flat($parts[0]) !== self::flat($focus)) return false;
        if ($brand !== '' && $parts[2] !== $brand) return false;
        preg_match_all(self::EMOJI_PATTERN, $parts[1], $in_benefit);
        preg_match_all(self::EMOJI_PATTERN, implode('|', $parts), $all);
        if (count($in_benefit[0]) !== 1 || count($all[0]) !== 1) return false;
        return (bool) preg_match(substr(self::EMOJI_PATTERN, 0, -2) . '$/u', $parts[1]) && trim(preg_replace(self::EMOJI_PATTERN, '', $parts[1])) !== '';
    }

    private static function flat($text) {
        return str_replace(' ', '', self::norm(trim(preg_replace('/\s+/u', ' ', (string) $text))));
    }

    private static function flat_contains($haystack, $needle) {
        $n = self::flat($needle);
        return $n !== '' && mb_strpos(self::flat($haystack), $n) !== false;
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
