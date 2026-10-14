<?php
/**
 * لایه ارجاع‌دهنده به Khazan\Http\RestRouter (امضای متدهای عمومی قبلی حفظ شده است)
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_REST_Controller {

    public function register_routes() {
        (new \Khazan\Http\RestRouter())->register_routes();
    }

    public function check_permission() {
        return current_user_can('manage_options');
    }
}
