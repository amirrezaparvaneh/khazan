<?php
/**
 * Settings of the image modules (format conversion, alt text, duplicate cleanup) stored in ONE option.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Images;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageSettings {

    const OPTION = 'khazan_images';

    public static function defaults() {
        return array(
            'convert_on_upload' => false,
            'format'            => 'webp',   // webp | avif
            'quality'           => 82,
            'delete_original'   => false,    // only for NEW uploads (nothing references them yet)
            'serve_next_gen'    => true,     // swap jpg/png for the converted sibling in rendered <img> tags
            'alt_on_upload'     => false,    // costs AI credit for every upload
            'alt_overwrite'     => false,    // never touch an alt text that already exists unless enabled
            'alt_model'         => '',       // vision-capable model; empty = the main model
        );
    }

    public static function all() {
        $saved = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        return self::sanitize(is_array($saved) ? $saved : array());
    }

    public static function get($key) {
        $all = self::all();
        return isset($all[$key]) ? $all[$key] : null;
    }

    /** Validates a raw payload against the defaults (unknown keys dropped, wrong types fall back). */
    public static function sanitize(array $input) {
        $out = self::defaults();
        foreach (array('convert_on_upload', 'delete_original', 'serve_next_gen', 'alt_on_upload', 'alt_overwrite') as $key) {
            if (array_key_exists($key, $input)) $out[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($input['format'])) $out['format'] = $input['format'] === 'avif' ? 'avif' : 'webp';
        if (isset($input['quality'])) $out['quality'] = max(30, min(100, (int) $input['quality']));
        if (isset($input['alt_model'])) {
            $model = trim((string) $input['alt_model']);
            $out['alt_model'] = preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,99}$/', $model) ? $model : '';
        }
        return $out;
    }

    public static function save(array $input) {
        update_option(self::OPTION, self::sanitize($input), false);
        return self::all();
    }
}
