<?php
/**
 * Per-run bookkeeping of links already handed out, so rows processed in separate requests stay balanced.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

if (!defined('ABSPATH')) {
    exit;
}

interface AssignmentLedger {

    /**
     * @return array{assigned:array<int,int>,done:array<int,bool>} product_id => inbound links assigned in this run / rows already processed
     */
    public function load($run_id);

    public function save($run_id, array $state);
}
