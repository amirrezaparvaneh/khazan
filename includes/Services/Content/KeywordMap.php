<?php
/**
 * Keyword ownership: one main keyword per page plus up to three real (Search Console) secondary keywords.
 * The map is stored in the `wila_keyword_map` option as {slug: {main, secondary: []}} and can be imported from the
 * project's ownership table; a page without an entry falls back to its own name and no secondary keywords - the
 * writer never invents a keyword that is not in the map.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content;

if (!defined('ABSPATH')) {
    exit;
}

final class KeywordMap {

    const OPTION = 'wila_keyword_map';
    const FILTER = 'wila_keyword_map';
    const MAX_SECONDARY = 3;

    /** @return array{main:string,secondary:string[],mapped:bool} */
    public static function for_page($slug, $fallback_name) {
        $map = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        $map = apply_filters(self::FILTER, is_array($map) ? $map : array());
        $row = isset($map[$slug]) && is_array($map[$slug]) ? $map[$slug] : array();
        $main = isset($row['main']) ? trim((string) $row['main']) : '';
        $secondary = array();
        foreach (isset($row['secondary']) ? (array) $row['secondary'] : array() as $word) {
            $word = trim((string) $word);
            if ($word !== '' && $word !== $main) $secondary[] = $word;
        }
        return array(
            'main'      => $main !== '' ? $main : trim((string) $fallback_name),
            'secondary' => array_slice(array_values(array_unique($secondary)), 0, self::MAX_SECONDARY),
            'mapped'    => $main !== '',
        );
    }

    /**
     * Parses the project's ownership.csv rows (keyword_or_group, owner_url, owner_type, ...) into the map: the row
     * whose owner_type is «category» is the main keyword, «subcategory» rows are secondary keywords of that URL.
     *
     * @param array[] $rows associative CSV rows
     * @return array<string,array>
     */
    public static function from_ownership(array $rows) {
        $map = array();
        foreach ($rows as $row) {
            $url = isset($row['owner_url']) ? (string) $row['owner_url'] : '';
            $word = isset($row['keyword_or_group']) ? trim(preg_replace('/\s*\(.*?\)\s*/u', ' ', (string) $row['keyword_or_group'])) : '';
            if ($url === '' || $word === '' || stripos($url, 'http') !== 0) continue;
            $slug = BusinessFacts::slug_from_url($url);
            if ($slug === '') continue;
            if (!isset($map[$slug])) $map[$slug] = array('main' => '', 'secondary' => array());
            $type = isset($row['owner_type']) ? $row['owner_type'] : '';
            if ($type === 'category' && $map[$slug]['main'] === '') $map[$slug]['main'] = $word;
            else $map[$slug]['secondary'][] = $word;
        }
        return $map;
    }
}
