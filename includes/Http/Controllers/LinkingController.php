<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

use Khazan\Modules\Linking\LinkSuggester;
use Khazan\Modules\Linking\OrphanAudit;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Accurate orphan audit and link suggestions from existing articles.
 */
final class LinkingController extends BaseController {

    private static function key_params($request) {
        $type = sanitize_key((string) $request->get_param('type'));
        $id = (int) $request->get_param('id');
        if ($type === '' || $id <= 0 || (!post_type_exists($type) && !taxonomy_exists($type))) return new \WP_Error('khazan_params', 'صفحه مشخص نیست.', array('status' => 400));
        return array($type, $id);
    }

    public function overview() {
        return rest_ensure_response(array(
            'success' => true,
            'audit' => OrphanAudit::stored(),
            'recent' => LinkSuggester::recent(),
            'settings' => LinkSuggester::settings(),
            'log' => array_reverse(array_slice(LinkSuggester::get_log(), -30)),
        ));
    }

    public function audit() {
        return rest_ensure_response(array('success' => true, 'audit' => OrphanAudit::run()));
    }

    public function suggest($request) {
        $k = self::key_params($request);
        if (is_wp_error($k)) return $k;
        $r = LinkSuggester::suggest($k[0], $k[1]);
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('success' => true) + $r);
    }

    public function suggest_ai($request) {
        $p = $request->get_json_params();
        $type = sanitize_key(isset($p['type']) ? (string) $p['type'] : '');
        $id = isset($p['id']) ? (int) $p['id'] : 0;
        if ($type === '' || $id <= 0) return new \WP_Error('khazan_params', 'صفحه مشخص نیست.', array('status' => 400));
        $r = LinkSuggester::suggest_ai($type, $id);
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('success' => true) + $r);
    }

    public function apply($request) {
        $p = $request->get_json_params();
        $type = sanitize_key(isset($p['type']) ? (string) $p['type'] : '');
        $id = isset($p['id']) ? (int) $p['id'] : 0;
        if ($type === '' || $id <= 0) return new \WP_Error('khazan_params', 'صفحه مشخص نیست.', array('status' => 400));
        $r = LinkSuggester::apply($type, $id, isset($p['items']) && is_array($p['items']) ? $p['items'] : array());
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('success' => true) + $r);
    }

    public function undo($request) {
        $p = $request->get_json_params();
        $r = LinkSuggester::undo(isset($p['log_id']) ? sanitize_text_field((string) $p['log_id']) : '');
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('success' => true) + $r);
    }

    public function settings($request) {
        $s = LinkSuggester::save_settings((array) $request->get_json_params());
        return rest_ensure_response(array('success' => true, 'settings' => $s));
    }
}
