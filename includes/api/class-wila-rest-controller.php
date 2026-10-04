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
        register_rest_route($this->namespace, '/queue', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_scan_queue'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/scan-item', array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'scan_single_item'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/summary', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_dashboard_summary'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/items', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_items_report'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/item-details', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_item_details'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/orphans', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_orphan_items'), 'permission_callback' => array($this, 'check_permission')));
        
        register_rest_route($this->namespace, '/settings', array(
            array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_settings'), 'permission_callback' => array($this, 'check_permission')),
            array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'save_settings'), 'permission_callback' => array($this, 'check_permission'))
        ));

        register_rest_route($this->namespace, '/ai/suggest', array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'generate_ai_suggestions'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/ai/planner', array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'generate_content_blueprint'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/test-connection', array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'test_gemini_connection'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/gemini-models', array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'get_gemini_models_list'), 'permission_callback' => array($this, 'check_permission')));

    }

    public function check_permission() { 
        return current_user_can('manage_options'); 
    }

    private function get_type_label($type) {
        $pt = get_post_type_object($type);
        if ($pt) return $pt->label;
        $tax = get_taxonomy($type);
        if ($tax) return $tax->label;
        return $type;
    }

    public function get_scan_queue() {
        $scanner = new WILA_Scanner();
        $items = $scanner->get_all_scannable_items();
        return rest_ensure_response(array('success' => true, 'total' => count($items), 'items' => $items));
    }

    public function scan_single_item($request) {
        $params  = $request->get_json_params();
        $item_id = isset($params['id']) ? absint($params['id']) : 0;
        $type    = isset($params['type']) ? sanitize_text_field($params['type']) : 'post';
        if (!$item_id) return new WP_Error('invalid_id', 'شناسه نامعتبر.', array('status' => 400));
        
        $scanner = new WILA_Scanner();
        $result  = $scanner->scan_item($item_id, $type);
        return rest_ensure_response(array('success' => true, 'item_id' => $item_id, 'links_found' => isset($result['links_found']) ? $result['links_found'] : 0));
    }

    public function get_dashboard_summary() {
        global $wpdb;
        $table = WILA_DB::get_table_name();
        $scanner = new WILA_Scanner();
        $all_items = $scanner->get_all_scannable_items();

        $breakdown = array();
        
        foreach ($all_items as $item) {
            $type = $item['type'];
            if (!isset($breakdown[$type])) {
                $breakdown[$type] = array(
                    'label' => $this->get_type_label($type),
                    'count' => 0
                );
            }
            $breakdown[$type]['count']++;
        }

        $total_links = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $orphans = $this->calculate_orphans($all_items);

        return rest_ensure_response(array(
            'total_items'  => count($all_items), 
            'total_links'  => $total_links, 
            'orphan_count' => count($orphans['list']),
            'breakdown'    => $breakdown
        ));
    }

    public function get_items_report($request) {
        global $wpdb;
        $table = WILA_DB::get_table_name();
        $filter = sanitize_text_field($request->get_param('type'));
        $scanner = new WILA_Scanner();
        $items = $scanner->get_all_scannable_items();
        $report = array();

        foreach ($items as $item) {
            if (!empty($filter) && $filter !== 'all' && $item['type'] !== $filter) continue;
            
            $out = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE source_id = %d AND source_type = %s", $item['id'], $item['type']));
            $in  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE target_id = %d AND target_type = %s", $item['id'], $item['type']));
            
            $report[] = array(
                'id'         => $item['id'], 
                'type'       => $item['type'], 
                'type_label' => $this->get_type_label($item['type']),
                'title'      => $item['title'], 
                'url'        => $item['url'], 
                'out_links'  => $out, 
                'in_links'   => $in, 
                'is_orphan'  => ($in === 0)
            );
        }
        return rest_ensure_response($report);
    }

    public function get_item_details($request) {
        global $wpdb;
        $table = WILA_DB::get_table_name();
        $id = absint($request->get_param('id'));
        $type = sanitize_text_field($request->get_param('type'));
        
        $out = $wpdb->get_results($wpdb->prepare("SELECT target_url, target_type, anchor_text, rel_attribute FROM {$table} WHERE source_id = %d AND source_type = %s", $id, $type), ARRAY_A);
        $in  = $wpdb->get_results($wpdb->prepare("SELECT source_title, source_url, source_type, anchor_text, rel_attribute FROM {$table} WHERE target_id = %d AND target_type = %s", $id, $type), ARRAY_A);
        
        foreach ($in as &$link) {
            $link['type_label'] = $this->get_type_label($link['source_type']);
        }

        return rest_ensure_response(array('id' => $id, 'type' => $type, 'incoming_count' => count($in), 'outgoing_count' => count($out), 'incoming_links' => $in, 'outgoing_links' => $out));
    }

    public function get_orphan_items() {
        $scanner = new WILA_Scanner();
        $orphans = $this->calculate_orphans($scanner->get_all_scannable_items());
        
        foreach ($orphans['list'] as &$item) {
            $item['type_label'] = $this->get_type_label($item['type']);
        }
        return rest_ensure_response($orphans['list']);
    }

    public function get_settings() {
        $provider = get_option('wila_ai_provider', 'gemini');
        
        $gemini_key = trim(get_option('wila_gemini_api_key', ''));
        $gemini_client = new WILA_Gemini_Client();
        $gemini_model = $gemini_client->normalize_gemini_model(get_option('wila_gemini_active_model', 'gemini-3.1-pro-preview'));
        
        $openrouter_key = trim(get_option('wila_openrouter_api_key', ''));
        $openrouter_model = get_option('wila_openrouter_active_model', 'qwen/qwen3.8-27b:free');

        $homepage_keywords = get_option('wila_homepage_keywords', '');

        return rest_ensure_response(array(
            'provider'           => $provider,
            'has_gemini_key'     => !empty($gemini_key),
            'gemini_masked_key'  => !empty($gemini_key) ? (substr($gemini_key, 0, 6) . '...' . substr($gemini_key, -4)) : '',
            'gemini_model'       => $gemini_model,
            'has_openrouter_key' => !empty($openrouter_key),
            'openrouter_masked_key' => !empty($openrouter_key) ? (substr($openrouter_key, 0, 6) . '...' . substr($openrouter_key, -4)) : '',
            'openrouter_model'   => $openrouter_model,
            'homepage_keywords'  => $homepage_keywords
        ));
    }

    public function save_settings($request) {
        $params   = $request->get_json_params();
        $provider = isset($params['provider']) ? sanitize_text_field($params['provider']) : 'gemini';
        
        update_option('wila_ai_provider', $provider);

        if (isset($params['homepage_keywords'])) {
            update_option('wila_homepage_keywords', sanitize_textarea_field($params['homepage_keywords']));
        }

        if (isset($params['openrouter_model'])) {
            $model = sanitize_text_field($params['openrouter_model']);
            update_option('wila_openrouter_active_model', $model);
        }
        if (isset($params['openrouter_api_key'])) {
            $key = trim(sanitize_text_field($params['openrouter_api_key']));
            if (!empty($key) && strpos($key, '...') === false) {
                update_option('wila_openrouter_api_key', $key);
            }
        }

        if (isset($params['gemini_model'])) {
            $gemini_client = new WILA_Gemini_Client();
            $model = $gemini_client->normalize_gemini_model(sanitize_text_field($params['gemini_model']));
            update_option('wila_gemini_active_model', $model);
        }
        if (isset($params['gemini_api_key'])) {
            $key = trim(sanitize_text_field($params['gemini_api_key']));
            if (!empty($key) && strpos($key, '...') === false) {
                update_option('wila_gemini_api_key', $key);
            }
        }

        return rest_ensure_response(array('success' => true, 'message' => 'تنظیمات با موفقیت ذخیره شد.'));
    }

    public function generate_ai_suggestions($request) {
        $params = $request->get_json_params();
        $gemini = new WILA_Gemini_Client();
        
        $item_id = isset($params['id']) ? absint($params['id']) : 0;
        $item_type = isset($params['type']) ? sanitize_text_field($params['type']) : '';
        
        $suggestions = $gemini->generate_link_suggestions($item_id, $item_type);
        if (is_wp_error($suggestions)) return $suggestions;
        
        foreach ($suggestions as &$s) {
            if (isset($s['source_type'])) {
                $s['type_label'] = $this->get_type_label($s['source_type']);
            } else {
                $s['type_label'] = 'نامشخص';
            }
        }
        
        return rest_ensure_response(array('success' => true, 'suggestions' => $suggestions));
    }

    public function generate_content_blueprint($request) {
        try {
            $params = $request->get_json_params();
            $gemini = new WILA_Gemini_Client();
            
            $clean_params = array(
                'h1_title'         => isset($params['h1_title']) ? sanitize_text_field($params['h1_title']) : '',
                'focus_keyword'    => isset($params['focus_keyword']) ? sanitize_text_field($params['focus_keyword']) : '',
                'long_tail'        => isset($params['long_tail']) ? sanitize_text_field($params['long_tail']) : '',
                'content_type'     => isset($params['content_type']) ? sanitize_text_field($params['content_type']) : '',
                'funnel_stage'     => isset($params['funnel_stage']) ? sanitize_text_field($params['funnel_stage']) : '',
                'word_count'       => isset($params['word_count']) ? absint($params['word_count']) : 1000,
            );

            $blueprint = $gemini->generate_content_blueprint($clean_params);
            if (is_wp_error($blueprint)) {
                return $blueprint;
            }

            return rest_ensure_response(array('success' => true, 'blueprint' => $blueprint));
        } catch (Exception $e) {
            return new WP_Error('rest_blueprint_error', $e->getMessage());
        }
    }

    public function test_gemini_connection($request) {
        $params   = $request->get_json_params();
        $provider = isset($params['provider']) ? sanitize_text_field($params['provider']) : 'gemini';
        
        if ($provider === 'openrouter') {
            $api_key = isset($params['openrouter_api_key']) ? trim(sanitize_text_field($params['openrouter_api_key'])) : null;
            $model   = isset($params['openrouter_model']) ? sanitize_text_field($params['openrouter_model']) : null;
        } else {
            $api_key = isset($params['gemini_api_key']) ? trim(sanitize_text_field($params['gemini_api_key'])) : null;
            $model   = isset($params['gemini_model']) ? sanitize_text_field($params['gemini_model']) : null;
        }

        if (!empty($api_key) && strpos($api_key, '...') !== false) {
            $api_key = null;
        }

        $gemini = new WILA_Gemini_Client();
        $result = $gemini->test_connection($api_key, $model, $provider);

        if (is_wp_error($result)) {
            return new WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => 400));
        }
        return rest_ensure_response($result);
    }

    public function get_gemini_models_list($request) {
        $params  = $request->get_json_params();
        $api_key = isset($params['gemini_api_key']) ? trim(sanitize_text_field($params['gemini_api_key'])) : null;

        if (!empty($api_key) && strpos($api_key, '...') !== false) {
            $api_key = null;
        }

        $gemini = new WILA_Gemini_Client();
        $result = $gemini->list_gemini_models($api_key);

        if (is_wp_error($result)) {
            return new WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => 400));
        }

        return rest_ensure_response(array('success' => true, 'models' => $result));
    }

    private function calculate_orphans($items) {
        global $wpdb;
        $table = WILA_DB::get_table_name();
        $targets = $wpdb->get_results("SELECT DISTINCT target_id, target_type FROM {$table} WHERE target_id IS NOT NULL", ARRAY_A);
        $map = array();
        foreach ($targets as $t) {
            $map[$t['target_type'] . '_' . $t['target_id']] = true;
        }
        
        $list = array();
        foreach ($items as $item) {
            if (!isset($map[$item['type'] . '_' . $item['id']])) {
                $list[] = $item;
            }
        }
        return array('list' => $list);
    }
}