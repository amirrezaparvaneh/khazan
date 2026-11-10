<?php
/**
 * Post-processes the raw model output of a product-category description. No network access.
 *
 * Order: extract -> sanitize -> LinkSanitizer (planned links only, verified externals only, one per URL, max 2 per
 * paragraph) -> paragraphs -> title / meta rules -> ContentAudit against SeoStandards -> wrapper.
 * Nothing is injected into the text: a text that misses a standard is reported in `audit` / `missing_links`, and the
 * generator asks the model to repair exactly those points (one pass). The result always carries the final audit so the
 * UI can show the Yoast-style table with real numbers.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Ai;

use Khazan\Core\Settings;
use Khazan\Services\Content\ContentAudit;
use Khazan\Services\Content\ContentGuard;
use Khazan\Services\Content\HtmlSanitizer;
use Khazan\Services\Links\LinkSanitizer;
use Khazan\Services\Seo\SeoMetaRules;

if (!defined('ABSPATH')) {
    exit;
}

final class CategoryContentParser {

    /**
     * @param string $raw    model output
     * @param array  $params focus_keyword, internal_links (plan), verified_sources, target {url}, brand, prev_title, prev_meta
     * @return array|\WP_Error
     */
    public static function parse($raw, array $params) {
        $raw = trim((string) $raw);
        $content = ContentParser::extract_block($raw, 'WILA_CONTENT');
        if ($content === '') {
            $clean = preg_replace('/^```(?:html)?\s*|\s*```$/i', '', $raw);
            if (preg_match('~<(?:p|h2|h3)\b~i', $clean)) $content = trim($clean);
        }
        if ($content === '') {
            return new \WP_Error('invalid_content_response', 'محتوای HTML از سرویس هوش مصنوعی دریافت نشد. لطفاً مجدداً امتحان کنید.');
        }

        $focus = trim((string) (isset($params['focus_keyword']) ? $params['focus_keyword'] : ''));
        $plan  = isset($params['internal_links']) ? (array) $params['internal_links'] : array();
        $self  = isset($params['target']['url']) ? (string) $params['target']['url'] : '';
        $brand = isset($params['brand']) ? (string) $params['brand'] : Settings::brand_name();
        $host  = strtolower((string) parse_url(isset($params['home_url']) ? $params['home_url'] : home_url('/'), PHP_URL_HOST));
        $verified = array();
        foreach (isset($params['verified_sources']) ? (array) $params['verified_sources'] : array() as $src) $verified[] = $src['url'];

        $content = self::clean($content);
        $links = LinkSanitizer::apply($content, $plan, $self, $host, $verified);
        $content = ContentGuard::enforce_paragraphs($links['html']);

        $title_raw = ContentParser::extract_block($raw, 'WILA_SEO_TITLE');
        $meta_raw  = ContentParser::extract_block($raw, 'WILA_META_DESCRIPTION');
        $title = SeoMetaRules::fit_title($title_raw !== '' ? $title_raw : (isset($params['prev_title']) ? $params['prev_title'] : ''), $focus, $brand);
        $meta  = SeoMetaRules::fit_meta($meta_raw !== '' ? $meta_raw : (isset($params['prev_meta']) ? $params['prev_meta'] : ''), $focus);

        $audit = ContentAudit::analyze($content, $focus, array('kind' => Settings::KIND_CATEGORY, 'home_host' => $host, 'title' => $title, 'meta' => $meta));
        if ($links['missing']) $audit['failures']['links_missing'] = count($links['missing']) . ' لینک برنامه‌ریزی‌شده در متن نیامده است.';
        $audit['ok'] = !$audit['failures'];

        return array(
            'content'               => ContentGuard::wrap($content),
            'featured_image_prompt' => '',
            'slug'                  => '',
            'seo_title'             => $title,
            'meta_description'      => $meta,
            'keyword_synonyms'      => '',
            'internal_link_count'   => $audit['internal_links'],
            'external_link_count'   => $audit['external_links'],
            'external_links'        => array(),
            'audit'                 => $audit,
            'missing_links'         => $links['missing'],
            'notes'                 => ContentParser::extract_block($raw, 'WILA_NOTES'),
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
