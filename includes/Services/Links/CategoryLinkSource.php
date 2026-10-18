<?php
/**
 * Data the category spider linker needs from the site (kept behind an interface so the planner is unit-testable).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

if (!defined('ABSPATH')) {
    exit;
}

interface CategoryLinkSource {

    /**
     * Every product category of the site (empty ones included).
     *
     * @return array[] {id, name, url, parent, anchor} - anchor is the exact focus keyphrase / name of the category
     */
    public function categories();

    /**
     * Inbound links per category, counted inside the descriptions of OTHER categories.
     * Descriptions of the categories in $exclude_source_ids are ignored (they are re-generated in the current run
     * and counted through the run ledger instead).
     *
     * @param int[] $exclude_source_ids
     * @return array<int,int> term_id => inbound link count (missing = 0)
     */
    public function inbound_counts(array $exclude_source_ids = array());
}
