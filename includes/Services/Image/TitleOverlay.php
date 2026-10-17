<?php
/**
 * Burns the article title onto a 16:9 base image with GD: bottom-third dark gradient for contrast, Vazirmatn text,
 * right-aligned with at least 40 px padding, wrapped to two lines. Persian shaping/ordering is done by PersianShaper
 * because GD does not do it. Every failure (no GD / FreeType, missing font, unreadable image) returns null, so the
 * caller keeps the raw, text-free image.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Image;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

class TitleOverlay {

    const MIN_PADDING     = 40;
    const GRADIENT_START  = 2 / 3;
    const GRADIENT_MAX    = 0.5;  // opacity at the very bottom
    const JPEG_QUALITY    = 90;

    public static function available() {
        return function_exists('imagecreatefromstring') && function_exists('imagettftext') && function_exists('imagettfbbox') && is_readable(self::font_path());
    }

    public static function font_path() {
        return WILA_PLUGIN_DIR . Settings::IMAGE_FONT;
    }

    /**
     * @return string|null path of a new JPEG with the title, or null when the raw image should be kept
     */
    public function apply($path, $title) {
        if (trim((string) $title) === '' || !self::available() || !is_readable($path)) return null;
        $image = null;
        try {
            $image = $this->load($path);
            if (!$image) return null;
            $image = $this->crop_16_9($image);
            $this->gradient($image);
            if (!$this->draw_title($image, (string) $title)) { imagedestroy($image); return null; }

            $out = $path . '-title.jpg';
            $ok = imagejpeg($image, $out, self::JPEG_QUALITY);
            imagedestroy($image);
            return $ok && filesize($out) > 0 ? $out : null;
        } catch (\Throwable $e) {
            if ($image) @imagedestroy($image);
            return null;
        }
    }

    private function load($path) {
        $bytes = file_get_contents($path);
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        if (!$image) return null;
        if (!imageistruecolor($image)) imagepalettetotruecolor($image);
        return $image;
    }

    /** The API should return 16:9; if not, centre-crop so the final featured image never ends up square. */
    private function crop_16_9($image) {
        $w = imagesx($image);
        $h = imagesy($image);
        $target_h = (int) round($w * 9 / 16);
        if (abs($target_h - $h) <= 2) return $image;
        if ($target_h < $h) {
            $rect = array('x' => 0, 'y' => (int) floor(($h - $target_h) / 2), 'width' => $w, 'height' => $target_h);
        } else {
            $target_w = (int) round($h * 16 / 9);
            $rect = array('x' => (int) floor(($w - $target_w) / 2), 'y' => 0, 'width' => $target_w, 'height' => $h);
        }
        $cropped = imagecrop($image, $rect);
        if (!$cropped) return $image;
        imagedestroy($image);
        return $cropped;
    }

    /** Transparent at two thirds of the height, 50% black at the bottom edge. */
    private function gradient($image) {
        $w = imagesx($image);
        $h = imagesy($image);
        $start = (int) floor($h * self::GRADIENT_START);
        imagealphablending($image, true);
        for ($y = $start; $y < $h; $y++) {
            $t = ($y - $start) / max(1, $h - $start - 1);
            $opacity = self::GRADIENT_MAX * $t * $t * (3 - 2 * $t); // smoothstep: no visible band edge
            $alpha = (int) round(127 - 127 * $opacity);
            imagefilledrectangle($image, 0, $y, $w, $y, imagecolorallocatealpha($image, 0, 0, 0, $alpha));
        }
    }

    private function draw_title($image, $title) {
        $w = imagesx($image);
        $h = imagesy($image);
        $padding = max(self::MIN_PADDING, (int) round($w * 0.04));
        $font = self::font_path();
        $measure = function ($line, $size) use ($font) {
            $box = imagettfbbox($size, 0, $font, PersianShaper::visual($line));
            return $box ? abs($box[2] - $box[0]) : PHP_INT_MAX;
        };
        $sizes = range((int) round($h * 0.075), (int) round($h * 0.045), -2);
        $layout = TitleLayout::fit($title, $w - 2 * $padding, $sizes, $measure, 2);

        $size = $layout['size'];
        $line_height = (int) round($size * 1.55);
        $white  = imagecolorallocate($image, 255, 255, 255);
        $shadow = imagecolorallocatealpha($image, 0, 0, 0, 70);
        $count = count($layout['lines']);
        $baseline = $h - $padding - (int) round($size * 0.25); // leaves room for descenders inside the bottom padding

        foreach ($layout['lines'] as $i => $line) {
            $visual = PersianShaper::visual($line);
            $box = imagettfbbox($size, 0, $font, $visual);
            if (!$box) return false;
            $x = $w - $padding - abs($box[2] - $box[0]) - $box[0]; // right-aligned
            $y = $baseline - ($count - 1 - $i) * $line_height;
            imagettftext($image, $size, 0, $x + 2, $y + 2, $shadow, $font, $visual);
            if (imagettftext($image, $size, 0, $x, $y, $white, $font, $visual) === false) return false;
        }
        return true;
    }
}
