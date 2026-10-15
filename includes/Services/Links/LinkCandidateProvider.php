<?php
/**
 * Data source for the link distributor (kept behind an interface so the algorithm stays pure and testable).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

if (!defined('ABSPATH')) {
    exit;
}

interface LinkCandidateProvider {

    /**
     * @return array|null {id, title, url, anchor, synonyms?:string[], category_ids:int[], primary_category_id:int}
     */
    public function product($product_id);

    /**
     * @return array|null {id, name, url, parent}
     */
    public function category($category_id);

    /**
     * Published products that may receive a link from $product (same categories, then ancestors),
     * each in the same shape as product(); never contains $product itself.
     *
     * @return array<int,array>
     */
    public function candidates_for(array $product);

    /**
     * Current inbound internal-link counts.
     *
     * @param int[] $product_ids
     * @return array<int,int>
     */
    public function inbound_counts(array $product_ids);
}
