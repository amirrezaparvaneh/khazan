<?php
/**
 * کنترلر مسیرهای REST API سامانه لینکر
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_REST_Controller {

    private $namespace = 'wila/v1';

    public function register_routes() {
        // ۱. دریافت لیست آیتم‌ها برای اسکن گام‌به‌گام
        register_rest_route($this->namespace, '/queue', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_scan_queue'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // ۲. اسکن تکی یک آیتم
        register_rest_route($this->namespace, '/scan-item', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'scan_single_item'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // ۳. خلاصه آمار داشبورد
        register_rest_route($this->namespace, '/summary', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_dashboard_summary'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // ۴. جدول اقلام با فیلتر
        register_rest_route($this->namespace, '/items', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_items_report'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // ۵. جزئیات دقیق لینک‌های یک صفحه
        register_rest_route($this->namespace, '/item-details', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_item_details'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // ۶. استخراج صفحات بدون لینک ورودی (Orphan Pages)
        register_rest_route($this->namespace, '/orphans', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_orphan_items'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // ۷. ذخیره و دریافت تنظیمات (API Key جمینای)
        register_rest_route($this->namespace, '/settings', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_settings'),
                'permission_callback' => array($this, 'check_permission'),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'save_settings'),
                'permission_callback' => array($this, 'check_permission'),
            )
        ));

        // ۸. تولید پیشنهادات استراتژیک لینک‌سازی توسط هوش مصنوعی جمینای
        register_rest_route($this->namespace, '/ai/suggest', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'generate_ai_suggestions'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }

    public function check_permission() {
        return current_user_can('manage_options');
    }

    public function get_scan_queue() {
        $scanner = new WILA_Scanner();
        $items = $scanner->get_all_scannable_items();

        return rest_ensure_response(array(
            'success' => true,
            'total'   => count($items),
            'items'   => $items
        ));
    }

    public function scan_single_item($request) {
        $params    = $request->get_json_params();
        $item_id   = isset($params['id']) ? absint($params['id']) : 0;
        $item_type = isset($params['type']) ? sanitize_text_field($params['type']) : 'post';

        if (!$item_id) {
            return new WP_Error('invalid_id', 'شناسه آیتم نامعتبر است.', array('status' => 400));
        }

        $scanner = new WILA_Scanner();
        $result  = $scanner->scan_item($item_id, $item_type);

        return rest_ensure_response(array(
            'success'     => true,
            'item_id'     => $item_id,
            'links_found' => isset($result['links_found']) ? $result['links_found'] : 0
        ));
    }

    public function get_dashboard_summary() {
        global $wpdb;
        $table = WILA_DB::get_table_name();

        $scanner = new WILA_Scanner();
        $all_items = $scanner->get_all_scannable_items();

        $total_posts        = 0;
        $total_product_cats = 0;
        $total_cats         = 0;

        foreach ($all_items as $item) {
            if ($item['type'] === 'post') $total_posts++;
            if ($item['type'] === 'product_cat') $total_product_cats++;
            if ($item['type'] === 'category') $total_cats++;
        }

        $total_links = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $orphans     = $this->calculate_orphans($all_items);

        return rest_ensure_response(array(
            'total_items'        => count($all_items),
            'total_posts'        => $total_posts,
            'total_product_cats' => $total_product_cats,
            'total_cats'         => $total_cats,
            'total_links'        => $total_links,
            'orphan_count'       => count($orphans['list']),
            'orphan_posts'       => $orphans['posts_count'],
            'orphan_cats'        => $orphans['cats_count'],
        ));
    }

    public function get_items_report($request) {
        global $wpdb;
        $table = WILA_DB::get_table_name();

        $filter_type = $request->get_param('type');
        $scanner = new WILA_Scanner();
        $items = $scanner->get_all_scannable_items();

        $report = array();

        foreach ($items as $item) {
            if (!empty($filter_type) && $filter_type !== 'all' && $item['type'] !== $filter_type) {
                continue;
            }

            $out_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE source_id = %d AND source_type = %s",
                $item['id'],
                $item['type']
            ));

            $in_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE target_id = %d AND target_type = %s",
                $item['id'],
                $item['type']
            ));

            $report[] = array(
                'id'        => $item['id'],
                'type'      => $item['type'],
                'title'     => $item['title'],
                'url'       => $item['url'],
                'out_links' => $out_count,
                'in_links'  => $in_count,
                'is_orphan' => ($in_count === 0)
            );
        }

        return rest_ensure_response($report);
    }

    public function get_item_details($request) {
        global $wpdb;
        $table = WILA_DB::get_table_name();

        $id   = absint($request->get_param('id'));
        $type = sanitize_text_field($request->get_param('type'));

        if (!$id || !$type) {
            return new WP_Error('missing_params', 'پارامترهای id و type الزامی هستند.', array('status' => 400));
        }

        $out_links = $wpdb->get_results($wpdb->prepare(
            "SELECT target_url, target_id, target_type, anchor_text, rel_attribute 
             FROM {$table} 
             WHERE source_id = %d AND source_type = %s",
            $id,
            $type
        ), ARRAY_A);

        $in_links = $wpdb->get_results($wpdb->prepare(
            "SELECT source_id, source_type, source_title, source_url, anchor_text, rel_attribute 
             FROM {$table} 
             WHERE target_id = %d AND target_type = %s",
            $id,
            $type
        ), ARRAY_A);

        return rest_ensure_response(array(
            'id'             => $id,
            'type'           => $type,
            'incoming_count' => count($in_links),
            'outgoing_count' => count($out_links),
            'incoming_links' => $in_links,
            'outgoing_links' => $out_links,
        ));
    }

    public function get_orphan_items() {
        $scanner = new WILA_Scanner();
        $all_items = $scanner->get_all_scannable_items();
        $orphans_data = $this->calculate_orphans($all_items);

        return rest_ensure_response($orphans_data['list']);
    }

    public function get_settings() {
        $key = get_option('wila_gemini_api_key', '');
        return rest_ensure_response(array(
            'has_api_key' => !empty($key),
            'api_key'     => !empty($key) ? (substr($key, 0, 6) . '...' . substr($key, -4)) : ''
        ));
    }

    public function save_settings($request) {
        $params  = $request->get_json_params();
        $api_key = isset($params['api_key']) ? sanitize_text_field($params['api_key']) : '';

        if (!empty($api_key)) {
            update_option('wila_gemini_api_key', $api_key);
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'تنظیمات با موفقیت ذخیره شد.'
        ));
    }

    public function generate_ai_suggestions($request) {
        $params = $request->get_json_params();
        $id     = isset($params['id']) ? absint($params['id']) : 0;
        $type   = isset($params['type']) ? sanitize_text_field($params['type']) : 'post';

        if (!$id || !$type) {
            return new WP_Error('invalid_params', 'پارامترهای ارسالی ناقص هستند.', array('status' => 400));
        }

        $gemini = new WILA_Gemini_Client();
        $suggestions = $gemini->generate_link_suggestions($id, $type);

        if (is_wp_error($suggestions)) {
            return $suggestions;
        }

        return rest_ensure_response(array(
            'success'     => true,
            'suggestions' => $suggestions
        ));
    }

    private function calculate_orphans($items) {
        global $wpdb;
        $table = WILA_DB::get_table_name();

        $linked_targets = $wpdb->get_results(
            "SELECT DISTINCT target_id, target_type FROM {$table} WHERE target_id IS NOT NULL",
            ARRAY_A
        );

        $linked_map = array();
        foreach ($linked_targets as $t) {
            $linked_map[$t['target_type'] . '_' . $t['target_id']] = true;
        }

        $orphan_list = array();
        $posts_count = 0;
        $cats_count  = 0;

        foreach ($items as $item) {
            $key = $item['type'] . '_' . $item['id'];
            if (!isset($linked_map[$key])) {
                $orphan_list[] = $item;
                if ($item['type'] === 'post') {
                    $posts_count++;
                } else {
                    $cats_count++;
                }
            }
        }

        return array(
            'list'        => $orphan_list,
            'posts_count' => $posts_count,
            'cats_count'  => $cats_count,
        );
    }
}