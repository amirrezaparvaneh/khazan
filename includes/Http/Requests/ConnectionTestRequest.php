<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Connection-test payload: an optional unsaved API key (masked keys are ignored).
 */
final class ConnectionTestRequest {

    /** @var array */
    private $input;

    public function __construct($input) {
        $this->input = is_array($input) ? $input : array();
    }

    /**
     * @return string|null Override key, or null to use the saved key.
     */
    public function api_key() {
        $key = isset($this->input['avalai_api_key']) ? trim(sanitize_text_field($this->input['avalai_api_key'])) : null;
        if (!empty($key) && strpos($key, '...') !== false) {
            return null;
        }
        return $key;
    }
}
