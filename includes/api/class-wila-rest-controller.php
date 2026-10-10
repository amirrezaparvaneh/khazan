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
        register_rest_route($this->namespace, '/content/outline', array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'generate_content_outline'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/generate', array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'generate_full_content'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/draft', array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'create_content_draft'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/history', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_content_history'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/content/history/(?P<id>\d+)', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_content_history_item'), 'permission_callback' => array($this, 'check_permission')));
        register_rest_route($this->namespace, '/test-connection', array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'test_avalai_connection'), 'permission_callback' => array($this, 'check_permission')));

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

        // Keep the dashboard usable when the index table is unavailable.
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) {
            return rest_ensure_response(array(
                'total_items'  => count($all_items),
                'total_links'  => 0,
                'orphan_count' => count($all_items),
                'breakdown'    => $breakdown
            ));
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
        
        $priorities = array('product_cat' => 1, 'product' => 2, 'post' => 3, 'page' => 4);
        foreach ($orphans['list'] as &$item) {
            $item['type_label'] = $this->get_type_label($item['type']);
            $item['priority'] = isset($priorities[$item['type']]) ? $priorities[$item['type']] : 3;
        }
        unset($item);
        usort($orphans['list'], function ($a, $b) {
            if ($a['priority'] !== $b['priority']) return $a['priority'] - $b['priority'];
            return strcasecmp($a['title'], $b['title']);
        });
        return rest_ensure_response($orphans['list']);
    }

    public function get_settings() {
        $provider = 'avalai';
        $avalai_key = trim(get_option('wila_avalai_api_key', ''));
        $avalai_client = new WILA_AvalAI_Client();
        $homepage_keywords = get_option('wila_homepage_keywords', '');

        return rest_ensure_response(array(
            'provider' => $provider,
            'has_avalai_key' => !empty($avalai_key),
            'avalai_masked_key' => !empty($avalai_key) ? (substr($avalai_key, 0, 6) . '...' . substr($avalai_key, -4)) : '',
            'avalai_model' => $avalai_client->get_active_model(),
            'homepage_keywords' => $homepage_keywords
        ));
    }

    public function save_settings($request) {
        $params   = $request->get_json_params();
        update_option('wila_ai_provider', 'avalai');

        if (isset($params['homepage_keywords'])) {
            update_option('wila_homepage_keywords', sanitize_textarea_field($params['homepage_keywords']));
        }
        if (isset($params['avalai_api_key'])) {
            $key = trim(sanitize_text_field($params['avalai_api_key']));
            if (!empty($key) && strpos($key, '...') === false) {
                update_option('wila_avalai_api_key', $key);
            }
        }

        return rest_ensure_response(array('success' => true, 'message' => 'تنظیمات با موفقیت ذخیره شد.'));
    }

    public function generate_ai_suggestions($request) {
        $params = $request->get_json_params();
        $avalai = new WILA_AvalAI_Client();
        
        $item_id = isset($params['id']) ? absint($params['id']) : 0;
        $item_type = isset($params['type']) ? sanitize_text_field($params['type']) : '';
        
        $suggestions = $avalai->generate_link_suggestions($item_id, $item_type);
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
            $avalai = new WILA_AvalAI_Client();
            
            $clean_params = array(
                'h1_title'         => isset($params['h1_title']) ? sanitize_text_field($params['h1_title']) : '',
                'focus_keyword'    => isset($params['focus_keyword']) ? sanitize_text_field($params['focus_keyword']) : '',
                'long_tail'        => isset($params['long_tail']) ? sanitize_text_field($params['long_tail']) : '',
                'content_type'     => isset($params['content_type']) ? sanitize_text_field($params['content_type']) : '',
                'funnel_stage'     => isset($params['funnel_stage']) ? sanitize_text_field($params['funnel_stage']) : '',
                'word_count'       => isset($params['word_count']) ? absint($params['word_count']) : 1000,
            );

            $blueprint = $avalai->generate_content_blueprint($clean_params);
            if (is_wp_error($blueprint)) {
                return $blueprint;
            }

            return rest_ensure_response(array('success' => true, 'blueprint' => $blueprint));
        } catch (Exception $e) {
            return new WP_Error('rest_blueprint_error', $e->getMessage());
        }
    }

    private function content_generator_params($request) {
        $params = $request->get_json_params();
        $internal_links = isset($params['internal_links']) && is_array($params['internal_links']) ? array_map(function ($link) {
            return array('anchor' => isset($link['anchor']) ? sanitize_text_field($link['anchor']) : '', 'url' => isset($link['url']) ? esc_url_raw($link['url']) : '');
        }, $params['internal_links']) : array();
        $use_orphan_links = !empty($params['use_orphan_links']);

        if ($use_orphan_links && empty($internal_links)) {
            $scanner = new WILA_Scanner();
            $orphans = $this->calculate_orphans($scanner->get_all_scannable_items());
            $priorities = array('product_cat' => 1, 'product' => 2, 'post' => 3, 'page' => 4);
            usort($orphans['list'], function ($a, $b) use ($priorities) {
                $a_priority = isset($priorities[$a['type']]) ? $priorities[$a['type']] : 3;
                $b_priority = isset($priorities[$b['type']]) ? $priorities[$b['type']] : 3;
                return $a_priority === $b_priority ? strcasecmp($a['title'], $b['title']) : $a_priority - $b_priority;
            });
            foreach ($orphans['list'] as $orphan) {
                if (!empty($orphan['url']) && !empty($orphan['title'])) {
                    $internal_links[] = array('anchor' => $orphan['title'], 'url' => esc_url_raw($orphan['url']), 'orphan' => true);
                }
            }
        }

        return array(
            'h1_title' => isset($params['h1_title']) ? sanitize_text_field($params['h1_title']) : '',
            'focus_keyword' => isset($params['focus_keyword']) ? sanitize_text_field($params['focus_keyword']) : '',
            'long_tail' => isset($params['long_tail']) ? sanitize_text_field($params['long_tail']) : '',
            'word_count' => isset($params['word_count']) ? max(300, min(10000, absint($params['word_count']))) : 1200,
            'content_type' => isset($params['content_type']) ? sanitize_text_field($params['content_type']) : '',
            'funnel_stage' => isset($params['funnel_stage']) ? sanitize_text_field($params['funnel_stage']) : '',
            'description' => isset($params['description']) ? sanitize_textarea_field($params['description']) : '',
            'internal_links' => $internal_links,
            'use_orphan_links' => $use_orphan_links,
            'homepage_keywords' => get_option('wila_homepage_keywords', ''),
            'homepage_url' => home_url('/'),
        );
    }

    public function generate_content_outline($request) {
        $params = $this->content_generator_params($request);
        if (empty($params['h1_title']) || empty($params['focus_keyword'])) return new WP_Error('missing_content_details', 'عنوان و کلیدواژه کانونی الزامی هستند.', array('status' => 400));
        $result = (new WILA_AvalAI_Client())->generate_content_outline($params);
        return is_wp_error($result) ? $result : rest_ensure_response(array('success' => true, 'outline' => $result));
    }

    public function generate_full_content($request) {
        $params = $this->content_generator_params($request);
        $body = $request->get_json_params();
        $outline = isset($body['outline']) && is_array($body['outline']) ? $body['outline'] : array();
        if (empty($params['h1_title']) || empty($params['focus_keyword']) || empty($outline)) return new WP_Error('missing_content_details', 'مشخصات و طرح تاییدشده محتوا الزامی هستند.', array('status' => 400));
        $result = (new WILA_AvalAI_Client())->generate_full_content($params, $outline);
        if (is_wp_error($result)) return $result;

        global $wpdb;
        $history_table = WILA_DB::get_content_history_table_name();
        $wpdb->insert($history_table, array(
            'title' => $params['h1_title'],
            'focus_keyword' => $params['focus_keyword'],
            'slug' => $result['slug'],
            'content' => $result['content'],
            'featured_image_prompt' => $result['featured_image_prompt'],
            'internal_link_count' => $result['internal_link_count'],
            'external_link_count' => $result['external_link_count'],
            'external_links' => wp_json_encode($result['external_links'], JSON_UNESCAPED_UNICODE),
            'seo_title' => $result['seo_title'],
            'meta_description' => $result['meta_description'],
            'keyword_synonyms' => $result['keyword_synonyms'],
            'created_at' => current_time('mysql'),
        ), array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s'));

        return rest_ensure_response(array('success' => true, 'content' => $result['content'], 'featured_image_prompt' => $result['featured_image_prompt'], 'slug' => $result['slug'], 'seo_title' => $result['seo_title'], 'meta_description' => $result['meta_description'], 'keyword_synonyms' => $result['keyword_synonyms'], 'internal_link_count' => $result['internal_link_count'], 'external_link_count' => $result['external_link_count'], 'external_links' => $result['external_links']));
    }

    public function get_content_history() {
        global $wpdb;
        $table = WILA_DB::get_content_history_table_name();
        $items = $wpdb->get_results("SELECT id, title, focus_keyword, slug, internal_link_count, external_link_count, seo_title, meta_description, created_at FROM {$table} ORDER BY created_at DESC, id DESC LIMIT 100", ARRAY_A);
        return rest_ensure_response(array('success' => true, 'items' => $items));
    }

    public function get_content_history_item($request) {
        global $wpdb;
        $table = WILA_DB::get_content_history_table_name();
        $item = $wpdb->get_row($wpdb->prepare("SELECT id, title, focus_keyword, slug, content, featured_image_prompt, internal_link_count, external_link_count, external_links, seo_title, meta_description, keyword_synonyms, created_at FROM {$table} WHERE id = %d", absint($request['id'])), ARRAY_A);
        if (!$item) return new WP_Error('content_not_found', 'محتوای درخواستی یافت نشد.', array('status' => 404));
        return rest_ensure_response(array('success' => true, 'item' => $item));
    }

    public function create_content_draft($request) {
        $params = $request->get_json_params();
        $title = isset($params['title']) ? sanitize_text_field($params['title']) : '';
        $content = isset($params['content']) ? wp_kses_post($params['content']) : '';
        $slug = isset($params['slug']) ? sanitize_title($params['slug']) : '';
        if (empty($title) || empty($content)) return new WP_Error('missing_draft_content', 'عنوان و محتوای پیش‌نویس الزامی هستند.', array('status' => 400));
        $blog_post_type = apply_filters('wila_blog_post_type', 'blog');
        if (!post_type_exists($blog_post_type)) {
            return new WP_Error('blog_post_type_not_found', 'نوع محتوای «وبلاگ» یافت نشد. با فیلتر wila_blog_post_type نام post type وبلاگ را تنظیم کنید.', array('status' => 400));
        }
        if (!current_user_can(get_post_type_object($blog_post_type)->cap->create_posts)) {
            return new WP_Error('cannot_create_blog_draft', 'اجازه ایجاد پیش‌نویس در بخش وبلاگ را ندارید.', array('status' => 403));
        }
        $post_id = wp_insert_post(array('post_title' => $title, 'post_content' => $content, 'post_status' => 'draft', 'post_type' => $blog_post_type, 'post_name' => $slug), true);
        if (is_wp_error($post_id)) return $post_id;

        $focus_keyword = isset($params['focus_keyword']) ? sanitize_text_field($params['focus_keyword']) : '';
        $synonyms = isset($params['keyword_synonyms']) ? sanitize_text_field($params['keyword_synonyms']) : '';
        $seo_title = isset($params['seo_title']) ? sanitize_text_field($params['seo_title']) : $title;
        $meta_description = isset($params['meta_description']) ? sanitize_textarea_field($params['meta_description']) : '';
        if ($focus_keyword !== '') update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyword);
        if ($synonyms !== '') update_post_meta($post_id, '_yoast_wpseo_keywordsynonyms', $synonyms);
        if ($seo_title !== '') update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
        if ($meta_description !== '') update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);

        return rest_ensure_response(array('success' => true, 'post_id' => $post_id, 'slug' => get_post_field('post_name', $post_id), 'edit_url' => get_edit_post_link($post_id, 'raw')));
    }

    public function test_avalai_connection($request) {
        $params   = $request->get_json_params();
        $api_key = isset($params['avalai_api_key']) ? trim(sanitize_text_field($params['avalai_api_key'])) : null;
        if (!empty($api_key) && strpos($api_key, '...') !== false) {
            $api_key = null;
        }

        $client = new WILA_AvalAI_Client();
        $result = $client->test_connection($api_key);

        if (is_wp_error($result)) {
            return new WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => 400));
        }
        return rest_ensure_response($result);
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