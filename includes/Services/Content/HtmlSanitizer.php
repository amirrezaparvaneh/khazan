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
     * پاک‌سازی کامل بدنه محتوا (جدول مشخصات مجاز است؛ tables_to_lists برای فراخوانی دستی می‌ماند)
     */
    public static function clean($html, $kind) {
        $html = (string) $html;
        if ($kind === Settings::KIND_PRODUCT) $html = self::drop_faq_terms(self::polish_product(self::drop_unknowns($html)));
        $html = self::style_tables($html);
        return self::tidy_whitespace($html);
    }

    /**
     * Tables get visible borders without any theme CSS: inline style on table / th / td (only where no style exists yet).
     */
    public static function style_tables($html) {
        $cell = 'border:1px solid #d9d9d9;padding:8px 12px;text-align:right;vertical-align:top;';
        $map = array(
            'table' => 'width:100%;border-collapse:collapse;border:1px solid #d9d9d9;margin:12px 0;font-size:14px;',
            'th' => $cell . 'background:#f5f5f5;font-weight:700;',
            'td' => $cell,
        );
        foreach ($map as $tag => $style) {
            $html = preg_replace_callback('~<' . $tag . '\b([^>]*)>~i', function ($m) use ($tag, $style) {
                if (stripos($m[1], 'style=') !== false) return $m[0];
                return '<' . $tag . $m[1] . ' style="' . $style . '">';
            }, $html);
        }
        return $html;
    }

    /**
     * Product copy: machine-sounding material phrases ("چرم تبریز با پایه چرم گاوی"), a space before the Persian comma,
     * and a link left alone at the end of a paragraph (no sentence around it) is wrapped into a sentence.
     */
    public static function polish_product($html) {
        $html = preg_replace('~چرم\s+(?:طبیعی\s+و\s+چرم\s+تبریز|تبریز\s+و\s+چرم\s+طبیعی)~u', 'چرم طبیعی تبریز', $html);
        $html = preg_replace('~چرم\s+تبریز\s+با\s+(?:پایه|منشأ|منشا)\s+(?:چرم\s+)?گاوی~u', 'چرم گاوی تبریز', $html);
        $html = preg_replace('~\s+با\s+(?:پایه|منشأ|منشا)\s+(?=(?:چرم\s+)?(?:گاوی|طبیعی))~u', ' ', $html);
        $html = preg_replace('~\s+([،؛])~u', '$1', $html);
        // benefit bullets: an arrow points the wrong way in right-to-left text; "feature: benefit" with a bold feature reads right
        $html = preg_replace_callback('~<li\b([^>]*)>(.*?)</li>~is', function ($m) {
            if (!preg_match('~^\s*(.+?)\s*(?:→|←|⇒|⇐|&rarr;|&larr;|-&gt;|=&gt;)\s*(.+)$~us', $m[2], $x)) return $m[0];
            $label = trim(preg_replace('~</?(?:strong|b)\b[^>]*>~i', '', $x[1]));
            // a full sentence before the arrow (the shop-trust bullet) is joined as plain text instead of a bold label
            if (preg_match_all('/\S+/u', strip_tags($label)) > 6) return '<li' . $m[1] . '>' . rtrim($label, ' .') . '؛ ' . trim($x[2]) . '</li>';
            return '<li' . $m[1] . '><strong>' . rtrim($label, ' :') . ':</strong> ' . trim($x[2]) . '</li>';
        }, $html);
        return preg_replace_callback('~<p\b([^>]*)>(.*?)</p>~is', function ($m) {
            if (preg_match('~^(.*[.!؟])\s*(?:[^<\s.!؟]+\s+){0,2}(<a\b[^>]*>[^<]{2,80}</a>)\s*$~us', $m[2], $x)) {
                return '<p' . $m[1] . '>' . $x[1] . ' برای دیدن گزینه‌های بیشتر می‌توانید به ' . $x[2] . ' سر بزنید.</p>';
            }
            if (preg_match('~^\s*(<a\b[^>]*>[^<]{2,80}</a>)\s*$~us', $m[2], $x)) {
                return '<p' . $m[1] . '>برای دیدن گزینه‌های بیشتر می‌توانید به ' . $x[1] . ' سر بزنید.</p>';
            }
            return $m[0];
        }, $html);
    }

    /** A FAQ answer repeating shop terms (shipping, installments, returns) is dropped with its question: those facts appear once, in the benefits list. */
    public static function drop_faq_terms($html) {
        $terms = '~(?:ارسال\s+رایگان|اقساط|قسط|اسنپ[‌\s]*پی|ترب[‌\s]*پی|ضمانت\s+بازگشت|بازگشت\s+کالا)~u';
        $cut = preg_match('~<h2\b[^>]*>[^<]*(?:سوال|سؤال|پرسش)~u', $html, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
        if ($cut === false) return $html;
        $head = substr($html, 0, $cut); $faq = substr($html, $cut);
        $faq = preg_replace_callback('~(<h3\b[^>]*>[^<]*</h3>)\s*(<p\b[^>]*>.*?</p>)~isu', function ($m) use ($terms) {
            return preg_match($terms, strip_tags($m[1] . $m[2])) ? '' : $m[0];
        }, $faq);
        return $head . $faq;
    }

    /** Wording that announces a missing fact: it must not reach a product page (a buyer reads it as a flaw). */
    const UNKNOWN = '(?:اعلام\s*نشده|ثبت\s*نشده|ذکر\s*نشده|مشخص\s*نشده|مشخص\s*نیست|نامشخص|تأیید\s*نشده|تایید\s*نشده|بدون\s*اتکا\s*به\s*حدس|(?:داده|مشخصات)[\x{200c}\s]*های?\s*رسمی|تکیه\s*کنید)';

    /**
     * Product copy: spec-table rows without a real value are removed (the table goes when fewer than 2 rows are left),
     * and sentences that announce a missing fact are cut; a FAQ question left without an answer goes too.
     */
    public static function drop_unknowns($html) {
        $unknown = '~' . self::UNKNOWN . '~u';
        $html = preg_replace_callback('~<table\b[^>]*>.*?</table>~is', function ($m) use ($unknown) {
            $rows = 0;
            $out = preg_replace_callback('~<tr\b[^>]*>(.*?)</tr>~is', function ($r) use ($unknown, &$rows) {
                if (!preg_match('~<td\b~i', $r[1])) return $r[0];
                if (preg_match($unknown, strip_tags($r[1])) || preg_match('~<td\b[^>]*>\s*(?:[-–—]|)\s*</td>\s*</tr>~i', $r[0])) return '';
                $rows++;
                return $r[0];
            }, $m[0]);
            return $rows >= 2 ? $out : '';
        }, $html);
        $html = preg_replace_callback('~<(p|li)\b([^>]*)>(.*?)</\1>~is', function ($m) use ($unknown) {
            if (!preg_match($unknown, strip_tags($m[3]))) return $m[0];
            $parts = preg_split('~(?<=[.!؟?])\s+~u', $m[3]);
            $kept = array_filter($parts, function ($s) use ($unknown) { return !preg_match($unknown, strip_tags($s)); });
            return $kept ? '<' . $m[1] . $m[2] . '>' . implode(' ', $kept) . '</' . $m[1] . '>' : '';
        }, $html);
        return preg_replace('~<h3\b[^>]*>[^<]*</h3>\s*(?=<h[23]\b|$)~iu', '', $html);
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
