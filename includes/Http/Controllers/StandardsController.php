<?php
/**
 * Owner-confirmed rules (business facts, verified external sources, SEO standards, keyword map) and the analysis of
 * the site's existing links and content.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

use Khazan\Services\Content\BusinessFacts;
use Khazan\Services\Content\KeywordMap;
use Khazan\Services\Content\SeoStandards;
use Khazan\Services\Content\StandardsStore;

if (!defined('ABSPATH')) {
    exit;
}

final class StandardsController extends BaseController {

    const ANALYSIS_TRANSIENT = 'khazan_link_analysis';

    public function get_standards() {
        $facts = BusinessFacts::all();
        $facts['verified_sources_text'] = StandardsStore::sources_text(BusinessFacts::verified_sources());
        $map = get_option(KeywordMap::OPTION, array());
        return rest_ensure_response(array(
            'success'      => true,
            'seo'          => SeoStandards::all(),
            'seo_defaults' => SeoStandards::defaults(),
            'facts'        => $facts,
            'keyword_map'  => array('pages' => is_array($map) ? count($map) : 0),
        ));
    }

    public function save_standards($request) {
        $body = (array) $request->get_json_params();
        if (isset($body['facts']) && is_array($body['facts'])) {
            $facts = $body['facts'];
            if (isset($facts['verified_sources_text'])) $facts['verified_sources'] = $facts['verified_sources_text'];
            $saved = get_option(BusinessFacts::OPTION, array());
            update_option(BusinessFacts::OPTION, array_merge(is_array($saved) ? $saved : array(), StandardsStore::sanitize_facts($facts)), false);
        }
        if (!empty($body['reset_seo'])) {
            delete_option(SeoStandards::OPTION);
        } elseif (isset($body['seo']) && is_array($body['seo'])) {
            $saved = get_option(SeoStandards::OPTION, array());
            update_option(SeoStandards::OPTION, array_merge(is_array($saved) ? $saved : array(), StandardsStore::sanitize_seo($body['seo'])), false);
        }
        return $this->get_standards();
    }

    public function import_keyword_map($request) {
        $body = (array) $request->get_json_params();
        $map = StandardsStore::keyword_map_from_csv(isset($body['csv']) ? (string) $body['csv'] : '');
        if (!$map) return new \WP_Error('empty_map', 'هیچ ردیف معتبری پیدا نشد. ستون‌های keyword_or_group، owner_url و owner_type لازم است.', array('status' => 400));
        $current = get_option(KeywordMap::OPTION, array());
        $merged = array_merge(is_array($current) ? $current : array(), $map);
        update_option(KeywordMap::OPTION, $merged, false);
        return rest_ensure_response(array('success' => true, 'imported' => count($map), 'pages' => count($merged)));
    }

    /** Link and content analysis of the existing site; cached for ten minutes (?refresh=1 recomputes). */
    public function link_analysis($request) {
        $cached = absint($request->get_param('refresh')) ? false : get_transient(self::ANALYSIS_TRANSIENT);
        if (is_array($cached)) return rest_ensure_response(array_merge(array('success' => true, 'cached' => true), $cached));

        $links = new \Khazan\Repository\LinkRepository();
        if (!$links->table_exists() || $links->total_links() === 0) {
            return new \WP_Error('no_index', 'ابتدا یک‌بار اسکن سایت را در بخش پیشخوان انجام دهید.', array('status' => 400));
        }
        $pages = (new \Khazan\Services\Scan\ScannerService())->get_all_scannable_items();
        $report = \Khazan\Services\Report\LinkAnalysis::build($links->outgoing_totals(), $links->anchor_groups(), $links->inbound_totals(), $this->category_entries(), $pages);
        $report['generated_at'] = current_time('mysql');
        set_transient(self::ANALYSIS_TRANSIENT, $report, 10 * MINUTE_IN_SECONDS);
        return rest_ensure_response(array_merge(array('success' => true, 'cached' => false), $report));
    }

    private function category_entries() {
        $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
        if (is_wp_error($terms)) return array();
        $out = array();
        foreach ($terms as $term) {
            $out[] = array(
                'id'       => $term->term_id,
                'name'     => $term->name,
                'url'      => get_term_link($term),
                'products' => (int) $term->count,
                'words'    => \Khazan\Services\Content\ContentAudit::word_count((string) $term->description),
            );
        }
        return $out;
    }
}
