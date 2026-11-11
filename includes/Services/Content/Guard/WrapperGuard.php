<?php
/**
 * الغلاف wrapper دور توضیحات محصول/دسته (افزودن و برداشتن div اصلی)
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content\Guard;

if (!defined('ABSPATH')) {
    exit;
}

final class WrapperGuard {

    const WRAPPER_CLASS = 'aka-desc-wrapper';

    /**
     * پوشش کل محتوا با کلاس اختصاصی سایت؛ اجرای دوباره روی محتوای پوشش‌داده‌شده تاثیری ندارد
     */
    public static function wrap($html) {
        $html = self::unwrap($html);
        if ($html === '') return '';
        $html = PersianTextNormalizer::normalize($html);
        $class = self::wrapper_class();
        return $class === '' ? $html : '<div class="' . $class . '">' . $html . '</div>';
    }

    public static function unwrap($html) {
        $html = trim((string) $html);
        $classes = array_unique(array_filter(array(self::wrapper_class(), self::WRAPPER_CLASS)));
        foreach ($classes as $class) {
            if (preg_match('~^<div\s+class="' . preg_quote($class, '~') . '">(.*)</div>$~is', $html, $m)) return trim($m[1]);
        }
        return $html;
    }

    /**
     * Class of the wrapper div: the site's own class from the settings; unset keeps the default,
     * an empty value means "no wrapper" (sites without a matching CSS class).
     */
    public static function wrapper_class() {
        $raw = function_exists('get_option') ? get_option(\Khazan\Core\Settings::OPT_CONTENT_WRAPPER_CLASS, false) : false;
        if ($raw === false) return self::WRAPPER_CLASS;
        return self::clean_class($raw);
    }

    public static function clean_class($value) {
        $parts = preg_split('/\s+/', trim((string) $value));
        $out = array();
        foreach ($parts as $part) {
            $part = preg_replace('/[^A-Za-z0-9_-]/', '', $part);
            if ($part !== '') $out[] = $part;
        }
        return implode(' ', $out);
    }
}
