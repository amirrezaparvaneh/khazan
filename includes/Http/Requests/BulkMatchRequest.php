<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Payload of POST /bulk/match: sheet rows (capped) and the uploaded file name.
 */
final class BulkMatchRequest {

    const MAX_ROWS = 1000;

    /** @var array */
    private $input;

    public function __construct($input) {
        $this->input = is_array($input) ? $input : array();
    }

    public function rows() {
        return isset($this->input['rows']) && is_array($this->input['rows']) ? array_slice($this->input['rows'], 0, self::MAX_ROWS) : array();
    }

    public function file_name() {
        return isset($this->input['file_name']) ? sanitize_text_field($this->input['file_name']) : '';
    }
}
