<?php
/**
 * ثبت مسیرهای REST سامانه (namespace: wila/v1). آدرس‌ها، متدها و قالب پاسخ‌ها بدون تغییر مانده‌اند و
 * منطق هر مسیر در Khazan\Http\Controllers قرار دارد.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http;

use Khazan\Http\Controllers\ContentController;
use Khazan\Http\Controllers\QueueController;
use Khazan\Http\Controllers\ReportController;
use Khazan\Http\Controllers\SeoRepairController;
use Khazan\Http\Controllers\SettingsController;

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

        register_rest_route($this->namespace, '/queue', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($queue, 'get_scan_queue'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/scan-item', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($queue, 'scan_single_item'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/summary', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($report, 'get_dashboard_summary'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/items', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($report, 'get_items_report'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/item-details', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($report, 'get_item_details'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/orphans', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($report, 'get_orphan_items'), 'permission_callback' => array($this, 'check_permission')));
        
        register_rest_route($this->namespace, '/settings', array(
            array('methods' => \WP_REST_Server::READABLE, 'callback' => array($settings, 'get_settings'), 'permission_callback' => array($this, 'check_permission')),
            array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($settings, 'save_settings'), 'permission_callback' => array($this, 'check_permission'))
        ));
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
        register_rest_route($this->namespace, '/content/history', array('methods' => \WP_REST_Server::READABLE, 'callback' => array($content, 'get_content_history'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/history/(?P<id>\d+)', array(
            array('methods' => \WP_REST_Server::READABLE, 'callback' => array($content, 'get_content_history_item'), 'permission_callback' => array($this, 'check_permission')),
            array('methods' => \WP_REST_Server::DELETABLE, 'callback' => array($content, 'delete_content_history_item'), 'permission_callback' => array($this, 'check_permission')),
        ));
        register_rest_route($this->namespace, '/test-connection', array('methods' => \WP_REST_Server::CREATABLE, 'callback' => array($settings, 'test_avalai_connection'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/seo-repair', array('methods' => 'POST', 'callback' => array($seo, 'analyze'), 'permission_callback' => array($seo, 'permission')));
    }

    public function check_permission() {
        return current_user_can('manage_options');
    }
}
