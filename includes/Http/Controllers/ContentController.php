<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

use Khazan\Http\Requests\ContentDraftRequest;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * تولید و مدیریت محتوا: پیشنهاد لینک، طرح، محتوای کامل، رفع خطای سئو، اعمال روی مقصد، پیش‌نویس و آرشیو
 */
final class ContentController extends BaseController {

    public function generate_ai_suggestions($request) {
        $params = $request->get_json_params();
        $avalai = new \Khazan\Services\Ai\AiService();
        
        $item_id = isset($params['id']) ? absint($params['id']) : 0;
        $item_type = isset($params['type']) ? sanitize_text_field($params['type']) : '';
        
        $suggestions = $avalai->generate_link_suggestions($item_id, $item_type);
        if (is_wp_error($suggestions)) return $suggestions;
        
        foreach ($suggestions as &$s) {
            if (isset($s['source_type'])) {
                $s['type_label'] = $this->get_type_label($s['source_type']);
            } else {
                $s['type_label'] = 'نامشخص';
            }
        }
        
        return rest_ensure_response(array('success' => true, 'suggestions' => $suggestions));
    }

    public function generate_content_blueprint($request) {
        try {
            $params = $request->get_json_params();
            $avalai = new \Khazan\Services\Ai\AiService();
            
            $clean_params = array(
                'h1_title'         => isset($params['h1_title']) ? sanitize_text_field($params['h1_title']) : '',
                'focus_keyword'    => isset($params['focus_keyword']) ? sanitize_text_field($params['focus_keyword']) : '',
                'long_tail'        => isset($params['long_tail']) ? sanitize_text_field($params['long_tail']) : '',
                'content_type'     => isset($params['content_type']) ? sanitize_text_field($params['content_type']) : '',
                'funnel_stage'     => isset($params['funnel_stage']) ? sanitize_text_field($params['funnel_stage']) : '',
                'word_count'       => isset($params['word_count']) ? absint($params['word_count']) : 1000,
            );

            $blueprint = $avalai->generate_content_blueprint($clean_params);
            if (is_wp_error($blueprint)) {
                return $blueprint;
            }

            return rest_ensure_response(array('success' => true, 'blueprint' => $blueprint));
        } catch (\Exception $e) {
            return new \WP_Error('rest_blueprint_error', $e->getMessage());
        }
    }

    private function content_generator_params($request) {
        return (new \Khazan\Http\Requests\ContentGenerationRequest($request->get_json_params()))->validated();
    }

    public function get_content_targets($request) {
        $kind = Settings::normalize_kind($request->get_param('kind'));
        $items = (new \Khazan\Repository\ContentTargetRepository())->list_targets($kind, sanitize_text_field((string) $request->get_param('search')));
        return is_wp_error($items) ? $items : rest_ensure_response(array('success' => true, 'items' => $items));
    }

    public function get_current_content($request) {
        $kind = Settings::normalize_kind($request->get_param('content_kind'));
        $result = (new \Khazan\Repository\ContentTargetRepository())->current_content($kind, $request->get_param('target_id'));
        return is_wp_error($result) ? $result : rest_ensure_response(array_merge(array('success' => true), $result));
    }

    public function fix_seo_errors($request) {
        $params = $request->get_json_params();
        $kind = Settings::normalize_kind(isset($params['content_kind']) ? $params['content_kind'] : '');
        $errors = isset($params['errors']) ? sanitize_textarea_field(mb_substr((string) $params['errors'], 0, 3000)) : '';
        if (trim($errors) === '') return new \WP_Error('missing_seo_errors', 'متن خطاهای سئو را وارد کنید.', array('status' => 400));
        $current = (new \Khazan\Repository\ContentTargetRepository())->current_content($kind, isset($params['target_id']) ? $params['target_id'] : 0);
        if (is_wp_error($current)) return $current;
        if (trim(wp_strip_all_tags($current['content'])) === '') return new \WP_Error('empty_content', 'این صفحه محتوایی ندارد؛ ابتدا محتوا تولید کنید.', array('status' => 400));
        $result = (new \Khazan\Services\Ai\AiService())->generate_seo_fix($kind, $current, $errors);
        return is_wp_error($result) ? $result : rest_ensure_response(array_merge(array('success' => true), $result));
    }

