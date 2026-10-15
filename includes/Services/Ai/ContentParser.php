<?php
/**
 * Parses and post-processes raw model output: block extraction, HTML cleanup,
 * link/anchor enforcement and validation. No network access.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Ai;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentParser {

    public static function extract_block($raw, $name) {
        return preg_match('/===' . $name . '_START===\s*(.*?)\s*===' . $name . '_END===/is', (string) $raw, $m) ? trim($m[1]) : '';
    }

    public static function parse_full_content_payload($raw, $params) {
        $raw = trim((string) $raw);
        $content = '';
        $featured_prompt = '';
        $slug = '';
        $seo_title = '';
        $meta_description = '';
        $keyword_synonyms = '';

        if (preg_match('/===WILA_CONTENT_START===\s*(.*?)\s*===WILA_CONTENT_END===/is', $raw, $m)) {
            $content = trim($m[1]);
        } elseif (preg_match('/===WILA_CONTENT_START===\s*(.*)/is', $raw, $m)) {
            $content = trim($m[1]);
            // اگر برچسب تصویر شاخص بعد از آن آمده باشد، آن را جدا کن
            if (preg_match('/(.*?)(?:===WILA_FEATURED_IMAGE_PROMPT_START===|$)/is', $content, $cm)) {
                $content = trim($cm[1]);
            }
        }

        if (preg_match('/===WILA_FEATURED_IMAGE_PROMPT_START===\s*(.*?)\s*===WILA_FEATURED_IMAGE_PROMPT_END===/is', $raw, $m)) {
            $featured_prompt = trim($m[1]);
        } elseif (preg_match('/===WILA_FEATURED_IMAGE_PROMPT_START===\s*(.*)/is', $raw, $m)) {
            $featured_prompt = trim($m[1]);
            if (preg_match('/(.*?)(?:===WILA_CONTENT_START===|$)/is', $featured_prompt, $fm)) {
                $featured_prompt = trim($fm[1]);
            }
        }

        if (preg_match('/===WILA_SLUG_START===\s*(.*?)\s*===WILA_SLUG_END===/is', $raw, $m)) $slug = trim($m[1]);
        if (preg_match('/===WILA_SEO_TITLE_START===\s*(.*?)\s*===WILA_SEO_TITLE_END===/is', $raw, $m)) $seo_title = trim($m[1]);
        if (preg_match('/===WILA_META_DESCRIPTION_START===\s*(.*?)\s*===WILA_META_DESCRIPTION_END===/is', $raw, $m)) $meta_description = trim($m[1]);
        if (preg_match('/===WILA_KEYWORD_SYNONYMS_START===\s*(.*?)\s*===WILA_KEYWORD_SYNONYMS_END===/is', $raw, $m)) $keyword_synonyms = trim($m[1]);

        // پشتیبانی فال‌بک در صورتی که مدل خروجی JSON داده باشد
        if (empty($content)) {
            $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw);
            $json = json_decode(trim($clean), true);
            if (is_array($json) && !empty($json['content'])) {
                $content = trim($json['content']);
                if (!empty($json['featured_image_prompt'])) {
                    $featured_prompt = trim($json['featured_image_prompt']);
                }
                if (!empty($json['slug'])) $slug = trim($json['slug']);
                if (!empty($json['seo_title'])) $seo_title = trim($json['seo_title']);
                if (!empty($json['meta_description'])) $meta_description = trim($json['meta_description']);
                if (!empty($json['keyword_synonyms'])) $keyword_synonyms = trim($json['keyword_synonyms']);
            }
        }

        // پشتیبانی فال‌بک در صورتی که مدل مستقیماً HTML بازگردانده باشد
        if (empty($content)) {
            $clean = preg_replace('/^```(?:html)?\s*|\s*```$/i', '', $raw);
            if (strpos($clean, '<p') !== false || strpos($clean, '<h') !== false || strpos($clean, '<div') !== false) {
                $content = trim($clean);
            }
        }

        if (empty($content)) {
            return new \WP_Error('invalid_content_response', 'محتوای HTML از AvalAI دریافت نشد. لطفاً مجدداً امتحان کنید.');
        }

        $slug = sanitize_title($slug);
        if ($slug === '' && self::kind($params) === Settings::KIND_ARTICLE) {
            $slug = sanitize_title(remove_accents($params['h1_title']));
        }
        if ($slug === '' && self::kind($params) === Settings::KIND_ARTICLE) {
            $slug = sanitize_title(remove_accents($params['focus_keyword']));
        }

        if (self::kind($params) !== Settings::KIND_ARTICLE) {
            // Product and category pages carry no image boxes or featured image prompt and never link to the homepage.
            $content = trim(preg_replace('~<div\b[^>]*wila-image-placeholder[^>]*>.*?</div>~is', '', $content));
            $featured_prompt = '';
            $content = self::strip_homepage_links($content);
        }
        $content = \Khazan\Services\Content\HtmlSanitizer::clean($content, self::kind($params));
        $focus = isset($params['focus_keyword']) ? (string) $params['focus_keyword'] : '';
        $content = \Khazan\Services\Content\ContentGuard::enforce_link_anchors($content, self::locked_anchor_map(isset($params['internal_links']) ? $params['internal_links'] : array()));
        if (self::kind($params) === Settings::KIND_PRODUCT) {
            // هر URL فقط یک‌بار و حداکثر ۲ لینک محصول و ۱ لینک خارجی؛ لینک‌های برنامه‌ریزی‌شده‌ای که جا افتاده‌اند پایین‌تر اضافه می‌شوند
            $content = \Khazan\Services\Content\ContentGuard::enforce_unique_links($content, isset($params['internal_links']) ? $params['internal_links'] : array());
        }
        $content = \Khazan\Services\Content\ContentGuard::ensure_internal_links($content, isset($params['internal_links']) ? $params['internal_links'] : array());
        $content = \Khazan\Services\Content\ContentGuard::ensure_external_link($content, $focus);
        // مترادف‌هایی که در Yoast ذخیره می‌شوند (ستون شیت در تولید انبوه) ملاک هدینگ‌ها هستند.
        $guard_synonyms = !empty($params['seo_synonyms']) ? $params['seo_synonyms'] : $keyword_synonyms;
        $content = \Khazan\Services\Content\ContentGuard::enforce_heading_keyphrases($content, $focus, $guard_synonyms);
        $content = \Khazan\Services\Content\ContentGuard::cap_keyword_density($content, $focus, $guard_synonyms);
        $content = \Khazan\Services\Content\ContentGuard::cap_anchor_length($content);
        $content = \Khazan\Services\Content\ContentGuard::wrap($content);

        $external_links = self::extract_external_links($content);
        return array(
            'content' => $content,
            'featured_image_prompt' => sanitize_textarea_field($featured_prompt),
            'slug' => $slug,
            'seo_title' => sanitize_text_field($seo_title ?: $params['h1_title']),
            'meta_description' => sanitize_textarea_field($meta_description),
            'keyword_synonyms' => sanitize_text_field($keyword_synonyms),
            'internal_link_count' => self::count_content_links($content, true),
            'external_link_count' => count($external_links),
            'external_links' => $external_links,
        );
    }

    private static function strip_homepage_links($content) {
        $home = untrailingslashit(home_url('/'));
        return preg_replace_callback('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', function ($m) use ($home) {
            $url = untrailingslashit(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            $is_home = $url === $home || trim($m[2]) === '/' || ($path === '' && strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === strtolower((string) wp_parse_url($home, PHP_URL_HOST)));
            return $is_home ? $m[3] : $m[0];
        }, $content);
    }

    private static function parse_content_anchors($content) {
        $anchors = array();
        if (!preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', $content, $matches, PREG_SET_ORDER)) return $anchors;
        foreach ($matches as $match) {
            $anchors[] = array('url' => html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'), 'anchor' => sanitize_text_field(wp_strip_all_tags($match[3])));
        }
        return $anchors;
    }

    private static function count_content_links($content, $internal) {
        $site_host = strtolower(wp_parse_url(home_url('/'), PHP_URL_HOST));
        $count = 0;
        foreach (self::parse_content_anchors($content) as $link) {
            $host = strtolower(wp_parse_url($link['url'], PHP_URL_HOST) ?: $site_host);
            if (($host === $site_host) === $internal) $count++;
        }
        return $count;
    }

    private static function extract_external_links($content) {
        $links = array();
        $site_host = strtolower(wp_parse_url(home_url('/'), PHP_URL_HOST));
        foreach (self::parse_content_anchors($content) as $link) {
            $host = strtolower(wp_parse_url($link['url'], PHP_URL_HOST) ?: '');
            if ($host && $host !== $site_host && in_array(wp_parse_url($link['url'], PHP_URL_SCHEME), array('http', 'https'), true)) {
                $links[] = array('url' => esc_url_raw($link['url']), 'anchor' => $link['anchor']);
            }
        }
        return $links;
    }

    public static function locked_anchor_map($links) {
        $map = array();
        foreach ((array) $links as $link) {
            if (!empty($link['locked']) && !empty($link['anchor']) && !empty($link['url'])) $map[$link['url']] = $link['anchor'];
        }
        return $map;
    }

    /**
     * تضمین حضور دقیقاً یک ایموجی در عنوان سئو (در صورت نبود، ایموجی پیش‌فرض نوع محتوا در انتها)
     */
    public static function normalize_seo_title($title, $kind, $hint = '', $focus = '') {
        $title = trim(sanitize_text_field((string) $title));
        if ($title === '') return '';
        if ($kind === Settings::KIND_PRODUCT) return \Khazan\Services\Seo\SeoMetaService::guard_title($title, $focus, null, $hint);
        $emoji = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B50}\x{2B55}]\x{FE0F}?/u';
        preg_match_all($emoji, $title, $found);
        if (count($found[0]) > 1) {
            $kept = false;
            $title = preg_replace_callback($emoji, function ($m) use (&$kept) {
                if (!$kept) { $kept = true; return $m[0]; }
                return '';
            }, $title);
            $title = trim(preg_replace('/\s{2,}/u', ' ', $title));
        } elseif (empty($found[0])) {
            $defaults = array(Settings::KIND_ARTICLE => '📘', Settings::KIND_PRODUCT => '🛒', Settings::KIND_CATEGORY => '🛍️');
            $title .= ' ' . $defaults[$kind];
        }
        return $title;
    }

    /**
     * اعتبارسنجی پیشنهادهای اصلاح خوانایی؛ فقط وصله‌های سالم و منطبق بر قطعه اصلی نگه داشته می‌شوند
     */
    public static function repair_patches($parsed, $segments) {
        if (!isset($parsed['patches']) || !is_array($parsed['patches'])) return new \WP_Error('invalid_repairs', 'پاسخ اصلاح معتبر نیست.');
        $patches = array();
        $seen = array();
        foreach (array_slice($parsed['patches'], 0, 20) as $patch) {
            if (!is_array($patch) || !isset($patch['index'], $patch['before'], $patch['after'], $patch['reason']) || !is_int($patch['index'])) continue;
            $i = $patch['index'];
            if (!isset($segments[$i]) || isset($seen[$i]) || !is_string($patch['after']) || !is_string($patch['reason']) || $patch['before'] !== $segments[$i]) continue;
            $after = $patch['after'];
            if (trim($after) === '' || $after === $segments[$i] || preg_match('/[<>\[\]]/u', $after) || strlen($after) > strlen($segments[$i]) * 3 + 300) continue;
            $patches[] = array('index' => $i, 'before' => $segments[$i], 'after' => $after, 'reason' => sanitize_text_field($patch['reason']));
            $seen[$i] = true;
        }
        return $patches;
    }

    public static function blueprint($json_text) {
        $json_text = preg_replace('/^```json\s*/i', '', trim($json_text));$json_text = preg_replace('/```$/', '', trim($json_text));

        $parsed = json_decode(trim($json_text), true);
        return $parsed ? $parsed : array();
    }

    /**
     * @return array|\WP_Error
     */
    public static function link_suggestions($json_text, $candidates) {
        $json_text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($json_text));
        $parsed = json_decode($json_text, true);
        if (!is_array($parsed) || !isset($parsed['suggestions']) || !is_array($parsed['suggestions'])) {
            return new \WP_Error('invalid_ai_response', 'پاسخ هوش مصنوعی ساختار معتبری ندارد. لطفاً دوباره تلاش کنید.');
        }

        $sources = array();
        foreach ($candidates as $candidate) {
            $sources[$candidate['type'] . ':' . $candidate['id']] = $candidate;
        }
        $suggestions = array();
        $seen = array();
        foreach ($parsed['suggestions'] as $item) {
            if (!is_array($item) || !isset($item['source_id'], $item['source_type']) || !is_scalar($item['source_id']) || !is_string($item['source_type'])) continue;
            $key = $item['source_type'] . ':' . (int) $item['source_id'];
            if (!isset($sources[$key]) || isset($seen[$key])) continue;
            if (!isset($item['recommended_anchor_texts']) || !is_array($item['recommended_anchor_texts'])) continue;
            $anchors = array();
            foreach ($item['recommended_anchor_texts'] as $anchor) {
                if (is_string($anchor) && trim($anchor) !== '') $anchors[] = sanitize_text_field($anchor);
            }
            if (empty($anchors)) continue;
            $source = $sources[$key];
            $suggestions[] = array(
                'source_id' => $source['id'],
                'source_type' => $source['type'],
                'source_title' => $source['title'],
                'recommended_anchor_texts' => array_slice($anchors, 0, 3),
                'link_frequency' => 1,
                'placement_guide' => isset($item['placement_guide']) && is_string($item['placement_guide']) ? sanitize_text_field($item['placement_guide']) : '',
                'reasoning' => isset($item['reasoning']) && is_string($item['reasoning']) ? sanitize_text_field($item['reasoning']) : '',
            );
            $seen[$key] = true;
            if (count($suggestions) >= 5) break;
        }
        if (!empty($parsed['suggestions']) && empty($suggestions)) {
            return new \WP_Error('invalid_ai_response', 'پیشنهادهای دریافتی معتبر نیستند. لطفاً دوباره تلاش کنید.');
        }
        return $suggestions;
    }

    public static function normalize_outline($outline, $kind) {
        if (is_array($outline) && !is_wp_error($outline)) {
            if ($kind !== Settings::KIND_ARTICLE) unset($outline['slug'], $outline['image_positions']);
            if (isset($outline['seo_title'])) $outline['seo_title'] = self::normalize_seo_title($outline['seo_title'], $kind);
        }
        return $outline;
    }

    /**
     * @return array|\WP_Error
     */
    public static function full_content($raw, $params) {
        $kind       = self::kind($params);
        $is_article = $kind === Settings::KIND_ARTICLE;

        $result = self::parse_full_content_payload($raw, $params);
        if (is_wp_error($result)) return $result;
        $result['seo_title'] = self::normalize_seo_title($result['seo_title'], $kind, isset($params['h1_title']) ? $params['h1_title'] : '', isset($params['focus_keyword']) ? $params['focus_keyword'] : '');
        // Never silently substitute a Persian URL for the requested English slug.
        if (!$is_article || !preg_match('/^[a-z]+(?:-[a-z0-9]+)*$/D', $result['slug'])) {
            $result['slug'] = '';
        }
        return $result;
    }

    /**
     * @return array|\WP_Error
     */
    public static function bulk_product_result($raw, $params, $need_title) {
        $kind   = Settings::KIND_PRODUCT;
        $result = self::parse_full_content_payload($raw, $params);
        if (is_wp_error($result)) return $result;
        $result['seo_title'] = $need_title ? self::normalize_seo_title($result['seo_title'], $kind, isset($params['h1_title']) ? $params['h1_title'] : '', isset($params['focus_keyword']) ? $params['focus_keyword'] : '') : '';
        $result['slug'] = '';
        return $result;
    }

    /**
     * @return array|\WP_Error
     */
    public static function seo_fix_result($raw, $kind, $current) {
        $seo = $current['seo'];
        $content = self::extract_block($raw, 'WILA_CONTENT');
        if ($content === '') return new \WP_Error('invalid_content_response', 'متن اصلاح‌شده از AvalAI دریافت نشد. لطفاً مجدداً امتحان کنید.');
        // اگر متن خیلی کوتاه یا خیلی بلند شده، احتمال بازنویسی کلی است و جایگزینی انجام نمی‌شود.
        $old_len = mb_strlen(wp_strip_all_tags($current['content']));
        $new_len = mb_strlen(wp_strip_all_tags($content));
        if ($old_len > 0 && ($new_len < $old_len * 0.8 || $new_len > $old_len * 1.25)) {
            return new \WP_Error('seo_fix_rejected', 'متن اصلاح‌شده طول غیرعادی داشت (احتمال بازنویسی کلی) و جایگزین نشد. لطفاً دوباره امتحان کنید.', array('status' => 502));
        }

        $content = \Khazan\Services\Content\HtmlSanitizer::clean($content, $kind);
        $content = \Khazan\Services\Content\ContentGuard::enforce_link_anchors($content);
        if ($kind === Settings::KIND_PRODUCT) $content = \Khazan\Services\Content\ContentGuard::enforce_unique_links($content);
        $content = \Khazan\Services\Content\ContentGuard::enforce_heading_keyphrases($content, $seo['focuskw'], $seo['synonyms']);
        $content = \Khazan\Services\Content\ContentGuard::cap_anchor_length($content);
        $content = \Khazan\Services\Content\ContentGuard::wrap($content);
        return array(
            'content'          => $content,
            'seo_title'        => sanitize_text_field(self::extract_block($raw, 'WILA_SEO_TITLE')),
            'meta_description' => sanitize_textarea_field(self::extract_block($raw, 'WILA_META_DESCRIPTION')),
            'notes'            => sanitize_textarea_field(self::extract_block($raw, 'WILA_NOTES')),
            'changed'          => \Khazan\Services\Content\ContentGuard::unwrap($content) !== \Khazan\Services\Content\ContentGuard::unwrap($current['content']),
        );
    }

    private static function kind($params) {
        return Settings::normalize_kind(isset($params['content_kind']) ? $params['content_kind'] : '');
    }
}
