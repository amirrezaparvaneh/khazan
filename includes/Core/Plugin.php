<?php
/**
 * Plugin orchestrator: loads legacy classes, wires modules, owns lifecycle hooks.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Core;

use Khazan\Admin\AdminAssets;
use Khazan\Admin\AdminMenu;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin {

    /** @var Plugin|null */
    private static $instance = null;

    /** @var bool */
    private $booted = false;

    /**
     * Legacy WILA_* classes that are not namespaced yet (migrated step by step).
     */
    private static $legacy_files = array(
        'includes/class-wila-db.php',
        'includes/scanner/class-wila-scanner.php',
        'includes/class-wila-seo-meta.php',
        'includes/class-wila-content-targets.php',
        'includes/ai/class-wila-content-rules.php',
        'includes/ai/class-wila-html-sanitizer.php',
        'includes/ai/class-wila-content-guard.php',
        'includes/ai/class-wila-avalai-client.php',
        'includes/class-wila-bulk-history.php',
        'includes/class-wila-bulk-generator.php',
        'includes/api/class-wila-rest-controller.php',
        'includes/class-wila-seo-repair.php',
    );

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public static function load_legacy() {
        foreach (self::$legacy_files as $file) {
            require_once WILA_PLUGIN_DIR . $file;
        }
    }

    public static function activate() {
        self::load_legacy();
        \WILA_DB::create_tables();
        update_option('wila_db_version', WILA_VERSION);
    }

    public static function deactivate() {
        // لاجیک پاکسازی در صورت نیاز
    }

    public function boot() {
        if ($this->booted) {
            return $this;
        }
        $this->booted = true;

        self::load_legacy();
        new \WILA_SEO_Repair();

        (new AdminMenu())->register();
        (new AdminAssets())->register();

        add_action('plugins_loaded', array($this, 'maybe_upgrade_database'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        return $this;
    }

    public function maybe_upgrade_database() {
        if (get_option('wila_db_installed_version') !== WILA_VERSION) {
            \WILA_DB::create_tables();
        }
    }

    public function register_rest_routes() {
        $controller = new \WILA_REST_Controller();
        $controller->register_routes();
    }
}
