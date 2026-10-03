<?php
/**
 * کلاینت هوشمند ارتباط با Gemini API با قابلیت تشخیص خودکار مدل‌های در دسترس
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Gemini_Client {

    private $api_key;
    private $default_model = 'gemini-1.5-flash-latest';

    public function __construct() {
        $this->api_key = get_option('wila_gemini_api_key', '');
    }

    public function is_configured() {
        return !empty($this->api_key);
    }

    public function get_active_model() {
        return get_option('wila_gemini_active_model', $this->default_model);
    }

    /**
     * دریافت لیست مدل‌های مجاز برای این API Key از گوگل
     */
    public function fetch_available_models($override_key = null) {
        $key = !empty($override_key) ? sanitize_text_field($override_key) : $this->api_key;

        if (empty($key)) {
            return new WP_Error('missing_api_key', 'کلید API مشخص نشده است.');
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($key);

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json')
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);

        if ($code !== 200 || !isset($data['models'])) {
            $err_msg = isset($data['error']['message']) ? $data['error']['message'] : ('کد خطای سرور: ' . $code);
            return new WP_Error('list_models_failed', $err_msg);
        }

        $content_models = array();
        foreach ($data['models'] as $m) {
            if (isset($m['supportedGenerationMethods']) && in_array('generateContent', $m['supportedGenerationMethods'], true)) {
                $content_models[] = str_replace('models/', '', $m['name']);
            }
        }

        return $content_models;
    }

    /**
     * انتخاب خودکار بهینه‌ترین مدل از بین مدل‌های در دسترس
     */
    public function pick_best_model($available_models) {
        if (empty($available_models)) {
            return $this->default_model;
        }

        // اولویت‌بندی مدل‌ها از نظر سرعت، قیمت و کارایی
        $priority_list = array(
            'gemini-1.5-flash-latest',
            'gemini-1.5-flash',
            'gemini-1.5-flash-001',
            'gemini-1.5-flash-002',
            'gemini-1.5-flash-8b',
            'gemini-2.0-flash',
            'gemini-2.0-flash-exp',
            'gemini-1.5-pro-latest',
            'gemini-1.5-pro',
            'gemini-pro'
        );

        foreach ($priority_list as $candidate) {
            if (in_array($candidate, $available_models, true)) {
                return $candidate;
            }
        }

        foreach ($available_models as $model) {
            if (stripos($model, 'flash') !== false) {
                return $model;
            }
        }

        return $available_models[0];
    }

    /**
     * تست زنده اتصال همراه با اعتبارسنجی خودکار مدل
     */
    public function test_connection($override_key = null) {
        $key = !empty($override_key) ? sanitize_text_field($override_key) : $this->api_key;

        if (empty($key)) {
            return new WP_Error('missing_api_key', 'هیچ کلید API برای تست ارسال نشده است.');
        }

        // ۱. واکشی مدل‌های مجاز برای این کلید
        $available_models = $this->fetch_available_models($key);
        if (is_wp_error($available_models)) {
            return $available_models;
        }

        if (empty($available_models)) {
            return new WP_Error('no_supported_models', 'این کلید API به هیچ مدلی با قابلیت تولید محتوا (generateContent) دسترسی ندارد.');
        }

        // ۲. انتخاب هوشمند بهترین مدل در دسترس
        $selected_model = $this->pick_best_model($available_models);

        // ۳. ارسال پینگ تست به مدل انتخاب‌شده
        $start_time = microtime(true);
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$selected_model}:generateContent?key=" . urlencode($key);

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
            'headers'     => array('Content-Type' => 'application/json'),
            'body'        => wp_json_encode($body),
            'timeout'     => 15,
            'data_format' => 'body'
        ));

        $latency = round((microtime(true) - $start_time) * 1000);

        if (is_wp_error($response)) {
            return new WP_Error('connection_failed', 'خطا در ارتباط با سرور گوگل: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);

        if ($code === 200) {
            update_option('wila_gemini_active_model', $selected_model);

            return array(
                'success'    => true,
                'model'      => $selected_model,
                'latency_ms' => $latency,
                'message'    => 'ارتباط با موفقیت برقرار شد. مدل مناسب با کلید شما شناسایی و فعال گردید.'
            );
        }

        $err_msg = isset($data['error']['message']) ? $data['error']['message'] : ('کد وضعیت سرور: ' . $code);
        return new WP_Error('api_error', $err_msg, array('status' => $code));
    }

    /**
     * تولید پیشنهادهای استراتژیک لینک‌سازی برای یک صفحه مشخص
     */
    public function generate_link_suggestions($target_id, $target_type) {
        if (!$this->is_configured()) {
            return new WP_Error('missing_api_key', 'کلید Gemini API هنوز در تنظیمات افزونه ثبت نشده است.');
        }

        $target_info = $this->get_entity_info($target_id, $target_type);
        if (!$target_info) {
            return new WP_Error('invalid_target', 'اطلاعات صفحه مقصد یافت نشد.');
        }

        $candidates = $this->get_candidate_sources($target_id, $target_type);
        if (empty($candidates)) {
            return new WP_Error('no_candidates', 'صفحه کاندیدایی برای لینک‌سازی در سایت موجود نیست.');
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
            'headers'     => array('Content-Type' => 'application/json'),
            'body'        => wp_json_encode($body),
            'timeout'     => 45,
            'data_format' => 'body'
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $error_data = json_decode($raw_body, true);
            $msg = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'خطا در ارتباط با سرور گوگل جمینای.';
            return new WP_Error('gemini_api_error', $msg);
        }

        $result = json_decode($raw_body, true);
        if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return new WP_Error('invalid_ai_response', 'ساختار پاسخ دریافتی از جمینای معتبر نبود.');
        }

        $json_text = $result['candidates'][0]['content']['parts'][0]['text'];
        $parsed_suggestions = json_decode($json_text, true);

        return $parsed_suggestions ? $parsed_suggestions : array();
    }

    private function get_entity_info($id, $type) {
        if ($type === 'post') {
            $post = get_post($id);
            if (!$post) return null;
            return array(
                'id'      => $id,
                'type'    => 'مقاله',
                'title'   => $post->post_title,
                'url'     => get_permalink($id),
                'excerpt' => wp_strip_all_tags(wp_trim_words($post->post_content, 50))
            );
        }

        $term = get_term($id, $type);
        if (!$term || is_wp_error($term)) return null;

        $type_label = ($type === 'product_cat') ? 'دسته‌بندی محصول ووکامرس' : 'دسته‌بندی وبلاگ';
        return array(
            'id'      => $id,
            'type'    => $type_label,
            'title'   => $term->name,
            'url'     => get_term_link($term),
            'excerpt' => !empty($term->description) ? wp_strip_all_tags($term->description) : 'بدون توضیحات اولیه'
        );
    }

    private function get_candidate_sources($exclude_id, $exclude_type) {
        $candidates = array();

        $posts = get_posts(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'exclude'        => ($exclude_type === 'post') ? array($exclude_id) : array()
        ));

        foreach ($posts as $p) {
            $candidates[] = array(
                'id'      => $p->ID,
                'type'    => 'post',
                'title'   => $p->post_title,
                'summary' => wp_strip_all_tags(wp_trim_words($p->post_content, 35))
            );
        }

        if (taxonomy_exists('product_cat')) {
            $terms = get_terms(array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'number'     => 15,
                'exclude'    => ($exclude_type === 'product_cat') ? array($exclude_id) : array()
            ));

            if (!is_wp_error($terms)) {
                foreach ($terms as $t) {
                    $candidates[] = array(
                        'id'      => $t->term_id,
                        'type'    => 'product_cat',
                        'title'   => $t->name,
                        'summary' => !empty($t->description) ? wp_strip_all_tags(wp_trim_words($t->description, 25)) : 'دسته‌بندی فروشگاه'
                    );
                }
            }
        }

        return $candidates;
    }

    private function build_analysis_prompt($target, $candidates) {
        $target_json     = wp_json_encode($target, JSON_UNESCAPED_UNICODE);
        $candidates_json = wp_json_encode($candidates, JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
شما یک متخصص ارشد سئو تکنیکال و معماری لینک‌سازی داخلی سایت هستید.
صفحه مقصد زیر در سایت دارای فقر لینک داخلی است یا به عنوان یک صفحه یتیم شناخته شده است:
مشخصات صفحه مقصد:
{$target_json}

لیست صفحات موجود در سایت که می‌توانند به عنوان مبدا لینک‌سازی استفاده شوند:
{$candidates_json}

دستورالعمل‌های دقیق:
۱. از بین صفحات کاندیدا، بین ۲ تا ۴ مورد از مرتبط‌ترین صفحات (اعم از مقاله یا دسته‌بندی محصول) را برای لینک‌دهی به صفحه مقصد انتخاب کنید.
۲. برای هر مورد مشخص کنید در چه بخشی از محتوا باید لینک اضافه شود.
۳. انکرتکست‌های طبیعی، غیر اسپم و منطبق بر قصد کاربر (Search Intent) پیشنهاد دهید.
۴. تعداد تکرار لینک در آن محتوا را دقیقا مشخص کنید (معمولاً ۱ بار، حداکثر ۲ بار).
۵. خروجی باید الزاماً و بدون هیچ توضیح متنی اضافه، یک آرایه JSON معتبر طبق فرمت زیر باشد:

[
  {
    "source_id": 123,
    "source_title": "عنوان صفحه مبدا",
    "source_type": "post یا product_cat",
    "recommended_anchor_texts": ["انکر اصلی", "انکر مکمل دوم"],
    "link_frequency": 1,
    "placement_guide": "در پاراگراف مربوط به معرفی ویژگی‌ها یا متن دسته قرار گیرد.",
    "reasoning": "دلیل فنی و موضوعی انتخاب این صفحه و ارتباط آن با مقصد"
  }
]
PROMPT;
    }
}