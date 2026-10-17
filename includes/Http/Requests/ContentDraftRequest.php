<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates POST /content/draft: English slug, required title/content, SEO fields.
 */
final class ContentDraftRequest {

    /** @var array */
    private $input;

    public function __construct($input) {
        $this->input = is_array($input) ? $input : array();
    }

    /**
     * @return array|\WP_Error array(title, content, slug, featured_image_id, seo)
     */
    public function validated() {
        $params  = $this->input;
        $title   = isset($params['title']) ? sanitize_text_field($params['title']) : '';
        $content = isset($params['content']) ? wp_kses_post($params['content']) : '';
        $slug    = isset($params['slug']) ? strtolower(trim(sanitize_text_field($params['slug']))) : '';
        if (!preg_match('/^[a-z]+(?:-[a-z0-9]+)*$/D', $slug)) {
            return new \WP_Error('invalid_english_slug', 'نامک انگلیسی مرتبط را با حروف لاتین و خط تیره وارد کنید.', array('status' => 400));
        }
        if (empty($title) || empty($content)) return new \WP_Error('missing_draft_content', 'عنوان و محتوای پیش‌نویس الزامی هستند.', array('status' => 400));

        return array(
            'title'   => $title,
            'content' => $content,
            'slug'    => $slug,
            'featured_image_id' => isset($params['featured_image_id']) ? absint($params['featured_image_id']) : 0,
            'seo'     => array(
                'title'    => isset($params['seo_title']) ? sanitize_text_field($params['seo_title']) : $title,
                'desc'     => isset($params['meta_description']) ? sanitize_textarea_field($params['meta_description']) : '',
                'focuskw'  => isset($params['focus_keyword']) ? sanitize_text_field($params['focus_keyword']) : '',
                'synonyms' => isset($params['keyword_synonyms']) ? sanitize_text_field($params['keyword_synonyms']) : '',
            ),
        );
    }
}
