<?php
/**
 * Database layer: table names, schema creation/upgrade, versioning and CRUD for the plugin tables.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Repository;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class DatabaseRepository {

    /** Single schema-version option. */
    const VERSION_OPTION = Settings::OPT_DB_VERSION;

    /** Previous option that tracked the installed schema version (migrated, then removed). */
    const LEGACY_VERSION_OPTION = Settings::OPT_DB_INSTALLED_VERSION_LEGACY;

    /** @var \wpdb */
    private $wpdb;

    public function __construct($wpdb = null) {
        $this->wpdb = $wpdb !== null ? $wpdb : $GLOBALS['wpdb'];
    }

    // ---- table names ----

    public function links_table() {
        return $this->wpdb->prefix . Settings::TABLE_LINKS;
    }

    public function bulk_items_table() {
        return $this->wpdb->prefix . Settings::TABLE_BULK_ITEMS;
    }

    public function content_history_table() {
        return $this->wpdb->prefix . Settings::TABLE_CONTENT_HISTORY;
    }

    // ---- schema & versioning ----

    /**
     * Soft migration: the old installed-version option was the accurate one (updated on every
     * schema upgrade), so its value replaces the stale activation-time value, then it is removed.
     */
    public function migrate_legacy_version_option() {
        $legacy = get_option(self::LEGACY_VERSION_OPTION, null);
        if ($legacy === null) {
            return;
        }
        update_option(self::VERSION_OPTION, $legacy);
        delete_option(self::LEGACY_VERSION_OPTION);
    }

    public function maybe_upgrade() {
        $this->migrate_legacy_version_option();
        if (get_option(self::VERSION_OPTION) !== WILA_VERSION) {
            $this->create_tables();
        }
    }

    /**
     * ایجاد یا ارتقای جداول دیتابیس
     */
    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $charset_collate = $this->wpdb->get_charset_collate();

        $this->create_links_table($charset_collate);
        $this->create_bulk_items_table($charset_collate);
        $this->create_content_history_table($charset_collate);
        $this->upgrade_content_history_columns();

        update_option(self::VERSION_OPTION, WILA_VERSION);
    }

    private function create_links_table($charset_collate) {
        $table_name = $this->links_table();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT(20) UNSIGNED NOT NULL,
            source_type VARCHAR(20) NOT NULL DEFAULT 'post',
            source_title VARCHAR(255) NOT NULL,
            source_url VARCHAR(500) NOT NULL,
            target_url VARCHAR(500) NOT NULL,
            target_id BIGINT(20) UNSIGNED DEFAULT NULL,
            target_type VARCHAR(20) DEFAULT 'unknown',
            anchor_text VARCHAR(255) NOT NULL DEFAULT '',
            rel_attribute VARCHAR(100) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY source_index (source_id, source_type),
            KEY target_index (target_id, target_type),
            KEY target_url_idx (target_url(191))
        ) $charset_collate;";
        dbDelta($sql);
    }

    /**
     * سابقه آپلود CSV: نام و نامک ردیف‌ها برای تشخیص ردیف‌های تکراری در آپلودهای بعدی
     */
    private function create_bulk_items_table($charset_collate) {
        $bulk_table = $this->bulk_items_table();
        $bulk_sql = "CREATE TABLE {$bulk_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name_key VARCHAR(191) NOT NULL DEFAULT '',
            slug_key VARCHAR(191) NOT NULL DEFAULT '',
            file_name VARCHAR(255) NOT NULL DEFAULT '',
            first_seen DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY name_key_idx (name_key),
            KEY slug_key_idx (slug_key)
        ) $charset_collate;";
        dbDelta($bulk_sql);
    }

    private function create_content_history_table($charset_collate) {
        $history_table = $this->content_history_table();
        $history_sql = "CREATE TABLE {$history_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            focus_keyword VARCHAR(255) NOT NULL DEFAULT '',
            slug VARCHAR(200) NOT NULL DEFAULT '',
            content_kind VARCHAR(20) NOT NULL DEFAULT 'article',
            target_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            content LONGTEXT NOT NULL,
            featured_image_prompt TEXT NOT NULL,
            internal_link_count INT UNSIGNED NOT NULL DEFAULT 0,
            external_link_count INT UNSIGNED NOT NULL DEFAULT 0,
            external_links LONGTEXT NOT NULL,
            seo_title VARCHAR(255) NOT NULL DEFAULT '',
            meta_description TEXT NOT NULL,
            keyword_synonyms TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY created_at_idx (created_at)
        ) $charset_collate;";
        dbDelta($history_sql);
    }

    private function upgrade_content_history_columns() {
        $wpdb = $this->wpdb;
        $history_table = $this->content_history_table();
        $history_columns = $wpdb->get_col("SHOW COLUMNS FROM {$history_table}", 0);
        $history_column_sql = array(
            'slug' => "ALTER TABLE {$history_table} ADD slug VARCHAR(200) NOT NULL DEFAULT '' AFTER focus_keyword",
            'content_kind' => "ALTER TABLE {$history_table} ADD content_kind VARCHAR(20) NOT NULL DEFAULT 'article' AFTER slug",
            'target_id' => "ALTER TABLE {$history_table} ADD target_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER content_kind",
            'internal_link_count' => "ALTER TABLE {$history_table} ADD internal_link_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER featured_image_prompt",
            'external_link_count' => "ALTER TABLE {$history_table} ADD external_link_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER internal_link_count",
            'external_links' => "ALTER TABLE {$history_table} ADD external_links LONGTEXT NOT NULL AFTER external_link_count",
            'seo_title' => "ALTER TABLE {$history_table} ADD seo_title VARCHAR(255) NOT NULL DEFAULT '' AFTER external_links",
            'meta_description' => "ALTER TABLE {$history_table} ADD meta_description TEXT NOT NULL AFTER seo_title",
            'keyword_synonyms' => "ALTER TABLE {$history_table} ADD keyword_synonyms TEXT NOT NULL AFTER meta_description",
        );
        foreach ($history_column_sql as $column => $sql) {
            if (!in_array($column, $history_columns, true)) $wpdb->query($sql);
        }
    }

    // ---- CRUD ----

    /**
     * ثبت یک محتوای تولیدشده در آرشیو
     */
    public function insert_content_history($params, $result) {
        $this->wpdb->insert($this->content_history_table(), array(
            'title' => $params['h1_title'],
            'focus_keyword' => $params['focus_keyword'],
            'slug' => $result['slug'],
            'content_kind' => $params['content_kind'],
            'target_id' => $params['target_id'],
            'content' => $result['content'],
            'featured_image_prompt' => $result['featured_image_prompt'],
            'internal_link_count' => $result['internal_link_count'],
            'external_link_count' => $result['external_link_count'],
            'external_links' => wp_json_encode($result['external_links'], JSON_UNESCAPED_UNICODE),
            'seo_title' => $result['seo_title'],
            'meta_description' => $result['meta_description'],
            'keyword_synonyms' => $result['keyword_synonyms'],
            'created_at' => current_time('mysql'),
        ), array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s'));
    }

    /**
     * فهرست ۱۰۰ محتوای آخر آرشیو (بدون متن کامل)
     */
    public function list_content_history() {
        $table = $this->content_history_table();
        return $this->wpdb->get_results("SELECT id, title, focus_keyword, slug, content_kind, target_id, internal_link_count, external_link_count, seo_title, meta_description, keyword_synonyms, created_at FROM {$table} ORDER BY created_at DESC, id DESC LIMIT 100", ARRAY_A);
    }

    public function find_content_history($id) {
        $table = $this->content_history_table();
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT id, title, focus_keyword, slug, content_kind, target_id, content, featured_image_prompt, internal_link_count, external_link_count, external_links, seo_title, meta_description, keyword_synonyms, created_at FROM {$table} WHERE id = %d", absint($id)), ARRAY_A);
    }

    public function delete_content_history($id) {
        return $this->wpdb->delete($this->content_history_table(), array('id' => absint($id)), array('%d'));
    }

    /**
     * پاکسازی تمام داده‌های جدول ایندکس
     */
    public function truncate_links() {
        $table_name = $this->links_table();
        $this->wpdb->query("TRUNCATE TABLE {$table_name}");
    }

    /**
     * حذف داده‌های مربوط به یک مبدا مشخص (قبل از بازاسکن آن)
     */
    public function delete_source_links($source_id, $source_type) {
        $this->wpdb->delete(
            $this->links_table(),
            array('source_id' => $source_id, 'source_type' => $source_type),
            array('%d', '%s')
        );
    }
}
