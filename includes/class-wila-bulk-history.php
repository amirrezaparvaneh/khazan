<?php
/**
 * سابقه آپلودهای CSV: نام و نامک ردیف‌ها ثبت می‌شوند تا آپلودهای بعدی ردیف‌های تکراری را شناسایی کنند
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Bulk_History {

    const CHUNK_SIZE = 200;

    /**
     * جستجوی سابقه برای هر ردیف و ثبت ردیف‌های تازه؛ خروجی هم‌اندازه ردیف‌هاست: null یا ['first_seen' => ..., 'file' => ...]
     *
     * @param array $keys هر عضو: ['name_key' => '', 'slug_key' => '']
     */
    public static function sync(array $keys, $file_name) {
        $known = self::lookup($keys);
        $results = array();
        $new = array();
        $queued = array();
        foreach ($keys as $i => $key) {
            $results[$i] = isset($known[$i]) ? $known[$i] : null;
            if ($results[$i] !== null || ($key['name_key'] === '' && $key['slug_key'] === '')) continue;
            $signature = $key['name_key'] . '|' . $key['slug_key'];
            if (isset($queued[$signature])) continue;
            $queued[$signature] = true;
            $new[] = $key;
        }
        self::insert_rows($new, $file_name);
        return $results;
    }

    /**
     * یافتن ردیف‌های قبلی با نام یا نامک یکسان؛ کلیدهای جستجو به‌صورت گروهی خوانده می‌شوند
     */
    private static function lookup(array $keys) {
        global $wpdb;
        $table = WILA_DB::get_bulk_items_table_name();
        $names = array_values(array_unique(array_filter(array_column($keys, 'name_key'))));
        $slugs = array_values(array_unique(array_filter(array_column($keys, 'slug_key'))));
        $by_name = array();
        $by_slug = array();
        foreach (array_chunk($names, self::CHUNK_SIZE) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare("SELECT name_key, slug_key, file_name, first_seen FROM {$table} WHERE name_key IN ({$in})", $chunk), ARRAY_A);
            foreach ((array) $rows as $row) $by_name[$row['name_key']] = $row;
        }
        foreach (array_chunk($slugs, self::CHUNK_SIZE) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare("SELECT name_key, slug_key, file_name, first_seen FROM {$table} WHERE slug_key IN ({$in})", $chunk), ARRAY_A);
            foreach ((array) $rows as $row) $by_slug[$row['slug_key']] = $row;
        }

        $found = array();
        foreach ($keys as $i => $key) {
            $hit = null;
            if ($key['name_key'] !== '' && isset($by_name[$key['name_key']])) $hit = $by_name[$key['name_key']];
            elseif ($key['slug_key'] !== '' && isset($by_slug[$key['slug_key']])) $hit = $by_slug[$key['slug_key']];
            if ($hit) $found[$i] = array('first_seen' => $hit['first_seen'], 'file' => $hit['file_name']);
        }
        return $found;
    }

    private static function insert_rows(array $rows, $file_name) {
        global $wpdb;
        if (!$rows) return;
        $table = WILA_DB::get_bulk_items_table_name();
        $now = current_time('mysql');
        $file_name = mb_substr((string) $file_name, 0, 255);
        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $placeholders = array();
            $values = array();
            foreach ($chunk as $key) {
                $placeholders[] = '(%s, %s, %s, %s)';
                array_push($values, mb_substr($key['name_key'], 0, 191), mb_substr($key['slug_key'], 0, 191), $file_name, $now);
            }
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (name_key, slug_key, file_name, first_seen) VALUES " . implode(', ', $placeholders),
                $values
            ));
        }
    }
}
