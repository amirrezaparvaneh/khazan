<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Query of GET /items and GET /item-details.
 */
final class ItemsReportRequest {

    /** @var \WP_REST_Request */
    private $request;

    public function __construct($request) {
        $this->request = $request;
    }

    public function type_filter() {
        return sanitize_text_field($this->request->get_param('type'));
    }

    public function item_id() {
        return absint($this->request->get_param('id'));
    }

    public function item_type() {
        return sanitize_text_field($this->request->get_param('type'));
    }
}
