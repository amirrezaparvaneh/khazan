<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Query of GET /bulk/site-products: filter, category, search and page size.
 */
final class SiteProductsRequest {

    /** all / empty (no description) / has_content (has description); ungenerated kept for API compatibility. */
    const FILTERS = array('all', 'empty', 'has_content', 'ungenerated');
    const MAX_LIMIT = 500;

    /** @var \WP_REST_Request */
    private $request;

    public function __construct($request) {
        $this->request = $request;
    }

    public function filter() {
        $filter = sanitize_key((string) $this->request->get_param('filter'));
        if ($filter === 'no_description') $filter = 'empty';
        return in_array($filter, self::FILTERS, true) ? $filter : 'all';
    }

    public function category() {
        return absint($this->request->get_param('category'));
    }

    public function search() {
        return sanitize_text_field((string) $this->request->get_param('search'));
    }

    public function limit() {
        $limit = absint($this->request->get_param('limit'));
        return $limit ? min($limit, self::MAX_LIMIT) : 300;
    }

    /**
     * @return array filter, category, search, limit
     */
    public function to_args() {
        return array('filter' => $this->filter(), 'category' => $this->category(), 'search' => $this->search(), 'limit' => $this->limit());
    }
}
