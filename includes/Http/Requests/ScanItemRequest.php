<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates the payload of POST /scan-item.
 */
final class ScanItemRequest {

    /** @var array */
    private $input;

    public function __construct($input) {
        $this->input = is_array($input) ? $input : array();
    }

    /**
     * @return array|\WP_Error array(id, type)
     */
    public function validated() {
        $id   = isset($this->input['id']) ? absint($this->input['id']) : 0;
        $type = isset($this->input['type']) ? sanitize_text_field($this->input['type']) : 'post';
        if (!$id) return new \WP_Error('invalid_id', 'شناسه نامعتبر.', array('status' => 400));
        return array('id' => $id, 'type' => $type);
    }
}
