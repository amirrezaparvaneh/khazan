<?php
/**
 * Google Search Console connection (service account) and a local copy of the performance data.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Search;

if (!defined('ABSPATH')) {
    exit;
}

final class SearchConsole {

    const OPT_KEY = 'khazan_gsc_key';
    const OPT_SITE = 'khazan_gsc_site';
    const OPT_DATA = 'khazan_gsc_data';
    const TRANSIENT_TOKEN = 'khazan_gsc_token';
    const API = 'https://www.googleapis.com/webmasters/v3/sites';
    const ROW_LIMIT = 25000;
    const MAX_PAGES = 4;
    const KEEP_PAGES = 1000;
    const KEEP_QUERIES = 2000;
    const KEEP_PAIRS = 5000;

    // ---- pure helpers ----

    /** Position weighted by impressions, CTR from the totals. */
    private static function add(array &$bucket, $key, array $row) {
        $imp = (float) $row['impressions'];
        if (!isset($bucket[$key])) $bucket[$key] = array('clicks' => 0.0, 'impressions' => 0.0, 'pos_sum' => 0.0);
        $bucket[$key]['clicks'] += (float) $row['clicks'];
        $bucket[$key]['impressions'] += $imp;
        $bucket[$key]['pos_sum'] += (float) $row['position'] * $imp;
    }

    private static function finish(array $bucket, $name, $keep) {
        $out = array();
        foreach ($bucket as $key => $b) {
            $imp = $b['impressions'];
            $out[] = array(
                $name => (string) $key,
                'clicks' => (int) round($b['clicks']),
                'impressions' => (int) round($imp),
                'ctr' => $imp > 0 ? round($b['clicks'] / $imp, 4) : 0,
                'position' => $imp > 0 ? round($b['pos_sum'] / $imp, 1) : 0,
            );
        }
        usort($out, function ($a, $b) { return $b['impressions'] <=> $a['impressions'] ?: $b['clicks'] <=> $a['clicks']; });
        return array_slice($out, 0, $keep);
    }

    /** Rows of (query, page) pairs from the API → per-page, per-query and pair lists, busiest first. */
    public static function aggregate(array $rows) {
        $pages = $queries = $pairs = array();
        foreach ($rows as $row) {
            if (!isset($row['keys'][0], $row['keys'][1])) continue;
            $row += array('clicks' => 0, 'impressions' => 0, 'position' => 0);
            self::add($queries, $row['keys'][0], $row);
            self::add($pages, $row['keys'][1], $row);
            self::add($pairs, $row['keys'][0] . "\x1f" . $row['keys'][1], $row);
        }
        $pair_rows = array();
        foreach (self::finish($pairs, 'k', self::KEEP_PAIRS) as $p) {
            list($q, $page) = explode("\x1f", $p['k']) + array('', '');
            unset($p['k']);
            $pair_rows[] = array('query' => $q, 'page' => $page) + $p;
        }
        return array(
            'pages' => self::finish($pages, 'page', self::KEEP_PAGES),
            'queries' => self::finish($queries, 'query', self::KEEP_QUERIES),
            'pairs' => $pair_rows,
        );
    }


    /** CTR a page should roughly get at a position; below it the title or description is the problem. */
    public static function expected_ctr($position) {
        if ($position <= 3) return 0.15;
        if ($position <= 5) return 0.07;
        if ($position <= 10) return 0.03;
        return 0.015;
    }

    /**
     * Four lists of work to do, from the synced data (pure).
     *  near_top    queries ranked 5-20 with real demand: worth strengthening
     *  low_ctr     pages ranked within the top 12 that get fewer clicks than their position should: fix title/description
     *  deep        pages ranked 20-80 with high impressions: need content and internal links
     *  cannibal    queries where two or more pages split the impressions
     */
    public static function opportunities(array $d, $limit = 15) {
        $queries = isset($d['queries']) ? $d['queries'] : array();
        $pages = isset($d['pages']) ? $d['pages'] : array();
        $pairs = isset($d['pairs']) ? $d['pairs'] : array();

        $best = array(); // query => page with the most impressions
        $by_query = array();
        foreach ($pairs as $p) {
            $by_query[$p['query']][] = $p;
            if (!isset($best[$p['query']]) || $p['impressions'] > $best[$p['query']]['impressions']) $best[$p['query']] = $p;
        }

        $near = array();
        foreach ($queries as $q) {
            if ($q['position'] >= 5 && $q['position'] <= 20 && $q['impressions'] >= 20) {
                $q['page'] = isset($best[$q['query']]) ? $best[$q['query']]['page'] : '';
                $near[] = $q;
            }
        }
        usort($near, function ($a, $b) { return $b['impressions'] <=> $a['impressions']; });

        $low = array();
        foreach ($pages as $p) {
            if ($p['position'] > 0 && $p['position'] <= 12 && $p['impressions'] >= 20 && $p['ctr'] < self::expected_ctr($p['position']) * 0.5) {
                $p['expected_ctr'] = self::expected_ctr($p['position']);
                $low[] = $p;
            }
        }
        usort($low, function ($a, $b) { return $b['impressions'] <=> $a['impressions']; });

        $deep = array();
        foreach ($pages as $p) {
            if ($p['position'] > 20 && $p['position'] <= 80 && $p['impressions'] >= 50) $deep[] = $p;
        }
        usort($deep, function ($a, $b) { return $b['impressions'] <=> $a['impressions']; });

        $cannibal = array();
        foreach ($by_query as $query => $list) {
            $total = array_sum(array_column($list, 'impressions'));
            if ($total < 30) continue;
            $big = array_values(array_filter($list, function ($p) use ($total) { return $p['impressions'] >= 0.2 * $total; }));
            if (count($big) < 2) continue;
            usort($big, function ($a, $b) { return $b['impressions'] <=> $a['impressions']; });
            $cannibal[] = array('query' => $query, 'impressions' => $total, 'pages' => array_map(function ($p) { return array('page' => $p['page'], 'impressions' => $p['impressions'], 'position' => $p['position']); }, $big));
        }
        usort($cannibal, function ($a, $b) { return $b['impressions'] <=> $a['impressions']; });

        return array(
            'near_top' => array_slice($near, 0, $limit),
            'low_ctr' => array_slice($low, 0, $limit),
            'deep' => array_slice($deep, 0, $limit),
            'cannibal' => array_slice($cannibal, 0, $limit),
        );
    }

    /** The service-account email, safe to show. */
    public static function email() {
        $key = GoogleServiceAuth::parse_key(get_option(self::OPT_KEY, ''));
        return $key ? $key['client_email'] : '';
    }

    public static function site() {
        return (string) get_option(self::OPT_SITE, '');
    }

    public static function data() {
        $d = get_option(self::OPT_DATA, array());
        return is_array($d) ? $d : array();
    }

    public static function status() {
        $d = self::data();
        return array(
            'connected' => self::email() !== '' && self::site() !== '',
            'has_key' => self::email() !== '',
            'email' => self::email(),
            'site' => self::site(),
            'synced_at' => isset($d['synced_at']) ? $d['synced_at'] : '',
            'range' => isset($d['range']) ? $d['range'] : null,
            'counts' => array(
                'pages' => isset($d['pages']) ? count($d['pages']) : 0,
                'queries' => isset($d['queries']) ? count($d['queries']) : 0,
            ),
        );
    }

    // ---- API ----

    private static function error($code, $message) {
        return new \WP_Error($code, $message, array('status' => 502));
    }

    private static function token() {
        $cached = get_transient(self::TRANSIENT_TOKEN);
        if (is_string($cached) && $cached !== '') return $cached;
        $key = GoogleServiceAuth::parse_key(get_option(self::OPT_KEY, ''));
        if (!$key) return self::error('khazan_gsc_nokey', 'کلید سرویس‌اکانت ذخیره نشده است.');
        $jwt = GoogleServiceAuth::jwt($key, GoogleServiceAuth::SCOPE_READONLY, time());
        if (!$jwt) return self::error('khazan_gsc_badkey', 'کلید خصوصی خوانده نشد. فایل JSON را کامل و بدون تغییر بچسبانید.');
        $res = wp_remote_post($key['token_uri'], array('timeout' => 20, 'body' => array('grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt)));
        if (is_wp_error($res)) return self::error('khazan_gsc_net', 'اتصال به گوگل برقرار نشد: ' . $res->get_error_message());
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (empty($body['access_token'])) {
            $why = is_array($body) && isset($body['error_description']) ? $body['error_description'] : 'پاسخ نامعتبر';
            return self::error('khazan_gsc_auth', 'گوگل توکن نداد: ' . $why);
        }
        set_transient(self::TRANSIENT_TOKEN, $body['access_token'], max(60, (int) ($body['expires_in'] ?? 3600) - 120));
        return $body['access_token'];
    }

    private static function request($method, $url, $payload = null) {
        $token = self::token();
        if (is_wp_error($token)) return $token;
        $args = array('method' => $method, 'timeout' => 40, 'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'));
        if ($payload !== null) $args['body'] = wp_json_encode($payload);
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) return self::error('khazan_gsc_net', 'اتصال به گوگل برقرار نشد: ' . $res->get_error_message());
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 400) {
            $why = is_array($body) && isset($body['error']['message']) ? $body['error']['message'] : 'HTTP ' . $code;
            return self::error('khazan_gsc_http', 'خطای سرچ کنسول: ' . $why);
        }
        return is_array($body) ? $body : array();
    }

    /** Properties this service account can read. */
    public static function sites() {
        $r = self::request('GET', self::API);
        if (is_wp_error($r)) return $r;
        $out = array();
        foreach (isset($r['siteEntry']) ? $r['siteEntry'] : array() as $e) {
            if (!empty($e['siteUrl'])) $out[] = array('url' => $e['siteUrl'], 'level' => isset($e['permissionLevel']) ? $e['permissionLevel'] : '');
        }
        return $out;
    }

    /** Saves the key, checks that Google accepts it and picks the property (the only one, or the one given). */
    public static function connect($json, $site) {
        if (!GoogleServiceAuth::parse_key($json)) return new \WP_Error('khazan_gsc_json', 'این فایل، کلید سرویس‌اکانت گوگل نیست. کل محتوای فایل JSON را بچسبانید.', array('status' => 400));
        update_option(self::OPT_KEY, trim((string) $json), false);
        delete_transient(self::TRANSIENT_TOKEN);
        $sites = self::sites();
        if (is_wp_error($sites)) { self::disconnect(); return $sites; }
        $site = trim((string) $site);
        $urls = array_column($sites, 'url');
        if ($site === '' && count($urls) === 1) $site = $urls[0];
        if ($site === '') {
            return array('connected' => false, 'sites' => $sites, 'message' => $urls ? 'چند سایت پیدا شد؛ یکی را انتخاب کنید.' : 'سرویس‌اکانت به هیچ سایتی دسترسی ندارد. ایمیلش را در سرچ کنسول به‌عنوان کاربر اضافه کنید.');
        }
        if (!in_array($site, $urls, true)) return new \WP_Error('khazan_gsc_site', 'این سایت در دسترس سرویس‌اکانت نیست.', array('status' => 400));
        update_option(self::OPT_SITE, $site, false);
        return array('connected' => true, 'sites' => $sites, 'site' => $site, 'message' => 'اتصال برقرار شد.');
    }

    public static function disconnect() {
        delete_option(self::OPT_KEY);
        delete_option(self::OPT_SITE);
        delete_option(self::OPT_DATA);
        delete_transient(self::TRANSIENT_TOKEN);
    }

    /** Pulls the last N days (Google data lags about two days) and keeps the aggregated lists. */
    public static function sync($days = 90) {
        $site = self::site();
        if ($site === '') return new \WP_Error('khazan_gsc_nosite', 'ابتدا اتصال را کامل کنید.', array('status' => 400));
        $days = max(7, min(480, (int) $days));
        $end = gmdate('Y-m-d', time() - 2 * 86400);
        $start = gmdate('Y-m-d', time() - (2 + $days) * 86400);
        $rows = array();
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $r = self::request('POST', self::API . '/' . rawurlencode($site) . '/searchAnalytics/query', array(
                'startDate' => $start, 'endDate' => $end, 'dimensions' => array('query', 'page'),
                'rowLimit' => self::ROW_LIMIT, 'startRow' => $i * self::ROW_LIMIT,
            ));
            if (is_wp_error($r)) return $r;
            $got = isset($r['rows']) ? $r['rows'] : array();
            $rows = array_merge($rows, $got);
            if (count($got) < self::ROW_LIMIT) break;
        }
        $agg = self::aggregate($rows);
        $agg['synced_at'] = current_time('mysql');
        $agg['range'] = array('start' => $start, 'end' => $end);
        $agg['rows_read'] = count($rows);
        update_option(self::OPT_DATA, $agg, false);
        return $agg;
    }
}
