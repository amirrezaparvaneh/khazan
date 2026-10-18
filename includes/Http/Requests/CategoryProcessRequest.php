<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Payload of POST /bulk/process-category: target category and run options.
 */
final class CategoryProcessRequest {

    /** @var array */
    private $input;

    public function __construct($input) {
        $this->input = is_array($input) ? $input : array();
    }

    public function term_id() {
        return isset($this->input['term_id']) ? absint($this->input['term_id']) : 0;
    }

    /** @return array inject_seo, auto_links (booleans), run_id (key), queue_ids (term ids) */
    public function options() {
        $options = isset($this->input['options']) && is_array($this->input['options']) ? $this->input['options'] : array();
        return array(
            'inject_seo' => !empty($options['inject_seo']),
            'auto_links' => !empty($options['auto_links']),
            'run_id'     => isset($options['run_id']) ? sanitize_key((string) $options['run_id']) : '',
            'queue_ids'  => isset($options['queue_ids']) && is_array($options['queue_ids']) ? array_values(array_filter(array_map('absint', array_slice($options['queue_ids'], 0, 5000)))) : array(),
        );
    }
}
