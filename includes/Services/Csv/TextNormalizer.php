<?php
/**
 * Persian-aware text/slug normalization used when matching sheet rows to products.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Csv;

if (!defined('ABSPATH')) {
    exit;
}

final class TextNormalizer {

    /**
     * یکسان‌سازی متن برای مقایسه: ی/ک عربی، نیم‌فاصله، ارقام، ایموجی و فاصله‌های اضافه
     */
    public static function normalize($text) {
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8');
        $text = strtr($text, array(
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه', 'ـ' => '',
            "\u{200c}" => ' ', "\u{200f}" => '', "\u{200e}" => '',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ));
        $text = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return mb_strtolower(trim($text));
    }

    public static function normalize_slug($slug) {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $slug)) {
            $slug = (string) wp_parse_url($slug, PHP_URL_PATH);
        }
        $parts = array_filter(explode('/', trim($slug, '/')), 'strlen');
        $slug  = $parts ? end($parts) : '';
        return mb_strtolower(trim(rawurldecode($slug)));
    }
}
