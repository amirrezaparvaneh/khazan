<?php
/**
 * Ledger persisted in a transient, shared by the REST requests of one bulk run.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class TransientAssignmentLedger implements AssignmentLedger {

    public function load($run_id) {
        $state = get_transient(Settings::TRANSIENT_LINK_LEDGER_PREFIX . $run_id);
        if (!is_array($state) || !isset($state['assigned'], $state['done'])) {
            return array('assigned' => array(), 'done' => array());
        }
        return $state;
    }

    public function save($run_id, array $state) {
        set_transient(Settings::TRANSIENT_LINK_LEDGER_PREFIX . $run_id, $state, Settings::LINK_LEDGER_TTL);
    }
}
