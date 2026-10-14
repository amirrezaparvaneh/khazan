<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * صف اسکن لینک‌ها و صف تولید انبوه محصولات (تطبیق ردیف‌ها و پردازش تک‌ردیفی)
 */
final class QueueController extends BaseController {

    public function get_scan_queue() {
        $scanner = new \Khazan\Services\Scan\ScannerService();
        $items = $scanner->get_all_scannable_items();
        return rest_ensure_response(array('success' => true, 'total' => count($items), 'items' => $items));
    }

    public function scan_single_item($request) {
        $params  = $request->get_json_params();
        $item_id = isset($params['id']) ? absint($params['id']) : 0;
        $type    = isset($params['type']) ? sanitize_text_field($params['type']) : 'post';
        if (!$item_id) return new \WP_Error('invalid_id', 'شناسه نامعتبر.', array('status' => 400));
        
        $scanner = new \Khazan\Services\Scan\ScannerService();
        $result  = $scanner->scan_item($item_id, $type);
        return rest_ensure_response(array('success' => true, 'item_id' => $item_id, 'links_found' => isset($result['links_found']) ? $result['links_found'] : 0));
    }

    public function bulk_match_products($request) {
        $params = $request->get_json_params();
        $rows = isset($params['rows']) && is_array($params['rows']) ? array_slice($params['rows'], 0, 1000) : array();
        $file_name = isset($params['file_name']) ? sanitize_text_field($params['file_name']) : '';
        $result = \Khazan\Services\Csv\ProductMatcher::match_rows($rows, $file_name);
        return is_wp_error($result) ? $result : rest_ensure_response(array('success' => true, 'matches' => $result));
    }

    public function bulk_process_product($request) {
        $params = $request->get_json_params();
        $result = (new \Khazan\Services\Csv\BulkRowProcessor())->process(
            isset($params['product_id']) ? $params['product_id'] : 0,
            isset($params['row']) && is_array($params['row']) ? $params['row'] : array(),
            isset($params['options']) && is_array($params['options']) ? $params['options'] : array()
        );
        return is_wp_error($result) ? $result : rest_ensure_response(array_merge(array('success' => true), $result));
    }
}
