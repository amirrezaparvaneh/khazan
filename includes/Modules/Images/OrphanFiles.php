<?php
/**
 * Image files that sit in the uploads folder but are NOT in the media library (made by another plugin or process).
 *
 * The media-library tools cannot see them, so this module scans the folder and reports; it never deletes by itself.
 *
 * Safety rules:
 *  - scan and analysis only read; the result is kept in one non-autoloaded option;
 *  - a file is "used" when its file name appears anywhere in posts, post meta, term meta or options (a false positive only
 *    keeps a file); files that only the theme's CSS or code point to cannot be detected, so every removal copies the file
 *    into uploads/khazan-quarantine/orphans first;
 *  - automatic removal covers only files with NO references that have an identical twin (a library image or another file
 *    that stays); a file without a twin is removed only when the owner selects it by hand;
 *  - every removal re-checks usage and the twin right before deleting and runs on a few files per request;
 *  - shrinking uses the same in-place, same-dimension rules as the library tool (ImageShrinker).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Images;

if (!defined('ABSPATH')) {
    exit;
}

final class OrphanFiles {

    const OPTION      = 'khazan_orphan_scan';
    const EXTENSIONS  = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif');
    const SKIP_DIRS   = array('khazan-quarantine', 'woocommerce_uploads', 'wc-logs', 'wpcode', 'fbrfg', 'wpseo-redirects', 'wp-rocket-config', 'cache');
    const MAX_FILES   = 30000;

    // ---------- pure helpers (unit tested) ----------

    /** Pure: every uploads-relative path that an attachment's metadata names (original, sizes and any generated formats). */
    public static function names_from_meta(array $meta, $attached_file = '') {
        $names = array();
        $add = function ($dir, $file) use (&$names) {
            if (!is_string($file) || $file === '') return;
            $names[] = ($dir !== '' && $dir !== '.' ? $dir . '/' : '') . $file;
        };
        $main = !empty($meta['file']) ? (string) $meta['file'] : (string) $attached_file;
        if ($attached_file !== '') $names[] = ltrim((string) $attached_file, '/');
        if ($main !== '') $names[] = ltrim($main, '/');
        $dir = $main !== '' ? dirname($main) : ($attached_file !== '' ? dirname($attached_file) : '');
        if (!empty($meta['original_image'])) $add($dir, $meta['original_image']);
        foreach (isset($meta['sizes']) && is_array($meta['sizes']) ? $meta['sizes'] : array() as $size) {
            if (!is_array($size)) continue;
            if (!empty($size['file'])) $add($dir, $size['file']);
            foreach (isset($size['sources']) && is_array($size['sources']) ? $size['sources'] : array() as $src) {
                if (is_array($src) && !empty($src['file'])) $add($dir, $src['file']);
            }
        }
        foreach (isset($meta['sources']) && is_array($meta['sources']) ? $meta['sources'] : array() as $src) {
            if (is_array($src) && !empty($src['file'])) $add($dir, $src['file']);
        }
        return array_values(array_unique($names));
    }

    /** Pure: a path under the uploads folder, or '' when it tries to leave it. */
    public static function safe_rel($rel) {
        $rel = str_replace('\\', '/', (string) $rel);
        $rel = ltrim($rel, '/');
        if ($rel === '' || strpos($rel, "\0") !== false) return '';
        foreach (explode('/', $rel) as $part) if ($part === '..' || $part === '.' || $part === '') return '';
        return $rel;
    }

    public static function ext($path) { return strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)); }

    public static function mime_for($path) {
        $map = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif');
        $e = self::ext($path);
        return isset($map[$e]) ? $map[$e] : '';
    }

    /**
     * Pure: status of the files that share one hash.
     * $items: list of {rel, refs, mtime}; $in_library: an identical image exists in the media library.
     * @return array<string,string> rel => keep | removable | used | unused
     */
    public static function classify(array $items, $in_library) {
        $out = array();
        if (!$items) return $out;
        $keeper = '';
        if (!$in_library && count($items) > 1) {
            usort($items, function ($a, $b) {
                if ($a['refs'] !== $b['refs']) return $a['refs'] > $b['refs'] ? -1 : 1;
                if ($a['mtime'] !== $b['mtime']) return $a['mtime'] < $b['mtime'] ? -1 : 1;
                if (strlen($a['rel']) !== strlen($b['rel'])) return strlen($a['rel']) < strlen($b['rel']) ? -1 : 1;
                return strcmp($a['rel'], $b['rel']);
            });
            $keeper = $items[0]['rel'];
        }
        $has_twin = $in_library || count($items) > 1;
        foreach ($items as $it) {
            if ($it['rel'] === $keeper) { $out[$it['rel']] = 'keep'; continue; }
            if ($it['refs'] > 0) { $out[$it['rel']] = 'used'; continue; }
            $out[$it['rel']] = $has_twin ? 'removable' : 'unused';
        }
        return $out;
    }

    /** Filesystem walk of the uploads folder: rel => {size, mtime}. No WordPress needed. */
    public static function walk($basedir, $max = self::MAX_FILES) {
        $basedir = rtrim(str_replace('\\', '/', $basedir), '/');
        $found = array();
        if (!is_dir($basedir)) return $found;
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($basedir, \FilesystemIterator::SKIP_DOTS),
            function ($cur) use ($basedir) {
                if ($cur->isDir()) {
                    if ($cur->isLink()) return false;
                    return !(str_replace('\\', '/', dirname($cur->getPathname())) === $basedir && in_array($cur->getFilename(), OrphanFiles::SKIP_DIRS, true));
                }
                return true;
            }
        ));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->isLink()) continue;
            $name = $f->getFilename();
            if (strpos($name, '.khazan-tmp.') !== false) continue;
            if (!in_array(self::ext($name), self::EXTENSIONS, true)) continue;
            $rel = ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen($basedir)), '/');
            $found[$rel] = array('size' => (int) $f->getSize(), 'mtime' => (int) $f->getMTime());
            if (count($found) >= $max) break;
        }
        ksort($found);
        return $found;
    }

    // ---------- storage ----------

    public static function data() {
        $d = get_option(self::OPTION, array());
        return is_array($d) && isset($d['files']) && is_array($d['files']) ? $d : array('scanned_at' => 0, 'files' => array(), 'library_files' => 0);
    }

    private static function save(array $d) { update_option(self::OPTION, $d, false); }

    public static function reset() { delete_option(self::OPTION); }

    // ---------- scan ----------

    /** Files the media library knows about (uploads-relative), read in chunks. */
    private static function library_files() {
        global $wpdb;
        $known = array();
        $offset = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' ORDER BY meta_id LIMIT 1000 OFFSET %d", $offset), ARRAY_A);
            foreach ($rows as $r) $known[ltrim((string) $r['meta_value'], '/')] = true;
            $offset += 1000;
        } while (count($rows) === 1000);
        $offset = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' ORDER BY meta_id LIMIT 500 OFFSET %d", $offset), ARRAY_A);
            foreach ($rows as $r) {
                $meta = maybe_unserialize($r['meta_value']);
                if (is_array($meta)) foreach (self::names_from_meta($meta) as $n) $known[$n] = true;
            }
            $offset += 500;
        } while (count($rows) === 500);
        return $known;
    }

    /** Lists the image files that are not in the media library and stores the list. @return array summary */
    public static function scan() {
        $base = wp_upload_dir()['basedir'];
        $all = self::walk($base);
        $known = self::library_files();
        $orphans = array();
        foreach ($all as $rel => $info) {
            if (!isset($known[$rel])) $orphans[$rel] = $info;
        }
        self::save(array('scanned_at' => time(), 'files' => $orphans, 'library_files' => count($known), 'on_disk' => count($all)));
        return self::summary();
    }

    // ---------- analysis ----------

    /** Number of places that name this file. 0 = nothing in the database uses it. */
    public static function count_refs($basename) {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($basename) . '%';
        $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type <> 'attachment' AND post_status NOT IN ('auto-draft') AND post_content LIKE %s", $like));
        $n += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata') AND meta_key NOT LIKE %s AND meta_value LIKE %s", '\_khazan%', $like));
        $n += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_value LIKE %s", $like));
        $n += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name <> %s AND option_value LIKE %s", '\_transient%', '\_site\_transient%', self::OPTION, $like));
        return $n;
    }

    /** Hashes of library attachments (already computed by the duplicate tool): hash => attachment id. */
    private static function library_hashes() {
        global $wpdb;
        $map = array();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_value, post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> 'missing'", Deduplicator::META_HASH), ARRAY_A);
        foreach ($rows as $r) $map[$r['meta_value']] = (int) $r['post_id'];
        return $map;
    }

    /** Hashes and counts references for a few files within a time budget. @return array{done:int,remaining:int} */
    public static function analyze_batch($seconds = 8, $limit = 60) {
        $d = self::data();
        $base = trailingslashit(wp_upload_dir()['basedir']);
        $done = 0; $start = microtime(true);
        foreach ($d['files'] as $rel => &$f) {
            if (isset($f['hash'])) continue;
            if ($done >= $limit || ($done > 0 && (microtime(true) - $start) > $seconds)) break;
            $full = $base . $rel;
            if (is_readable($full)) {
                $f['hash'] = (string) sha1_file($full);
                $f['refs'] = self::count_refs(wp_basename($rel));
            } else {
                $f['hash'] = 'missing'; $f['refs'] = 0;
            }
            $done++;
        }
        unset($f);
        if ($done) self::save($d);
        $pending = 0;
        foreach ($d['files'] as $f) if (!isset($f['hash'])) $pending++;
        return array('done' => $done, 'remaining' => $pending);
    }

    /** Status of every analysed file: rel => keep | removable | used | unused. */
    public static function statuses(array $d = null) {
        $d = $d === null ? self::data() : $d;
        $lib = self::library_hashes();
        $groups = array();
        foreach ($d['files'] as $rel => $f) {
            if (!isset($f['hash']) || $f['hash'] === 'missing') continue;
            $groups[$f['hash']][] = array('rel' => $rel, 'refs' => (int) $f['refs'], 'mtime' => (int) $f['mtime']);
        }
        $out = array();
        foreach ($groups as $hash => $items) $out += self::classify($items, isset($lib[$hash]));
        return $out;
    }

    // ---------- report ----------

    public static function summary() {
        $d = self::data();
        $st = self::statuses($d);
        $s = array('scanned_at' => (int) $d['scanned_at'], 'on_disk' => (int) ($d['on_disk'] ?? 0), 'orphans' => count($d['files']), 'orphan_bytes' => 0,
            'pending_analyze' => 0, 'pending_shrink' => 0,
            'removable' => 0, 'removable_bytes' => 0, 'unused' => 0, 'unused_bytes' => 0, 'used' => 0, 'used_bytes' => 0, 'keep' => 0);
        $min = ImageSettings::all()['shrink_min_kb'] * 1024;
        foreach ($d['files'] as $rel => $f) {
            $s['orphan_bytes'] += (int) $f['size'];
            if (!isset($f['hash'])) { $s['pending_analyze']++; continue; }
            $status = isset($st[$rel]) ? $st[$rel] : 'unused';
            if (isset($s[$status])) { $s[$status]++; if (isset($s[$status . '_bytes'])) $s[$status . '_bytes'] += (int) $f['size']; }
            if ($status !== 'removable' && !isset($f['shrink']) && (int) $f['size'] >= $min && self::mime_for($rel) !== '' && in_array(self::mime_for($rel), ImageShrinker::MIMES, true)) $s['pending_shrink']++;
        }
        return $s;
    }

    /** A page of files for the owner to look at. @return array{removable:array,unused:array} */
    public static function items($limit = 20) {
        $d = self::data();
        $st = self::statuses($d);
        $out = array('removable' => array(), 'unused' => array());
        foreach ($d['files'] as $rel => $f) {
            if (!isset($f['hash'])) continue;
            $status = isset($st[$rel]) ? $st[$rel] : 'unused';
            if ($status !== 'removable' && $status !== 'unused') continue;
            if (count($out[$status]) >= $limit) continue;
            $out[$status][] = array('rel' => $rel, 'bytes' => (int) $f['size'], 'date' => (int) $f['mtime'], 'hash' => $f['hash']);
        }
        return $out;
    }

    // ---------- removal ----------

    private static function quarantine($full, $rel) {
        $dir = trailingslashit(wp_upload_dir()['basedir']) . Deduplicator::QUARANTINE_DIR;
        if (!wp_mkdir_p($dir)) return false;
        if (!file_exists($dir . '/index.php')) @file_put_contents($dir . '/index.php', "<?php // silence\n");
        if (!file_exists($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Deny from all\n");
        $target = $dir . '/orphans/' . $rel;
        if (!wp_mkdir_p(dirname($target))) return false;
        return @copy($full, $target);
    }

    /**
     * Removes the given files after re-checking everything. $manual lets the owner remove a file that has no twin.
     * @return array{removed:string[],skipped:array,bytes:int}
     */
    public static function remove(array $rels, $manual = false) {
        $d = self::data();
        $base = trailingslashit(wp_upload_dir()['basedir']);
        $removed = array(); $skipped = array(); $bytes = 0;
        foreach (array_slice(array_unique($rels), 0, 10) as $raw) {
            $rel = self::safe_rel($raw);
            if ($rel === '' || !isset($d['files'][$rel]) || !is_file($base . $rel)) { $skipped[(string) $raw] = 'نامعتبر'; continue; }
            if (self::count_refs(wp_basename($rel)) > 0) { $skipped[$rel] = 'در حال استفاده است'; continue; }
            $hash = (string) sha1_file($base . $rel);
            $twin = false;
            foreach (self::library_hashes() as $h => $id) { if ($h === $hash) { $twin = true; break; } }
            if (!$twin) {
                foreach ($d['files'] as $other => $of) {
                    if ($other !== $rel && isset($of['hash']) && $of['hash'] === $hash && is_file($base . $other)) { $twin = true; break; }
                }
            }
            if (!$twin && !$manual) { $skipped[$rel] = 'نسخه هم‌ارزی باقی نمی‌ماند'; continue; }
            $size = (int) filesize($base . $rel);
            if (!self::quarantine($base . $rel, $rel)) { $skipped[$rel] = 'ایجاد نسخه پشتیبان ناموفق بود'; continue; }
            if (!@unlink($base . $rel)) { $skipped[$rel] = 'حذف ناموفق بود'; continue; }
            unset($d['files'][$rel]);
            $removed[] = $rel; $bytes += $size;
        }
        if ($removed) { self::save($d); ImageStats::add_duplicates(count($removed), $bytes); }
        return array('removed' => $removed, 'skipped' => $skipped, 'bytes' => $bytes);
    }

    // ---------- shrinking ----------

    /** Shrinks files in place within a time budget. Each file is marked before it is opened. */
    public static function shrink_batch($seconds = 8, $limit = 40) {
        $d = self::data();
        $st = self::statuses($d);
        $s = ImageSettings::all();
        $base = trailingslashit(wp_upload_dir()['basedir']);
        $done = 0; $handled = 0; $saved = 0; $reasons = array(); $start = microtime(true); $left = 0;
        foreach ($d['files'] as $rel => &$f) {
            if (!isset($f['hash']) || isset($f['shrink'])) continue;
            $mime = self::mime_for($rel);
            if ($mime === '' || !in_array($mime, ImageShrinker::MIMES, true)) { $f['shrink'] = 'unsupported'; continue; }
            if ((isset($st[$rel]) ? $st[$rel] : 'unused') === 'removable') continue;   // will be removed, no point in shrinking
            if ($handled >= $limit || ($handled > 0 && (microtime(true) - $start) > $seconds)) { $left++; continue; }
            $handled++;
            if (!ImageShrinker::worth_shrinking((int) $f['size'], $s['shrink_min_kb'])) { $f['shrink'] = 'small'; $reasons['small_enough'] = array('code' => 'small_enough', 'message' => 'حجم فایل از حد تعیین‌شده کمتر است.', 'count' => (isset($reasons['small_enough']) ? $reasons['small_enough']['count'] : 0) + 1); continue; }
            $f['shrink'] = 'started';
            self::save(array_merge($d, array('files' => $d['files'])));   // survives a PHP crash on this file
            $result = ImageShrinker::shrink_file($base . $rel, $mime, $s['shrink_quality'], $s['shrink_min_kb']);
            if (is_wp_error($result)) {
                $code = $result->get_error_code();
                $f['shrink'] = 'skipped:' . $code;
                if (!isset($reasons[$code])) $reasons[$code] = array('code' => $code, 'message' => $result->get_error_message(), 'count' => 0);
                $reasons[$code]['count']++;
                continue;
            }
            clearstatcache(true, $base . $rel);
            $f['size'] = (int) filesize($base . $rel);
            $f['shrink'] = (string) (int) $result;
            $f['hash'] = (string) sha1_file($base . $rel);   // content changed
            if ($result > 0) { $done++; $saved += $result; ImageStats::add_shrink($result); }
        }
        unset($f);
        self::save($d);
        return array('processed' => $handled, 'shrunk' => $done, 'saved_bytes' => $saved, 'reasons' => array_values($reasons), 'remaining' => $left);
    }
}
