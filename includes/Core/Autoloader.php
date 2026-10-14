<?php
/**
 * PSR-4 autoloader for the Khazan\ namespace.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class Autoloader {

    /** @var string */
    private $prefix;

    /** @var string */
    private $base_dir;

    private function __construct($prefix, $base_dir) {
        $this->prefix   = trim($prefix, '\\') . '\\';
        $this->base_dir = rtrim($base_dir, '/\\') . '/';
    }

    public static function register($prefix, $base_dir) {
        $loader = new self($prefix, $base_dir);
        spl_autoload_register(array($loader, 'load'));
        return $loader;
    }

    public function load($class) {
        if (strpos($class, $this->prefix) !== 0) {
            return;
        }
        $relative = substr($class, strlen($this->prefix));
        $file     = $this->base_dir . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    }
}
