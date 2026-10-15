<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

use Khazan\Http\Requests\BulkMatchRequest;
use Khazan\Http\Requests\BulkProcessRequest;
use Khazan\Http\Requests\ScanItemRequest;
use Khazan\Http\Requests\SiteProductsRequest;

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
        $input = (new ScanItemRequest($request->get_json_params()))->validated();
        if (is_wp_error($input)) return $input;

        $scanner = new \Khazan\Services\Scan\ScannerService();
        $result  = $scanner->scan_item($input['id'], $input['type']);
        return rest_ensure_response(array('success' => true, 'item_id' => $input['id'], 'links_found' => isset($result['links_found']) ? $result['links_found'] : 0));
    }

    public function bulk_match_products($request) {
        $input = new BulkMatchRequest($request->get_json_params());
        $result = \Khazan\Services\Csv\ProductMatcher::match_rows($input->rows(), $input->file_name());
        return is_wp_error($result) ? $result : rest_ensure_response(array('success' => true, 'matches' => $result));
    }

    public function bulk_process_product($request) {
        $input = new BulkProcessRequest($request->get_json_params());
        $result = (new \Khazan\Services\Csv\BulkRowProcessor())->process($input->product_id(), $input->row(), $input->options());
        return is_wp_error($result) ? $result : rest_ensure_response(array_merge(array('success' => true), $result));
    }

    /**
     * Site products for the bulk queue without a CSV file (with the category filter list).
     */
    public function get_site_products($request) {
        $targets = new \Khazan\Repository\ContentTargetRepository();
        if (!post_type_exists(\Khazan\Core\Settings::product_post_type())) {
            return new \WP_Error('product_type_unavailable', 'نوع محتوای محصول در این سایت یافت نشد. آن را در تنظیمات مشخص کنید.', array('status' => 400));
        }
        $result = $targets->list_site_products((new SiteProductsRequest($request))->to_args());
        return rest_ensure_response(array_merge(
            array('success' => true, 'items' => $result['items'], 'has_more' => $result['has_more'], 'categories' => $targets->product_filter_categories(), 'counts' => $result['counts']),
            $result['counts']
        ));
    }
}
