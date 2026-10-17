<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

use Khazan\Core\Settings;
use Khazan\Http\Requests\AiBalanceRequest;
use Khazan\Http\Requests\AvailableTaxonomiesRequest;
use Khazan\Http\Requests\ConnectionTestRequest;
use Khazan\Http\Requests\SettingsUpdateRequest;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * تنظیمات افزونه، کلید API، تست اتصال و فهرست نوع‌محتوا/طبقه‌بندی‌ها
 */
final class SettingsController extends BaseController {

    public function get_settings() {
        $provider = 'avalai';
        $avalai_key = trim(get_option(Settings::OPT_AVALAI_API_KEY, ''));
        $avalai_client = new \Khazan\Services\Ai\AiService();
        $homepage_keywords = get_option(Settings::OPT_HOMEPAGE_KEYWORDS, '');

        return rest_ensure_response(array(
            'provider' => $provider,
            'has_avalai_key' => !empty($avalai_key),
            'avalai_masked_key' => !empty($avalai_key) ? (substr($avalai_key, 0, 6) . '...' . substr($avalai_key, -4)) : '',
            'avalai_model' => $avalai_client->get_active_model(),
            'homepage_keywords' => $homepage_keywords,
            'auto_image_enabled' => Settings::auto_image_enabled(),
            'image_model' => Settings::IMAGE_MODEL,
            'product_post_type' => Settings::product_post_type(),
            'product_category_taxonomy' => Settings::category_taxonomy(),
            'ai_pricing' => \Khazan\Services\Ai\CostEstimator::per_row($avalai_client->get_active_model()),
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
        $post_type = (new AvailableTaxonomiesRequest($request['post_type']))->post_type();
        if (is_wp_error($post_type)) return $post_type;
        $taxes = get_object_taxonomies($post_type, 'objects');
        $items = array();
        foreach ($taxes as $tax) {
            if (!$tax->public) continue;
            $items[] = array('value' => $tax->name, 'label' => $tax->labels->singular_name ?? $tax->name);
        }
        return rest_ensure_response(array('success' => true, 'items' => $items));
    }

    public function save_settings($request) {
        foreach ((new SettingsUpdateRequest($request->get_json_params()))->options() as $option => $value) {
            update_option($option, $value);
        }

        return rest_ensure_response(array('success' => true, 'message' => 'تنظیمات با موفقیت ذخیره شد.'));
    }

    public function test_avalai_connection($request) {
        $api_key = (new ConnectionTestRequest($request->get_json_params()))->api_key();

        $client = new \Khazan\Services\Ai\AiService();
        $result = $client->test_connection($api_key);

        if (is_wp_error($result)) {
            return new \WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => 400));
        }
        return rest_ensure_response($result);
    }

    /**
     * Live API credit for the dashboard badge (total / used / remaining in toman and dollars).
     */
    public function get_ai_balance($request) {
        $result = (new \Khazan\Services\Ai\AiService())->get_balance((new AiBalanceRequest($request))->refresh());
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            return new \WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => is_array($data) && isset($data['status']) ? (int) $data['status'] : 502));
        }
        return rest_ensure_response(array_merge(array('success' => true), $result));
    }
}
