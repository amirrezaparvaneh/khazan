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
     * Product candidates in three groups, each shortlisted by inbound count: products of the matched categories or whose
     * title shares a topic word (Tier 1), products of related (parent / sibling / child) categories (Tier 2), and the
     * global catalogue (Tier 3). The distributor decides the tier of every entry; the union is returned without duplicates.
     *
     * @param array<int,int> $assigned     product id => links already assigned in earlier generations
     * @param int[]          $category_ids matched categories (Tier 1)
     * @param int[]          $related_ids  related categories (Tier 2)
     * @param string[]       $topic_tokens distinctive topic words (no generic words like چرم / خرید)
     * @return array<int,array> {id, title, url, anchor (the 4-word keyphrase), inbound, category_ids}
     */
    public function product_candidates(array $assigned, array $category_ids, array $related_ids, array $topic_tokens);

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
