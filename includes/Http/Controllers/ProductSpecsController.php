<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

use Khazan\Core\Settings;
use Khazan\Http\Requests\ProductSpecsListRequest;
use Khazan\Http\Requests\ProductSpecsSaveRequest;
use Khazan\Repository\ProductSpecsRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * «مدیریت سریع ویژگی‌های محصولات»: فهرست صفحه‌بندی‌شده/جستجوپذیر و ذخیره ایجکس مشخصات فنی هر محصول
 */
final class ProductSpecsController extends BaseController {

    public function list_products($request) {
        $args = (new ProductSpecsListRequest($request))->to_args();
        $result = (new ProductSpecsRepository())->list_products($args);
        return rest_ensure_response(array_merge(array('success' => true, 'fields' => Settings::PRODUCT_SPEC_FIELDS), $result));
    }

    public function save_specs($request) {
        $input = (new ProductSpecsSaveRequest($request->get_json_params()))->validated();
        if (is_wp_error($input)) return $input;
        $saved = (new ProductSpecsRepository())->save($input['product_id'], $input['specs']);
        if (is_wp_error($saved)) return $saved;
        return rest_ensure_response(array('success' => true, 'product_id' => $input['product_id'], 'specs' => $saved));
    }
}
