<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

use Khazan\Modules\Search\SearchConsole;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Search Console connection and synced data.
 */
final class SearchController extends BaseController {

    public function status() {
        return rest_ensure_response(array('success' => true) + SearchConsole::status());
    }

    public function connect($request) {
        $p = $request->get_json_params();
        $r = SearchConsole::connect(isset($p['key']) ? (string) $p['key'] : '', isset($p['site']) ? (string) $p['site'] : '');
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('success' => true) + $r + SearchConsole::status());
    }

    public function disconnect() {
        SearchConsole::disconnect();
        return rest_ensure_response(array('success' => true) + SearchConsole::status());
    }

    public function sync($request) {
        $p = $request->get_json_params();
        $r = SearchConsole::sync(isset($p['days']) ? (int) $p['days'] : 90);
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('success' => true, 'rows_read' => $r['rows_read']) + SearchConsole::status());
    }

    /** Top lists for the screen (the full copy stays on the server for the other tools). */
    public function data() {
        $d = SearchConsole::data();
        return rest_ensure_response(array(
            'success' => true,
            'queries' => array_slice(isset($d['queries']) ? $d['queries'] : array(), 0, 50),
            'pages' => array_slice(isset($d['pages']) ? $d['pages'] : array(), 0, 50),
        ) + SearchConsole::status());
    }

    public function opportunities() {
        return rest_ensure_response(array('success' => true) + SearchConsole::opportunities(SearchConsole::data()));
    }
}
