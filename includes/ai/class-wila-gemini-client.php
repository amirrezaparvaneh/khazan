<?php
/**
 * کلاینت ارتباط با Gemini API با قابلیت انتخاب دستی مدل توسط کاربر
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Gemini_Client {

    private $api_key;
    private $default_model = 'gemini-1.5-flash';

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

        if (empty($key)) {
            return new WP_Error('missing_api_key', 'هیچ کلید معتبری برای تست ارسال نشده است.');
        }

        $headers = array(
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $key,
        );

        $start_time = microtime(true);
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($key);

        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array('text' => 'Ping')
                    )
                )
            ),
            'generationConfig' => array(
                'maxOutputTokens' => 3
            )
        );

        $response = wp_remote_post($url, array(
            'headers'     => $headers,
            'body'        => wp_json_encode($body),
            'timeout'     => 15,
            'data_format' => 'body'
        ));

        $latency = round((microtime(true) - $start_time) * 1000);

        if (is_wp_error($response)) {
            return new WP_Error('connection_failed', 'خطا در ارتباط با سرور: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);

        if ($code === 200) {
            return array(
                'success'    => true,
                'model'      => $model,
                'latency_ms' => $latency,
                'message'    => 'ارتباط با موفقیت برقرار شد. مدل آماده دریافت درخواست است.'
            );
        }

        if ($code === 401) {
            return new WP_Error('auth_failed', 'احراز هویت ناموفق بود (401). کلید نامعتبر است.');
        }

        $err_msg = isset($data['error']['message']) ? $data['error']['message'] : ('کد وضعیت سرور: ' . $code);
        return new WP_Error('api_error', $err_msg, array('status' => $code));
    }

    public function generate_link_suggestions($target_id, $target_type) {
        if (!$this->is_configured()) {
            return new WP_Error('missing_api_key', 'کلید API ثبت نشده است.');
        }

        $target_info = $this->get_entity_info($target_id, $target_type);
        if (!$target_info) {
            return new WP_Error('invalid_target', 'اطلاعات صفحه مقصد یافت نشد.');
        }

        $candidates = $this->get_candidate_sources($target_id, $target_type);
        if (empty($candidates)) {
            return new WP_Error('no_candidates', 'صفحه کاندیدایی یافت نشد.');
        }

        $active_model = $this->get_active_model();
        $prompt = $this->build_analysis_prompt($target_info, $candidates);
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$active_model}:generateContent?key=" . urlencode($this->api_key);

        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array('text' => $prompt)
                    )
                )
            ),
            'generationConfig' => array(
                'temperature'      => 0.2,
                'responseMimeType' => 'application/json',
            )
        );

        $response = wp_remote_post($url, array(
            'headers'     => array(
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $this->api_key,
            ),
            'body'        => wp_json_encode($body),
            'timeout'     => 45,
            'data_format' => 'body'
        ));

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $error_data = json_decode($raw_body, true);
            $msg = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'خطا در ارتباط با سرور گوگل.';
            return new WP_Error('gemini_api_error', $msg);
        }

        $result = json_decode($raw_body, true);
        if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return new WP_Error('invalid_ai_response', 'پاسخ نامعتبر از جمینای.');
        }

        $json_text = $result['candidates'][0]['content']['parts'][0]['text'];
        $parsed = json_decode($json_text, true);

        return $parsed ? $parsed : array();
    }

    private function get_entity_info($id, $type) {
        if ($type === 'post') {
            $post = get_post($id);
            if (!$post) return null;
            return array('id' => $id, 'type' => 'مقاله', 'title' => $post->post_title, 'url' => get_permalink($id), 'excerpt' => wp_strip_all_tags(wp_trim_words($post->post_content, 50)));
        }
        $term = get_term($id, $type);
        if (!$term || is_wp_error($term)) return null;
        return array('id' => $id, 'type' => ($type === 'product_cat' ? 'دسته‌بندی محصول' : 'دسته وبلاگ'), 'title' => $term->name, 'url' => get_term_link($term), 'excerpt' => !empty($term->description) ? wp_strip_all_tags($term->description) : 'بدون توضیحات');
    }

    private function get_candidate_sources($exclude_id, $exclude_type) {
        $candidates = array();
        $posts = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 20, 'exclude' => ($exclude_type === 'post') ? array($exclude_id) : array()));
        foreach ($posts as $p) $candidates[] = array('id' => $p->ID, 'type' => 'post', 'title' => $p->post_title, 'summary' => wp_strip_all_tags(wp_trim_words($p->post_content, 35)));
        
        if (taxonomy_exists('product_cat')) {
            $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 15, 'exclude' => ($exclude_type === 'product_cat') ? array($exclude_id) : array()));
            if (!is_wp_error($terms)) foreach ($terms as $t) $candidates[] = array('id' => $t->term_id, 'type' => 'product_cat', 'title' => $t->name, 'summary' => !empty($t->description) ? wp_strip_all_tags(wp_trim_words($t->description, 25)) : 'دسته‌بندی');
        }
        return $candidates;
    }

    private function build_analysis_prompt($target, $candidates) {
        $target_json = wp_json_encode($target, JSON_UNESCAPED_UNICODE);
        $cands_json = wp_json_encode($candidates, JSON_UNESCAPED_UNICODE);
        return <<<PROMPT
شما یک متخصص ارشد سئو تکنیکال هستید.
صفحه مقصد: {$target_json}
صفحات کاندیدا مبدا: {$cands_json}
بر اساس اطلاعات بالا یک آرایه JSON معتبر با مشخصات زیر تولید کنید:
[{"source_id": 123, "source_title": "...", "source_type": "post", "recommended_anchor_texts": ["انکر۱", "انکر۲"], "link_frequency": 1, "placement_guide": "...", "reasoning": "..."}]
PROMPT;
    }
}