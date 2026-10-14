<?php
/**
 * قابلیت آزمایشی مصورسازی ساختار لینک‌ها و Quick Winهای Search Console.
 *
 * وضعیت: «پارک‌شده» (inactive). این کلاس در Plugin::$legacy_files بارگذاری نمی‌شود، مسیرهای
 * /insights/* ثبت نمی‌شوند و assets/js/wila-insights.js هیچ صفحه‌ای را نمی‌سازد
 * (المان #wila-insights-root وجود ندارد). وابستگی‌هایش (WILA_Scanner، WILA_DB) همچنان
 * از طریق لایه‌های ارجاع‌دهنده معتبرند. برای فعال‌سازی باید یک صفحه/تب مجزا، ثبت مسیرها
 * در Khazan\Http و وارد کردن Access Token در رابط کاربری اضافه شود (docs/ARCHITECTURE.md).
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Insights {

    public function register_routes() {
        register_rest_route('wila/v1', '/insights/graph', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_graph'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('wila/v1', '/insights/search-console', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'get_search_console_quick_wins'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }

    public function check_permission() {
        return current_user_can('manage_options');
    }

    public function get_graph() {
        global $wpdb;

        $nodes = array();
        $scanner = new WILA_Scanner();
        foreach ($scanner->get_all_scannable_items() as $item) {
            $key = $item['type'] . ':' . $item['id'];
            $nodes[$key] = array(
                'id'       => $key,
                'item_id'  => (int) $item['id'],
                'type'     => $item['type'],
                'title'    => $item['title'],
                'url'      => $item['url'],
                'incoming' => 0,
                'outgoing' => 0,
            );
        }

        $table = WILA_DB::get_table_name();
        $rows = $wpdb->get_results("SELECT DISTINCT source_id, source_type, target_id, target_type FROM {$table} WHERE target_id IS NOT NULL", ARRAY_A);
        $edges = array();

        foreach ($rows as $row) {
            $source = $row['source_type'] . ':' . $row['source_id'];
            $target = $row['target_type'] . ':' . $row['target_id'];
            if ($source === $target || !isset($nodes[$source], $nodes[$target])) {
                continue;
            }

            $edges[] = array('source' => $source, 'target' => $target);
            $nodes[$source]['outgoing']++;
            $nodes[$target]['incoming']++;
        }

        return rest_ensure_response(array(
            'nodes' => array_values($nodes),
            'edges' => $edges,
        ));
    }

    private function normalized_url($url) {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return '';
        }

        $path = isset($parts['path']) ? untrailingslashit($parts['path']) : '';
        return strtolower($parts['host']) . $path;
    }

    public function get_search_console_quick_wins($request) {
        $token = trim((string) $request->get_param('access_token'));
        $property = trim((string) $request->get_param('property'));

        if (empty($token) || strlen($token) > 4096 || empty($property) || strlen($property) > 512) {
            return new WP_Error('wila_gsc_invalid_request', 'Property و Access Token را وارد کنید.', array('status' => 400));
        }

        $end_date = gmdate('Y-m-d', time() - (3 * DAY_IN_SECONDS));
        $start_date = gmdate('Y-m-d', strtotime($end_date . ' UTC') - (27 * DAY_IN_SECONDS));
        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/searchAnalytics/query';

        $response = wp_remote_post($url, array(
            'timeout'     => 25,
            'redirection' => 0,
            'headers'     => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'        => wp_json_encode(array(
                'startDate'  => $start_date,
                'endDate'    => $end_date,
                'dimensions' => array('page'),
                'type'       => 'web',
                'rowLimit'   => 5000,
            )),
        ));

        if (is_wp_error($response)) {
            return new WP_Error('wila_gsc_network_error', 'ارتباط با Google Search Console برقرار نشد.', array('status' => 502));
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            $message = in_array($status, array(401, 403), true)
                ? 'توکن معتبر نیست یا به این Property دسترسی فقط‌خواندنی ندارد.'
                : 'Google Search Console خطای HTTP ' . absint($status) . ' برگرداند.';
            return new WP_Error('wila_gsc_api_error', $message, array('status' => 502));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return new WP_Error('wila_gsc_invalid_response', 'پاسخ Google Search Console معتبر نیست.', array('status' => 502));
        }

        $graph_response = $this->get_graph();
        $graph = $graph_response instanceof WP_REST_Response ? $graph_response->get_data() : array('nodes' => array());
        $pages = array();
        foreach ($graph['nodes'] as $node) {
            $pages[$this->normalized_url($node['url'])] = $node;
        }

        $wins = array();
        foreach ((array) ($body['rows'] ?? array()) as $row) {
            $page_url = isset($row['keys'][0]) ? $row['keys'][0] : '';
            $position = isset($row['position']) ? (float) $row['position'] : 0;
            $impressions = isset($row['impressions']) ? (int) $row['impressions'] : 0;
            $key = $this->normalized_url($page_url);

            if ($position < 11 || $position > 20 || $impressions < 1 || !isset($pages[$key])) {
                continue;
            }

            $node = $pages[$key];
            $node['position'] = round($position, 2);
            $node['impressions'] = $impressions;
            $node['clicks'] = isset($row['clicks']) ? (int) $row['clicks'] : 0;
            $node['priority'] = round($impressions / (($node['incoming'] + 1) * ($position - 10)), 2);
            $wins[] = $node;
        }

        usort($wins, function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        return rest_ensure_response(array(
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'quick_wins' => $wins,
        ));
    }
}
