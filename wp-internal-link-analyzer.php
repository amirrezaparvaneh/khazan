<?php
/**
 * Plugin Name:       لینکر | تحلیل پیشرفته لینک‌سازی داخلی
 * Plugin URI:        https://github.com/your-username/wp-internal-link-analyzer
 * Description:       سامانه تحلیل جامع لینک‌های داخلی، دسته‌‌بندی محصولات، مقالات و پیشنهادات هوشمند لینک‌سازی با AvalAI و مدل DeepSeek Chat
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
        require_once WILA_PLUGIN_DIR . 'includes/ai/class-wila-avalai-client.php';
        require_once WILA_PLUGIN_DIR . 'includes/api/class-wila-rest-controller.php';
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    public function activate() {
        WILA_DB::create_tables();
        update_option('wila_db_version', WILA_VERSION);
    }

    public function deactivate() {
        // لاجیک پاکسازی در صورت نیاز
    }

    public function register_rest_routes() {
        $controller = new WILA_REST_Controller();
        $controller->register_routes();
    }

    public function register_admin_menu() {
        add_menu_page(
            'لینکر - تحلیل و بهینه‌سازی لینک‌های داخلی',
            'لینکر',
            'manage_options',
            'wila-dashboard',
            array($this, 'render_dashboard_view'),
            'dashicons-admin-links',
            30
        );
    }

    public function enqueue_admin_assets($hook_suffix) {
        if ($hook_suffix !== 'toplevel_page_wila-dashboard') {
            return;
        }

        wp_enqueue_style(
            'wila-admin-style',
            WILA_PLUGIN_URL . 'assets/css/wila-admin.css',
            array(),
            WILA_VERSION
        );

        wp_enqueue_script(
            'wila-admin-script',
            WILA_PLUGIN_URL . 'assets/js/wila-admin.js',
            array(),
            WILA_VERSION . '.' . filemtime(WILA_PLUGIN_DIR . 'assets/js/wila-admin.js'),
            true
        );

        wp_localize_script('wila-admin-script', 'wilaData', array(
            'restUrl' => esc_url_raw(rest_url()),
            'nonce'   => wp_create_nonce('wp_rest'),
        ));
    }

    public function render_dashboard_view() {
        echo '<div id="wila-app-root"></div>';
    }
}

function wila_run_plugin() {
    return WP_Internal_Link_Analyzer::instance();
}
wila_run_plugin();