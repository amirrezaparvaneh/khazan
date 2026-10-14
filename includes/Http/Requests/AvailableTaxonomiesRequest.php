<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates the post type of GET /taxonomies/{post_type}.
 */
final class AvailableTaxonomiesRequest {

    /** @var mixed */
    private $post_type;

    public function __construct($post_type) {
        $this->post_type = $post_type;
    }

    /**
     * @return string|\WP_Error Sanitized, existing post type.
     */
    public function post_type() {
        $post_type = sanitize_key($this->post_type);
        if (!post_type_exists($post_type)) {
            return new \WP_Error('invalid_post_type', 'نوع محتوا یافت نشد.', array('status' => 400));
        }
        return $post_type;
    }
}
