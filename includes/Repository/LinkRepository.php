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
}
