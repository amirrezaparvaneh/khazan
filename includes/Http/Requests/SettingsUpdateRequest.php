<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates and sanitizes the settings payload; yields the ordered option => value map to persist.
 */
final class SettingsUpdateRequest {

    /** @var array */
    private $input;

    public function __construct($input) {
        $this->input = is_array($input) ? $input : array();
    }

    /**
     * @return array option name => sanitized value (only accepted fields, fixed order)
     */
    public function options() {
        $options = array(Settings::OPT_AI_PROVIDER => Settings::AI_PROVIDER);

        if (isset($this->input['homepage_keywords'])) {
            $options[Settings::OPT_HOMEPAGE_KEYWORDS] = sanitize_textarea_field($this->input['homepage_keywords']);
        }
        if (isset($this->input['avalai_api_key'])) {
            $key = trim(sanitize_text_field($this->input['avalai_api_key']));
            // کلید ماسک‌شده (با «...») هرگز ذخیره نمی‌شود
            if (!empty($key) && strpos($key, '...') === false) {
                $options[Settings::OPT_AVALAI_API_KEY] = $key;
            }
        }
        if (isset($this->input['ai_model'])) {
            $model = trim(sanitize_text_field($this->input['ai_model']));
            if (Settings::is_valid_model_id($model)) $options[Settings::OPT_AI_MODEL] = $model;
        }
        if (isset($this->input['ai_base_url'])) {
            $url = trim(esc_url_raw((string) $this->input['ai_base_url'], array('https')));
            // خالی = بازگشت به آدرس پیش‌فرض AvalAI
            $options[Settings::OPT_AI_BASE_URL] = $url === '' ? '' : rtrim($url, '/');
        }
        if (isset($this->input['product_post_type'])) {
            $type = sanitize_key($this->input['product_post_type']);
            if (post_type_exists($type)) $options[Settings::OPT_PRODUCT_POST_TYPE] = $type;
        }
        if (isset($this->input['product_category_taxonomy'])) {
            $taxonomy = sanitize_key($this->input['product_category_taxonomy']);
            if (taxonomy_exists($taxonomy)) $options[Settings::OPT_PRODUCT_CATEGORY_TAXONOMY] = $taxonomy;
        }
        if (isset($this->input['auto_image_enabled'])) {
            $options[Settings::OPT_AUTO_IMAGE_ENABLED] = rest_sanitize_boolean($this->input['auto_image_enabled']) ? '1' : '0';
        }
        return $options;
    }
}
