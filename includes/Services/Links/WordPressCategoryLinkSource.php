<?php
/**
 * WordPress-backed source for the category spider linker: categories come from get_terms and inbound counts are
 * parsed out of the descriptions of the other categories (no raw SQL).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

use Khazan\Repository\CategoryRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class WordPressCategoryLinkSource implements CategoryLinkSource {

    /** @var CategoryRepository */
    private $categories;

    public function __construct(CategoryRepository $categories = null) {
        $this->categories = $categories ?: new CategoryRepository();
    }

    public function categories() {
        return $this->categories->all_for_linking();
    }

    public function inbound_counts(array $exclude_source_ids = array()) {
        $exclude = array_flip(array_map('intval', $exclude_source_ids));
        $by_url = array();
        foreach ($this->categories->all_for_linking() as $category) {
            $by_url[self::url_key($category['url'])] = (int) $category['id'];
        }

        $counts = array();
        foreach ($this->categories->descriptions() as $source_id => $description) {
            if (isset($exclude[(int) $source_id]) || !preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~is', (string) $description, $found)) continue;
            $seen = array();
            foreach ($found[2] as $href) {
                $key = self::url_key($href);
                if (!isset($by_url[$key]) || isset($seen[$key]) || $by_url[$key] === (int) $source_id) continue;
                $seen[$key] = true;
                $counts[$by_url[$key]] = (isset($counts[$by_url[$key]]) ? $counts[$by_url[$key]] : 0) + 1;
            }
        }
        return $counts;
    }

    private static function url_key($url) {
        $url = preg_replace('/#.*$/', '', trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8')));
        return untrailingslashit(rawurldecode($url));
    }
}
