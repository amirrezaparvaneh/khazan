<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

use Khazan\Admin\SeoRepairPanel;
use Khazan\Core\Settings;
use Khazan\Services\Ai\AiService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Selective, editor-only SEO repairs (POST /wila/v1/seo-repair).
 */
final class SeoRepairController extends BaseController {

    public function permission($request) {
        $id = absint($request->get_param('id'));
        if ($request->get_param('kind') === 'term') {
            $term = get_term($id);
            return $term && !is_wp_error($term) && in_array($term->taxonomy, SeoRepairPanel::TAXONOMIES, true) && current_user_can('edit_term', $id);
        }
        $post = get_post($id);
        return $post && in_array($post->post_type, SeoRepairPanel::POST_TYPES, true) && current_user_can('edit_post', $id);
    }

    public function analyze($request) {
        $segments = $request->get_param('segments');
        $instructions = $request->get_param('instructions');
        if (!is_array($segments) || count($segments) > 300 || strlen(wp_json_encode($segments)) > 80000 || !is_string($instructions) || strlen($instructions) > 8000) {
            return new \WP_Error('invalid_repair_input', 'حجم متن بیش از حد مجاز یا ورودی نامعتبر است.', array('status' => 400));
        }
        foreach ($segments as $segment) {
            if (!is_string($segment) || $segment !== wp_strip_all_tags($segment)) return new \WP_Error('invalid_segment', 'فقط قطعه متن ساده مجاز است.', array('status' => 400));
        }
        // Bound concurrent requests and API spend per editor.
        $lock = \Khazan\Core\Settings::TRANSIENT_REPAIR_LOCK_PREFIX . get_current_user_id();
        if (get_transient($lock)) return new \WP_Error('repair_busy', 'بررسی در جریان است؛ کمی بعد تلاش کنید.', array('status' => 429));
        set_transient($lock, 1, 190);
        try {
            $result = (new AiService())->suggest_seo_repairs(array_values($segments), sanitize_textarea_field($instructions));
        } finally {
            delete_transient($lock);
        }
        return is_wp_error($result) ? $result : rest_ensure_response(array('patches' => $result));
    }
}
