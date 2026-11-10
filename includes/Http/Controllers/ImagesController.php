<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

use Khazan\Modules\Images\AltText;
use Khazan\Modules\Images\Deduplicator;
use Khazan\Modules\Images\ImageConverter;
use Khazan\Modules\Images\ImageSettings;
use Khazan\Modules\Images\ImageShrinker;
use Khazan\Modules\Images\ImageStats;
use Khazan\Modules\Images\OrphanFiles;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Image modules: next-gen conversion, AI alt text and duplicate cleanup. Every batch is small (a few items per request)
 * so a slow host or a slow AI answer never hits the PHP time limit.
 */
final class ImagesController extends BaseController {

    const CONVERT_BATCH = 3;
    const ALT_BATCH     = 2;
    const SHRINK_BATCH  = 40;
    const SHRINK_SECONDS = 8;
    const HASH_BATCH    = 30;

    public function overview() {
        return rest_ensure_response(array(
            'success'  => true,
            'settings' => ImageSettings::all(),
            'stats'    => ImageStats::all(),
            'support'  => array('webp' => ImageConverter::supports('webp'), 'avif' => ImageConverter::supports('avif')),
            'pending'  => array(
                'convert' => (int) ImageConverter::pending_query(1)->found_posts,
                'shrink'  => (int) ImageShrinker::pending_query(1)->found_posts,
                'shrink_crashed' => (int) (new \WP_Query(array('post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array(array('key' => ImageShrinker::META_DONE, 'value' => 'started')))))->found_posts,
                'alt'     => (int) AltText::missing_query(1)->found_posts,
                'hash'    => (int) (new \WP_Query(array('post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array(array('key' => Deduplicator::META_HASH, 'compare' => 'NOT EXISTS')))))->found_posts,
            ),
        ));
    }

    public function save_settings($request) {
        $params = $request->get_json_params();
        return rest_ensure_response(array('success' => true, 'settings' => ImageSettings::save(is_array($params) ? $params : array())));
    }

    public function convert_batch() {
        if (function_exists('set_time_limit')) @set_time_limit(120);
        if (!ImageConverter::supports(\Khazan\Modules\Images\ImageSettings::get('format'))) {
            return new \WP_Error('unsupported', 'سرور از این فرمت پشتیبانی نمی‌کند؛ فرمت دیگری را انتخاب کنید.', array('status' => 400));
        }
        $query = ImageConverter::pending_query(self::CONVERT_BATCH);
        $done = 0; $failed = array(); $saved = 0;
        foreach ($query->posts as $id) {
            $result = ImageConverter::convert_attachment($id);
            if (is_wp_error($result)) {
                // not_smaller is a normal outcome (marked as skipped); other errors are reported and the item is marked so the batch can finish
                if ($result->get_error_code() !== 'not_smaller') {
                    $failed[$id] = $result->get_error_message();
                    update_post_meta($id, ImageConverter::META_DONE, 'failed');
                }
                continue;
            }
            $done++; $saved += $result['saved'];
        }
        return rest_ensure_response(array('success' => true, 'processed' => count($query->posts), 'converted' => $done, 'saved_bytes' => $saved, 'failed' => $failed, 'remaining' => max(0, (int) $query->found_posts - count($query->posts))));
    }

    /** Recompresses oversized images in place; two attachments per request (each has several generated sizes). */
    public function shrink_batch() {
        if (function_exists('set_time_limit')) @set_time_limit(120);
        $query = ImageShrinker::pending_query(self::SHRINK_BATCH);
        $done = 0; $saved = 0; $reasons = array(); $handled = 0; $started = microtime(true);
        foreach ($query->posts as $id) {
            if ($handled > 0 && (microtime(true) - $started) > self::SHRINK_SECONDS) break;   // many small images are fast, heavy ones take a while
            $handled++;
            $result = ImageShrinker::shrink_attachment($id);
            if (is_wp_error($result)) {
                update_post_meta($id, ImageShrinker::META_DONE, 'failed:' . $result->get_error_code());
                $code = $result->get_error_code();
                if (!isset($reasons[$code])) $reasons[$code] = array('code' => $code, 'message' => $result->get_error_message(), 'count' => 0);
                $reasons[$code]['count']++;
                continue;
            }
            if ($result['files']) $done++;
            $saved += $result['saved'];
            foreach ($result['reasons'] as $r) {
                if (!isset($reasons[$r['code']])) $reasons[$r['code']] = array('code' => $r['code'], 'message' => $r['message'], 'count' => 0);
                $reasons[$r['code']]['count'] += $r['count'];
            }
        }
        return rest_ensure_response(array('success' => true, 'processed' => $handled, 'shrunk' => $done, 'saved_bytes' => $saved, 'reasons' => array_values($reasons), 'remaining' => max(0, (int) $query->found_posts - $handled)));
    }

    public function alt_batch() {
        if (function_exists('set_time_limit')) @set_time_limit(180);
        $query = AltText::missing_query(self::ALT_BATCH);
        $items = array(); $stop = '';
        foreach ($query->posts as $id) {
            $alt = AltText::generate_and_save($id);
            if (is_wp_error($alt)) {
                $code = $alt->get_error_code();
                if ($code === 'ai_rate_limited' || $code === 'missing_api_key' || $code === 'ai_error') { $stop = $alt->get_error_message(); break; }
                // an image the model cannot use is marked so the batch does not loop on it forever
                update_post_meta($id, AltText::META_DONE, 'skipped:' . $code);
                $items[] = array('id' => $id, 'error' => $alt->get_error_message());
                continue;
            }
            $items[] = array('id' => $id, 'alt' => $alt, 'thumb' => wp_get_attachment_image_url($id, 'thumbnail'));
        }
        return rest_ensure_response(array('success' => true, 'items' => $items, 'stopped' => $stop, 'remaining' => max(0, (int) $query->found_posts - count($items))));
    }

    public function hash_batch() {
        return rest_ensure_response(array_merge(array('success' => true), Deduplicator::hash_batch(self::HASH_BATCH)));
    }

    public function dedupe_report($request = null) {
        $offset = $request ? max(0, (int) $request->get_param('offset')) : 0;
        $out = array('success' => true, 'groups' => Deduplicator::report(5, $offset));
        if ($offset === 0 && !($request && $request->get_param('summary') === '0')) $out['summary'] = Deduplicator::summary();
        return rest_ensure_response($out);
    }

    public function orphan_scan() {
        if (function_exists('set_time_limit')) @set_time_limit(120);
        return rest_ensure_response(array('success' => true, 'summary' => OrphanFiles::scan()));
    }

    public function orphan_analyze() {
        if (function_exists('set_time_limit')) @set_time_limit(120);
        return rest_ensure_response(array_merge(array('success' => true), OrphanFiles::analyze_batch(8, 60)));
    }

    public function orphan_report() {
        return rest_ensure_response(array('success' => true, 'summary' => OrphanFiles::summary(), 'items' => OrphanFiles::items(20)));
    }

    public function orphan_remove($request) {
        if (function_exists('set_time_limit')) @set_time_limit(120);
        $params = $request->get_json_params();
        $rels = isset($params['rels']) && is_array($params['rels']) ? $params['rels'] : array();
        if (!$rels) return new \WP_Error('no_files', 'هیچ فایلی انتخاب نشده است.', array('status' => 400));
        return rest_ensure_response(array_merge(array('success' => true), OrphanFiles::remove($rels, !empty($params['manual']))));
    }

    public function orphan_shrink() {
        if (function_exists('set_time_limit')) @set_time_limit(120);
        return rest_ensure_response(array_merge(array('success' => true), OrphanFiles::shrink_batch(self::SHRINK_SECONDS, self::SHRINK_BATCH)));
    }

    public function dedupe_remove($request) {
        $params = $request->get_json_params();
        $ids = isset($params['ids']) && is_array($params['ids']) ? $params['ids'] : array();
        if (!$ids) return new \WP_Error('no_ids', 'هیچ تصویری انتخاب نشده است.', array('status' => 400));
        return rest_ensure_response(array_merge(array('success' => true), Deduplicator::remove($ids)));
    }
}
