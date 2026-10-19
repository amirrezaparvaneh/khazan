<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validation and sanitization for POST /wila/v1/seo-repair.
 */
final class SeoRepairRequest {

    const MAX_SEGMENTS = 300;
    const MAX_BYTES    = 80000;

    private $segments = array();
    private $instructions = '';
    private $meta = array('title' => '', 'desc' => '', 'focuskw' => '');

    /**
     * @return self|\WP_Error
     */
    public static function from($request) {
        $segments = $request->get_param('segments');
        $instructions = $request->get_param('instructions');
        $meta = $request->get_param('meta');
        if ($instructions === null) $instructions = '';
        if (!is_array($segments) || count($segments) > self::MAX_SEGMENTS || strlen(wp_json_encode($segments)) > self::MAX_BYTES || !is_string($instructions) || strlen($instructions) > 20000) {
            return new \WP_Error('invalid_repair_input', 'حجم متن بیش از حد مجاز یا ورودی نامعتبر است.', array('status' => 400));
        }
        foreach ($segments as $segment) {
            if (!is_string($segment)) return new \WP_Error('invalid_segment', 'فقط قطعه متن ساده مجاز است.', array('status' => 400));
        }
        $self = new self();
        $self->segments = array_values($segments);
        $self->instructions = sanitize_textarea_field($instructions);
        if (is_array($meta)) {
            foreach (array('title', 'desc', 'focuskw') as $key) {
                if (isset($meta[$key]) && is_string($meta[$key])) $self->meta[$key] = sanitize_text_field($meta[$key]);
            }
        }
        return $self;
    }

    public function segments() { return $this->segments; }
    public function instructions() { return $this->instructions; }
    public function meta() { return $this->meta; }
}
