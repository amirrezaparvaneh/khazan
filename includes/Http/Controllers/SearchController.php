<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

use Khazan\Modules\Search\ContentUpdater;
use Khazan\Modules\Search\PageAnalyzer;
use Khazan\Modules\Search\RedirectCheck;
use Khazan\Modules\Search\SearchConsole;
use Khazan\Services\Links\HtmlLinkText;

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
        $host_name = preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
        $opps = SearchConsole::opportunities(SearchConsole::data(), 30, array(\Khazan\Core\Settings::brand_name(), (string) preg_replace('/\.[a-z.]+$/i', '', $host_name)));
        $cards = SearchConsole::plan($opps);
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $done = array_flip(PageAnalyzer::analyzed_keys());
        foreach ($cards as $i => $c) {
            $cards[$i]['analyzed'] = isset($done[(string) HtmlLinkText::url_key($c['page'], $host)]);
            $label = $c['page'] !== '' ? PageAnalyzer::label($c['page']) : null;
            $cards[$i]['page_name'] = $label ? $label['name'] : '';
            $cards[$i]['page_type'] = $label ? $label['type'] : '';
        }
        $counts = array();
        foreach ($cards as $c) $counts[$c['kind']] = (isset($counts[$c['kind']]) ? $counts[$c['kind']] : 0) + 1;
        return rest_ensure_response(array('success' => true, 'cards' => $cards, 'counts' => $counts, 'potential' => array_sum(array_column($cards, 'potential'))));
    }

    private static function param($request, $key) {
        $p = $request->get_json_params();
        $v = is_array($p) && isset($p[$key]) ? $p[$key] : $request->get_param($key);
        return is_scalar($v) ? (string) $v : '';
    }

    public function redirects($request) {
        $p = $request->get_json_params();
        $urls = array();
        foreach (is_array($p) && isset($p['pages']) && is_array($p['pages']) ? $p['pages'] : array() as $u) {
            if (is_string($u) && RedirectCheck::is_own($u)) $urls[] = esc_url_raw($u);
        }
        return rest_ensure_response(array('success' => true, 'results' => (object) RedirectCheck::check_many($urls)));
    }

    public function analyze($request) {
        $r = PageAnalyzer::analyze(esc_url_raw(self::param($request, 'page')), sanitize_text_field(self::param($request, 'opportunity')), sanitize_text_field(self::param($request, 'diagnosis')));
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('success' => true, 'result' => $r, 'backup' => ContentUpdater::has_backup($r['kind'], $r['id'])));
    }

    public function analysis($request) {
        $r = PageAnalyzer::cached(esc_url_raw(self::param($request, 'page')));
        return rest_ensure_response(array('success' => true, 'result' => $r, 'backup' => $r ? ContentUpdater::has_backup($r['kind'], $r['id']) : ''));
    }

    public function update_draft($request) {
        $r = ContentUpdater::draft(esc_url_raw(self::param($request, 'page')));
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('success' => true, 'draft' => $r));
    }

    public function update_apply($request) {
        $r = ContentUpdater::apply(self::param($request, 'draft_id'), self::param($request, 'with_meta') === '1' || self::param($request, 'with_meta') === 'true');
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('success' => true) + $r);
    }

    public function update_restore($request) {
        $r = ContentUpdater::restore(self::param($request, 'kind') === 'term' ? 'term' : 'post', self::param($request, 'type'), (int) self::param($request, 'id'));
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('success' => true) + $r);
    }
}
