<?php
/**
 * Global, balanced candidate pool for internal product links (mesh / spider architecture).
 *
 * Every published product is a possible link target. The shortlist is ordered by effective inbound count
 * (database + links already assigned in the current run, lowest first), then related (same / ancestor category)
 * products, then cyclic id order from the current product (id+1, id+2, ... then wrap around), and cut to SIZE.
 * The least-linked products therefore always reach the distributor (no product stays without inbound links),
 * related ones win ties, and the targets rotate from row to row instead of always being the lowest ids.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

if (!defined('ABSPATH')) {
    exit;
}

final class GlobalLinkPool {

    /** Maximum number of shortlisted candidates that get hydrated by the provider. */
    const SIZE = 80;

    /**
     * @param int[]            $all_ids     Every published product id (self may be included; it is removed).
     * @param array<int,int>   $db_counts   Inbound counts from the database (missing = 0).
     * @param array<int,int>   $assigned    Links already assigned in the current run (missing = 0).
     * @param int              $self_id     The product that receives the links.
     * @param int[]            $related_ids Same / ancestor category products (preferred among equal counts).
     * @return int[] shortlisted ids (at most $size)
     */
    public static function shortlist(array $all_ids, array $db_counts, array $assigned, $self_id, array $related_ids = array(), $size = self::SIZE) {
        $self_id = (int) $self_id;
        $related = array_flip(array_map('intval', $related_ids));
        $rows = array();
        foreach ($all_ids as $id) {
            $id = (int) $id;
            if ($id === $self_id || $id <= 0 || isset($rows[$id])) continue;
            $rows[$id] = array(
                'id'      => $id,
                'count'   => (isset($db_counts[$id]) ? (int) $db_counts[$id] : 0) + (isset($assigned[$id]) ? (int) $assigned[$id] : 0),
                'related' => isset($related[$id]) ? 0 : 1,
                'wrap'    => $id > $self_id ? 0 : 1,
            );
        }
        usort($rows, function ($a, $b) {
            foreach (array('count', 'related', 'wrap', 'id') as $field) {
                if ($a[$field] !== $b[$field]) return $a[$field] < $b[$field] ? -1 : 1;
            }
            return 0;
        });
        return array_map(function ($row) { return $row['id']; }, array_slice($rows, 0, max(1, (int) $size)));
    }
}
