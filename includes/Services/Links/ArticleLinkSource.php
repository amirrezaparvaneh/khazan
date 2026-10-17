<?php
/**
 * Data source of the article link distributor (interface keeps the algorithm pure and testable).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

if (!defined('ABSPATH')) {
    exit;
}

interface ArticleLinkSource {

    /**
     * @return array<int,array> product categories {id, name, url}
     */
    public function categories();

    /**
     * Global product pool (every published product is a possible target), shortlisted by inbound count.
     *
     * @param array<int,int> $assigned    product id => links already assigned in earlier generations
     * @param int            $category_id the article's matched category (0 = none); its products are preferred on ties
     * @return array<int,array> {id, title, url, anchor (the 4-word keyphrase), inbound, category_ids}
     */
    public function product_candidates(array $assigned, $category_id);

    /**
     * Published blog articles, shortlisted by inbound count.
     *
     * @return array<int,array> {id, title, url, anchor (the target's exact focus keyword), inbound}
     */
    public function article_candidates(array $assigned);

    /**
     * Homepage / brand link, only when a brand or service context exists.
     *
     * @return array|null {url, anchor}
     */
    public function brand_page();
}
