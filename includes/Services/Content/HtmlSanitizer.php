<?php
/**
 * پاک‌سازی HTML خروجی مدل: تبدیل جدول به لیست و حذف فاصله‌های اضافه پیش از wpautop
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class HtmlSanitizer {

    const BLOCK_TAGS = 'p|h[1-6]|ul|ol|li|div|section|blockquote|table|thead|tbody|tfoot|tr';

    /**
     * پاک‌سازی کامل بدنه محتوا؛ جدول فقط برای محصولات به لیست تبدیل می‌شود
     */
    public static function clean($html, $kind) {
        $html = (string) $html;
        if ($kind === Settings::KIND_PRODUCT) $html = self::tables_to_lists($html);
        return self::tidy_whitespace($html);
    }

    /**
     * هر جدول به یک <ul> تبدیل می‌شود: ستون اول برچسب و بقیه ستون‌ها مقدار هستند
     */
    public static function tables_to_lists($html) {
        $html = preg_replace_callback('~<table\b[^>]*>(.*?)</table>~is', array(__CLASS__, 'table_to_list'), $html);
        // جدول‌های بسته‌نشده یا تگ‌های جدولی باقی‌مانده هم حذف می‌شوند.
        return preg_replace('~</?(?:table|thead|tbody|tfoot|tr|td|th|caption|colgroup|col)\b[^>]*>~i', '', $html);
    }

    private static function table_to_list($match) {
        $items = array();
        if (!preg_match_all('~<tr\b[^>]*>(.*?)</tr>~is', $match[1], $rows)) return '';
        foreach ($rows[1] as $row) {
            // ردیف‌هایی که فقط سرستون دارند، اطلاعاتی برای فهرست ندارند.
            if (!preg_match('~<td\b~i', $row) || !preg_match_all('~<t[dh]\b[^>]*>(.*?)</t[dh]>~is', $row, $cells)) continue;
            $values = array();
            foreach ($cells[1] as $cell) {
                $text = trim(html_entity_decode(wp_strip_all_tags($cell), ENT_QUOTES, 'UTF-8'));
                if ($text !== '') $values[] = $text;
            }
            if (!$values) continue;
            if (count($values) === 1) {
                $items[] = '<li>' . esc_html($values[0]) . '</li>';
                continue;
            }
            $label = array_shift($values);
            $items[] = '<li><strong>' . esc_html($label) . ':</strong> ' . esc_html(implode('، ', $values)) . '</li>';
        }
        return $items ? '<ul>' . implode('', $items) . '</ul>' : '';
    }

    /**
     * حذف خط‌های خالی بین تگ‌های بلاک، پاراگراف‌های خالی و <br> های اضافه تا wpautop تگ خالی نسازد
     */
    public static function tidy_whitespace($html) {
        $html = str_replace(array("\r\n", "\r"), "\n", (string) $html);
        $block = '<\/?(?:' . self::BLOCK_TAGS . ')\b[^>]*>';
        $html = preg_replace('~(' . $block . ')\s*\n\s*~i', '$1', $html);
        $html = preg_replace('~\s*\n\s*(' . $block . ')~i', '$1', $html);
        $html = preg_replace('~(</(?:p|h[1-6]|li|ul|ol|div|blockquote)>)\s+(?=<)~i', '$1', $html);

        $blank = '(?:\s|&nbsp;|&#160;|<br\s*/?>)*';
        for ($pass = 0; $pass < 2; $pass++) {
            $html = preg_replace('~<p\b[^>]*>' . $blank . '</p>~i', '', $html);
        }
        $html = preg_replace('~(<(?:p|li|h[1-6]|div)\b[^>]*>)' . $blank . '~i', '$1', $html);
        $html = preg_replace('~' . $blank . '(</(?:p|li|h[1-6]|div)>)~i', '$1', $html);
        $html = preg_replace('~(<br\s*/?>\s*){2,}~i', '<br>', $html);

        return trim($html);
    }
}
