<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * تنظیمات افزونه، کلید API، تست اتصال و فهرست نوع‌محتوا/طبقه‌بندی‌ها
 */
final class SettingsController extends BaseController {

    public function get_settings() {
        $provider = 'avalai';
        $avalai_key = trim(get_option('wila_avalai_api_key', ''));
        $avalai_client = new \WILA_AvalAI_Client();
        $homepage_keywords = get_option('wila_homepage_keywords', '');

        return rest_ensure_response(array(
            'provider' => $provider,
            'has_avalai_key' => !empty($avalai_key),
            'avalai_masked_key' => !empty($avalai_key) ? (substr($avalai_key, 0, 6) . '...' . substr($avalai_key, -4)) : '',
            'avalai_model' => $avalai_client->get_active_model(),
            'homepage_keywords' => $homepage_keywords,
            'product_post_type' => \WILA_Content_Targets::product_post_type(),
            'product_category_taxonomy' => \WILA_Content_Targets::category_taxonomy(),
        ));
    }

    public function get_available_post_types() {
        $pts = get_post_types(array('public' => true), 'objects');
        unset($pts['attachment']);
        $types = array();
        foreach ($pts as $pt) {
            $types[] = array('value' => $pt->name, 'label' => $pt->labels->singular_name ?? $pt->name);
        }
        return rest_ensure_response(array('success' => true, 'items' => array_values($types)));
    }

    public function get_available_taxonomies($request) {
        $post_type = sanitize_key($request['post_type']);
        if (!post_type_exists($post_type)) {
            return new \WP_Error('invalid_post_type', 'نوع محتوا یافت نشد.', array('status' => 400));
        }
        $taxes = get_object_taxonomies($post_type, 'objects');
        $items = array();
        foreach ($taxes as $tax) {
            if (!$tax->public) continue;
            $items[] = array('value' => $tax->name, 'label' => $tax->labels->singular_name ?? $tax->name);
        }
        return rest_ensure_response(array('success' => true, 'items' => $items));
    }

    public function save_settings($request) {
        $params   = $request->get_json_params();
        update_option('wila_ai_provider', 'avalai');

        if (isset($params['homepage_keywords'])) {
            update_option('wila_homepage_keywords', sanitize_textarea_field($params['homepage_keywords']));
        }
        if (isset($params['avalai_api_key'])) {
            $key = trim(sanitize_text_field($params['avalai_api_key']));
            if (!empty($key) && strpos($key, '...') === false) {
                update_option('wila_avalai_api_key', $key);
            }
        }
        if (isset($params['product_post_type'])) {
            $pt = sanitize_key($params['product_post_type']);
            if (post_type_exists($pt)) update_option('wila_product_post_type', $pt);
        }
        if (isset($params['product_category_taxonomy'])) {
            $tax = sanitize_key($params['product_category_taxonomy']);
            if (taxonomy_exists($tax)) update_option('wila_product_category_taxonomy', $tax);
        }

        return rest_ensure_response(array('success' => true, 'message' => 'تنظیمات با موفقیت ذخیره شد.'));
    }

    public function test_avalai_connection($request) {
        $params   = $request->get_json_params();
        $api_key = isset($params['avalai_api_key']) ? trim(sanitize_text_field($params['avalai_api_key'])) : null;
        if (!empty($api_key) && strpos($api_key, '...') !== false) {
            $api_key = null;
        }

        $client = new \WILA_AvalAI_Client();
        $result = $client->test_connection($api_key);

        if (is_wp_error($result)) {
            return new \WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => 400));
        }
        return rest_ensure_response($result);
    }
}
