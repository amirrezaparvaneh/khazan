<?php
/**
 * SEO meta service: pure keyword/meta mapping for Yoast and RankMath plus reading and
 * writing the SEO fields of posts and product-category terms.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Seo;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class SeoMetaService {

    /**
     * فهرست یکتای کلیدواژه‌ها از رشته‌ای که با ویرگول فارسی یا لاتین جدا شده؛ کلیدواژه اصلی حذف می‌شود
     */
    public static function split_keywords($raw, $exclude = '') {
        $exclude_key = mb_strtolower(trim((string) $exclude));
        $seen = array();
        $list = array();
        foreach (preg_split('/[,،;؛\r\n]+/u', (string) $raw) as $item) {
            $item = trim(preg_replace('/\s+/u', ' ', $item));
            $key = mb_strtolower($item);
            if ($item === '' || $key === $exclude_key || isset($seen[$key])) continue;
            $seen[$key] = true;
            $list[] = $item;
        }
        return $list;
    }

    /**
     * فیلد مترادف Yoast یک آرایه JSON است که هر خانه آن رشته ویرگول‌دار مترادف‌های یک عبارت کلیدی است:
     * خانه ۰ برای کلیدواژه کانونی و خانه‌های بعدی به ترتیب برای عبارات کلیدی مرتبط.
     * اگر هر مترادف در یک خانه جدا ذخیره شود، Yoast فقط اولی را مترادف کلیدواژه کانونی می‌شناسد.
     */
    public static function yoast_synonyms_json($synonyms) {
        if (!$synonyms) return '';
        $slots = array(implode(', ', $synonyms));
        foreach ($synonyms as $unused) $slots[] = '';
        return wp_json_encode($slots, JSON_UNESCAPED_UNICODE);
    }

    /**
     * عبارات کلیدی مرتبط Yoast (نسخه پرمیوم) با قالب استاندارد خودش؛ هر عبارت یک آیتم جدا
     */
    public static function yoast_related_json($synonyms) {
        if (!$synonyms) return '';
        $items = array();
        foreach ($synonyms as $keyword) $items[] = array('keyword' => $keyword, 'score' => 'good');
        return wp_json_encode($items, JSON_UNESCAPED_UNICODE);
    }

    /**
     * مترادف‌های کلیدواژه کانونی از متای ذخیره‌شده Yoast؛ قالب قدیمی افزونه (هر مترادف یک خانه) هم خوانده می‌شود
     */
    public static function parse_yoast_synonyms($json) {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) return self::split_keywords($json);
        $first = isset($decoded[0]) ? (string) $decoded[0] : '';
        $rest = array_filter(array_slice($decoded, 1), 'is_string');
        $legacy = count(array_filter($rest, 'strlen')) > 0 && strpos($first, ',') === false && strpos($first, '،') === false;
        return self::split_keywords($legacy ? implode(',', $decoded) : $first);
    }

    /**
     * متای پست: Yoast کلیدواژه اصلی و مترادف‌ها را جدا نگه می‌دارد، RankMath همه را در یک فهرست ویرگول‌دار می‌خواهد
     */
    public static function post_meta_map($seo) {
        $focus = trim((string) $seo['focuskw']);
        $synonyms = self::split_keywords($seo['synonyms'], $focus);
        $map = array(
            '_yoast_wpseo_title'           => $seo['title'],
            '_yoast_wpseo_metadesc'        => $seo['desc'],
            '_yoast_wpseo_focuskw'         => $focus,
            '_yoast_wpseo_keywordsynonyms' => self::yoast_synonyms_json($synonyms),
            '_yoast_wpseo_focuskeywords'   => self::yoast_related_json($synonyms),
            'rank_math_title'              => $seo['title'],
            'rank_math_description'        => $seo['desc'],
            'rank_math_focus_keyword'      => implode(',', array_filter(array_merge(array($focus), $synonyms), 'strlen')),
        );
        return array_filter($map, function ($value) {
            return $value !== '';
        });
    }

    /**
     * متای ترم در Yoast (گزینه wpseo_taxonomy_meta) با همان قالب مترادف‌ها و عبارات مرتبط
     */
    public static function term_meta_map($seo) {
        $focus = trim((string) $seo['focuskw']);
        $synonyms = self::split_keywords($seo['synonyms'], $focus);
        $map = array(
            'wpseo_title'           => $seo['title'],
            'wpseo_desc'            => $seo['desc'],
            'wpseo_focuskw'         => $focus,
            'wpseo_keywordsynonyms' => self::yoast_synonyms_json($synonyms),
            'wpseo_focuskeywords'   => self::yoast_related_json($synonyms),
        );
        return array_filter($map, function ($value) {
            return $value !== '';
        });
    }

    /**
     * عنوان، توضیحات متا، کلیدواژه کانونی و مترادف‌های ذخیره‌شده یک پست (RankMath مقدم بر Yoast)
     *
     * @return array{title:string,desc:string,focuskw:string,synonyms:string}
     */
    public function read_post_seo($id) {
        // RankMath کلیدواژه اصلی و مترادف‌ها را در یک فهرست دارد؛ Yoast مترادف‌ها را JSON ذخیره می‌کند.
        $rank_list = self::split_keywords((string) get_post_meta($id, 'rank_math_focus_keyword', true));
        $focus = $rank_list ? $rank_list[0] : (string) get_post_meta($id, '_yoast_wpseo_focuskw', true);
        $synonyms = $rank_list ? array_slice($rank_list, 1) : self::parse_yoast_synonyms(get_post_meta($id, '_yoast_wpseo_keywordsynonyms', true));
        $title = (string) get_post_meta($id, 'rank_math_title', true);
        if ($title === '') $title = (string) get_post_meta($id, '_yoast_wpseo_title', true);
        $desc = (string) get_post_meta($id, 'rank_math_description', true);
        if ($desc === '') $desc = (string) get_post_meta($id, '_yoast_wpseo_metadesc', true);
        return array('title' => $title, 'desc' => $desc, 'focuskw' => $focus, 'synonyms' => implode(', ', $synonyms));
    }

    /**
     * ذخیره متای سئو پست در یوست و رنک‌مث (فقط مقادیر غیرخالی)
     */
    public function write_post_seo($id, $seo) {
        foreach (self::post_meta_map($seo) as $meta_key => $value) {
            update_post_meta($id, $meta_key, $value);
        }
    }

    /**
     * متای سئوی ترم از گزینه wpseo_taxonomy_meta (Yoast)
     *
     * @return array{title:string,desc:string,focuskw:string,synonyms:string}
     */
    public function read_term_seo($taxonomy, $id) {
        $option = get_option(Settings::OPT_YOAST_TAXONOMY_META, array());
        $meta = is_array($option) && isset($option[$taxonomy][$id]) ? (array) $option[$taxonomy][$id] : array();
        $synonyms = self::parse_yoast_synonyms($meta['wpseo_keywordsynonyms'] ?? '');
        return array(
            'title'    => (string) ($meta['wpseo_title'] ?? ''),
            'desc'     => (string) ($meta['wpseo_desc'] ?? ''),
            'focuskw'  => (string) ($meta['wpseo_focuskw'] ?? ''),
            'synonyms' => implode(', ', $synonyms),
        );
    }

    /**
     * Yoast keeps taxonomy SEO data in a single option keyed by taxonomy and term id.
     */
    public function write_term_seo($taxonomy, $id, $seo) {
        if (!defined('WPSEO_VERSION')) return;
        $option = get_option(Settings::OPT_YOAST_TAXONOMY_META, array());
        if (!is_array($option)) $option = array();
        foreach (self::term_meta_map($seo) as $meta_key => $value) {
            $option[$taxonomy][$id][$meta_key] = $value;
        }
        update_option(Settings::OPT_YOAST_TAXONOMY_META, $option);
    }
}
