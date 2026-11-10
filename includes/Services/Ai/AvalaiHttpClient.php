<?php
/**
 * AvalAI HTTP transport: request body, headers, timeouts and error mapping only.
 * Prompt building and response post-processing live elsewhere.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Ai;


use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class AvalaiHttpClient {


    /** @var string */
    private $api_key;

    /** @var string */
    private $model;

    public function __construct($api_key, $model) {
        $this->api_key = (string) $api_key;
        $this->model   = (string) $model;
    }

    public function is_configured() {
        return $this->api_key !== '';
    }

    /**
     * Uses the override key unless it is empty or a masked value ("abc...xyz").
     */
    public static function resolve_key($override_key = null) {
        $saved_key = trim(get_option(Settings::OPT_AVALAI_API_KEY, ''));

        $key = !empty($override_key) ? trim(sanitize_text_field($override_key)) : $saved_key;
        if (strpos($key, '...') !== false || empty($key)) {
            $key = $saved_key;
        }
        return $key;
    }

    /**
     * بدنه درخواست چت؛ مدل‌های GPT-5 پارامتر max_completion_tokens می‌خواهند و دمای سفارشی را نمی‌پذیرند
     */
    public function build_request_body($prompt, $max_tokens) {
        $body = array(
            'model'    => $this->model,
            'messages' => is_array($prompt) ? $prompt : array(array('role' => 'user', 'content' => $prompt)),
        );
        if (preg_match('/^(gpt-5|o\d)/i', $this->model)) {
            $body['max_completion_tokens'] = $max_tokens;
        } else {
            $body['temperature'] = 0.2;
            $body['max_tokens']  = $max_tokens;
        }
        return $body;
    }

    /**
     * Sends a chat completion and returns the message content, or WP_Error.
     * $prompt may be a plain string or a standard messages array.
     *
     * @return string|\WP_Error
     */
    public function chat($prompt, $suggestions_request = false, $json_response = true, $max_tokens = null) {
        if (!$this->is_configured()) {
            return new \WP_Error('missing_api_key', 'کلید API ثبت نشده است.');
        }

        $tokens = $max_tokens ? $max_tokens : ($suggestions_request ? Settings::TOKENS_SUGGESTION : Settings::TOKENS_GENERATION);
        $body   = $this->build_request_body($prompt, $tokens);
        if ($json_response) {
            $body['response_format'] = array('type' => 'json_object');
        }

        $timeout  = $suggestions_request ? Settings::TIMEOUT_LONG : Settings::TIMEOUT_BULK;
        $response = $this->post($this->api_key, $body, $timeout);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        // Some models behind an OpenAI-compatible gateway reject response_format=json_object; retry once as plain text
        // (ContentParser / AiResponseDecoder already extract JSON from free text).
        if ($code === 400 && $json_response && isset($body['response_format']) && stripos((string) wp_remote_retrieve_body($response), 'response_format') !== false) {
            unset($body['response_format']);
            $response = $this->post($this->api_key, $body, $timeout);
            if (is_wp_error($response)) {
                return $response;
            }
            $code = wp_remote_retrieve_response_code($response);
            $data = json_decode(wp_remote_retrieve_body($response), true);
        }

        if ($code !== 200) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'خطای سرور AvalAI';
            // Keep 429/503 visible to the client so the bulk queue can back off and retry.
            return new \WP_Error($code === 429 ? 'ai_rate_limited' : 'ai_error', $msg, in_array($code, array(429, 503), true) ? array('status' => $code) : array());
        }
        if (!isset($data['choices'][0]['message']['content'])) {
            return new \WP_Error('invalid_response', 'پاسخ نامعتبر از AvalAI.');
        }
        return $data['choices'][0]['message']['content'];
    }

    /**
     * Image generation (OpenAI-compatible /images/generations): returns array('url' => ...) or array('b64' => ...), or WP_Error.
     *
     * @return array|\WP_Error
     */
    public function generate_image($prompt, $model, $size) {
        if (!$this->is_configured()) {
            return new \WP_Error('missing_api_key', 'کلید API ثبت نشده است.');
        }
        $response = wp_remote_post(Settings::images_endpoint(), array(
            'headers' => array('Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->api_key),
            'body'    => wp_json_encode(array('model' => $model, 'prompt' => $prompt, 'n' => 1, 'size' => $size)),
            'timeout' => Settings::TIMEOUT_IMAGE,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'خطای تولید تصویر در AvalAI (کد ' . $code . ')';
            return new \WP_Error('image_api_error', $msg);
        }
        if (!empty($data['data'][0]['url'])) {
            return array('url' => esc_url_raw($data['data'][0]['url']));
        }
        if (!empty($data['data'][0]['b64_json'])) {
            return array('b64' => $data['data'][0]['b64_json']);
        }
        return new \WP_Error('invalid_image_response', 'پاسخ نامعتبر از سرویس تولید تصویر.');
    }

    /**
     * Model catalogue of the provider (GET {base}/models, OpenAI-compatible): sorted list of model ids, or WP_Error.
     *
     * @return string[]|\WP_Error
     */
    public function models($key) {
        $key = trim((string) $key);
        if ($key === '') {
            return new \WP_Error('missing_api_key', 'کلید API ثبت نشده است.', array('status' => 400));
        }
        $response = wp_remote_get(Settings::models_endpoint(), array(
            'headers' => array('Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'),
            'timeout' => Settings::TIMEOUT_DEFAULT,
        ));
        if (is_wp_error($response)) {
            return new \WP_Error('connection_failed', 'خطا در ارتباط با سرویس: ' . $response->get_error_message(), array('status' => 502));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code === 401 || $code === 403) {
            return new \WP_Error('invalid_api_key', 'کلید API نامعتبر است.', array('status' => 400));
        }
        if ($code !== 200 || !isset($data['data']) || !is_array($data['data'])) {
            return new \WP_Error('api_error', 'دریافت فهرست مدل‌ها ناموفق بود (کد ' . $code . ').', array('status' => 502));
        }
        $ids = array();
        foreach ($data['data'] as $row) {
            if (is_array($row) && isset($row['id']) && Settings::is_valid_model_id((string) $row['id'])) $ids[] = (string) $row['id'];
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NATURAL | SORT_FLAG_CASE);
        return $ids;
    }

    /**
     * Connectivity check used by the settings screen.
     *
     * @return array|\WP_Error
     */
    public function ping($key) {
        if (empty($key)) {
            return new \WP_Error('missing_api_key', 'کلید API وارد نشده است.');
        }

        $start    = microtime(true);
        $response = $this->post($key, $this->build_request_body('Ping', 16), Settings::TIMEOUT_DEFAULT);
        $latency  = round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            return new \WP_Error('connection_failed', 'خطا در ارتباط: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200) {
            return array('success' => true, 'model' => $this->model, 'latency_ms' => $latency, 'message' => 'ارتباط با موفقیت برقرار شد!');
        }

        if ($code === 503 || $code === 429) {
            return new \WP_Error('api_error', 'سرور هوش مصنوعی در حال حاضر ترافیک بالایی دارد. لطفاً مجدداً تلاش کنید.');
        }

        $err_msg = isset($data['error']['message']) ? sanitize_text_field($data['error']['message']) : '';
        $err_msg = empty($err_msg) ? 'کد وضعیت سرور: ' . $code : 'کد وضعیت سرور: ' . $code . ' (' . $err_msg . ')';
        return new \WP_Error('api_error', $err_msg, array('status' => $code));
    }

    /**
     * Account credit (GET /user/v1/credit): raw decoded response, or a WP_Error mapped to
     * missing_api_key / invalid_api_key / api_busy / connection_failed / invalid_response / api_error.
     *
     * @return array|\WP_Error
     */
    public function balance($key) {
        $key = trim((string) $key);
        if ($key === '') {
            return new \WP_Error('missing_api_key', 'کلید API ثبت نشده است.', array('status' => 400));
        }

        $response = wp_remote_get(Settings::credit_endpoint(), array(
            'headers' => array('Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'),
            'timeout' => Settings::TIMEOUT_DEFAULT,
        ));
        if (is_wp_error($response)) {
            return new \WP_Error('connection_failed', 'خطا در ارتباط با سرویس: ' . $response->get_error_message(), array('status' => 502));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 401 || $code === 403) {
            return new \WP_Error('invalid_api_key', 'کلید API نامعتبر است یا اجازه دسترسی به اعتبار را ندارد.', array('status' => 400));
        }
        if ($code === 429 || $code === 503) {
            return new \WP_Error('api_busy', 'سرویس هوش مصنوعی اکنون شلوغ است؛ کمی بعد دوباره تلاش کنید.', array('status' => 503));
        }
        if ($code !== 200) {
            return new \WP_Error('api_error', 'استعلام اعتبار ناموفق بود (کد ' . $code . ').', array('status' => 502));
        }
        if (!is_array($data)) {
            return new \WP_Error('invalid_response', 'پاسخ استعلام اعتبار معتبر نیست.', array('status' => 502));
        }
        return $data;
    }

    private function post($key, array $body, $timeout) {
        return wp_remote_post(Settings::chat_endpoint(), array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ),
            'body'    => wp_json_encode($body),
            'timeout' => $timeout,
        ));
    }
}
