<?php
/**
 * Plugin Name:       خـزان | دستیار هوشمند تولید محتوا و مهندسی سئو
 * Plugin URI:        https://github.com/your-username/wp-internal-link-analyzer
 * Description:       سامانه تحلیل جامع لینک‌های داخلی، دسته‌‌بندی محصولات، مقالات و پیشنهادات هوشمند لینک‌سازی با AvalAI و مدل GPT-5.4 Mini
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

define('WILA_VERSION', '1.6.0');
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
        require_once WILA_PLUGIN_DIR . 'includes/class-wila-seo-meta.php';
        require_once WILA_PLUGIN_DIR . 'includes/class-wila-content-targets.php';
        require_once WILA_PLUGIN_DIR . 'includes/ai/class-wila-content-rules.php';
        require_once WILA_PLUGIN_DIR . 'includes/ai/class-wila-html-sanitizer.php';
        require_once WILA_PLUGIN_DIR . 'includes/ai/class-wila-content-guard.php';
        require_once WILA_PLUGIN_DIR . 'includes/ai/class-wila-avalai-client.php';
        require_once WILA_PLUGIN_DIR . 'includes/class-wila-bulk-history.php';
        require_once WILA_PLUGIN_DIR . 'includes/class-wila-bulk-generator.php';
        require_once WILA_PLUGIN_DIR . 'includes/api/class-wila-rest-controller.php';
        require_once WILA_PLUGIN_DIR . 'includes/class-wila-seo-repair.php';
        new WILA_SEO_Repair();
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('plugins_loaded', array($this, 'maybe_upgrade_database'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_head', array($this, 'print_menu_styles'));
    }

    public function activate() {
        WILA_DB::create_tables();
        update_option('wila_db_version', WILA_VERSION);
    }

    public function deactivate() {
        // لاجیک پاکسازی در صورت نیاز
    }

    public function maybe_upgrade_database() {
        if (get_option('wila_db_installed_version') !== WILA_VERSION) {
            WILA_DB::create_tables();
        }
    }

    public function register_rest_routes() {
        $controller = new WILA_REST_Controller();
        $controller->register_routes();
    }

    public function register_admin_menu() {
        add_menu_page(
            'خـزان - دستیار هوشمند تولید محتوا و مهندسی سئو',
            'خـزان',
            'manage_options',
            'wila-dashboard',
            array($this, 'render_dashboard_view'),
            $this->get_menu_icon(),
            30
        );
    }

    private function get_menu_icon() {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" fill="#a7aaad"><path d="M50 4l9 20 14-6-4 22 22-6-11 18 14 8-25 8 4 14-21-6v20h-8V78l-21 6 4-14-25-8 14-8-11-18 22 6-4-22 14 6z"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function print_menu_styles() {
        echo '<style id="khazan-menu-style">
#adminmenu li#toplevel_page_wila-dashboard > a.menu-top,
#adminmenu li#toplevel_page_wila-dashboard.wp-has-current-submenu > a.menu-top {
  background: linear-gradient(135deg, #991b1b 0%, #c2410c 50%, #f59e0b 100%) !important;
  color: #fff !important;
  font-weight: 700;
  box-shadow: 0 0 14px rgba(245, 158, 11, 0.45);
}
#adminmenu li#toplevel_page_wila-dashboard > a.menu-top:hover { filter: brightness(1.08); }
#adminmenu li#toplevel_page_wila-dashboard .wp-menu-name { color: #fff !important; font-weight: 700; text-shadow: 0 1px 2px rgba(0,0,0,.25); }
#adminmenu li#toplevel_page_wila-dashboard div.wp-menu-image { filter: brightness(0) invert(1); opacity: 1; }
#adminmenu li#toplevel_page_wila-dashboard div.wp-menu-image::before { color: #fff; }
</style>';
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
            'khazan-leaves',
            WILA_PLUGIN_URL . 'assets/js/khazan-leaves.js',
            array(),
            WILA_VERSION . '.' . filemtime(WILA_PLUGIN_DIR . 'assets/js/khazan-leaves.js'),
            true
        );

        wp_enqueue_script(
            'wila-admin-script',
            WILA_PLUGIN_URL . 'assets/js/wila-admin.js',
            array('khazan-leaves'),
            WILA_VERSION . '.' . filemtime(WILA_PLUGIN_DIR . 'assets/js/wila-admin.js'),
            true
        );

        wp_localize_script('wila-admin-script', 'wilaData', array(
            'restUrl' => esc_url_raw(rest_url()),
            'nonce'   => wp_create_nonce('wp_rest'),
            'version' => WILA_VERSION,
        ));
    }

    public function render_dashboard_view() {
        echo '<div id="wila-app-root" class="khazan-app-shell"></div>';
    }
}

function wila_run_plugin() {
    return WP_Internal_Link_Analyzer::instance();
}
wila_run_plugin();