<?php
/**
 * Counters of the image modules (one non-autoloaded option, not one option per number).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Images;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageStats {

    const OPTION = 'khazan_images_stats';

    public static function all() {
        $s = get_option(self::OPTION, array());
        return array_merge(array('converted' => 0, 'saved_bytes' => 0, 'alt_written' => 0, 'duplicates_removed' => 0, 'duplicate_bytes' => 0, 'shrunk' => 0, 'shrunk_bytes' => 0), is_array($s) ? $s : array());
    }

    private static function bump(array $deltas) {
        $s = self::all();
        foreach ($deltas as $key => $value) $s[$key] += (int) $value;
        update_option(self::OPTION, $s, false);
    }

    public static function add_conversion($saved_bytes) { self::bump(array('converted' => 1, 'saved_bytes' => $saved_bytes)); }
    public static function add_shrink($saved_bytes) { self::bump(array('shrunk' => 1, 'shrunk_bytes' => $saved_bytes)); }
    public static function add_alt($count = 1)          { self::bump(array('alt_written' => $count)); }
    public static function add_duplicates($count, $bytes) { self::bump(array('duplicates_removed' => $count, 'duplicate_bytes' => $bytes)); }
}
