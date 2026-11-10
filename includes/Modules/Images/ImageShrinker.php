<?php
/**
 * Recompresses oversized images IN PLACE (same file name, same format, same dimensions), so every URL, srcset and
 * attachment keeps working and nothing in the content needs to change. This is the tool for images that are already
 * WebP (or JPEG/PNG) but far heavier than they need to be, e.g. AI-generated images saved at maximum quality.
 *
 * Safe by design:
 *  - the new file is written next to the original under a temporary name and is only used when it is a valid image
 *    with the SAME dimensions and clearly smaller (at least MIN_GAIN); otherwise the original is untouched;
 *  - the swap is an atomic rename over the original, so a crash never leaves a half-written image;
 *  - the main file and every generated size are processed; the result is recorded per attachment, so an image is
 *    never recompressed twice (repeated lossy passes would only degrade it).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Images;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageShrinker {

    const MIMES     = array('image/jpeg', 'image/png', 'image/webp');
    const META_DONE = '_khazan_shrunk2';
    const META_OLD  = '_khazan_shrunk';  // first version: images it recorded as 0/failed are looked at again, successful ones are not
    const MIN_GAIN  = 0.10;   // keep the result only when it is at least 10% smaller

    /** Pure: does a file of this size deserve a recompression pass? */
    public static function worth_shrinking($bytes, $min_kb) {
        return (int) $bytes >= max(1, (int) $min_kb) * 1024;
    }

    /** Pure: is the new file good enough to replace the original? */
    public static function acceptable($old_bytes, $new_bytes, array $old_size, array $new_size) {
        if ($new_bytes <= 0 || $old_bytes <= 0) return false;
        if ($old_size !== $new_size) return false;                       // same width and height, always
        return $new_bytes <= $old_bytes * (1 - self::MIN_GAIN);
    }

    /** Animated WebP (VP8X header with the animation flag) must never be re-encoded: the editor would flatten it. */
    /** Pure: can an image of this size be decoded and re-encoded with the memory that is left? (about 5 bytes per pixel) */
    public static function fits_memory($width, $height, $limit_bytes, $used_bytes) {
        if ((int) $limit_bytes <= 0) return true;                       // -1 = unlimited
        $need = (int) $width * (int) $height * 5;
        return $need <= max(0, (int) $limit_bytes - (int) $used_bytes) * 0.85;
    }

    private static function memory_allows($width, $height) {
        if (function_exists('wp_raise_memory_limit')) @wp_raise_memory_limit('image');
        $raw = (string) ini_get('memory_limit');
        $limit = function_exists('wp_convert_hr_to_bytes') ? (int) wp_convert_hr_to_bytes($raw) : (int) $raw;
        return self::fits_memory($width, $height, $limit, memory_get_usage(true));
    }

    public static function is_animated_webp($path) {
        $head = @file_get_contents($path, false, null, 0, 32);
        return is_string($head) && strlen($head) >= 21 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP' && substr($head, 12, 4) === 'VP8X' && (ord($head[20]) & 0x02) === 0x02;
    }

    /**
     * Recompresses one file in place.
     *
     * @return int|\WP_Error bytes saved (0 when the file was left as it is for a normal reason, reported by the code)
     */
    public static function shrink_file($path, $mime, $quality, $min_kb) {
        if (!is_readable($path) || !is_writable($path)) return new \WP_Error('not_writable', 'فایل قابل خواندن یا نوشتن نیست.');
        if (!in_array($mime, self::MIMES, true)) return new \WP_Error('unsupported_mime', 'نوع فایل پشتیبانی نمی‌شود.');
        if ($mime === 'image/webp' && self::is_animated_webp($path)) return new \WP_Error('animated', 'WebP متحرک فشرده نمی‌شود.');
        $old_bytes = (int) filesize($path);
        if (!self::worth_shrinking($old_bytes, $min_kb)) return new \WP_Error('small_enough', 'حجم فایل از حد تعیین‌شده کمتر است.');
        $old_size = @getimagesize($path);
        if (!$old_size) return new \WP_Error('not_an_image', 'فایل تصویر معتبر نیست.');
        if (!self::memory_allows($old_size[0], $old_size[1])) return new \WP_Error('too_big', 'ابعاد تصویر برای حافظه‌ی هاست زیاد است.');

        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) return $editor;
        $editor->set_quality((int) $quality);
        $info = pathinfo($path);
        $tmp = $info['dirname'] . '/' . $info['filename'] . '.khazan-tmp.' . $info['extension'];
        $saved = $editor->save($tmp, $mime);
        if (is_wp_error($saved)) { @unlink($tmp); return $saved; }
        $tmp = isset($saved['path']) ? $saved['path'] : $tmp;

        $new_bytes = file_exists($tmp) ? (int) filesize($tmp) : 0;
        $new_size = $new_bytes > 0 ? @getimagesize($tmp) : false;
        if (!$new_size || !self::acceptable($old_bytes, $new_bytes, array($old_size[0], $old_size[1]), array($new_size[0], $new_size[1]))) {
            @unlink($tmp);
            return new \WP_Error('not_smaller', 'نسخه فشرده‌شده به‌اندازه کافی کوچک‌تر نشد؛ فایل اصلی حفظ شد.');
        }
        if (!@rename($tmp, $path)) { @unlink($tmp); return new \WP_Error('replace_failed', 'جایگزینی فایل انجام نشد؛ فایل اصلی حفظ شد.'); }
        clearstatcache(true, $path);
        return $old_bytes - (int) filesize($path);
    }

    /**
     * Shrinks the main file and every generated size of an attachment.
     *
     * @return array|\WP_Error {saved, files}
     */
    public static function shrink_attachment($attachment_id) {
        $path = get_attached_file($attachment_id);
        $mime = get_post_mime_type($attachment_id);
        if (!$path || !in_array($mime, self::MIMES, true)) return new \WP_Error('not_shrinkable', 'این پیوست قابل فشرده‌سازی نیست.');
        $s = ImageSettings::all();

        // a main file under the limit means all its (smaller) generated sizes are too: skip without opening anything
        if (is_readable($path) && !self::worth_shrinking((int) filesize($path), $s['shrink_min_kb'])) {
            update_post_meta($attachment_id, self::META_DONE, 'skipped:small_enough');
            return array('saved' => 0, 'files' => 0, 'reasons' => array(array('code' => 'small_enough', 'message' => 'حجم فایل از حد تعیین‌شده کمتر است.', 'count' => 1)));
        }
        // marker: if PHP dies on this image (memory), it is not tried again and again
        update_post_meta($attachment_id, self::META_DONE, 'started');

        $files = array($path);
        $meta = wp_get_attachment_metadata($attachment_id);
        if (is_array($meta) && !empty($meta['sizes'])) {
            $dir = dirname($path);
            foreach ($meta['sizes'] as $size) {
                if (!empty($size['file'])) $files[] = $dir . '/' . $size['file'];
            }
        }
        $saved = 0; $done = 0; $reasons = array();
        foreach (array_unique($files) as $file) {
            $result = self::shrink_file($file, $mime, $s['shrink_quality'], $s['shrink_min_kb']);
            if (is_wp_error($result)) {
                $code = $result->get_error_code();
                if (!isset($reasons[$code])) $reasons[$code] = array('code' => $code, 'message' => $result->get_error_message(), 'count' => 0);
                $reasons[$code]['count']++;
                continue;
            }
            $saved += $result; $done++;
        }
        update_post_meta($attachment_id, self::META_DONE, $done ? (string) $saved : ('skipped:' . implode(',', array_keys($reasons))));
        if ($done) ImageStats::add_shrink($saved);
        return array('saved' => $saved, 'files' => $done, 'reasons' => array_values($reasons));
    }

    /** Attachments not processed yet (single source of truth for the count AND the batch). */
    public static function pending_query($limit) {
        return new \WP_Query(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => self::MIMES,
            'posts_per_page' => (int) $limit,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'meta_query'     => array(
                'relation' => 'AND',
                array('key' => self::META_DONE, 'compare' => 'NOT EXISTS'),
                array('relation' => 'OR',
                    array('key' => self::META_OLD, 'compare' => 'NOT EXISTS'),
                    array('key' => self::META_OLD, 'value' => array('0', 'failed'), 'compare' => 'IN'),
                ),
            ),
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ));
    }
}
