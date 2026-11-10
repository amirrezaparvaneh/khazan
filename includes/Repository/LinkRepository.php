<?php
/**
 * Queries and inserts for the internal-link index table.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class LinkRepository {

    /** @var \wpdb */
    private $wpdb;

    /** @var DatabaseRepository */
    private $database;

    public function __construct($wpdb = null, DatabaseRepository $database = null) {
        $this->wpdb     = $wpdb !== null ? $wpdb : $GLOBALS['wpdb'];
        $this->database = $database ?: new DatabaseRepository($this->wpdb);
    }

    public function table_exists() {
        $table = $this->database->links_table();
        return (bool) $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $this->wpdb->esc_like($table)));
    }

    public function total_links() {
        $table = $this->database->links_table();
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public function outgoing_count($id, $type) {
        $table = $this->database->links_table();
        return (int) $this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE source_id = %d AND source_type = %s", $id, $type));
    }

    public function incoming_count($id, $type) {
        $table = $this->database->links_table();
        return (int) $this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE target_id = %d AND target_type = %s", $id, $type));
    }

    public function outgoing_links($id, $type) {
        $table = $this->database->links_table();
        return $this->wpdb->get_results($this->wpdb->prepare("SELECT target_url, target_type, anchor_text, rel_attribute FROM {$table} WHERE source_id = %d AND source_type = %s", $id, $type), ARRAY_A);
    }

    public function incoming_links($id, $type) {
        $table = $this->database->links_table();
        return $this->wpdb->get_results($this->wpdb->prepare("SELECT source_title, source_url, source_type, anchor_text, rel_attribute FROM {$table} WHERE target_id = %d AND target_type = %s", $id, $type), ARRAY_A);
    }

    /**
     * Inbound internal-link counts for the given target ids (ids without links are returned as 0).
     *
     * @param int[]  $ids
     * @return array<int,int> target_id => count
     */
    public function inbound_counts(array $ids, $type = 'product') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $counts = array_fill_keys($ids, 0);
        if (!$ids) {
            return $counts;
        }
        $table = $this->database->links_table();
        $in = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT target_id, COUNT(*) AS inbound FROM {$table} WHERE target_type = %s AND target_id IN ({$in}) GROUP BY target_id",
            array_merge(array($type), $ids)
        ), ARRAY_A);
        foreach ((array) $rows as $row) {
            $counts[(int) $row['target_id']] = (int) $row['inbound'];
        }
        return $counts;
    }

    /**
     * Distinct (target_id, target_type) pairs that receive at least one internal link.
     */
    public function linked_targets() {
        $table = $this->database->links_table();
        return $this->wpdb->get_results("SELECT DISTINCT target_id, target_type FROM {$table} WHERE target_id IS NOT NULL", ARRAY_A);
    }

    /**
     * @param array $row source_id, source_type, source_title, source_url, target_url, target_id, target_type, anchor_text, rel_attribute, created_at
     */
    public function insert_link(array $row) {
        $this->wpdb->insert(
            $this->database->links_table(),
            $row,
            array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Outgoing internal links per source page (distinct target URLs).
     *
     * @return array[] source_id, source_type, source_title, source_url, links
     */
    public function outgoing_totals() {
        $table = $this->database->links_table();
        return (array) $this->wpdb->get_results("SELECT source_id, source_type, MAX(source_title) AS source_title, MAX(source_url) AS source_url, COUNT(DISTINCT target_url) AS links FROM {$table} WHERE target_type <> 'unknown_internal' GROUP BY source_id, source_type", ARRAY_A);
    }

    /**
     * Anchor usage: how many times an anchor text points at a target.
     *
     * @return array[] anchor_text, target_id, target_type, target_url, uses
     */
    public function anchor_groups($limit = 20000) {
        $table = $this->database->links_table();
        return (array) $this->wpdb->get_results($this->wpdb->prepare("SELECT anchor_text, target_id, target_type, MAX(target_url) AS target_url, COUNT(*) AS uses FROM {$table} WHERE anchor_text <> '' GROUP BY anchor_text, target_id, target_type ORDER BY uses DESC LIMIT %d", (int) $limit), ARRAY_A);
    }

    /**
     * Inbound links per target page.
     *
     * @return array[] target_id, target_type, target_url, inbound
     */
    public function inbound_totals() {
        $table = $this->database->links_table();
        return (array) $this->wpdb->get_results("SELECT target_id, target_type, MAX(target_url) AS target_url, COUNT(DISTINCT source_id, source_type) AS inbound FROM {$table} WHERE target_id IS NOT NULL GROUP BY target_id, target_type", ARRAY_A);
    }
}
