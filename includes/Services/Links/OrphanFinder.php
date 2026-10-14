<?php
/**
 * Finds scannable items that have no incoming internal link.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

if (!defined('ABSPATH')) {
    exit;
}

final class OrphanFinder {

    public static function calculate($items) {
        global $wpdb;
        $table = \WILA_DB::get_table_name();
        $targets = $wpdb->get_results("SELECT DISTINCT target_id, target_type FROM {$table} WHERE target_id IS NOT NULL", ARRAY_A);
        $map = array();
        foreach ($targets as $t) {
            $map[$t['target_type'] . '_' . $t['target_id']] = true;
        }
        
        $list = array();
        foreach ($items as $item) {
            if (!isset($map[$item['type'] . '_' . $item['id']])) {
                $list[] = $item;
            }
        }
        return array('list' => $list);
    }
}
