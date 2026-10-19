<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

use Khazan\Admin\SeoRepairPanel;
use Khazan\Core\Settings;
use Khazan\Http\Requests\SeoRepairRequest;
use Khazan\Services\Ai\AiService;
use Khazan\Services\Seo\SeoMetaService;

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
        $input = SeoRepairRequest::from($request);
        if (is_wp_error($input)) return $input;
        $kind = $request->get_param('kind') === 'term' ? 'term' : (get_post_type(absint($request->get_param('id'))) === 'product' ? 'product' : 'post');
        $meta = $this->with_saved_meta($input->meta(), $request, $kind);
        // Bound concurrent requests and API spend per editor.
        $lock = Settings::TRANSIENT_REPAIR_LOCK_PREFIX . get_current_user_id();
        if (get_transient($lock)) return new \WP_Error('repair_busy', 'بررسی در جریان است؛ کمی بعد تلاش کنید.', array('status' => 429));
        set_transient($lock, 1, 190);
        try {
            $result = (new AiService())->suggest_seo_repairs($input->segments(), $input->instructions(), $meta, $kind);
        } finally {
            delete_transient($lock);
        }
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    /** Fills metadata the editor did not send from the saved Yoast / Rank Math values. */
    private function with_saved_meta($meta, $request, $kind) {
        $id = absint($request->get_param('id'));
        $service = new SeoMetaService();
        if ($kind === 'term') {
            $term = get_term($id);
            $saved = $term && !is_wp_error($term) ? $service->read_term_seo($term->taxonomy, $id) : array();
        } else {
            $saved = $service->read_post_seo($id);
        }
        foreach (array('title', 'desc', 'focuskw') as $key) {
            if ($meta[$key] === '' && !empty($saved[$key])) $meta[$key] = $saved[$key];
        }
        return $meta;
    }
}
