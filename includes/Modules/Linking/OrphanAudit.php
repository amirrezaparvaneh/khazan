<?php
/**
 * Accurate orphan check. The link index only reads the main content of posts and term descriptions, so pages linked
 * from a product short description, a page builder (Elementor), category meta, a menu or a widget look orphaned there.
 * This audit reads all of those sources, compares links by a normalised URL (encoded Persian slugs, www, trailing
 * slash, relative links), and sorts every page into: orphan (no link at all), menu_only (only a menu or widget links to
 * it, which Google weighs far less than links in content) and linked.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Linking;

use Khazan\Services\Links\HtmlLinkText;

if (!defined('ABSPATH')) {
    exit;
}

final class OrphanAudit {

    const OPTION = 'khazan_orphan_audit';
    const CHUNK = 300;

    public static function home_host() {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    /** All href values in a piece of HTML or page-builder JSON. */
    public static function hrefs($text, $builder = false) {
        $text = str_replace('\\/', '/', (string) $text);
        $out = array();
        if (stripos($text, 'href') !== false) {
            preg_match_all('/href\s*=\s*\\\\?["\']([^"\'\\\\]+)\\\\?["\']/i', $text, $m);
            $out = $m[1];
        }
        // page builders keep button and image links as {"url":"..."}
        if ($builder && preg_match_all('/"url"\s*:\s*"([^"]+)"/', $text, $m)) $out = array_merge($out, $m[1]);
        return $out;
    }

    /**
     * Pure: inbound counts per page from a list of (source key, href, kind) links.
     *
     * @param array  $url_map   url_key => page key ("type_id")
     * @param array  $links     list of array(source key|null, href, 'content'|'menu')
     * @return array page key => array(content, menu, sources[])
     */
    public static function count(array $url_map, array $links, $home_host) {
        $in = array();
        foreach ($links as $l) {
            list($source, $href, $kind) = $l;
            $key = HtmlLinkText::url_key($href, $home_host);
            if ($key === null || !isset($url_map[$key])) continue;
            $target = $url_map[$key];
            if ($source !== null && $source === $target) continue; // a page linking to itself does not count
            if (!isset($in[$target])) $in[$target] = array('content' => 0, 'menu' => 0, 'sources' => array());
            $in[$target][$kind === 'menu' ? 'menu' : 'content']++;
            if ($kind !== 'menu' && $source !== null && count($in[$target]['sources']) < 5 && !in_array($source, $in[$target]['sources'], true)) $in[$target]['sources'][] = $source;
        }
        return $in;
    }

    /** Pure: status of a page from its counts. */
    public static function status($counts) {
        if (!$counts || ($counts['content'] === 0 && $counts['menu'] === 0)) return 'orphan';
        return $counts['content'] === 0 ? 'menu_only' : 'linked';
    }

    private static function items() {
        $items = (new \Khazan\Services\Scan\ScannerService())->get_all_scannable_items();
        $front = (int) get_option('page_on_front');
        $out = array();
        foreach ($items as $it) {
            if (is_wp_error($it['url']) || !$it['url']) continue;
            if ($front && (int) $it['id'] === $front && !taxonomy_exists($it['type'])) continue; // the home page is linked from every page
            $out[$it['type'] . '_' . $it['id']] = $it;
        }
        return $out;
    }

    public static function run() {
        global $wpdb;
        if (function_exists('set_time_limit')) @set_time_limit(120);
        $host = self::home_host();
        $items = self::items();
        $url_map = array();
        foreach ($items as $key => $it) {
            $k = HtmlLinkText::url_key($it['url'], $host);
            if ($k !== null) $url_map[$k] = $key;
        }

        $types = array_values(array_diff(get_post_types(array('public' => true), 'names'), array('attachment')));
        $in_types = "'" . implode("','", array_map('esc_sql', $types)) . "'";
        $links = array();

        // posts: content, excerpt (product short description) and page-builder data, in chunks
        $last = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_type, post_content, post_excerpt FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in_types}) AND ID > %d ORDER BY ID ASC LIMIT %d",
                $last, self::CHUNK
            ), ARRAY_A);
            if (!$rows) break;
            $ids = array();
            foreach ($rows as $r) {
                $last = (int) $r['ID'];
                $ids[] = $last;
                $src = $r['post_type'] . '_' . $r['ID'];
                foreach (self::hrefs($r['post_content'] . ' ' . $r['post_excerpt']) as $h) $links[] = array($src, $h, 'content');
            }
            $meta = $wpdb->get_results(
                "SELECT pm.post_id, p.post_type, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_elementor_data' AND pm.post_id IN (" . implode(',', array_map('intval', $ids)) . ")",
                ARRAY_A
            );
            foreach ((array) $meta as $m) {
                foreach (self::hrefs($m['meta_value'], true) as $h) $links[] = array($m['post_type'] . '_' . $m['post_id'], $h, 'content');
            }
        } while (count($rows) === self::CHUNK);

        // terms: description and any term meta with a link (a second description, an SEO block...)
        foreach ($items as $key => $it) {
            if (!taxonomy_exists($it['type'])) continue;
            $term = get_term((int) $it['id'], $it['type']);
            if ($term && !is_wp_error($term)) foreach (self::hrefs($term->description) as $h) $links[] = array($key, $h, 'content');
        }
        $tm = $wpdb->get_results("SELECT tm.term_id, tt.taxonomy, tm.meta_value FROM {$wpdb->termmeta} tm JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tm.meta_value LIKE '%href%'", ARRAY_A);
        foreach ((array) $tm as $m) foreach (self::hrefs($m['meta_value']) as $h) $links[] = array($m['taxonomy'] . '_' . $m['term_id'], $h, 'content');

        // site-wide links: menus and widgets
        $menu_items = $wpdb->get_results(
            "SELECT p.ID, MAX(CASE WHEN m.meta_key = '_menu_item_type' THEN m.meta_value END) AS t, MAX(CASE WHEN m.meta_key = '_menu_item_object' THEN m.meta_value END) AS o, MAX(CASE WHEN m.meta_key = '_menu_item_object_id' THEN m.meta_value END) AS oid, MAX(CASE WHEN m.meta_key = '_menu_item_url' THEN m.meta_value END) AS u FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'nav_menu_item' AND p.post_status = 'publish' GROUP BY p.ID",
            ARRAY_A
        );
        foreach ((array) $menu_items as $mi) {
            if ($mi['t'] === 'post_type' || $mi['t'] === 'taxonomy') {
                $url = $mi['t'] === 'post_type' ? get_permalink((int) $mi['oid']) : get_term_link((int) $mi['oid'], $mi['o']);
                if ($url && !is_wp_error($url)) $links[] = array(null, $url, 'menu');
            } elseif (!empty($mi['u'])) {
                $links[] = array(null, $mi['u'], 'menu');
            }
        }
        $widgets = $wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget\\_%' AND option_value LIKE '%href%'");
        foreach ((array) $widgets as $w) foreach (self::hrefs($w) as $h) $links[] = array(null, $h, 'menu');

        $in = self::count($url_map, $links, $host);
        $old = array();
        foreach ((new \Khazan\Repository\LinkRepository())->linked_targets() as $t) $old[$t['target_type'] . '_' . $t['target_id']] = true;

        $list = array();
        $totals = array('orphan' => 0, 'menu_only' => 0, 'linked' => 0, 'false_orphans' => 0, 'pages' => count($items));
        foreach ($items as $key => $it) {
            $c = isset($in[$key]) ? $in[$key] : null;
            $status = self::status($c);
            $totals[$status]++;
            if ($status === 'linked' && !isset($old[$key])) $totals['false_orphans']++;
            if ($status === 'linked') continue;
            $list[] = array(
                'key' => $key, 'id' => (int) $it['id'], 'type' => $it['type'], 'title' => html_entity_decode((string) $it['title'], ENT_QUOTES, 'UTF-8'),
                'url' => $it['url'], 'status' => $status, 'menu' => $c ? $c['menu'] : 0, 'type_label' => self::type_label($it['type']),
            );
        }
        usort($list, function ($a, $b) { return strcmp($a['status'], $b['status']) ?: strcmp($a['type'], $b['type']); });
        $result = array('at' => current_time('mysql'), 'totals' => $totals, 'items' => $list);
        update_option(self::OPTION, $result, false);
        return $result;
    }

    public static function type_label($type) {
        $o = get_post_type_object($type);
        if ($o) return $o->labels->singular_name;
        $t = get_taxonomy($type);
        return $t ? $t->labels->singular_name : $type;
    }

    public static function stored() {
        $r = get_option(self::OPTION, array());
        return is_array($r) ? $r : array();
    }

    /** After a link is added, the target leaves the orphan list without a full re-run. */
    public static function mark_linked($type, $id) {
        $r = self::stored();
        if (empty($r['items'])) return;
        foreach ($r['items'] as $i => $it) {
            if ($it['type'] === $type && (int) $it['id'] === (int) $id) {
                $r['totals'][$it['status']] = max(0, $r['totals'][$it['status']] - 1);
                $r['totals']['linked']++;
                array_splice($r['items'], $i, 1);
                update_option(self::OPTION, $r, false);
                return;
            }
        }
    }
}
