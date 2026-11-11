<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

use Khazan\Modules\Products\SpecSheetService;
use Khazan\Modules\Search\SearchConsole;

if (!defined('ABSPATH')) {
    exit;
}

/** Product features from a Google Sheet or CSV: read, preview, apply. */
final class SpecSheetController extends BaseController {

    private static function params($request) {
        $p = $request->get_json_params();
        return is_array($p) ? $p : array();
    }

    public function load($request) {
        $p = self::params($request);
        $r = SpecSheetService::load(isset($p['link']) ? esc_url_raw((string) $p['link']) : '', isset($p['csv']) && is_string($p['csv']) ? substr($p['csv'], 0, 4 * 1024 * 1024) : '');
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('success' => true, 'email' => SearchConsole::email()) + $r);
    }

    public function preview($request) {
        $p = self::params($request);
        $r = SpecSheetService::preview(isset($p['sheet_id']) ? (string) $p['sheet_id'] : '', isset($p['mapping']) ? $p['mapping'] : array(), !empty($p['fill_similar']));
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('success' => true) + $r);
    }

    public function apply($request) {
        $p = self::params($request);
        $r = SpecSheetService::apply(isset($p['plan_id']) ? (string) $p['plan_id'] : '');
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('success' => true) + $r);
    }

    public function status() {
        return rest_ensure_response(array('success' => true, 'email' => SearchConsole::email()));
    }
}