    public function apply_content_to_target($request) {
        $params = $request->get_json_params();
        $kind = Settings::normalize_kind(isset($params['content_kind']) ? $params['content_kind'] : '');
        $result = (new \Khazan\Repository\ContentTargetRepository())->apply($kind, isset($params['target_id']) ? $params['target_id'] : 0, is_array($params) ? $params : array());
        return is_wp_error($result) ? $result : rest_ensure_response(array_merge(array('success' => true), $result));
    }

    public function generate_content_outline($request) {
        $params = $this->content_generator_params($request);
        if (is_wp_error($params)) return $params;
        if (empty($params['h1_title']) || empty($params['focus_keyword'])) return new \WP_Error('missing_content_details', 'عنوان و کلیدواژه کانونی الزامی هستند.', array('status' => 400));
        $result = (new \Khazan\Services\Ai\AiService())->generate_content_outline($params);
        return is_wp_error($result) ? $result : rest_ensure_response(array('success' => true, 'outline' => $result));
    }

    public function generate_full_content($request) {
        $params = $this->content_generator_params($request);
        if (is_wp_error($params)) return $params;
        $body = $request->get_json_params();
        $outline = isset($body['outline']) && is_array($body['outline']) ? $body['outline'] : array();
        if (empty($params['h1_title']) || empty($params['focus_keyword']) || empty($outline)) return new \WP_Error('missing_content_details', 'مشخصات و طرح تاییدشده محتوا الزامی هستند.', array('status' => 400));
        $result = (new \Khazan\Services\Ai\AiService())->generate_full_content($params, $outline);
        if (is_wp_error($result)) return $result;
        $result = (new \Khazan\Services\Image\ImageGenerationService())->embed($params, $result);

        (new \Khazan\Repository\DatabaseRepository())->insert_content_history($params, $result);

        return rest_ensure_response(array('success' => true, 'content_kind' => $params['content_kind'], 'content' => $result['content'], 'slug' => $result['slug'], 'seo_title' => $result['seo_title'], 'meta_description' => $result['meta_description'], 'keyword_synonyms' => $result['keyword_synonyms'], 'internal_link_count' => $result['internal_link_count'], 'external_link_count' => $result['external_link_count'], 'external_links' => $result['external_links'], 'image' => $result['image'], 'featured_image_id' => isset($result['image']['featured_id']) ? $result['image']['featured_id'] : 0, 'featured_image_url' => isset($result['image']['featured_url']) ? $result['image']['featured_url'] : ''));
    }

    public function get_content_history() {
        $items = (new \Khazan\Repository\DatabaseRepository())->list_content_history();
        return rest_ensure_response(array('success' => true, 'items' => $items));
    }

    public function get_content_history_item($request) {
        $item = (new \Khazan\Repository\DatabaseRepository())->find_content_history($request['id']);
        if (!$item) return new \WP_Error('content_not_found', 'محتوای درخواستی یافت نشد.', array('status' => 404));
        return rest_ensure_response(array('success' => true, 'item' => $item));
    }

    public function delete_content_history_item($request) {
        $deleted = (new \Khazan\Repository\DatabaseRepository())->delete_content_history($request['id']);
        if (!$deleted) return new \WP_Error('content_not_found', 'محتوای درخواستی یافت نشد.', array('status' => 404));
        return rest_ensure_response(array('success' => true));
    }

    public function create_content_draft($request) {
        $input = (new ContentDraftRequest($request->get_json_params()))->validated();
        if (is_wp_error($input)) return $input;

        $post_id = (new \Khazan\Repository\ContentTargetRepository())->create_blog_draft($input['title'], $input['content'], $input['slug'], $input['seo'], $input['featured_image_id']);
        if (is_wp_error($post_id)) return $post_id;

        return rest_ensure_response(array('success' => true, 'post_id' => $post_id, 'slug' => get_post_field('post_name', $post_id), 'edit_url' => get_edit_post_link($post_id, 'raw')));
    }
}
