<?php
/**
 * لایه ارجاع‌دهنده: رابط کاربری در Khazan\Admin\SeoRepairPanel و مسیر REST در
 * Khazan\Http\Controllers\SeoRepairController است (ثبت مسیر در Khazan\Http\RestRouter).
 * ساخت این کلاس همچنان هوک‌های رابط کاربری را ثبت می‌کند؛ مسیر REST را Plugin از طریق RestRouter ثبت می‌کند.
 */
if (!defined('ABSPATH')) exit;

class WILA_SEO_Repair {
    public function __construct() {
        (new \Khazan\Admin\SeoRepairPanel())->register();
    }
}
