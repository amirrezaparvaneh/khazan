<?php
/**
 * Asks the site itself whether a URL from Search Console still answers or has been redirected.
 * Google keeps reporting an old address for weeks after a 301, which looks like two pages competing.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Search;

if (!defined('ABSPATH')) {
    exit;
}

final class RedirectCheck {

    const CACHE = 'khazan_redirect_';
    const TTL = 6 * HOUR_IN_SECONDS;
    const MAX_URLS = 12;

    /**
     * Pure: what a response means for the page.
     *
     * @return array state (ok|redirect|gone|error), status, to (absolute address or '')
     */
    public static function classify($status, $location, $url) {
        $status = (int) $status;
        if ($status >= 300 && $status < 400) {
            $to = self::absolute((string) $location, $url);
            return array('state' => $to === '' ? 'error' : 'redirect', 'status' => $status, 'to' => $to, 'permanent' => in_array($status, array(301, 308), true));
        }
        if ($status === 404 || $status === 410) return array('state' => 'gone', 'status' => $status, 'to' => '', 'permanent' => false);
        if ($status >= 200 && $status < 300) return array('state' => 'ok', 'status' => $status, 'to' => '', 'permanent' => false);
        return array('state' => 'error', 'status' => $status, 'to' => '', 'permanent' => false);
    }

    /** Pure: a Location header (maybe relative) as a full address. */
    public static function absolute($location, $base) {
        $location = trim($location);
        if ($location === '') return '';
        if (preg_match('#^https?://#i', $location)) return $location;
        $b = parse_url($base);
        if (!$b || empty($b['host'])) return '';
        $origin = (isset($b['scheme']) ? $b['scheme'] : 'https') . '://' . $b['host'];
        if (strpos($location, '//') === 0) return (isset($b['scheme']) ? $b['scheme'] : 'https') . ':' . $location;
        if ($location[0] === '/') return $origin . $location;
        $dir = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
        return $origin . $dir . $location;
    }

    /** Only addresses of this very site are fetched, so the check cannot be pointed elsewhere. */
    public static function is_own($url) {
        $h = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $own = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        if ($h === '' || $own === '') return false;
        return preg_replace('/^www\./', '', $h) === preg_replace('/^www\./', '', $own);
    }

    public static function check($url) {
        if (!self::is_own($url)) return array('state' => 'error', 'status' => 0, 'to' => '', 'permanent' => false);
        $key = self::CACHE . md5($url);
        $hit = get_transient($key);
        if (is_array($hit)) return $hit;
        $r = wp_remote_get($url, array('redirection' => 0, 'timeout' => 6, 'limit_response_size' => 2048, 'headers' => array('Cache-Control' => 'no-cache')));
        if (is_wp_error($r)) return array('state' => 'error', 'status' => 0, 'to' => '', 'permanent' => false);
        $out = self::classify(wp_remote_retrieve_response_code($r), (string) wp_remote_retrieve_header($r, 'location'), $url);
        if ($out['state'] !== 'error') set_transient($key, $out, self::TTL);
        return $out;
    }

    /** @return array url => result */
    public static function check_many(array $urls) {
        $out = array();
        foreach (array_slice(array_values(array_unique($urls)), 0, self::MAX_URLS) as $u) $out[$u] = self::check($u);
        return $out;
    }
}
