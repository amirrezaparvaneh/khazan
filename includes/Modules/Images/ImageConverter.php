<?php
/**
 * JPEG/PNG -> WebP/AVIF conversion with the WordPress image editor (GD or Imagick, whichever supports the target).
 *
 * Safe by design:
 *  - a converted file that is NOT smaller than the original is discarded (the original stays);
 *  - originals of EXISTING media are never deleted (their URLs are used in content); the option only applies to a fresh
 *    upload, which nothing references yet;
 *  - the target name never overwrites another file;
 *  - rendering swaps the URL to the sibling only when that file really exists (checked once per URL per request).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Images;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageConverter {

    const SOURCE_MIMES = array('image/jpeg', 'image/png');
    const META_DONE    = '_khazan_converted';

    public static function register() {
        add_filter('wp_handle_upload', array(__CLASS__, 'on_upload'), 10, 2);
        add_filter('wp_content_img_tag', array(__CLASS__, 'swap_img_tag'), 20, 3);
    }

    public static function mime_for($format) {
        return $format === 'avif' ? 'image/avif' : 'image/webp';
    }

    /** Can this server write the target format? (asks the editor WordPress would really use) */
    public static function supports($format) {
        return function_exists('wp_image_editor_supports') && wp_image_editor_supports(array('mime_type' => self::mime_for($format)));
    }

    /** Pure: target file name next to the source, never an existing one. */
    public static function target_path($source, $format, $exists = null) {
        $exists = $exists ?: 'file_exists';
        $info = pathinfo($source);
        $ext = $format === 'avif' ? 'avif' : 'webp';
        $path = $info['dirname'] . '/' . $info['filename'] . '.' . $ext;
        for ($i = 1; $exists($path) && $i < 100; $i++) $path = $info['dirname'] . '/' . $info['filename'] . '-' . $i . '.' . $ext;
        return $path;
    }

    /** @return string|\WP_Error path of the converted file (smaller than the source) */
    public static function convert($source, $format, $quality) {
        if (!is_readable($source)) return new \WP_Error('missing_source', 'فایل مبدا یافت نشد.');
        if (!self::supports($format)) return new \WP_Error('unsupported', 'سرور از فرمت ' . strtoupper($format) . ' پشتیبانی نمی‌کند.');
        $editor = wp_get_image_editor($source);
        if (is_wp_error($editor)) return $editor;
        $editor->set_quality((int) $quality);
        $target = self::target_path($source, $format);
        $saved = $editor->save($target, self::mime_for($format));
        if (is_wp_error($saved)) return $saved;
        if (!file_exists($saved['path'])) return new \WP_Error('save_failed', 'ذخیره فایل تبدیل‌شده ناموفق بود.');
        if (filesize($saved['path']) >= filesize($source)) {
            @unlink($saved['path']);
            return new \WP_Error('not_smaller', 'نسخه تبدیل‌شده کوچک‌تر نشد؛ فایل اصلی حفظ شد.');
        }
        return $saved['path'];
    }

    /** Upload hook: convert fresh JPEG/PNG uploads when enabled. */
    public static function on_upload($upload, $context) {
        $s = ImageSettings::all();
        if (!$s['convert_on_upload'] || $context !== 'upload' || !empty($upload['error']) || !in_array($upload['type'], self::SOURCE_MIMES, true)) return $upload;
        $converted = self::convert($upload['file'], $s['format'], $s['quality']);
        if (is_wp_error($converted)) return $upload;
        $original = $upload['file'];
        $saved = max(0, filesize($original) - filesize($converted));
        if ($s['delete_original']) @unlink($original);
        $upload['url']  = str_replace(wp_basename($original), wp_basename($converted), $upload['url']);
        $upload['file'] = $converted;
        $upload['type'] = self::mime_for($s['format']);
        ImageStats::add_conversion($saved);
        return $upload;
    }

    /**
     * Converts the main file of an existing attachment. The attachment keeps pointing at its original file and sizes
     * (nothing breaks); the converted sibling is recorded and served by swap_img_tag.
     *
     * @return array|\WP_Error {file, saved}
     */
    public static function convert_attachment($attachment_id) {
        $path = get_attached_file($attachment_id);
        $mime = get_post_mime_type($attachment_id);
        if (!$path || !in_array($mime, self::SOURCE_MIMES, true)) return new \WP_Error('not_convertible', 'این پیوست قابل تبدیل نیست.');
        $s = ImageSettings::all();
        $converted = self::convert($path, $s['format'], $s['quality']);
        if (is_wp_error($converted)) {
            if ($converted->get_error_code() === 'not_smaller') update_post_meta($attachment_id, self::META_DONE, 'skipped');
            return $converted;
        }
        $saved = max(0, filesize($path) - filesize($converted));
        update_post_meta($attachment_id, self::META_DONE, wp_basename($converted));
        ImageStats::add_conversion($saved);
        return array('file' => $converted, 'saved' => $saved);
    }

    /** Attachments still to convert (single source of truth for the count AND the batch). */
    public static function pending_query($limit) {
        return new \WP_Query(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => self::SOURCE_MIMES,
            'posts_per_page' => (int) $limit,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'meta_query'     => array(array('key' => self::META_DONE, 'compare' => 'NOT EXISTS')),
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ));
    }

    /** Rendered <img>: use the converted sibling for src and srcset when the file exists. */
    public static function swap_img_tag($html, $context = '', $attachment_id = 0) {
        $s = ImageSettings::all();
        if (!$s['serve_next_gen'] || strpos($html, '<img') === false) return $html;
        $ext = $s['format'] === 'avif' ? 'avif' : 'webp';
        $uploads = wp_get_upload_dir();
        $swap = function ($url) use ($uploads, $ext) {
            return self::sibling_url($url, $uploads['baseurl'], $uploads['basedir'], $ext);
        };
        $html = preg_replace_callback('~\b(src)=(["\'])([^"\']+)\2~i', function ($m) use ($swap) { return $m[1] . '=' . $m[2] . $swap($m[3]) . $m[2]; }, $html);
        return preg_replace_callback('~\b(srcset)=(["\'])([^"\']+)\2~i', function ($m) use ($swap) {
            $parts = array_map(function ($candidate) use ($swap) {
                $bits = preg_split('/\s+/', trim($candidate), 2);
                return $swap($bits[0]) . (isset($bits[1]) ? ' ' . $bits[1] : '');
            }, explode(',', $m[3]));
            return $m[1] . '=' . $m[2] . implode(', ', $parts) . $m[2];
        }, $html);
    }

    /** Converted sibling URL when the file exists on disk, otherwise the URL unchanged (memoised per request). */
    public static function sibling_url($url, $base_url, $base_dir, $ext) {
        static $memo = array();
        $key = $ext . '|' . $url;
        if (isset($memo[$key])) return $memo[$key];
        $memo[$key] = $url;
        if (!preg_match('/\.(jpe?g|png)$/i', $url) || strpos($url, $base_url) !== 0) return $url;
        $relative = urldecode(substr($url, strlen($base_url)));
        if (strpos($relative, '..') !== false) return $url;
        $sibling = preg_replace('/\.(jpe?g|png)$/i', '.' . $ext, $relative);
        if (file_exists($base_dir . $sibling)) $memo[$key] = preg_replace('/\.(jpe?g|png)$/i', '.' . $ext, $url);
        return $memo[$key];
    }
}
