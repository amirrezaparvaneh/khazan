<?php
/**
 * گارد لینک‌ها و انکرها: لینک خارجی تخصصی، لینک‌های داخلی اجباری و قفل انکر محصولات
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content\Guard;

use Khazan\Core\Settings;
use Khazan\Services\Seo\KeyphraseExtractor;

if (!defined('ABSPATH')) {
    exit;
}

final class LinkGuard {

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
        $terms = apply_filters(Settings::FILTER_ANCHOR_TERMS, array(
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
        $templates = apply_filters(Settings::FILTER_REFERENCE_URL_TEMPLATES, array(
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
     * لینک‌های داخلی اجباری (دسته‌بندی و محصولات هم‌دسته) که مدل جا انداخته در یک پاراگراف پایانی اضافه می‌شوند
     */
    public static function ensure_internal_links($html, $links) {
        $present = array();
        if (preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $found)) {
            foreach ($found[2] as $href) $present[untrailingslashit(html_entity_decode($href, ENT_QUOTES, 'UTF-8'))] = true;
        }
        $category = null;
        $products = array();
        foreach ((array) $links as $link) {
            if (empty($link['required']) || empty($link['url']) || empty($link['anchor'])) continue;
            if (isset($present[untrailingslashit($link['url'])])) continue;
            $anchor = '<a href="' . esc_url($link['url']) . '">' . esc_html(KeyphraseExtractor::cap($link['anchor'])) . '</a>';
            if (isset($link['type']) && $link['type'] === 'category') {
                if ($category === null) $category = $anchor;
            } else {
                $products[] = $anchor;
            }
        }
        if ($category === null && !$products) return $html;

        $parts = array();
        if ($category !== null) $parts[] = 'از دسته‌بندی ' . $category;
        if ($products) $parts[] = ($category !== null ? 'یا ' : 'از ') . 'محصولات مشابه مانند ' . implode(' و ', $products);
        $sentence = 'همچنین می‌توانید ' . implode(' ', $parts) . ' دیدن کنید.';
        return rtrim($html) . '<p>' . $sentence . '</p>';
    }

    /**
     * هر URL فقط یک‌بار در کل متن لینک می‌ماند و تعداد لینک‌ها سقف دارد:
     *  ۱. لینک تکراری (دومین بار به بعد) آن‌رپ می‌شود؛ تگ <a> حذف و متن داخلش حفظ می‌شود.
     *  ۲. لینک داخلی غیر از دسته‌بندی ("لینک محصول") حداکثر $max_products؛ لینک‌های برنامه‌ریزی‌شده در $links (type=product) اولویت دارند
     *     و سهم لینک‌های دیگر فقط جای خالی مانده است. بقیه آن‌رپ می‌شوند.
     *  ۳. لینک خارجی حداکثر $max_external؛ بقیه آن‌رپ می‌شوند.
     * لینک دسته‌بندی (type=category) فقط یک‌بار و بدون سقف محصول می‌ماند.
     */
    public static function enforce_unique_links($html, $links = array(), $max_products = 2, $max_external = 1) {
        $category_urls = array();
        $planned_products = array();
        foreach ((array) $links as $link) {
            if (empty($link['url'])) continue;
            $key = self::url_key($link['url']);
            if (isset($link['type']) && $link['type'] === 'category') $category_urls[$key] = true;
            elseif (isset($link['type']) && $link['type'] === 'product') $planned_products[$key] = true;
        }
        $other_allowance = max(0, (int) $max_products - count($planned_products));
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));

        $seen = array();
        $products = 0; $others = 0; $external = 0;
        return preg_replace_callback('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', function ($m) use (&$seen, &$products, &$others, &$external, $category_urls, $planned_products, $other_allowance, $home_host, $max_products, $max_external) {
            $key = self::url_key($m[2]);
            if ($key === '' || isset($seen[$key])) return $m[3];

            $host = strtolower((string) wp_parse_url(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), PHP_URL_HOST));
            if ($host !== '' && $host !== $home_host) {
                if ($external >= $max_external) return $m[3];
                $external++;
            } elseif (!isset($category_urls[$key])) {
                if (isset($planned_products[$key])) {
                    if ($products >= $max_products) return $m[3];
                } else {
                    if ($others >= $other_allowance || $products >= $max_products) return $m[3];
                    $others++;
                }
                $products++;
            }
            $seen[$key] = true;
            return $m[0];
        }, (string) $html);
    }

    /**
     * کلید مقایسه URL: بدون موجودیت‌های HTML، فاصله، اسلش انتهایی و بخش #fragment؛ طرح و میزبان با حروف کوچک
     */
    private static function url_key($url) {
        $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
        $url = preg_replace('/#.*$/', '', $url);
        if ($url === '') return '';
        $parts = wp_parse_url($url);
        if (!empty($parts['host'])) {
            $url = strtolower($parts['scheme'] ?? 'https') . '://' . strtolower($parts['host']) . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }
        return untrailingslashit($url);
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
                $product_id = (new \Khazan\Repository\ContentTargetRepository())->product_id_from_url($url);
                if ($product_id) $anchor = (new \Khazan\Repository\ContentTargetRepository())->product_link_anchor($product_id);
            }
            $anchor = KeyphraseExtractor::cap($anchor);
            if ($anchor === '' || trim(wp_strip_all_tags($m[4])) === $anchor) return $m[0];
            return $m[1] . esc_html($anchor) . $m[5];
        }, $html);
    }

    /**
     * هیچ لینکی نباید انکری بیش از $max کلمه داشته باشد: کلمات اضافه بعد از </a> به‌صورت متن ساده می‌مانند.
     * انکرهای دارای تگ تو در تو دست‌نخورده می‌مانند.
     */
    public static function cap_anchor_length($html, $max = Settings::KEYPHRASE_MAX_WORDS) {
        return preg_replace_callback('~(<a\b[^>]*>)([^<]*)(</a>)~is', function ($m) use ($max) {
            $text = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (KeyphraseExtractor::count_words($text) <= $max) return $m[0];
            $words = KeyphraseExtractor::words($text);
            return $m[1] . esc_html(implode(' ', array_slice($words, 0, $max))) . $m[3] . ' ' . esc_html(implode(' ', array_slice($words, $max)));
        }, $html);
    }
}
