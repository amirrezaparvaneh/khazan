<?php
/**
 * نگهبان خروجی محتوا: پوشش wrapper سفارشی، لینک خارجی پیش‌فرض و سقف تکرار کلیدواژه کانونی
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Content_Guard {

    const WRAPPER_CLASS = 'aka-desc-wrapper';
    const MAX_KEYWORD_OCCURRENCES = 7;

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

    /**
     * اگر محتوا هیچ لینک خارجی ندارد، نخستین تکرار کلیدواژه در متن به صفحه جستجوی ویکی‌پدیای فارسی لینک می‌شود
     */
    public static function ensure_external_link($html, $keyword) {
        if (self::has_external_link($html)) return $html;
        $keyword = trim((string) $keyword);
        if ($keyword === '') return $html;

        // انکرتکست ترجیحاً یک اصطلاح تخصصی موجود در متن است، نه عبارت عمومی یا نام محصول.
        $candidates = array_merge(self::specialist_terms(), array($keyword, self::first_word($keyword)));
        $candidates = array_unique($candidates);
        foreach ($candidates as $term) {
            if ($term === '' || mb_strlen($term) < 2) continue;
            $linked = self::link_first_text_occurrence($html, $term);
            if ($linked !== null) return $linked;
        }
        return $html;
    }

    /**
     * اصطلاحات تخصصی که اگر در متن باشند انکرتکست طبیعی‌تری برای لینک مرجع‌اند؛ با فیلتر wila_anchor_terms قابل تغییر است
     */
    private static function specialist_terms() {
        $terms = apply_filters('wila_anchor_terms', array(
            'استانداردهای جهانی ارگونومی', 'ارگونومی پا', 'ارگونومی', 'چرم طبیعی', 'چرم گاوی', 'چرم مصنوعی',
            'زیره پلی‌یورتان', 'زیره لاستیکی', 'زیره EVA', 'پلی‌یورتان', 'آناتومی پا', 'قوس کف پا', 'کفی طبی',
            'جذب ضربه', 'میکروفایبر', 'استاندارد ایزو', 'استاندارد',
        ));
        return array_values(array_filter((array) $terms, 'is_string'));
    }

    private static function has_external_link($html) {
        $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (!preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $matches)) return false;
        foreach ($matches[2] as $href) {
            $host = strtolower((string) wp_parse_url(html_entity_decode($href, ENT_QUOTES, 'UTF-8'), PHP_URL_HOST));
            if ($host !== '' && $host !== $site_host) return true;
        }
        return false;
    }

    private static function first_word($text) {
        $parts = preg_split('/\s+/u', trim($text));
        return isset($parts[0]) ? $parts[0] : '';
    }

    /**
     * یکی از سایت‌های مرجع را به‌صورت ثابت برای هر کلیدواژه انتخاب می‌کند تا لینک‌ها بین حوزه‌ها پخش شوند.
     * الگوها با فیلتر wila_reference_url_templates قابل تغییرند؛ هر الگو باید %s برای عبارت جستجو داشته باشد.
     */
    public static function pick_reference_url($term) {
        $templates = apply_filters('wila_reference_url_templates', array(
            'https://fa.wikipedia.org/wiki/Special:Search?search=%s',
            'https://en.wikipedia.org/w/index.php?search=%s',
            'https://scholar.google.com/scholar?q=%s',
        ), $term);
        $templates = array_values(array_filter((array) $templates, function ($template) {
            return is_string($template) && strpos($template, '%s') !== false;
        }));
        if (!$templates) return '';
        $template = $templates[crc32($term) % count($templates)];
        return esc_url_raw(sprintf($template, rawurlencode($term)));
    }

    /**
     * نخستین تکرار متن بیرون از لینک و هدینگ را لینک می‌کند؛ اگر پیدا نشد null برمی‌گرداند
     */
    private static function link_first_text_occurrence($html, $term) {
        $tokens = preg_split('~(<[^>]+>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $skip_depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token[0] === '<') {
                if (preg_match('~^<(a|h[1-6]|script|style)\b~i', $token)) $skip_depth++;
                elseif (preg_match('~^</(a|h[1-6]|script|style)>~i', $token)) $skip_depth = max(0, $skip_depth - 1);
                continue;
            }
            if ($skip_depth > 0) continue;
            $pos = mb_strpos($token, $term);
            if ($pos === false) continue;
            $url = self::pick_reference_url($term);
            $link = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . $term . '</a>';
            $tokens[$i] = mb_substr($token, 0, $pos) . $link . mb_substr($token, $pos + mb_strlen($term));
            return implode('', $tokens);
        }
        return null;
    }

    /**
     * اگر کلیدواژه بیش از سقف مجاز تکرار شد، تکرارهای اضافه (بیرون از لینک) با مترادف‌ها جایگزین می‌شوند
     */
    public static function cap_keyword_density($html, $keyword, $synonyms, $max = self::MAX_KEYWORD_OCCURRENCES) {
        $keyword = trim((string) $keyword);
        if ($keyword === '') return $html;
        $alternatives = WILA_Seo_Meta::split_keywords($synonyms, $keyword);

        $tokens = preg_split('~(<[^>]+>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        // تکرار داخل لینک‌ها حذف نمی‌شود تا لینک خراب نشود؛ پس بودجه متن عادی از سقف کم می‌شود.
        $anchor_count = self::count_anchor_occurrences($tokens, $keyword);
        if ($anchor_count + self::count_text_occurrences($tokens, $keyword) <= $max) return $html;
        $budget = max(0, $max - $anchor_count);

        $seen = 0;
        $alt_index = 0;
        $anchor_depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token[0] === '<') {
                if (preg_match('~^<a\b~i', $token)) $anchor_depth++;
                elseif (preg_match('~^</a>~i', $token)) $anchor_depth = max(0, $anchor_depth - 1);
                continue;
            }
            if ($anchor_depth > 0) continue;
            $pieces = explode($keyword, $token);
            if (count($pieces) === 1) continue;
            $out = $pieces[0];
            for ($k = 1; $k < count($pieces); $k++) {
                $seen++;
                $replace = $seen > $budget && $alternatives;
                $out .= $replace ? $alternatives[$alt_index++ % count($alternatives)] : $keyword;
                $out .= $pieces[$k];
            }
            $tokens[$i] = $out;
        }
        return implode('', $tokens);
    }

    /**
     * اگر بیش از نسبت مجاز هدینگ‌های H2/H3 کلیدواژه کانونی داشتند، کلیدواژه در هدینگ‌های بعدی با مترادف جایگزین می‌شود
     */
    public static function limit_heading_keyword($html, $keyword, $synonyms, $ratio = 0.4) {
        $keyword = trim((string) $keyword);
        $alternatives = WILA_Seo_Meta::split_keywords($synonyms, $keyword);
        if ($keyword === '' || !$alternatives) return $html;
        if (!preg_match_all('~<h[23]\b[^>]*>.*?</h[23]>~isu', $html, $found)) return $html;

        $total = count($found[0]);
        $allowed = max(1, (int) floor($total * $ratio));
        $with_keyword = 0;
        $alt_index = 0;
        return preg_replace_callback('~<h[23]\b[^>]*>.*?</h[23]>~isu', function ($m) use ($keyword, $alternatives, $allowed, &$with_keyword, &$alt_index) {
            if (mb_strpos($m[0], $keyword) === false) return $m[0];
            $with_keyword++;
            if ($with_keyword <= $allowed) return $m[0];
            return str_replace($keyword, $alternatives[$alt_index++ % count($alternatives)], $m[0]);
        }, $html);
    }

    private static function count_anchor_occurrences($tokens, $keyword) {
        $count = 0;
        $anchor_depth = 0;
        foreach ($tokens as $token) {
            if ($token[0] === '<') {
                if (preg_match('~^<a\b~i', $token)) $anchor_depth++;
                elseif (preg_match('~^</a>~i', $token)) $anchor_depth = max(0, $anchor_depth - 1);
                continue;
            }
            if ($anchor_depth > 0) $count += substr_count($token, $keyword);
        }
        return $count;
    }

    private static function count_text_occurrences($tokens, $keyword) {
        $count = 0;
        $anchor_depth = 0;
        foreach ($tokens as $token) {
            if ($token[0] === '<') {
                if (preg_match('~^<a\b~i', $token)) $anchor_depth++;
                elseif (preg_match('~^</a>~i', $token)) $anchor_depth = max(0, $anchor_depth - 1);
                continue;
            }
            if ($anchor_depth === 0) $count += substr_count($token, $keyword);
        }
        return $count;
    }
}
