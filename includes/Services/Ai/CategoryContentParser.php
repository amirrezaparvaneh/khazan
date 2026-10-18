<?php
/**
 * Post-processes the raw model output of a bulk product-category description. No network access.
 *
 * Order: extract -> sanitize -> planned links only (1 homepage + 2-3 categories, anchors locked, no self link) ->
 * paragraphs -> keyphrase density and distribution -> final anchor lock -> wrapper. The SEO title and meta description
 * are rebuilt by CategoryTitleGuard / CategoryMetaGuard so they always follow the transactional formula.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Ai;

use Khazan\Core\Settings;
use Khazan\Services\Content\ContentGuard;
use Khazan\Services\Content\Guard\CategoryKeyphraseDensityGuard;
use Khazan\Services\Content\Guard\CategoryLinkGuard;
use Khazan\Services\Content\Guard\LinkGuard;
use Khazan\Services\Content\HtmlSanitizer;
use Khazan\Services\Seo\CategoryMetaGuard;
use Khazan\Services\Seo\CategoryTitleGuard;

if (!defined('ABSPATH')) {
    exit;
}

final class CategoryContentParser {

    /**
     * @param string $raw    model output
     * @param array  $params content_kind, h1_title, focus_keyword, internal_links, target {url}, brand (optional)
     * @return array|\WP_Error result array in the shape the archive and the repository expect
     */
    public static function parse($raw, array $params) {
        $raw = trim((string) $raw);
        $content = ContentParser::extract_block($raw, 'WILA_CONTENT');
        if ($content === '') {
            $clean = preg_replace('/^```(?:html)?\s*|\s*```$/i', '', $raw);
            if (preg_match('~<(?:p|h2|h3)\b~i', $clean)) $content = trim($clean);
        }
        if ($content === '') {
            return new \WP_Error('invalid_content_response', 'محتوای HTML از AvalAI دریافت نشد. لطفاً مجدداً امتحان کنید.');
        }

        $focus = trim((string) (isset($params['focus_keyword']) ? $params['focus_keyword'] : ''));
        $links = isset($params['internal_links']) ? (array) $params['internal_links'] : array();
        $self  = isset($params['target']['url']) ? (string) $params['target']['url'] : '';
        $brand = isset($params['brand']) ? (string) $params['brand'] : Settings::brand_name();
        $seed  = abs(crc32($focus));

        $content = self::clean($content);
        $content = CategoryLinkGuard::enforce($content, $links, $self);
        $content = ContentGuard::enforce_paragraphs($content);
        $content = CategoryKeyphraseDensityGuard::enforce($content, $focus, $seed);
        $content = LinkGuard::lock_product_anchors($content, $links, CategoryLinkGuard::TYPES);
        $content = ContentGuard::wrap($content);

        $external = CategoryLinkGuard::external_links($content);
        return array(
            'content'               => $content,
            'featured_image_prompt' => '',
            'slug'                  => '',
            'seo_title'             => CategoryTitleGuard::fit(ContentParser::extract_block($raw, 'WILA_SEO_TITLE'), $focus, $brand, isset($params['h1_title']) ? $params['h1_title'] : ''),
            'meta_description'      => CategoryMetaGuard::fit(ContentParser::extract_block($raw, 'WILA_META_DESCRIPTION'), $focus, $brand),
            'keyword_synonyms'      => '',
            'internal_link_count'   => count(CategoryLinkGuard::internal_links($content)),
            'external_link_count'   => count($external),
            'external_links'        => $external,
        );
    }

    /** No image placeholders / prompts, no H1 and no scripts in the description. */
    private static function clean($html) {
        $html = preg_replace('~<div\b[^>]*wila-image-placeholder[^>]*>.*?</div>~is', '', (string) $html);
        $html = preg_replace('~<h1\b[^>]*>.*?</h1>~is', '', $html);
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
        return HtmlSanitizer::clean($html, Settings::KIND_CATEGORY);
    }
}
