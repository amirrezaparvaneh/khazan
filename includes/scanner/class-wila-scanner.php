<?php
/**
 * لایه ارجاع‌دهنده به Khazan\Services\Scan\ScannerService (امضای متدهای عمومی قبلی حفظ شده است)
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Scanner {

    private $service;

    public function __construct() {
        $this->service = new \Khazan\Services\Scan\ScannerService();
    }

    public function get_all_scannable_items() {
        return $this->service->get_all_scannable_items();
    }

    public function scan_item($item_id, $item_type) {
        return $this->service->scan_item($item_id, $item_type);
    }
}
