<?php
/**
 * ثبت مسیرهای REST سامانه (namespace: wila/v1). آدرس‌ها، متدها و قالب پاسخ‌ها بدون تغییر مانده‌اند و
 * منطق هر مسیر در Khazan\Http\Controllers قرار دارد.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http;

use Khazan\Http\Controllers\ContentController;
use Khazan\Http\Controllers\ImagesController;
use Khazan\Http\Controllers\ProductSpecsController;
use Khazan\Http\Controllers\QueueController;
use Khazan\Http\Controllers\ReportController;
use Khazan\Http\Controllers\SeoRepairController;
use Khazan\Http\Controllers\SettingsController;
use Khazan\Http\Controllers\StandardsController;

if (!defined('ABSPATH')) {
    exit;
}

final class RestRouter {

    const NAMESPACE_V1 = 'wila/v1';

    private $namespace = self::NAMESPACE_V1;

    public function register() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        $queue    = new QueueController();
        $report   = new ReportController();
        $content  = new ContentController();
        $settings = new SettingsController();
        $seo      = new SeoRepairController();
        $specs    = new ProductSpecsController();
        $images   = new ImagesController();
        $standards = new StandardsController();

        register_rest_route($this->namespace, '/queue', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($queue, 'get_scan_queue'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/scan-item', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($queue, 'scan_single_item'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/summary', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($report, 'get_dashboard_summary'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/report/site-stats', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($report, 'get_site_stats'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/items', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($report, 'get_items_report'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/item-details', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($report, 'get_item_details'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/orphans', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($report, 'get_orphan_items'), 'permission_callback' => array($this, 'check_permission')));
        
        register_rest_route($this->namespace, '/settings', array(
            array('methods' => \WP_REST_Server::READABLE, 'callback' => array($settings, 'get_settings'), 'permission_callback' => array($this, 'check_permission')),
            array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($settings, 'save_settings'), 'permission_callback' => array($this, 'check_permission'))
        ));
        $perm = array($this, 'check_permission');
        $search = new \Khazan\Http\Controllers\SearchController();
        register_rest_route($this->namespace, '/search/status', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($search, 'status'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/search/data', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($search, 'data'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/search/connect', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($search, 'connect'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/search/disconnect', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($search, 'disconnect'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/search/sync', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($search, 'sync'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/images/overview', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($images, 'overview'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/images/settings', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($images, 'save_settings'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/images/convert-batch', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($images, 'convert_batch'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/images/shrink-batch', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($images, 'shrink_batch'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/images/alt-batch', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($images, 'alt_batch'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/images/dedupe/hash-batch', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($images, 'hash_batch'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/images/dedupe/report', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($images, 'dedupe_report'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/images/dedupe/remove', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($images, 'dedupe_remove'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/images/orphans/scan', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($images, 'orphan_scan'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/images/orphans/analyze-batch', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($images, 'orphan_analyze'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/images/orphans/report', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($images, 'orphan_report'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/images/orphans/remove', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($images, 'orphan_remove'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/images/orphans/shrink-batch', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($images, 'orphan_shrink'), 'permission_callback' => $perm));
        register_rest_route($this->namespace, '/standards', array(
            array('methods' => \WP_REST_Server::READABLE, 'callback' => array($standards, 'get_standards'), 'permission_callback' => array($this, 'check_permission')),
            array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($standards, 'save_standards'), 'permission_callback' => array($this, 'check_permission')),
        ));
        register_rest_route($this->namespace, '/standards/keyword-map', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($standards, 'import_keyword_map'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/report/link-analysis', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($standards, 'link_analysis'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/settings/ai-models', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($settings, 'get_ai_models'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/settings/ai-balance', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($settings, 'get_ai_balance'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/post-types', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($settings, 'get_available_post_types'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/taxonomies/(?P<post_type>[^/]+)', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($settings, 'get_available_taxonomies'), 'permission_callback' => array($this, 'check_permission')));

        register_rest_route($this->namespace, '/ai/suggest', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($content, 'generate_ai_suggestions'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/ai/planner', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($content, 'generate_content_blueprint'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/outline', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($content, 'generate_content_outline'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/generate', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($content, 'generate_full_content'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/draft', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($content, 'create_content_draft'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/targets', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($content, 'get_content_targets'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/current', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($content, 'get_current_content'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/seo-fix', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($content, 'fix_seo_errors'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/apply', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($content, 'apply_content_to_target'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/bulk/match', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($queue, 'bulk_match_products'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/bulk/site-products', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($queue, 'get_site_products'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/bulk/process', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($queue, 'bulk_process_product'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/bulk/site-categories', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($queue, 'get_site_categories'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/bulk/process-category', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($queue, 'bulk_process_category'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/history', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($content, 'get_content_history'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/history/(?P<id>\d+)', array(
            array('methods' => \WP_REST_Server::READABLE, 'callback' => array($content, 'get_content_history_item'), 'permission_callback' => array($this, 'check_permission')),
            array('methods' => \WP_REST_Server::DELETABLE, 'callback' => array($content, 'delete_content_history_item'), 'permission_callback' => array($this, 'check_permission')),
        ));
        register_rest_route($this->namespace, '/test-connection', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($settings, 'test_avalai_connection'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/seo-repair', array('methods' => 'POST', 'callback' => array($seo, 'analyze'), 'permission_callback' => array($seo, 'permission')));
        register_rest_route($this->namespace, '/product-specs', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($specs, 'list_products'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/product-specs/save', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($specs, 'save_specs'), 'permission_callback' => array($this, 'check_permission')));
    }

    public function check_permission() {
        return current_user_can('manage_options');
    }
}
