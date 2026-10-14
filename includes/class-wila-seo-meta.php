<?php
/**
 * نگاشت داده‌های سئو به متافیلدهای Yoast و RankMath؛ کلیدواژه اصلی و مترادف‌ها هرکدام در جای خودشان
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Seo_Meta {

    /**
     * فهرست یکتای کلیدواژه‌ها از رشته‌ای که با ویرگول فارسی یا لاتین جدا شده؛ کلیدواژه اصلی حذف می‌شود
     */
    public static function split_keywords($raw, $exclude = '') {
        $exclude_key = mb_strtolower(trim((string) $exclude));
        $seen = array();
        $list = array();
        foreach (preg_split('/[,،]/u', (string) $raw) as $item) {
            $item = trim(preg_replace('/\s+/u', ' ', $item));
            $key = mb_strtolower($item);
            if ($item === '' || $key === $exclude_key || isset($seen[$key])) continue;
            $seen[$key] = true;
            $list[] = $item;
        }
        return $list;
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
            '_yoast_wpseo_keywordsynonyms' => $synonyms ? wp_json_encode($synonyms, JSON_UNESCAPED_UNICODE) : '',
            'rank_math_title'              => $seo['title'],
            'rank_math_description'        => $seo['desc'],
            'rank_math_focus_keyword'      => implode(',', array_filter(array_merge(array($focus), $synonyms), 'strlen')),
        );
        return array_filter($map, function ($value) {
            return $value !== '';
        });
    }

    /**
     * متای ترم در Yoast (گزینه wpseo_taxonomy_meta) با همان قالب مترادف‌های JSON
     */
    public static function term_meta_map($seo) {
        $focus = trim((string) $seo['focuskw']);
        $synonyms = self::split_keywords($seo['synonyms'], $focus);
        $map = array(
            'wpseo_title'          => $seo['title'],
            'wpseo_desc'           => $seo['desc'],
            'wpseo_focuskw'        => $focus,
            'wpseo_keywordsynonyms' => $synonyms ? wp_json_encode($synonyms, JSON_UNESCAPED_UNICODE) : '',
        );
        return array_filter($map, function ($value) {
            return $value !== '';
        });
    }
}
