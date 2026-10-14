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
        return '<div class="' . self::WRAPPER_CLASS . '">' . $html . '</div>';
    }

    public static function unwrap($html) {
        $html = trim((string) $html);
        if (preg_match('~^<div\s+class="' . self::WRAPPER_CLASS . '">(.*)</div>$~is', $html, $m)) return trim($m[1]);
        return $html;
    }
}
