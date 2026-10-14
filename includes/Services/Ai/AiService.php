<?php
/**
 * AI orchestrator: coordinates AvalaiHttpClient (network), PromptBuilder (prompts),
 * ContentParser (post-processing) and AiResponseDecoder (JSON decoding).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Ai;

use Khazan\Core\Settings;
use Khazan\Repository\SiteEntityRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class AiService {

    const MODEL = Settings::AI_MODEL;

    private $provider;
    private $api_key;
    private $model;
    private $http;
    private $prompts;
    private $entities;

    public function __construct() {
        $this->provider = Settings::AI_PROVIDER;
        $this->api_key  = Settings::avalai_api_key();
        $this->model    = self::MODEL;
        $this->http     = new AvalaiHttpClient($this->api_key, $this->model);
        $this->entities = new SiteEntityRepository();
        $this->prompts  = new PromptBuilder(trim(Settings::homepage_keywords()));
    }

    public function is_configured() {
        return !empty($this->api_key);
    }

    public function get_active_model() {
        return $this->model;
    }

    public function test_connection($override_key = null) {
        try {
            return $this->http->ping(AvalaiHttpClient::resolve_key($override_key));
        } catch (\Exception $e) {
            return new \WP_Error('exception_error', 'خطای سیستمی: ' . $e->getMessage());
        }
    }

    public function suggest_seo_repairs($segments, $instructions) {
        $parsed = AiResponseDecoder::decode_json($this->send($this->prompts->seo_repairs($segments, $instructions)));
        if (is_wp_error($parsed)) return $parsed;
        return ContentParser::repair_patches($parsed, $segments);
    }

    public function generate_content_blueprint($params) {
        try {
            $candidates = $this->getAllSiteEntities();
            if (empty($candidates)) return new \WP_Error('no_candidates', 'هیچ محتوایی در سایت برای لینک‌دهی یافت نشد.');

            $json_text = $this->send($this->prompts->blueprint($params, $candidates));
            if (is_wp_error($json_text)) {
                return new \WP_Error('suggestions_unavailable', 'دریافت پیشنهاد از سرویس هوش مصنوعی ناموفق بود. لطفاً کمی بعد دوباره تلاش کنید. ' . $json_text->get_error_message());
            }
            return ContentParser::blueprint($json_text);
        } catch (\Exception $e) {
            return new \WP_Error('blueprint_exception', 'خطا در پردازش استراتژی: ' . $e->getMessage());
        }
    }

    public function generate_link_suggestions($target_id, $target_type) {
        try {
            $target_info = $this->get_entity_info($target_id, $target_type);
            if (!$target_info) return new \WP_Error('invalid_target', 'اطلاعات صفحه مقصد یافت نشد.');

            $candidates = array_values(array_filter($this->getAllSiteEntities(), function ($candidate) use ($target_id, $target_type) {
                return !is_wp_error($candidate['url']) && !((int) $candidate['id'] === (int) $target_id && $candidate['type'] === $target_type);
            }));
            if (empty($candidates)) return new \WP_Error('no_candidates', 'هیچ محتوایی برای پیشنهاد پیدا نشد.');

            $prompt = $this->prompts->link_analysis($target_info, $candidates);
            $cache_key = Settings::TRANSIENT_SUGGEST_PREFIX . md5($this->provider . $this->model . $prompt);
            $cached = get_transient($cache_key);
            if (false !== $cached) return $cached;

            $json_text = $this->send($prompt, true);
            if (is_wp_error($json_text)) return $json_text;

            $suggestions = ContentParser::link_suggestions($json_text, $candidates);
            if (is_wp_error($suggestions)) return $suggestions;

            set_transient($cache_key, $suggestions, HOUR_IN_SECONDS);
            return $suggestions;
        } catch (\Exception $e) {
            return new \WP_Error('suggestions_exception', 'خطا: ' . $e->getMessage());
        }
    }

    public function generate_content_outline($params) {
        $kind = $this->prompts->kind($params);
        $outline = AiResponseDecoder::decode_json($this->send($this->prompts->outline($params)));
        return ContentParser::normalize_outline($outline, $kind);
    }

    public function generate_full_content($params, $outline) {
        $raw = $this->send($this->prompts->full_content($params, $outline), false, false);
        if (is_wp_error($raw)) return $raw;
        return ContentParser::full_content($raw, $params);
    }

    /**
     * رفع فقط ایرادهای گزارش‌شده سئو روی متن فعلی؛ بقیه متن، ساختار و لینک‌ها باید عیناً حفظ شوند
     */
    public function generate_seo_fix($kind, $current, $errors) {
        $prompt = $this->prompts->seo_fix($kind, $current, $errors);
        $max_tokens = (int) min(8000, max(3000, mb_strlen($current['content']) / 1.5));
        $raw = $this->send($prompt, false, false, $max_tokens);
        if (is_wp_error($raw)) return $raw;
        return ContentParser::seo_fix_result($raw, $kind, $current);
    }

    /**
     * تولید یک‌مرحله‌ای محتوای محصول از ردیف شیت؛ پرامپت فشرده و فقط با ستون‌های غیرخالی
     */
    public function generate_bulk_product_content($params, $row) {
        $prompt = $this->prompts->bulk_product($params, $row);
        // Persian text costs roughly 2-3 tokens per word; the cap leaves room for HTML markup and model reasoning.
        $max_tokens = (int) min(8000, max(3000, $params['word_count'] * 4.5));
        $raw = $this->send($prompt, false, false, $max_tokens);
        if (is_wp_error($raw)) return $raw;
        return ContentParser::bulk_product_result($raw, $params, empty($row['seo_title']));
    }

    /**
     * ارسال پرامپت به‌صورت آرایه استاندارد messages
     */
    private function send($prompt, $suggestions_request = false, $json_response = true, $max_tokens = null) {
        return $this->http->chat($this->prompts->messages($prompt), $suggestions_request, $json_response, $max_tokens);
    }

    private function get_entity_info($id, $type) {
        return $this->entities->info($id, $type);
    }

    private function getAllSiteEntities() {
        return $this->entities->all();
    }
}
