<?php
/**
 * لایه ارجاع‌دهنده به Khazan\Repository\BulkHistoryRepository (امضای متدهای عمومی قبلی حفظ شده است)
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Bulk_History {

    const CHUNK_SIZE = \Khazan\Repository\BulkHistoryRepository::CHUNK_SIZE;

    public static function sync(array $keys, $file_name) {
        return (new \Khazan\Repository\BulkHistoryRepository())->sync($keys, $file_name);
    }
}
