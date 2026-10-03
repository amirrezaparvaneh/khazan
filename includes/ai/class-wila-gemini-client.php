<?php
/**
 * کلاینت ارتباط با Gemini API
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Gemini_Client {

    private $api_key;
    private $default_model = 'gemini-3.8-flash';

    public function __construct() {
        $this->api_key = trim(get_option('wila_gemini_api_key', ''));
    }

    public function is_configured() {
        return !empty($this->api_key);
    }

    public function get_active_model() {
        return get_option('wila_gemini_active_model', $this->default_model);
    }

    private function resolve_key($override_key = null) {
        $key = !empty($override_key) ? trim(sanitize_text_field($override_key)) : $this->api_key;
        if (strpos($key, '...') !== false) {
            $key = $this->api_key;
        }
        return $key;
    }

    public function test_connection($override_key = null, $override_model = null) {
        $key = $this->resolve_key($override_key);
        $model = !empty($override_model) ? sanitize_text_field($override_model) : $this->get_active_model();
        $model = trim(str_replace('models/', '', $model));

        if (empty($key)) {
            return new WP_Error('missing_api_key', 'کلید API وارد نشده است.');
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($key);

        $body = array(
            'contents' => array(array('parts' => array(array('text' => 'Ping')))),
            'generationConfig' => array('maxOutputTokens' => 3)
        );

        $start_time = microtime(true);
        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json', 'x-goog-api-key' => $key),
            'body'    => wp_json_encode($body),
            'timeout' => 15
        ));

        $latency = round((microtime(true) - $start_time) * 1000);

        if (is_wp_error($response)) {
            return new WP_Error('connection_failed', 'خطا در ارتباط: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);

        if ($code === 200) {
            return array('success' => true, 'model' => $model, 'latency_ms' => $latency, 'message' => 'ارتباط موفقیت‌آمیز بود!');
        }

        $err_msg = isset($data['error']['message']) ? $data['error']['message'] : ('کد وضعیت سرور: ' . $code);
        return new WP_Error('api_error', $err_msg, array('status' => $code));
    }

    public function generate_link_suggestions($target_id, $target_type) {
        if (!$this->is_configured()) return new WP_Error('missing_api_key', 'کلید API ثبت نشده است.');

        $target_info = $this->get_entity_info($target_id, $target_type);
        if (!$target_info) return new WP_Error('invalid_target', 'اطلاعات صفحه مقصد یافت نشد.');

        $candidates = $this->get_candidate_sources($target_id, $target_type);
        if (empty($candidates)) return new WP_Error('no_candidates', 'هیچ محتوایی برای پیشنهاد پیدا نشد.');

        $model = $this->get_active_model();
        $prompt = $this->build_analysis_prompt($target_info, $candidates);
        
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($this->api_key);

        $body = array(
            'contents' => array(array('parts' => array(array('text' => $prompt)))),
            'generationConfig' => array('temperature' => 0.2, 'responseMimeType' => 'application/json')
        );

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json', 'x-goog-api-key' => $this->api_key),
            'body'    => wp_json_encode($body),
            'timeout' => 45
        ));

        if (is_wp_error($response)) return $response;
        
        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $err = json_decode($raw, true);
            return new WP_Error('gemini_error', isset($err['error']['message']) ? $err['error']['message'] : 'خطای سرور گوگل.');
        }

        $res = json_decode($raw, true);
        if (!isset($res['candidates'][0]['content']['parts'][0]['text'])) return new WP_Error('invalid_response', 'پاسخ نامعتبر.');
        
        $json_text = $res['candidates'][0]['content']['parts'][0]['text'];
        
        $json_text = preg_replace('/^```json\s*/i', '', trim($json_text));$json_text = preg_replace('/```$/', '', trim($json_text));

        $parsed = json_decode(trim($json_text), true);
        return $parsed ? $parsed : array();
    }

    private function get_entity_info($id, $type) {
        $post_types = get_post_types(array('public' => true), 'names');
        if (in_array($type, $post_types)) {
            $post = get_post($id);
            if (!$post) return null;
            return array('id' => $id, 'type' => $type, 'title' => $post->post_title, 'url' => get_permalink($id), 'excerpt' => wp_strip_all_tags(wp_trim_words($post->post_content, 50)));
        }
        $term = get_term($id, $type);
        if (!$term || is_wp_error($term)) return null;
        return array('id' => $id, 'type' => $type, 'title' => $term->name, 'url' => get_term_link($term), 'excerpt' => !empty($term->description) ? wp_strip_all_tags($term->description) : 'بدون توضیح');
    }

    private function get_candidate_sources($exclude_id, $exclude_type) {
        $candidates = array();
        
        $post_types = get_post_types(array('public' => true), 'names');
        unset($post_types['attachment']);
        $posts = get_posts(array(
            'post_type' => array_values($post_types), 
            'post_status' => 'publish', 
            'posts_per_page' => 15, 
            'exclude' => !taxonomy_exists($exclude_type) ? array($exclude_id) : array()
        ));
        foreach ($posts as $p) {
            $candidates[] = array('id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'summary' => wp_strip_all_tags(wp_trim_words($p->post_content, 35)));
        }

        $taxonomies = get_taxonomies(array('public' => true), 'names');
        unset($taxonomies['post_tag'], $taxonomies['nav_menu']);
        $terms = get_terms(array(
            'taxonomy' => array_values($taxonomies), 
            'hide_empty' => false, 
            'number' => 10,
            'exclude' => taxonomy_exists($exclude_type) ? array($exclude_id) : array()
        ));
        
        if (!is_wp_error($terms)) {
            foreach ($terms as $t) {
                $candidates[] = array('id' => $t->term_id, 'type' => $t->taxonomy, 'title' => $t->name, 'summary' => !empty($t->description) ? wp_strip_all_tags(wp_trim_words($t->description, 25)) : 'دسته‌بندی');
            }
        }

        return $candidates;
    }

    private function build_analysis_prompt($target, $candidates) {
        $target_json = wp_json_encode($target, JSON_UNESCAPED_UNICODE);
        $cands_json = wp_json_encode($candidates, JSON_UNESCAPED_UNICODE);
        
        $prompt = "شما یک متخصص ارشد سئو تکنیکال هستید.\n";
        $prompt .= "صفحه مقصد: " . $target_json . "\n";
        $prompt .= "صفحات کاندیدا مبدا: " . $cands_json . "\n";
        $prompt .= "بر اساس اطلاعات بالا یک آرایه JSON معتبر با مشخصات زیر تولید کنید:\n";
        $prompt .= '[{"source_id": 123, "source_title": "...", "source_type": "post", "recommended_anchor_texts": ["انکر۱", "انکر۲"], "link_frequency": 1, "placement_guide": "...", "reasoning": "..."}]';
        
        return $prompt;
    }
}