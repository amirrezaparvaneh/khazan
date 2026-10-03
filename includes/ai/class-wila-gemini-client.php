<?php
/**
 * کلاینت هوش مصنوعی (پشتیبانی از Google Gemini و OpenRouter.ai با قابلیت تزریق کلمات کلیدی صفحه اصلی)
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Gemini_Client {

    private $provider;
    private $api_key;
    private $model;
    private $homepage_keywords;

    public function __construct() {
        $this->provider = get_option('wila_ai_provider', 'gemini');
        $this->homepage_keywords = trim(get_option('wila_homepage_keywords', ''));
        
        if ($this->provider === 'openrouter') {
            $this->api_key = trim(get_option('wila_openrouter_api_key', ''));
            $this->model   = get_option('wila_openrouter_active_model', 'qwen/qwen3.8-27b:free');
        } else {
            $this->api_key = trim(get_option('wila_gemini_api_key', ''));
            $this->model   = get_option('wila_gemini_active_model', 'gemini-3.8-flash');
        }
    }

    public function is_configured() {
        return !empty($this->api_key);
    }

    public function get_active_model() {
        return $this->model;
    }

    private function resolve_key($override_key = null) {
        $key = !empty($override_key) ? trim(sanitize_text_field($override_key)) : $this->api_key;
        if (strpos($key, '...') !== false) {
            $key = $this->api_key;
        }
        return $key;
    }

    public function test_connection($override_key = null, $override_model = null, $override_provider = null) {
        try {
            $provider = !empty($override_provider) ? sanitize_text_field($override_provider) : $this->provider;
            $key = $this->resolve_key($override_key);
            $model = !empty($override_model) ? sanitize_text_field($override_model) : $this->model;

            if (empty($key)) {
                return new WP_Error('missing_api_key', 'کلید API وارد نشده است.');
            }

            $start_time = microtime(true);

            if ($provider === 'openrouter') {
                $url = "https://openrouter.ai/api/v1/chat/completions";
                $body = array(
                    'model' => $model,
                    'messages' => array(array('role' => 'user', 'content' => 'Ping')),
                    'max_tokens' => 5
                );

                $response = wp_remote_post($url, array(
                    'headers' => array(
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $key,
                        'HTTP-Referer'  => home_url(),
                        'X-Title'       => 'WP Internal Link Analyzer'
                    ),
                    'body'    => wp_json_encode($body),
                    'timeout' => 20
                ));
            } else {
                $model = trim(str_replace('models/', '', $model));
                $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent?key=" . urlencode($key);

                $body = array(
                    'contents' => array(array('parts' => array(array('text' => 'Ping')))),
                    'generationConfig' => array('maxOutputTokens' => 3)
                );

                $response = wp_remote_post($url, array(
                    'headers' => array('Content-Type' => 'application/json', 'x-goog-api-key' => $key),
                    'body'    => wp_json_encode($body),
                    'timeout' => 15
                ));
            }

            $latency = round((microtime(true) - $start_time) * 1000);

            if (is_wp_error($response)) {
                return new WP_Error('connection_failed', 'خطا در ارتباط: ' . $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);
            $data = json_decode($raw_body, true);

            if ($code === 200) {
                return array('success' => true, 'model' => $model, 'latency_ms' => $latency, 'message' => 'ارتباط با موفقیت برقرار شد!');
            }
            
            if ($code === 503 || $code === 429) {
                return new WP_Error('api_error', 'سرور هوش مصنوعی در حال حاضر ترافیک بالایی دارد. لطفاً مجدداً تلاش کنید.');
            }

            $err_msg = isset($data['error']['message']) ? sanitize_text_field($data['error']['message']) : ('کد وضعیت سرور: ' . $code);
            return new WP_Error('api_error', $err_msg, array('status' => $code));
        } catch (Exception $e) {
            return new WP_Error('exception_error', 'خطای سیستمی: ' . $e->getMessage());
        }
    }

    private function send_ai_request($prompt) {
        if (!$this->is_configured()) return new WP_Error('missing_api_key', 'کلید API ثبت نشده است.');

        if ($this->provider === 'openrouter') {
            $url = "https://openrouter.ai/api/v1/chat/completions";
            $body = array(
                'model' => $this->model,
                'messages' => array(
                    array('role' => 'user', 'content' => $prompt)
                ),
                'response_format' => array('type' => 'json_object')
            );

            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'HTTP-Referer'  => home_url(),
                    'X-Title'       => 'WP Internal Link Analyzer'
                ),
                'body'    => wp_json_encode($body),
                'timeout' => 90 // افزایش Timeout به ۹۰ ثانیه برای جلوگیری از خطای cURL 28
            ));

            if (is_wp_error($response)) return $response;
            
            $code = wp_remote_retrieve_response_code($response);
            $raw = wp_remote_retrieve_body($response);
            $data = json_decode($raw, true);

            if ($code !== 200) {
                $msg = isset($data['error']['message']) ? $data['error']['message'] : 'خطای سرور OpenRouter';
                return new WP_Error('ai_error', $msg);
            }

            if (!isset($data['choices'][0]['message']['content'])) {
                return new WP_Error('invalid_response', 'پاسخ نامعتبر از OpenRouter.');
            }

            return $data['choices'][0]['message']['content'];

        } else {
            $clean_model = trim(str_replace('models/', '', $this->model));
            $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $clean_model . ":generateContent?key=" . urlencode($this->api_key);

            $body = array(
                'contents' => array(array('parts' => array(array('text' => $prompt)))),
                'generationConfig' => array('temperature' => 0.2, 'responseMimeType' => 'application/json')
            );

            $response = wp_remote_post($url, array(
                'headers' => array('Content-Type' => 'application/json', 'x-goog-api-key' => $this->api_key),
                'body'    => wp_json_encode($body),
                'timeout' => 90 // افزایش Timeout به ۹۰ ثانیه
            ));

            if (is_wp_error($response)) return $response;
            
            $code = wp_remote_retrieve_response_code($response);
            $raw = wp_remote_retrieve_body($response);
            $data = json_decode($raw, true);

            if ($code !== 200) {
                $msg = isset($data['error']['message']) ? $data['error']['message'] : 'خطای سرور گوگل.';
                return new WP_Error('ai_error', $msg);
            }

            if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                return new WP_Error('invalid_response', 'پاسخ نامعتبر از جمینای.');
            }

            return $data['candidates'][0]['content']['parts'][0]['text'];
        }
    }

    public function generate_content_blueprint($params) {
        try {
            $candidates = $this->getAllSiteEntities();
            if (empty($candidates)) return new WP_Error('no_candidates', 'هیچ محتوایی در سایت برای لینک‌دهی یافت نشد.');

            $prompt = $this->build_blueprint_prompt($params, $candidates);
            $json_text = $this->send_ai_request($prompt);
            
            if (is_wp_error($json_text)) return $json_text;

            $json_text = preg_replace('/^```json\s*/i', '', trim($json_text));$json_text = preg_replace('/```$/', '', trim($json_text));

            $parsed = json_decode(trim($json_text), true);
            return $parsed ? $parsed : array();
        } catch (Exception $e) {
            return new WP_Error('blueprint_exception', 'خطا در پردازش استراتژی: ' . $e->getMessage());
        }
    }

    public function generate_link_suggestions($target_id, $target_type) {
        try {
            $target_info = $this->get_entity_info($target_id, $target_type);
            if (!$target_info) return new WP_Error('invalid_target', 'اطلاعات صفحه مقصد یافت نشد.');

            $candidates = $this->getAllSiteEntities();
            if (empty($candidates)) return new WP_Error('no_candidates', 'هیچ محتوایی برای پیشنهاد پیدا نشد.');

            $prompt = $this->build_analysis_prompt($target_info, $candidates);
            $json_text = $this->send_ai_request($prompt);

            if (is_wp_error($json_text)) return $json_text;

            $json_text = preg_replace('/^```json\s*/i', '', trim($json_text));$json_text = preg_replace('/```$/', '', trim($json_text));

            $parsed = json_decode(trim($json_text), true);
            return $parsed ? $parsed : array();
        } catch (Exception $e) {
            return new WP_Error('suggestions_exception', 'خطا: ' . $e->getMessage());
        }
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

    private function getAllSiteEntities() {
        $candidates = array();
        $post_types = get_post_types(array('public' => true), 'names');
        unset($post_types['attachment']);
        
        $posts = get_posts(array('post_type' => array_values($post_types), 'post_status' => 'publish', 'posts_per_page' => 40));
        foreach ($posts as $p) {
            $candidates[] = array('id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'url' => get_permalink($p->ID));
        }

        $taxonomies = get_taxonomies(array('public' => true), 'names');
        unset($taxonomies['post_tag'], $taxonomies['nav_menu']);
        $terms = get_terms(array('taxonomy' => array_values($taxonomies), 'hide_empty' => false, 'number' => 25));
        
        if (!is_wp_error($terms)) {
            foreach ($terms as $t) {
                $candidates[] = array('id' => $t->term_id, 'type' => $t->taxonomy, 'title' => $t->name, 'url' => get_term_link($t));
            }
        }
        return $candidates;
    }

    private function build_analysis_prompt($target, $candidates) {
        $target_json = wp_json_encode($target, JSON_UNESCAPED_UNICODE);
        $cands_json = wp_json_encode($candidates, JSON_UNESCAPED_UNICODE);
        
        $homepage_instruction = !empty($this->homepage_keywords) 
            ? "\nنکته بسیار مهم سئو: صفحه اصلی سایت (آدرس: " . home_url() . ") را به عنوان یکی از مقاصد اصلی در نظر بگیرید و از این کلمات کلیدی برای لینک به صفحه اصلی استفاده کنید: [" . $this->homepage_keywords . "]"
            : "";

        return "شما یک متخصص ارشد سئو تکنیکال هستید.\n" .
               "صفحه مقصد: " . $target_json . "\n" .
               "صفحات کاندیدا مبدا: " . $cands_json . "\n" .
               $homepage_instruction . "\n" .
               "بر اساس اطلاعات بالا یک آرایه JSON معتبر با مشخصات زیر تولید کنید:\n" .
               '[{"source_id": 123, "source_title": "...", "source_type": "post", "recommended_anchor_texts": ["انکر۱", "انکر۲"], "link_frequency": 1, "placement_guide": "...", "reasoning": "..."}]';
    }

    private function build_blueprint_prompt($params, $candidates) {
        $params_json = wp_json_encode($params, JSON_UNESCAPED_UNICODE);
        $cands_json = wp_json_encode($candidates, JSON_UNESCAPED_UNICODE);
        
        $homepage_instruction = !empty($this->homepage_keywords) 
            ? "\n[دستورالعمل ویژه سئو صفحه اول]: حتماً در بخش لینک‌های خروجی، صفحه اصلی سایت با آدرس (" . home_url() . ") را نیز به عنوان یکی از مقاصد لینک‌دهی با انکرتکست‌های مرتبط از میان کلمات کلیدی صفحه اصلی یعنی [" . $this->homepage_keywords . "] پیشنهاد دهید."
            : "";

        return "شما یک استراتژیست ارشد سئو و معماری لینک‌سازی داخلی هستید.\n" .
               "مشخصات مقاله جدیدی که قرار است نوشته شود:\n" . $params_json . "\n\n" .
               "لیست صفحات موجود در سایت برای لینک‌دهی:\n" . $cands_json . "\n\n" .
               $homepage_instruction . "\n\n" .
               "دستورالعمل: بر اساس تعداد کلمات و مشخصات این مقاله، یک نقشه راه استراتژیک لینک‌‌سازی به صورت یک شیء JSON معتبر با ساختار زیر تولید کنید:\n" .
               "{\n" .
               '  "total_recommended_links": 5,' . "\n" .
               '  "strategy_notes": "توضیح کوتاه استراتژیک درباره توزیع لینک‌ها بر اساس نوع محتوا",' . "\n" .
               '  "outgoing_links": [' . "\n" .
               '    {' . "\n" .
               '      "target_id": 123,' . "\n" .
               '      "target_title": "عنوان صفحه یا صفحه اصلی سایت",' . "\n" .
               '      "target_url": "آدرس صفحه",' . "\n" .
               '      "anchor_text": "انکرتکست پیشنهادی",' . "\n" .
               '      "placement": "محل دقیق قرارگیری در متن"' . "\n" .
               '    }' . "\n" .
               '  ],' . "\n" .
               '  "incoming_link_suggestions": [' . "\n" .
               '    {' . "\n" .
               '      "old_page_title": "عنوان یک مقاله قدیمی مرتبط",' . "\n" .
               '      "action": "پس از انتشار این مقاله، به مقاله قدیمی مراجعه کرده و لینک بدهید."\n' .
               '    }' . "\n" .
               '  ]' . "\n" .
               "}";
    }
}