<?php
/**
 * Minimal template renderer: keeps HTML out of PHP logic classes.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class View {

    /**
     * Renders templates/{$name}.php with the given variables.
     */
    public static function render($name, array $vars = array()) {
        $file = WILA_PLUGIN_DIR . 'templates/' . ltrim($name, '/') . '.php';
        if (!is_readable($file)) {
            return;
        }
        extract($vars, EXTR_SKIP);
        include $file;
    }
}
