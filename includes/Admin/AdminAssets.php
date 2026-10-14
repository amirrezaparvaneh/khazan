<?php
/**
 * Enqueues Khazan admin styles and scripts.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Admin;

use Khazan\Core\View;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminAssets {

    public function register() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
    }

    public function enqueue($hook_suffix) {
        // استایل آیتم منو در همه صفحات پیشخوان لازم است
        wp_enqueue_style('khazan-menu', $this->url('assets/css/khazan-menu.css'), array(), $this->version('assets/css/khazan-menu.css'));

        if ($hook_suffix !== AdminMenu::HOOK_SUFFIX) {
            return;
        }

        add_action('admin_head', array($this, 'print_font_preconnect'), 1);

        wp_enqueue_style('wila-admin-style', $this->url('assets/css/wila-admin.css'), array(), WILA_VERSION);

        // ترتیب بارگذاری: ابزارها -> CSV -> API -> صف و تب‌ها -> هسته (wila-admin.js)
        $scripts = array(
            'khazan-leaves' => array('assets/js/khazan-leaves.js', array()),
            'khazan-utils'  => array('assets/js/khazan-utils.js', array()),
            'khazan-csv'    => array('assets/js/khazan-csv.js', array()),
            'khazan-api'    => array('assets/js/khazan-api.js', array('khazan-utils')),
            'khazan-queue'  => array('assets/js/khazan-queue.js', array('khazan-utils', 'khazan-csv')),
            'khazan-tabs'   => array('assets/js/khazan-tabs.js', array('khazan-utils')),
            'wila-admin-script' => array('assets/js/wila-admin.js', array('khazan-leaves', 'khazan-utils', 'khazan-csv', 'khazan-api', 'khazan-queue', 'khazan-tabs')),
        );
        foreach ($scripts as $handle => $script) {
            wp_enqueue_script($handle, $this->url($script[0]), $script[1], $this->version($script[0]), true);
        }

        wp_localize_script('wila-admin-script', 'wilaData', array(
            'restUrl'      => esc_url_raw(rest_url()),
            'nonce'        => wp_create_nonce('wp_rest'),
            'version'      => WILA_VERSION,
            'logoUrl'      => esc_url_raw(plugins_url('assets/images/logo-header.webp', WILA_PLUGIN_FILE)),
            'watermarkUrl' => esc_url_raw(plugins_url('assets/images/watermark-sidebar.webp', WILA_PLUGIN_FILE)),
        ));
    }

    public function print_font_preconnect() {
        View::render('admin/font-preconnect');
    }

    private function url($relative) {
        return WILA_PLUGIN_URL . $relative;
    }

    private function version($relative) {
        return WILA_VERSION . '.' . filemtime(WILA_PLUGIN_DIR . $relative);
    }
}
