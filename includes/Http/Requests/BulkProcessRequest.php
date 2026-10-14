<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Payload of POST /bulk/process: target product, sheet row and run options.
 */
final class BulkProcessRequest {

    /** @var array */
    private $input;

    public function __construct($input) {
        $this->input = is_array($input) ? $input : array();
    }

    public function product_id() {
        return isset($this->input['product_id']) ? $this->input['product_id'] : 0;
    }

    public function row() {
        return isset($this->input['row']) && is_array($this->input['row']) ? $this->input['row'] : array();
    }

    public function options() {
        return isset($this->input['options']) && is_array($this->input['options']) ? $this->input['options'] : array();
    }
}
