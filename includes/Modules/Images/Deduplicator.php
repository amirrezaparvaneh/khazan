<?php
/**
 * Finds identical images in the media library and removes only the ones nothing uses.
 *
 * Safety rules (the old plugin deleted images that were still in use):
 *  - identical = same content hash (computed in small batches, stored once in post meta);
 *  - "used" means referenced ANYWHERE: post content, any post meta (page builders, ACF, WooCommerce galleries), term meta
 *    (category images), options (logo, site icon, widgets, theme mods) - by attachment ID or by ANY of its file names
 *    (the original and every generated size); a false positive only keeps a file, never loses one;
 *  - one keeper per group (the most referenced, then the oldest); used duplicates are only reported;
 *  - deletion needs an explicit list of ids, re-checks usage right before deleting, copies the file into
 *    uploads/khazan-quarantine first, and runs on at most a few items per request.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Images;

if (!defined('ABSPATH')) {
    exit;
}

final class Deduplicator {

    const META_HASH = '_khazan_hash';
    const QUARANTINE_DIR = 'khazan-quarantine';

    /** Pure: every file name an attachment can be referenced by (original + generated sizes + the id). */
    public static function needles($main_file, array $metadata, $attachment_id) {
        $names = array();
        if ($main_file !== '') $names[] = wp_basename($main_file);
        if (!empty($metadata['file'])) $names[] = wp_basename($metadata['file']);
        foreach (isset($metadata['sizes']) && is_array($metadata['sizes']) ? $metadata['sizes'] : array() as $size) {
            if (!empty($size['file'])) $names[] = $size['file'];
        }
        return array('names' => array_values(array_unique(array_filter($names))), 'id' => (int) $attachment_id);
    }

    /**
     * Pure: keeper of a group. Members: {id, refs, date}. Most referenced wins, then the oldest, then the lowest id.
     */
    public static function pick_keeper(array $members) {
        usort($members, function ($a, $b) {
            if ($a['refs'] !== $b['refs']) return $a['refs'] > $b['refs'] ? -1 : 1;
            $da = strtotime($a['date']); $db = strtotime($b['date']);
            if ($da !== $db) return $da < $db ? -1 : 1;
            return $a['id'] < $b['id'] ? -1 : 1;
        });
        return $members[0]['id'];
    }

    /** Hashes a batch of attachments that have no hash yet. @return array{done:int,remaining:int} */
    public static function hash_batch($limit = 30) {
        $q = new \WP_Query(array(
            'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => (int) $limit,
            'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
            'meta_query' => array(array('key' => self::META_HASH, 'compare' => 'NOT EXISTS')),
        ));
        foreach ($q->posts as $id) {
            $file = get_attached_file($id);
            update_post_meta($id, self::META_HASH, $file && is_readable($file) ? sha1_file($file) : 'missing');
        }
        return array('done' => count($q->posts), 'remaining' => max(0, (int) $q->found_posts - count($q->posts)));
    }

    /** Duplicate groups with usage and a keeper; dry-run, changes nothing. */
    public static function report($max_groups = 100) {
        global $wpdb;
        $hashes = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> 'missing' GROUP BY meta_value HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC LIMIT %d",
            self::META_HASH, (int) $max_groups
        ));
        $groups = array();
        foreach ($hashes as $hash) {
            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", self::META_HASH, $hash)));
            $members = array();
            foreach ($ids as $id) {
                $post = get_post($id);
                if (!$post || $post->post_type !== 'attachment') continue;
                $file = get_attached_file($id);
                $members[] = array('id' => $id, 'title' => get_the_title($id), 'url' => wp_get_attachment_url($id), 'date' => $post->post_date, 'refs' => self::count_references($id), 'bytes' => $file && is_readable($file) ? filesize($file) : 0);
            }
            if (count($members) < 2) continue;
            $keeper = self::pick_keeper($members);
            foreach ($members as &$m) $m['status'] = $m['id'] === $keeper ? 'keep' : ($m['refs'] > 0 ? 'used' : 'removable');
            unset($m);
            $groups[] = array('hash' => $hash, 'keeper' => $keeper, 'members' => $members);
        }
        return $groups;
    }

    /** Number of places that reference the attachment (0 = unused). */
    public static function count_references($attachment_id) {
        global $wpdb;
        $attachment_id = (int) $attachment_id;
        $n = self::needles((string) get_attached_file($attachment_id), (array) wp_get_attachment_metadata($attachment_id), $attachment_id);
        $count = 0;
        $like = function ($name) use ($wpdb) { return '%' . $wpdb->esc_like($name) . '%'; };

        $count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value = %s AND meta_key IN ('_thumbnail_id','thumbnail_id','_product_image_gallery_id') AND post_id <> %d", (string) $attachment_id, $attachment_id));
        $count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND FIND_IN_SET(%d, meta_value)", $attachment_id));
        $count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_value = %s AND meta_key IN ('thumbnail_id','image_id')", (string) $attachment_id));
        $count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN ('site_icon','site_logo') AND option_value = %s", (string) $attachment_id));
        $count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type <> 'attachment' AND post_status NOT IN ('auto-draft') AND post_content LIKE %s", '%wp-image-' . $attachment_id . '%'));
        $count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type <> 'attachment' AND post_status NOT IN ('auto-draft') AND post_content LIKE %s", '%"id":' . $attachment_id . ',%'));

        foreach ($n['names'] as $name) {
            $count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type <> 'attachment' AND post_status NOT IN ('auto-draft') AND post_content LIKE %s", $like($name)));
            $count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata') AND meta_key NOT LIKE %s AND meta_value LIKE %s", $attachment_id, '\_khazan%', $like($name)));
            $count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_value LIKE %s", $like($name)));
            $count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_value LIKE %s", '\_transient%', $like($name)));
        }
        return $count;
    }

    /**
     * Removes the given duplicates after re-checking everything. @return array{removed:int[],skipped:array,bytes:int}
     */
    public static function remove(array $ids) {
        $removed = array(); $skipped = array(); $bytes = 0;
        foreach (array_slice(array_unique(array_map('intval', $ids)), 0, 10) as $id) {
            $hash = (string) get_post_meta($id, self::META_HASH, true);
            $post = get_post($id);
            if ($hash === '' || $hash === 'missing' || !$post || $post->post_type !== 'attachment') { $skipped[$id] = 'نامعتبر'; continue; }
            if (self::count_references($id) > 0) { $skipped[$id] = 'در حال استفاده است'; continue; }
            $twins = get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 2, 'fields' => 'ids', 'post__not_in' => array($id), 'meta_key' => self::META_HASH, 'meta_value' => $hash));
            if (!$twins) { $skipped[$id] = 'نسخه هم‌ارزی باقی نمی‌ماند'; continue; }
            $file = get_attached_file($id);
            $size = $file && is_readable($file) ? filesize($file) : 0;
            if ($file && is_readable($file) && !self::quarantine($file, $id)) { $skipped[$id] = 'ایجاد نسخه پشتیبان ناموفق بود'; continue; }
            if (wp_delete_attachment($id, true)) { $removed[] = $id; $bytes += $size; } else { $skipped[$id] = 'حذف ناموفق بود'; }
        }
        if ($removed) ImageStats::add_duplicates(count($removed), $bytes);
        return array('removed' => $removed, 'skipped' => $skipped, 'bytes' => $bytes);
    }

    private static function quarantine($file, $id) {
        $dir = trailingslashit(wp_upload_dir()['basedir']) . self::QUARANTINE_DIR;
        if (!wp_mkdir_p($dir)) return false;
        if (!file_exists($dir . '/index.php')) @file_put_contents($dir . '/index.php', "<?php // silence\n");
        if (!file_exists($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Deny from all\n");
        return @copy($file, $dir . '/' . (int) $id . '-' . wp_basename($file));
    }
}
