<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared helpers for REST controllers.
 */
abstract class BaseController {

    protected function get_type_label($type) {
        $pt = get_post_type_object($type);
        if ($pt) return $pt->label;
        $tax = get_taxonomy($type);
        if ($tax) return $tax->label;
        return $type;
    }
}
