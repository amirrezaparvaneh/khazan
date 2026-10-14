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
     * بازه نسبت هدینگ‌های H2/H3 دارای کلیدواژه کانونی یا مترادف ثبت‌شده؛ کمتر از کف یا بیشتر از سقف به هدف ۴۰ تا ۵۰ درصد برمی‌گردد
     */
    const HEADING_MIN_RATIO = 0.35;
    const HEADING_MAX_RATIO = 0.7;
    const HEADING_TARGET_RATIO = 0.4;

    /**
     * Yoast دقیقاً دنبال کلیدواژه کانونی یا مترادف‌ها در H2/H3 می‌گردد؛ این تابع نسبت را بدون اتکا به مدل اصلاح می‌کند:
     * اگر کم بود کلیدواژه کانونی به هدینگ‌های فاقد آن اضافه می‌شود و اگر زیاد بود از هدینگ‌های اضافی حذف می‌شود.
     */
    public static function enforce_heading_keyphrases($html, $keyword, $synonyms) {
        $keyword = trim(preg_replace('/\s+/u', ' ', (string) $keyword));
        if ($keyword === '') return $html;
        $phrases = array_merge(array($keyword), WILA_Seo_Meta::split_keywords($synonyms, $keyword));
        if (!preg_match_all('~<(h[23])\b([^>]*)>(.*?)</\1>~isu', $html, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $html;

        $headings = array();
        foreach ($found as $m) {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($m[3][0]), ENT_QUOTES, 'UTF-8')));
            $headings[] = array('html' => $m[0][0], 'offset' => $m[0][1], 'tag' => $m[1][0], 'attrs' => $m[2][0], 'inner' => $m[3][0], 'text' => $text, 'match' => self::contains_phrase($text, $phrases));
        }
        $total = count($headings);
        $matched = count(array_filter(array_column($headings, 'match')));
        $target = max(1, (int) ceil($total * self::HEADING_TARGET_RATIO));
        $ratio = $matched / $total;

        if ($ratio < self::HEADING_MIN_RATIO) {
            $headings = self::add_keyword_to_headings($headings, $keyword, $target - $matched);
        } elseif ($ratio > self::HEADING_MAX_RATIO) {
            $headings = self::remove_keyword_from_headings($headings, $phrases, $matched - $target);
        } else {
            return $html;
        }

        // جایگزینی از انتها به ابتدا تا offsetها جابه‌جا نشوند
        for ($i = $total - 1; $i >= 0; $i--) {
            $h = $headings[$i];
            if (!isset($h['new_inner'])) continue;
            $replacement = '<' . $h['tag'] . $h['attrs'] . '>' . $h['new_inner'] . '</' . $h['tag'] . '>';
            $html = substr_replace($html, $replacement, $h['offset'], strlen($h['html']));
        }
        return $html;
    }

    private static function contains_phrase($text, $phrases) {
        foreach ($phrases as $phrase) {
            if ($phrase !== '' && mb_strpos($text, $phrase) !== false) return true;
        }
        return false;
    }

    private static function is_question($text) {
        return (bool) preg_match('/[؟?]\s*$/u', $text);
    }

    /**
     * کلیدواژه کانونی به هدینگ‌های فاقد آن اضافه می‌شود؛ اول H2های غیرسوالی، بعد H3های غیرسوالی و در آخر سوال‌ها
     */
    private static function add_keyword_to_headings($headings, $keyword, $needed) {
        $order = array();
        foreach ($headings as $i => $h) {
            if ($h['match'] || $h['text'] === '') continue;
            $order[$i] = (self::is_question($h['text']) ? 2 : 0) + (strtolower($h['tag']) === 'h3' ? 1 : 0);
        }
        asort($order);
        foreach (array_keys($order) as $i) {
            if ($needed <= 0) break;
            $inner = trim($headings[$i]['inner']);
            // سوال‌ها با پیشوند «کلیدواژه:» و بقیه با افزودن کلیدواژه در انتها، تا علامت سوال جابه‌جا نشود
            $headings[$i]['new_inner'] = self::is_question($headings[$i]['text'])
                ? esc_html($keyword) . ': ' . $inner
                : $inner . ' ' . esc_html($keyword);
            $needed--;
        }
        return $headings;
    }

    /**
     * کلیدواژه از هدینگ‌های اضافی (از انتها) حذف می‌شود؛ هدینگی که پس از حذف بی‌معنا یا خالی شود دست نمی‌خورد
     */
    private static function remove_keyword_from_headings($headings, $phrases, $excess) {
        usort($phrases, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });
        for ($i = count($headings) - 1; $i >= 0 && $excess > 0; $i--) {
            if (!$headings[$i]['match'] || $i === 0) continue;
            $inner = $headings[$i]['inner'];
            foreach ($phrases as $phrase) $inner = str_replace($phrase, '', $inner);
            $inner = preg_replace('/\s+([:،,؟?])/u', '$1', $inner);
            $inner = preg_replace('/^[\s:،,\-–|]+|[\s:،,\-–|]+$/u', '', preg_replace('/\s+/u', ' ', $inner));
            $plain = trim(wp_strip_all_tags($inner));
            if ($plain === '' || preg_match('/^[؟?]$/u', $plain) || count(preg_split('/\s+/u', $plain)) < 2) continue;
            $headings[$i]['new_inner'] = $inner;
            $excess--;
        }
        return $headings;
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

    /**
     * انکرتکست تمام لینک‌های داخلی به محصولات را با کلیدواژه کانونی دقیق محصول مقصد یکسان می‌کند.
     * $map (آدرس => انکرتکست اجباری) برای لینک‌هایی است که از قبل مشخص بوده‌اند؛ بقیه از روی آدرس شناسایی می‌شوند.
     */
    public static function enforce_link_anchors($html, $map = array()) {
        $normalized = array();
        foreach ((array) $map as $target => $anchor) $normalized[untrailingslashit($target)] = $anchor;
        return preg_replace_callback('~(<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\2[^>]*>)(.*?)(</a>)~is', function ($m) use ($normalized) {
            $url = untrailingslashit(html_entity_decode($m[3], ENT_QUOTES, 'UTF-8'));
            $anchor = isset($normalized[$url]) ? $normalized[$url] : '';
            if ($anchor === '') {
                $product_id = WILA_Content_Targets::product_id_from_url($url);
                if ($product_id) $anchor = WILA_Content_Targets::product_link_anchor($product_id);
            }
            if ($anchor === '' || trim(wp_strip_all_tags($m[4])) === $anchor) return $m[0];
            return $m[1] . esc_html($anchor) . $m[5];
        }, $html);
    }
}
