<?php
/**
 * لایه ارجاع‌دهنده به Khazan\Services\Content\ContentRules (امضای متدهای عمومی قبلی حفظ شده است)
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Content_Rules {

    public static function writing_rules($kind) {
        return \Khazan\Services\Content\ContentRules::writing_rules($kind);
    }

    public static function seo_title_rules($kind, $focus = "") {
        return \Khazan\Services\Content\ContentRules::seo_title_rules($kind, $focus);
    }

    public static function product_blueprint() {
        return \Khazan\Services\Content\ContentRules::product_blueprint();
    }

    public static function short_keyword($title) {
        return \Khazan\Services\Content\ContentRules::short_keyword($title);
    }
}
