<?php
/**
 * Single source of truth for the content standards of the site (length, keyword density, titles, link ranges).
 *
 * Every number the generators, prompts, guards and link planners used to hard-code now comes from here. The defaults
 * follow the SEO rules of the project (category = 1300-1500 words, density 1-2 %, 5-8 internal links, ...); each value
 * can be overridden with the `wila_seo_standards` filter or the saved `wila_seo_standards` option (array).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class SeoStandards {

    const OPTION = 'wila_seo_standards';
    const FILTER = 'wila_seo_standards';

    /**
     * @return array<string,mixed>
     */
    public static function defaults() {
        return array(
            // main body text (HTML below the product list)
            'body_words_min'      => 1300,
            'body_words_max'      => 1500,   // soft ceiling: longer is fine only with real content
            'product_words'       => array(550, 800),
            'article_words'       => array(1000, 1500),
            'top_words_min'       => 150,    // text above the product list
            'top_words_max'       => 250,
            'keyword_in_first'    => 100,    // the focus keyword must appear within the first N words
            'density_min'         => 1.0,    // percent of all words
            'density_max'         => 2.0,
            'h2_min'              => 3,
            'h2_keyword_max'      => 2,      // focus keyword allowed in at most this many H2
            'section_words_max'   => 220,    // words between two sub-headings
            // titles
            'title_min'           => 50,
            'title_max'           => 60,
            'meta_min'            => 140,
            'meta_max'            => 155,
            'title_forbidden'     => array('ارسال رایگان'),
            // links per page type: [min, max]
            'links_category'      => array(5, 8),
            'links_product'       => array(3, 6),
            'links_article'       => array(3, 8),
            'links_external'      => array(1, 2),
            'links_per_paragraph' => 2,
            'links_min_gap_words' => 70,     // words between two internal links: links are spread over the text, never bunched
            'link_each_url_once'  => true,
        );
    }

    /** @return array<string,mixed> */
    public static function all() {
        $values = self::defaults();
        $saved = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        if (is_array($saved)) {
            foreach ($saved as $key => $value) {
                if (array_key_exists($key, $values) && gettype($value) === gettype($values[$key])) $values[$key] = $value;
            }
        }
        $filtered = apply_filters(self::FILTER, $values);
        return is_array($filtered) ? array_merge($values, $filtered) : $values;
    }

    public static function get($key) {
        $all = self::all();
        return isset($all[$key]) ? $all[$key] : null;
    }

    /**
     * [min, max] internal links of a content kind.
     *
     * @return int[]
     */
    public static function internal_range($kind) {
        $key = $kind === Settings::KIND_CATEGORY ? 'links_category' : ($kind === Settings::KIND_PRODUCT ? 'links_product' : 'links_article');
        $range = self::get($key);
        $min = max(0, (int) $range[0]);
        return array($min, max($min, (int) $range[1]));
    }

    /**
     * Body length range of a content kind. Category pages follow body_words_min/max; products and articles have their own range.
     * @return int[] [min, max]
     */
    public static function words_range($kind) {
        if ($kind === Settings::KIND_PRODUCT || $kind === Settings::KIND_ARTICLE) {
            $range = self::get($kind === Settings::KIND_PRODUCT ? 'product_words' : 'article_words');
            if (is_array($range) && isset($range[0], $range[1])) return array(max(1, (int) $range[0]), max((int) $range[0], (int) $range[1]));
        }
        return array(max(1, (int) self::get('body_words_min')), max((int) self::get('body_words_min'), (int) self::get('body_words_max')));
    }

    /** @return int[] [min, max] verified external links */
    public static function external_range() {
        $range = self::get('links_external');
        $min = max(0, (int) $range[0]);
        return array($min, max($min, (int) $range[1]));
    }
}
