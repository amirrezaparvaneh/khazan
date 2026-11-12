<?php
/**
 * Brings the product features of a Google Sheet (read with the service account that is already connected
 * for Search Console) or of a CSV file into the products, and fills the gaps from similar products.
 * Nothing is written until a previewed plan is applied; the previous values are kept for undo.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Products;

use Khazan\Core\Settings;
use Khazan\Modules\Search\GoogleServiceAuth;
use Khazan\Modules\Search\SearchConsole;
use Khazan\Repository\ProductSpecsRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class SpecSheetService {

    const SHEET = 'khazan_specsheet_';
    const PLAN = 'khazan_specplan_';
    const TOKEN = 'khazan_sheets_token';
    const MAX_ROWS = 5000;

    // ---- pure ----

    /** @return array id, gid (string|null) */
    public static function parse_link($url) {
        $url = trim((string) $url);
        if (!preg_match('#docs\.google\.com/spreadsheets/d/([A-Za-z0-9_-]+)#', $url, $m)) return null;
        $gid = preg_match('/[#&?]gid=(\d+)/', $url, $g) ? $g[1] : null;
        return array('id' => $m[1], 'gid' => $gid);
    }

    // ---- sheet access ----

    private static function error($code, $message, $status = 502) {
        return new \WP_Error($code, $message, array('status' => $status));
    }

    private static function token() {
        $cached = get_transient(self::TOKEN);
        if (is_string($cached) && $cached !== '') return $cached;
        $key = GoogleServiceAuth::parse_key(get_option(SearchConsole::OPT_KEY, ''));
        if (!$key) return self::error('khazan_sheet_nokey', 'برای خواندن گوگل‌شیت باید اول در تب سرچ کنسول، کلید سرویس‌اکانت را وصل کنید؛ یا فایل CSV شیت را بارگذاری کنید.', 400);
        $jwt = GoogleServiceAuth::jwt($key, GoogleServiceAuth::SCOPE_SHEETS, time());
        if (!$jwt) return self::error('khazan_sheet_badkey', 'کلید خصوصی خوانده نشد.');
        $res = wp_remote_post($key['token_uri'], array('timeout' => 20, 'body' => array('grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt)));
        if (is_wp_error($res)) return self::error('khazan_sheet_net', 'اتصال به گوگل برقرار نشد. اینترنت یا فایروال سرور را بررسی کنید و دوباره تلاش کنید.');
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (empty($body['access_token'])) return self::error('khazan_sheet_auth', 'گوگل دسترسی نداد؛ فایل کلید سرویس‌اکانت را بررسی و دوباره ذخیره کنید.');
        set_transient(self::TOKEN, $body['access_token'], max(60, (int) (isset($body['expires_in']) ? $body['expires_in'] : 3600) - 120));
        return $body['access_token'];
    }

    private static function get_json($url) {
        $token = self::token();
        if (is_wp_error($token)) return $token;
        $res = wp_remote_get($url, array('timeout' => 40, 'headers' => array('Authorization' => 'Bearer ' . $token)));
        if (is_wp_error($res)) return self::error('khazan_sheet_net', 'اتصال به گوگل برقرار نشد. اینترنت یا فایروال سرور را بررسی کنید و دوباره تلاش کنید.');
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 200 && $code < 300 && is_array($body)) return $body;
        $email = SearchConsole::email();
        if ($code === 403 || $code === 404) {
            $api_off = is_array($body) && isset($body['error']['message']) && stripos((string) $body['error']['message'], 'has not been used') !== false;
            if ($api_off) return self::error('khazan_sheet_api', 'در Google Cloud باید «Google Sheets API» را هم فعال کنید (مثل Search Console API).', 403);
            return self::error('khazan_sheet_access', 'به این شیت دسترسی نیست. شیت را با ایمیل ' . $email . ' به‌صورت Viewer به اشتراک بگذارید.', 403);
        }
        return self::error('khazan_sheet_http', 'گوگل درخواست را نپذیرفت (کد ' . $code . '). ' . ($code === 429 ? 'سقف درخواست پر شده؛ چند دقیقه بعد دوباره تلاش کنید.' : ($code >= 500 ? 'سرویس گوگل اکنون پاسخ نمی‌دهد؛ کمی بعد دوباره تلاش کنید.' : 'نشانی یا شناسه شیت را بررسی کنید.')));
    }

    /** Rows of the sheet (or of the tab named by gid in the link). */
    public static function fetch_sheet($link) {
        $ref = self::parse_link($link);
        if (!$ref) return self::error('khazan_sheet_link', 'این آدرس، لینک یک گوگل‌شیت نیست.', 400);
        $meta = self::get_json('https://sheets.googleapis.com/v4/spreadsheets/' . $ref['id'] . '?fields=sheets.properties');
        if (is_wp_error($meta)) return $meta;
        $title = '';
        foreach (isset($meta['sheets']) ? $meta['sheets'] : array() as $s) {
            $pr = isset($s['properties']) ? $s['properties'] : array();
            if ($title === '') $title = isset($pr['title']) ? $pr['title'] : '';
            if ($ref['gid'] !== null && isset($pr['sheetId']) && (string) $pr['sheetId'] === $ref['gid']) { $title = $pr['title']; break; }
        }
        if ($title === '') return self::error('khazan_sheet_empty', 'برگه‌ای در این شیت پیدا نشد.');
        $vals = self::get_json('https://sheets.googleapis.com/v4/spreadsheets/' . $ref['id'] . '/values/' . rawurlencode("'" . str_replace("'", "''", $title) . "'") . '?majorDimension=ROWS');
        if (is_wp_error($vals)) return $vals;
        $rows = array();
        foreach (isset($vals['values']) && is_array($vals['values']) ? $vals['values'] : array() as $r) {
            $r = array_map(function ($c) { return trim((string) $c); }, (array) $r);
            if (implode('', $r) !== '') $rows[] = $r;
        }
        return array('rows' => $rows, 'title' => $title);
    }

    // ---- products ----

    private static function products() {
        $ids = get_posts(array('post_type' => Settings::product_post_type(), 'post_status' => array('publish', 'draft', 'pending', 'private'), 'posts_per_page' => 5000, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'ID'));
        if (!$ids) return array();
        update_meta_cache('post', $ids);
        $tax = Settings::category_taxonomy();
        $cats = array();
        $terms = wp_get_object_terms($ids, $tax, array('fields' => 'all_with_object_id'));
        foreach (is_wp_error($terms) ? array() : $terms as $t) $cats[(int) $t->object_id][] = (int) $t->term_id;
        $repo = new ProductSpecsRepository();
        $out = array();
        foreach ($ids as $id) {
            $post = get_post($id);
            $out[$id] = array(
                'id' => (int) $id, 'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                'sku' => trim((string) get_post_meta($id, '_sku', true)), 'slug' => (string) $post->post_name,
                'cats' => isset($cats[$id]) ? $cats[$id] : array(), 'specs' => $repo->get($id),
            );
        }
        return $out;
    }

    // ---- flow ----

    /** Step 1: read the sheet (or the uploaded CSV text) and guess the columns. */
    public static function load($link, $csv) {
        if (trim((string) $csv) !== '') {
            $rows = SpecSheet::parse_csv($csv);
            $title = 'فایل CSV';
        } else {
            $r = self::fetch_sheet($link);
            if (is_wp_error($r)) return $r;
            $rows = $r['rows'];
            $title = $r['title'];
        }
        $rows = array_values($rows);
        $h = SpecSheet::header_index($rows);
        if ($h > 0) $rows = array_slice($rows, $h);
        if (count($rows) < 2) return self::error('khazan_sheet_empty', 'در شیت ردیفی برای خواندن نبود. سطر اول باید عنوان ستون‌ها باشد.', 400);
        $rows = array_slice($rows, 0, self::MAX_ROWS + 1);
        $id = wp_generate_password(12, false);
        set_transient(self::SHEET . $id, $rows, HOUR_IN_SECONDS);
        $header = array_map('strval', $rows[0]);
        $width = max(array_map('count', array_slice($rows, 0, 50)));
        for ($i = count($header); $i < $width; $i++) $header[] = 'ستون ' . ($i + 1);
        return array('sheet_id' => $id, 'title' => $title, 'header' => $header, 'rows' => count($rows) - 1, 'sample' => array_slice($rows, 1, 4), 'mapping' => SpecSheet::guess_mapping($header));
    }

    private static function clean_mapping($m, $width) {
        $fields = array();
        foreach (Settings::PRODUCT_SPEC_FIELDS as $k => $label) {
            $v = isset($m['fields'][$k]) && $m['fields'][$k] !== '' && $m['fields'][$k] !== null ? (int) $m['fields'][$k] : null;
            $fields[$k] = $v !== null && $v >= 0 && $v < $width ? $v : null;
        }
        $key = isset($m['key']) && $m['key'] !== '' && $m['key'] !== null ? (int) $m['key'] : null;
        if ($key === null || $key < 0 || $key >= $width) return null;
        return array('key' => $key, 'fields' => $fields);
    }

    /** Step 2: what would change. Nothing is saved. */
    public static function preview($sheet_id, $mapping, $fill_similar) {
        $rows = get_transient(self::SHEET . preg_replace('/[^A-Za-z0-9]/', '', (string) $sheet_id));
        if (!is_array($rows)) return self::error('khazan_sheet_gone', 'خواندن شیت منقضی شده است؛ دوباره آن را بخوانید.', 404);
        $width = max(array_map('count', array_slice($rows, 0, 50)));
        $map = self::clean_mapping(is_array($mapping) ? $mapping : array(), $width);
        if (!$map) return self::error('khazan_sheet_map', 'ستون «نام یا کد محصول» را مشخص کنید.', 400);
        if (!array_filter($map['fields'], function ($v) { return $v !== null; })) return self::error('khazan_sheet_map', 'حداقل یک ستون ویژگی را انتخاب کنید.', 400);

        $products = self::products();
        $imp = SpecSheet::import(array_slice($rows, 1), $map, array_values($products));
        $write = array();
        $changes = array();
        foreach ($imp['matched'] as $id => $m) {
            $new = array_merge($products[$id]['specs'], $m['specs']);
            if ($new === $products[$id]['specs']) continue;
            $write[$id] = $new;
            $changes[] = array('id' => $id, 'title' => $products[$id]['title'], 'before' => $products[$id]['specs'], 'after' => $new, 'how' => $m['how']);
            $products[$id]['specs'] = $new;
        }
        $infer = array();
        $infer_view = array();
        if ($fill_similar) {
            $pool = array_values(array_filter($products, function ($p) { return !empty($p['specs']); }));
            $fields = array_keys(Settings::PRODUCT_SPEC_FIELDS);
            foreach ($products as $id => $p) {
                $missing = array_values(array_filter($fields, function ($f) use ($p) { return empty($p['specs'][$f]); }));
                if (!$missing) continue;
                $r = SpecSheet::infer($p, $pool, $missing);
                if (!$r['specs']) continue;
                $infer[$id] = array('specs' => $r['specs'], 'from' => $r['from']);
                $infer_view[] = array('id' => $id, 'title' => $p['title'], 'specs' => $r['specs'], 'from' => array_map(function ($f) use ($products) { return isset($products[$f]) ? $products[$f]['title'] : ''; }, array_slice($r['from'], 0, 3)));
            }
        }
        $plan_id = wp_generate_password(12, false);
        set_transient(self::PLAN . $plan_id, array('write' => $write, 'infer' => $infer), HOUR_IN_SECONDS);
        $without = count(array_filter($products, function ($p) { return empty($p['specs']); }));
        return array(
            'plan_id' => $plan_id, 'products' => count($products),
            'matched' => count($imp['matched']), 'to_change' => count($changes), 'unchanged' => count($imp['matched']) - count($changes),
            'unmatched' => array_slice($imp['unmatched'], 0, 30), 'unmatched_count' => count($imp['unmatched']),
            'ambiguous' => array_slice($imp['ambiguous'], 0, 30), 'ambiguous_count' => count($imp['ambiguous']), 'duplicate' => $imp['duplicate'],
            'changes' => array_slice($changes, 0, 40), 'infer_count' => count($infer), 'infer' => array_slice($infer_view, 0, 40),
            'still_empty' => max(0, $without - count($infer)), 'fill_similar' => (bool) $fill_similar,
        );
    }

    /** Step 3: save the previewed plan. */
    public static function apply($plan_id) {
        $plan_id = preg_replace('/[^A-Za-z0-9]/', '', (string) $plan_id);
        $plan = get_transient(self::PLAN . $plan_id);
        if (!is_array($plan)) return self::error('khazan_plan_gone', 'این پیش‌نمایش منقضی شده است؛ دوباره بسازید.', 404);
        $written = 0;
        foreach ($plan['write'] as $id => $specs) {
            $id = (int) $id;
            if (get_post_type($id) !== Settings::product_post_type() || !current_user_can('edit_post', $id)) continue;
            $old = get_post_meta($id, Settings::META_PRODUCT_CUSTOM_SPECS, true);
            update_post_meta($id, Settings::META_PRODUCT_SPECS_BACKUP, array('at' => current_time('mysql'), 'specs' => is_array($old) ? $old : array()));
            update_post_meta($id, Settings::META_PRODUCT_CUSTOM_SPECS, $specs);
            $written++;
        }
        $inferred = 0;
        foreach ($plan['infer'] as $id => $r) {
            $id = (int) $id;
            if (get_post_type($id) !== Settings::product_post_type() || !current_user_can('edit_post', $id)) continue;
            update_post_meta($id, Settings::META_PRODUCT_INFERRED_SPECS, array('at' => current_time('mysql'), 'specs' => $r['specs'], 'from' => $r['from']));
            $inferred++;
        }
        delete_transient(self::PLAN . $plan_id);
        return array('written' => $written, 'inferred' => $inferred);
    }
}
