<?php
/**
 * Plugin Name:       خزان
 * Plugin URI:        https://github.com/amirrezaparvaneh/wp-internal-link-analyzer
 * Description:       افزونه هوشمند تولید و بهینه‌سازی محتوا، سئو و تصاویر بر پایه AvalAI برای ووکامرس و وردپرس
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            امیــــــــررضا پـروانه
 * Text Domain:       khazan
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WILA_VERSION', '1.0.0');
define('WILA_PLUGIN_FILE', __FILE__);
define('WILA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WILA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WILA_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
\Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

register_activation_hook(__FILE__, array('Khazan\\Core\\Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('Khazan\\Core\\Plugin', 'deactivate'));

function wila_run_plugin() {
    return \Khazan\Core\Plugin::instance()->boot();
}
wila_run_plugin();
