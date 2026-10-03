<?php
/**
 * Plugin Name:       لینکر | تحلیل پیشرفته لینک‌سازی داخلی
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
    exit; // خروج در صورت دسترسی مستقیم
}

// تعریف ثوابت پلاگین
define('WILA_VERSION', '1.0.0');
define('WILA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WILA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WILA_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * کلاس اصلی راه انداز افزونه
 */
final class WP_Internal_Link_Analyzer {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->check_requirements();
        $this->includes();
        $this->init_hooks();
    }

    private function check_requirements() {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . esc_html__('افزونه لینکر برای اجرا حداقل به نسخه PHP 7.4 نیاز دارد.', 'wp-internal-link-analyzer') . '</p></div>';
            });
            return;
        }
    }

    private function includes() {
        // فایل‌های مراحل بعدی در اینجا include خواهند شد
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('admin_menu', array($this, 'register_admin_menu'));
    }

    public function activate() {
        // ایجاد جداول اختصاصی پایگاه‌داده در گام بعدی
        update_option('wila_db_version', WILA_VERSION);
    }

    public function deactivate() {
        // پاکسازی صف‌های زمان‌بندی‌شده در صورت نیاز
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
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('سامانه تحلیل لینک‌سازی داخلی', 'wp-internal-link-analyzer') . '</h1>';
        echo '<p>' . esc_html__('هسته اولیه با موفقیت لود شد. آماده برای پیاده‌سازی دیتابیس و اسکنر.', 'wp-internal-link-analyzer') . '</p>';
        echo '</div>';
    }
}

// راه‌اندازی اولیه
function wila_run_plugin() {
    return WP_Internal_Link_Analyzer::instance();
}
wila_run_plugin();