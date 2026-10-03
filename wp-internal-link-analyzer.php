<?php
/**
 * Plugin Name:       لینکر | تحلیل پیشرفته لینک‌‌سازی داخلی
 * Plugin URI:        https://github.com/your-username/wp-internal-link-analyzer
 * Description:       سامانه تحلیل جامع لینک‌های داخلی، دسته‌بندی محصولات، مقالات و پیشنهادات هوشمند لینک‌سازی با Gemini AI
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            امیــــــــررضا پـروانه
 * Text Domain:       wp-internal-link-analyzer
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WILA_VERSION', '1.0.0');
define('WILA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WILA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WILA_PLUGIN_BASENAME', plugin_basename(__FILE__));

final class WP_Internal_Link_Analyzer {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    private function includes() {
        require_once WILA_PLUGIN_DIR . 'includes/class-wila-db.php';
        require_once WILA_PLUGIN_DIR . 'includes/scanner/class-wila-scanner.php';
        // در گام‌های بعد: REST API و Gemini Client اضافه خواهند شد
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('admin_menu', array($this, 'register_admin_menu'));
    }

    public function activate() {
        WILA_DB::create_tables();
        update_option('wila_db_version', WILA_VERSION);
    }

    public function deactivate() {
        // در صورت لزوم لاجیک پاکسازی
    }

    public function register_admin_menu() {
        add_menu_page(
            'تحلیل لینک‌های داخلی',
            'لینک‌سازی داخلی',
            'manage_options',
            'wila-dashboard',
            array($this, 'render_dashboard_view'),
            'dashicons-admin-links',
            30
        );
    }

    public function render_dashboard_view() {
        echo '<div id="wila-app-root"></div>';
    }
}

function wila_run_plugin() {
    return WP_Internal_Link_Analyzer::instance();
}
wila_run_plugin();