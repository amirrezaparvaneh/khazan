<?php
/**
 * Places the generated image in the middle of the article HTML (before the third H2, i.e. between
 * the second and third sections). Only inserts a block; existing text, links and paragraphs are untouched.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Image;

use Khazan\Core\Settings;
use Khazan\Core\View;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentImageInjector {

    public static function has_image($html) {
        return strpos((string) $html, Settings::IMAGE_CSS_CLASS) !== false;
    }

    /**
     * Byte offset where the figure goes: before the 3rd H2; with only two H2s before the 2nd; otherwise after
     * the middle paragraph. Returns -1 when the content has no usable position.
     */
    public static function insert_offset($html) {
        $html = (string) $html;
        if (preg_match_all('~<h2\b~i', $html, $m, PREG_OFFSET_CAPTURE) && count($m[0]) >= 2) {
            $heading = count($m[0]) >= 3 ? 2 : 1;
            return $m[0][$heading][1];
        }
        if (preg_match_all('~</p>~i', $html, $m, PREG_OFFSET_CAPTURE) && count($m[0]) >= 2) {
            $mid = (int) floor((count($m[0]) - 1) / 2);
            return $m[0][$mid][1] + strlen('</p>');
        }
        return -1;
    }

    /** Plain text of the content just before the insertion point (the middle section), for the image brief. */
    public static function section_text($html) {
        $offset = self::insert_offset($html);
        if ($offset < 0) return wp_strip_all_tags((string) $html);
        $before = substr((string) $html, 0, $offset);
        $start  = strripos($before, '<h2');
        return wp_strip_all_tags($start === false ? $before : substr($before, $start));
    }

    public static function figure(array $image) {
        ob_start();
        View::render('content/image-figure', array(
            'url'       => $image['url'],
            'alt'       => $image['alt'],
            'id'        => $image['id'],
            'caption'   => $image['caption'],
            'css_class' => Settings::IMAGE_CSS_CLASS,
        ));
        return trim((string) ob_get_clean());
    }

    /** @return string content with the figure inserted, or unchanged when already present / no position. */
    public static function inject($html, array $image) {
        $html = (string) $html;
        $offset = self::insert_offset($html);
        if ($offset < 0 || self::has_image($html)) return $html;
        return substr($html, 0, $offset) . "\n" . self::figure($image) . "\n" . substr($html, $offset);
    }
}
