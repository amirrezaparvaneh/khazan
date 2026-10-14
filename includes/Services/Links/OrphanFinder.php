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
        $targets = (new \Khazan\Repository\LinkRepository())->linked_targets();
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
