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
     * Byte offset of the insertion point in the middle third of the text: the H2 (never the first) closest to
     * the middle; without enough H2s, the paragraph end closest to the middle. -1 when there is no usable position.
     */
    public static function insert_offset($html, $before_h2 = 0) {
        $html = (string) $html;
        // an explicit position («before the Nth H2», e.g. product categories) wins when the text has that many H2s
        if ($before_h2 > 0 && preg_match_all('~<h2\b~i', $html, $nth, PREG_OFFSET_CAPTURE) && count($nth[0]) >= $before_h2) {
            return (int) $nth[0][$before_h2 - 1][1];
        }
        $mid  = strlen($html) / 2;
        if (preg_match_all('~<h2\b~i', $html, $m, PREG_OFFSET_CAPTURE) && count($m[0]) >= 2) {
            return self::closest(array_slice(array_column($m[0], 1), 1), $mid);
        }
        if (preg_match_all('~</p>~i', $html, $m, PREG_OFFSET_CAPTURE) && count($m[0]) >= 2) {
            return self::closest(array_map(function ($offset) { return $offset + strlen('</p>'); }, array_column($m[0], 1)), $mid);
        }
        return -1;
    }

    private static function closest(array $offsets, $target) {
        $best = $offsets[0];
        foreach ($offsets as $offset) {
            if (abs($offset - $target) < abs($best - $target)) $best = $offset;
        }
        return $best;
    }

    /** Plain text from the start of the content (title area / intro), for the featured image brief. */
    public static function intro_text($html) {
        return mb_substr(wp_strip_all_tags((string) $html), 0, 600);
    }

    /** Plain text of the content just before the insertion point (the middle section), for the image brief. */
    public static function section_text($html, $before_h2 = 0) {
        $offset = self::insert_offset($html, $before_h2);
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
    public static function inject($html, array $image, $before_h2 = 0) {
        $html = (string) $html;
        $offset = self::insert_offset($html, $before_h2);
        if ($offset < 0 || self::has_image($html)) return $html;
        return substr($html, 0, $offset) . "\n" . self::figure($image) . "\n" . substr($html, $offset);
    }
}
