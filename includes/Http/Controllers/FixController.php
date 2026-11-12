<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

use Khazan\Modules\Fix\ContentFixService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Review & repair of stored content (/fix/*).
 */
final class FixController extends BaseController {

    private static function param($request, $key) {
        $p = $request->get_json_params();
        $v = is_array($p) && isset($p[$key]) ? $p[$key] : $request->get_param($key);
        if (is_bool($v)) return $v ? '1' : '0';
        return is_scalar($v) ? (string) $v : '';
    }

    private static function kind($request) {
        return sanitize_key((string) self::param($request, 'kind'));
    }

    private static function flag($request, $name) {
        $v = self::param($request, $name);
        return $v === '1' || $v === 'true';
    }

    private static function out($r) {
        return is_wp_error($r) ? $r : rest_ensure_response(array('success' => true) + $r);
    }

    public function items($request) {
        return self::out((new ContentFixService())->items(self::kind($request), (int) self::param($request, 'page'), sanitize_text_field((string) self::param($request, 'search'))));
    }

    public function ids($request) {
        return rest_ensure_response(array('success' => true, 'ids' => (new ContentFixService())->all_ids(self::kind($request))));
    }

    public function preview($request) {
        $instructions = sanitize_textarea_field(mb_substr((string) self::param($request, 'instructions'), 0, 3000));
        return self::out((new ContentFixService())->preview(self::kind($request), (int) self::param($request, 'id'), self::flag($request, 'ai'), $instructions));
    }

    public function apply($request) {
        return self::out((new ContentFixService())->apply((string) self::param($request, 'draft_id')));
    }

    public function run($request) {
        return self::out((new ContentFixService())->run(self::kind($request), (int) self::param($request, 'id'), self::flag($request, 'ai')));
    }

    public function restore($request) {
        return self::out((new ContentFixService())->restore(self::kind($request), (int) self::param($request, 'id')));
    }
}
