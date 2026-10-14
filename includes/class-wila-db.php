<?php
/**
 * لایه ارجاع‌دهنده سبک به Khazan\Repository\DatabaseRepository (امضای متدهای عمومی قبلی حفظ شده است)
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_DB {

    private static function repository() {
        return new \Khazan\Repository\DatabaseRepository();
    }

    public static function get_table_name() {
        return self::repository()->links_table();
    }

    public static function get_bulk_items_table_name() {
        return self::repository()->bulk_items_table();
    }

    public static function get_content_history_table_name() {
        return self::repository()->content_history_table();
    }

    public static function create_tables() {
        self::repository()->create_tables();
    }

    public static function insert_content_history($params, $result) {
        self::repository()->insert_content_history($params, $result);
    }

    public static function truncate_links() {
        self::repository()->truncate_links();
    }

    public static function delete_source_links($source_id, $source_type) {
        self::repository()->delete_source_links($source_id, $source_type);
    }
}
