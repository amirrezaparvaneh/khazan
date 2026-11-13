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
        $this->model    = Settings::ai_model();
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

    /**
     * Live account credit for the saved API key (cached for 60 s; $refresh bypasses the cache).
     * Errors are never cached.
     *
     * @return array|\WP_Error {balance, status, thresholds, estimate, cached, checked_at}
     */
    public function get_balance($refresh = false) {
        $key = AvalaiHttpClient::resolve_key(null);
        $hash = md5($key);
        if (!$refresh) {
            $cached = get_transient(Settings::TRANSIENT_AI_BALANCE);
            if (is_array($cached) && isset($cached['hash'], $cached['data']) && $cached['hash'] === $hash) {
                return array_merge($cached['data'], array('cached' => true));
            }
        }

        $raw = $this->http->balance($key);
        if (is_wp_error($raw)) {
            return $raw;
        }
        $balance = BalanceReport::from_api($raw);
        if (is_wp_error($balance)) {
            return $balance;
        }

        $data = array(
            'balance'    => $balance,
            'status'     => BalanceReport::status($balance['remaining_unit']),
            'thresholds' => array('warn_unit' => Settings::AI_BALANCE_WARN_UNIT, 'critical_unit' => Settings::AI_BALANCE_CRITICAL_UNIT),
            'estimate'   => CostEstimator::per_row($this->model),
            'checked_at' => current_time('mysql'),
        );
        set_transient(Settings::TRANSIENT_AI_BALANCE, array('hash' => $hash, 'data' => $data), Settings::AI_BALANCE_CACHE_TTL);
        return array_merge($data, array('cached' => false));
    }

    /**
     * @return array|\WP_Error {patches, meta_patches, issues}
     */
    public function suggest_seo_repairs($segments, $instructions, $meta = array(), $kind = 'post') {
        if (!$this->is_configured()) return new \WP_Error('no_api_key', 'کلید API هوش مصنوعی تنظیم نشده است. آن را در تنظیمات افزونه وارد کنید.', array('status' => 400));
        $issues = \Khazan\Services\Seo\SeoIssueScanner::scan($segments, $meta);
        $parsed = AiResponseDecoder::decode_json($this->send($this->prompts->seo_repairs($segments, $instructions, $meta, $issues)));
        if (is_wp_error($parsed)) return $parsed;
        $patches = ContentParser::repair_patches($parsed, $segments);
        if (is_wp_error($patches)) return $patches;
        return array('patches' => $patches, 'meta_patches' => ContentParser::repair_meta_patches($parsed, $meta, $kind), 'issues' => $issues);
    }

    /**
     * Rewrites the segments needed to clear the given audit failures (see PromptBuilder::fix_issues): patches only.
     *
     * @return array|\WP_Error array('patches' => [...])
     */
    public function suggest_fix_patches($segments, array $failures, $meta = array()) {
        if (!$this->is_configured()) return new \WP_Error('no_api_key', 'کلید API هوش مصنوعی تنظیم نشده است. آن را در تنظیمات افزونه وارد کنید.', array('status' => 400));
        $parsed = AiResponseDecoder::decode_json($this->send($this->prompts->fix_issues($segments, $failures, $meta)));
        if (is_wp_error($parsed)) return $parsed;
        $patches = ContentParser::repair_patches($parsed, $segments, 30);
        if (is_wp_error($patches)) return $patches;
        return array('patches' => $patches);
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
        $result = ContentParser::full_content($raw, $params);
        return is_wp_error($result) ? $result : $this->repair_once($params, $result);
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
        $result = ContentParser::bulk_product_result($raw, $params, empty($row['seo_title']));
        return is_wp_error($result) ? $result : $this->repair_once($params, $result);
    }

    /**
     * One repair pass for a product / article result: the audit failures and forgotten planned links go back to the model;
     * the repaired text is used only when it has strictly fewer failures. Title, meta and synonyms stay as generated.
     */
    private function repair_once($params, array $result) {
        $result['repaired'] = false;
        if (empty($result['audit']) || $result['audit']['ok']) return $result;
        $max_tokens = (int) min(8000, max(3000, $params['word_count'] * 4.5));
        $raw = $this->send($this->prompts->content_repair($params, \Khazan\Services\Content\Guard\WrapperGuard::unwrap($result['content']), $result['audit']['failures'], $result['missing_links']), false, false, $max_tokens);
        if (is_wp_error($raw)) return $result;
        $fixed = ContentParser::parse_full_content_payload($raw, $params);
        if (is_wp_error($fixed) || count($fixed['audit']['failures']) >= count($result['audit']['failures'])) return $result;
        foreach (array('content', 'internal_link_count', 'external_link_count', 'external_links', 'audit', 'missing_links') as $key) $result[$key] = $fixed[$key];
        $result['notes'] = $fixed['notes'] !== '' ? $fixed['notes'] : $result['notes'];
        $result['repaired'] = true;
        return $result;
    }

    /**
     * تولید یک‌مرحله‌ای توضیحات دسته‌بندی محصول (خریدمحور) با لینک‌های عنکبوتی برنامه‌ریزی‌شده
     */
    public function generate_bulk_category_content($params) {
        $prompt = $this->prompts->bulk_category($params);
        // ~2.5-3 tokens per Persian word plus HTML markup; the long category text needs a larger ceiling than the old 8000
        $max_tokens = (int) min(12000, max(4000, $params['word_count'] * 5));
        $raw = $this->send($prompt, false, false, $max_tokens);
        if (is_wp_error($raw)) return $raw;
        return CategoryContentParser::parse($raw, $params);
    }

    /**
     * Single repair pass for a category text: the audit failures and the forgotten links are sent back to the model.
     * Returns the parsed result (same shape as generate_bulk_category_content) or WP_Error.
     */
    public function repair_category_content($params, $content, array $failures, array $missing_links) {
        $max_tokens = (int) min(12000, max(4000, $params['word_count'] * 5));
        $raw = $this->send($this->prompts->category_repair($params, $content, $failures, $missing_links), false, false, $max_tokens);
        if (is_wp_error($raw)) return $raw;
        return CategoryContentParser::parse($raw, $params);
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
