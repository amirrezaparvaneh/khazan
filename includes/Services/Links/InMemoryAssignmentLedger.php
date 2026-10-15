<?php
/**
 * Ledger kept in memory (unit tests and single-request runs).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

if (!defined('ABSPATH')) {
    exit;
}

final class InMemoryAssignmentLedger implements AssignmentLedger {

    /** @var array */
    private $runs = array();

    public function load($run_id) {
        return isset($this->runs[$run_id]) ? $this->runs[$run_id] : array('assigned' => array(), 'done' => array());
    }

    public function save($run_id, array $state) {
        $this->runs[$run_id] = $state;
    }
}
