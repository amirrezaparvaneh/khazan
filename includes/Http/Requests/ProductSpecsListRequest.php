<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Query of GET /product-specs: search and pagination for the quick-specs admin table.
 */
final class ProductSpecsListRequest {

    const MAX_PER_PAGE = 100;

    /** @var \WP_REST_Request */
    private $request;

    public function __construct($request) {
        $this->request = $request;
    }

    public function search() {
        return sanitize_text_field((string) $this->request->get_param('search'));
    }

    public function page() {
        $page = absint($this->request->get_param('page'));
        return $page ?: 1;
    }

    public function per_page() {
        $per_page = absint($this->request->get_param('per_page'));
        return $per_page ? min($per_page, self::MAX_PER_PAGE) : 20;
    }

    /**
     * @return array search, page, per_page
     */
    public function to_args() {
        return array('search' => $this->search(), 'page' => $this->page(), 'per_page' => $this->per_page());
    }
}
