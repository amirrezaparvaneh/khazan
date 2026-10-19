<?php
/**
 * لایه ارجاع‌دهنده به Khazan\Services\Ai\AiService (امضای متدهای عمومی قبلی حفظ شده است)
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_AvalAI_Client {

    const MODEL = \Khazan\Services\Ai\AiService::MODEL;

    /** @var \Khazan\Services\Ai\AiService */
    private $service;

    public function __construct() {
        $this->service = new \Khazan\Services\Ai\AiService();
    }

    public function is_configured() {
        return $this->service->is_configured();
    }

    public function get_active_model() {
        return $this->service->get_active_model();
    }

    public function test_connection($override_key = null) {
        return $this->service->test_connection($override_key);
    }

    public function get_balance($refresh = false) {
        return $this->service->get_balance($refresh);
    }

    public function suggest_seo_repairs($segments, $instructions, $meta = array(), $kind = 'post') {
        return $this->service->suggest_seo_repairs($segments, $instructions, $meta, $kind);
    }

    public function generate_content_blueprint($params) {
        return $this->service->generate_content_blueprint($params);
    }

    public function generate_link_suggestions($target_id, $target_type) {
        return $this->service->generate_link_suggestions($target_id, $target_type);
    }

    public function generate_content_outline($params) {
        return $this->service->generate_content_outline($params);
    }

    public function generate_full_content($params, $outline) {
        return $this->service->generate_full_content($params, $outline);
    }

    public function generate_seo_fix($kind, $current, $errors) {
        return $this->service->generate_seo_fix($kind, $current, $errors);
    }

    public function generate_bulk_product_content($params, $row) {
        return $this->service->generate_bulk_product_content($params, $row);
    }
}
