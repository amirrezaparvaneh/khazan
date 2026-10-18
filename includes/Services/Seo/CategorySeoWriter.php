<?php
/**
 * Persists the SEO fields of a product category the way Yoast expects them:
 *   wpseo_taxonomy_meta[taxonomy][term_id] = {wpseo_title, wpseo_desc, wpseo_focuskw}
 * plus the plugin's own term meta and - only when Rank Math is active - Rank Math's term meta. The focus keyphrase of a
 * category is its exact name, so (unlike product keyphrases) it is stored whole, never capped to 4 words.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Seo;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class CategorySeoWriter {

    /**
     * @param array{title:string,desc:string,focuskw:string} $seo
     * @return array<string,string> Yoast taxonomy-meta keys (empty values dropped)
     */
    public static function yoast_map(array $seo) {
        $map = array(
            'wpseo_title'   => SeoTitleGuard::strip_variables(isset($seo['title']) ? $seo['title'] : ''),
            'wpseo_desc'    => trim((string) (isset($seo['desc']) ? $seo['desc'] : '')),
            'wpseo_focuskw' => trim(preg_replace('/\s+/u', ' ', (string) (isset($seo['focuskw']) ? $seo['focuskw'] : ''))),
        );
        return array_filter($map, function ($value) {
            return $value !== '';
        });
    }

    public static function rank_math_active() {
        return defined('RANK_MATH_VERSION') || class_exists('RankMath');
    }

    public function write($taxonomy, $term_id, array $seo) {
        $map = self::yoast_map($seo);
        if (!$map) return;

        $option = get_option(Settings::OPT_YOAST_TAXONOMY_META, array());
        if (!is_array($option)) $option = array();
        foreach ($map as $key => $value) {
            $option[$taxonomy][$term_id][$key] = $value;
        }
        update_option(Settings::OPT_YOAST_TAXONOMY_META, $option);

        if (isset($map['wpseo_focuskw'])) update_term_meta($term_id, Settings::META_CATEGORY_FOCUS, $map['wpseo_focuskw']);
        if (self::rank_math_active()) {
            foreach (array(Settings::RANK_MATH_TERM_TITLE => 'wpseo_title', Settings::RANK_MATH_TERM_DESC => 'wpseo_desc', Settings::RANK_MATH_TERM_FOCUS => 'wpseo_focuskw') as $meta_key => $source) {
                if (isset($map[$source])) update_term_meta($term_id, $meta_key, $map[$source]);
            }
        }
    }
}
