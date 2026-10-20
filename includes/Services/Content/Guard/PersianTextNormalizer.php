<?php
/**
 * نرمال‌سازی چسبیدگی کلمات فارسی: فاصله در مرز تگ‌های <a>/<strong> و پاکسازی نیم‌فاصله و فاصله‌های خراب درون متن.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content\Guard;

if (!defined('ABSPATH')) {
    exit;
}

final class PersianTextNormalizer {

    const ZWNJ = "\xE2\x80\x8C"; // U+200C نیم‌فاصله
    const ZWSP = "\xE2\x80\x8B"; // U+200B فاصله بدون عرض
    const NBSP = "\xC2\xA0";     // U+00A0 فاصله غیرشکن

    /** تگ‌هایی که اگر بدون فاصله به کلمه قبل/بعد بچسبند باعث چسبیدگی دیداری می‌شوند */
    private static $boundary_tags = array('a', 'strong');

    /**
     * اجرای کامل نرمال‌سازی؛ ایمن برای اجرای چندباره (idempotent)
     */
    public static function normalize($html) {
        $html = (string) $html;
        if (trim($html) === '') return $html;
        $html = self::fix_tag_boundaries($html);
        $html = self::fix_text_spacing($html);
        return $html;
    }

    /**
     * قبل از تگ بازشونده و بعد از تگ بسته‌شونده، اگر فاصله‌ای نباشد یک فاصله استاندارد اضافه می‌شود
     * (مثال: خرید<a href="...">کفش</a>طبی -> خرید <a href="...">کفش</a> طبی)
     */
    private static function fix_tag_boundaries($html) {
        foreach (self::$boundary_tags as $tag) {
            $html = preg_replace('~([^\s>])(<' . $tag . '\b)~u', '$1 $2', $html);
            $html = preg_replace('~(</' . $tag . '>)([^\s<.,؛:!?])~u', '$1 $2', $html);
        }
        return $html;
    }

    /**
     * فقط متن بیرون از تگ‌ها پاکسازی می‌شود تا ویژگی‌های HTML دست‌نخورده بمانند
     */
    private static function fix_text_spacing($html) {
        $tokens = preg_split('~(<[^>]+>)~u', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($tokens as $i => $token) {
            if ($token[0] === '<') continue;
            $tokens[$i] = self::clean_text($token);
        }
        return implode('', $tokens);
    }

    /**
     * نیم‌فاصله/فاصله بدون عرض چسبیده به فاصله معمولی یعنی نیم‌فاصله خراب است: به یک فاصله ساده تبدیل می‌شود.
     * فاصله غیرشکن به فاصله معمولی و فاصله‌های تکراری به یک فاصله تبدیل می‌شوند.
     */
    private static function clean_text($text) {
        $text = preg_replace('~[ \t]*' . self::ZWNJ . '[ \t]+~u', ' ', $text);
        $text = preg_replace('~[ \t]+' . self::ZWNJ . '[ \t]*~u', ' ', $text);
        $text = str_replace(self::ZWSP, '', $text);
        $text = str_replace(self::NBSP, ' ', $text);
        $text = preg_replace('~[ \t]{2,}~u', ' ', $text);
        return $text;
    }
}
