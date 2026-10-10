<?php
/**
 * مدیریت ساختار جداول اختصاصی پایگاه‌داده
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_DB {

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'wila_internal_links';
    }

    public static function get_content_history_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'wila_content_history';
    }

    /**
     * ایجاد یا ارتقای جداول دیتابیس
     */
    public static function create_tables() {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

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

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $history_table = self::get_content_history_table_name();
        $history_sql = "CREATE TABLE {$history_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            focus_keyword VARCHAR(255) NOT NULL DEFAULT '',
            content LONGTEXT NOT NULL,
            featured_image_prompt TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY created_at_idx (created_at)
        ) $charset_collate;";
        dbDelta($history_sql);

        update_option('wila_db_installed_version', WILA_VERSION);
    }

    /**
     * پاکسازی تمام داده‌های جدول ایندکس
     */
    public static function truncate_links() {
        global $wpdb;
        $table_name = self::get_table_name();
        $wpdb->query("TRUNCATE TABLE {$table_name}");
    }

    /**
     * حذف داده‌های مربوط به یک مبدا مشخص (قبل از بازاسکن آن)
     */
    public static function delete_source_links($source_id, $source_type) {
        global $wpdb;
        $table_name = self::get_table_name();
        $wpdb->delete(
            $table_name,
            array('source_id' => $source_id, 'source_type' => $source_type),
            array('%d', '%s')
        );
    }
}