<?php
/**
 * Links TO a page from existing articles: reads the full text of related articles, finds the exact paragraph,
 * sentence and anchor text for a natural link (HtmlLinkText), and adds the link without rewriting anything else.
 *
 * Rules: only articles that do not link to the page yet; never in headings, captions, tables or existing links; at
 * most one link per paragraph that has no other link; the article must still be under the internal-link maximum of the
 * standards; the anchor never contains the article's own focus keyphrase (no self-competition); 2-6 words, taken
 * word for word from the article text. Every change goes through wp_update_post, so WordPress keeps a revision, and
 * every applied link can be undone from the log.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Linking;

use Khazan\Core\Settings;
use Khazan\Services\Content\SeoStandards;
use Khazan\Services\Links\AnchorPhrases;
use Khazan\Services\Links\HtmlLinkText;
use Khazan\Services\Seo\SeoMetaService;

if (!defined('ABSPATH')) {
    exit;
}

final class LinkSuggester {

    const OPT_SETTINGS = 'khazan_linking_settings';
    const OPT_LOG = 'khazan_linking_log';
    const OPT_QUEUE = 'khazan_linking_queue';
    const CRON = 'khazan_linking_new_post';
    const LOG_MAX = 300;

    public static function settings() {
        $s = get_option(self::OPT_SETTINGS, array());
        $s = is_array($s) ? $s : array();
        return array(
            'auto_suggest' => isset($s['auto_suggest']) ? (bool) $s['auto_suggest'] : true,
            'auto_apply' => !empty($s['auto_apply']),
            'per_target' => isset($s['per_target']) ? max(1, min(10, (int) $s['per_target'])) : 5,
        );
    }

    public static function save_settings(array $in) {
        $s = array(
            'auto_suggest' => !empty($in['auto_suggest']),
            'auto_apply' => !empty($in['auto_apply']),
            'per_target' => isset($in['per_target']) ? max(1, min(10, (int) $in['per_target'])) : 5,
        );
        update_option(self::OPT_SETTINGS, $s, false);
        return $s;
    }

    public static function register() {
        add_action('transition_post_status', array(__CLASS__, 'on_publish'), 20, 3);
        add_action(self::CRON, array(__CLASS__, 'process_new_post'));
    }

    /** A newly published article: find links to it from older articles a minute later (not during the save). */
    public static function on_publish($new, $old, $post) {
        if ($new !== 'publish' || $old === 'publish' || !$post || $post->post_type !== Settings::blog_post_type()) return;
        if (!self::settings()['auto_suggest']) return;
        if (!wp_next_scheduled(self::CRON, array((int) $post->ID))) wp_schedule_single_event(time() + 60, self::CRON, array((int) $post->ID));
    }

    public static function process_new_post($post_id) {
        $s = self::suggest(get_post_type($post_id), (int) $post_id);
        if (is_wp_error($s)) return;
        $queue = self::queue();
        if (self::settings()['auto_apply'] && $s['suggestions']) {
            $done = self::apply($s['target']['type'], $s['target']['id'], $s['suggestions'], 'auto');
            $queue[(string) $post_id] = array('at' => current_time('mysql'), 'title' => $s['target']['title'], 'pending' => 0, 'applied' => $done['applied']);
        } else {
            $queue[(string) $post_id] = array('at' => current_time('mysql'), 'title' => $s['target']['title'], 'pending' => count($s['suggestions']), 'applied' => 0);
        }
        update_option(self::OPT_QUEUE, array_slice($queue, -50, null, true), false);
    }

    public static function queue() {
        $q = get_option(self::OPT_QUEUE, array());
        return is_array($q) ? $q : array();
    }

    // ---- target ----

    /** Url, title and anchor phrases of a post or term. */
    public static function target($type, $id) {
        $id = (int) $id;
        $seo = new SeoMetaService();
        if (taxonomy_exists($type)) {
            $term = get_term($id, $type);
            if (!$term || is_wp_error($term)) return new \WP_Error('khazan_target', 'این صفحه پیدا نشد.', array('status' => 404));
            $url = get_term_link($term);
            $title = $term->name;
            $meta = $seo->read_term_seo($type, $id);
        } else {
            $post = get_post($id);
            if (!$post || $post->post_status !== 'publish') return new \WP_Error('khazan_target', 'این صفحه پیدا نشد یا منتشر نشده است.', array('status' => 404));
            $url = get_permalink($post);
            $title = get_the_title($post);
            $meta = $seo->read_post_seo($id);
            $type = $post->post_type;
        }
        $host = OrphanAudit::home_host();
        $key = HtmlLinkText::url_key($url, $host);
        $queries = array();
        $data = \Khazan\Modules\Search\SearchConsole::data();
        foreach (isset($data['pairs']) ? $data['pairs'] : array() as $p) {
            if (HtmlLinkText::url_key($p['page'], $host) === $key) $queries[] = $p['query'];
            if (count($queries) >= 8) break;
        }
        $phrases = AnchorPhrases::build(array(
            'focus' => $meta['focuskw'], 'synonyms' => SeoMetaService::split_keywords($meta['synonyms']), 'queries' => $queries,
            'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'), 'brand' => Settings::brand_name(),
        ));
        return array('type' => $type, 'id' => $id, 'url' => $url, 'key' => $key, 'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'), 'phrases' => $phrases);
    }

    // ---- sources ----

    /** Published articles whose text contains one of the phrases (loose SQL match, checked exactly afterwards). */
    private static function candidate_ids(array $target, $max = 150) {
        global $wpdb;
        $type = Settings::blog_post_type();
        $ids = array();
        foreach (array_slice($target['phrases'], 0, 6) as $phrase) {
            $words = preg_split('/[\s\x{200C}]+/u', HtmlLinkText::norm_phrase($phrase));
            $variants = array(implode('%', array_map(array($wpdb, 'esc_like'), $words)));
            // the same words written with Arabic yeh/kaf
            $arabic = strtr($variants[0], array('ی' => 'ي', 'ک' => 'ك'));
            if ($arabic !== $variants[0]) $variants[] = $arabic;
            foreach ($variants as $v) {
                $found = $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ID <> %d AND post_content LIKE %s ORDER BY post_date DESC LIMIT 80",
                    $type, taxonomy_exists($target['type']) ? 0 : (int) $target['id'], '%' . $v . '%'
                ));
                foreach ((array) $found as $f) $ids[(int) $f] = true;
                if (count($ids) >= $max) break 2;
            }
        }
        return array_keys($ids);
    }

    private static function tokens($text) {
        $n = HtmlLinkText::norm_phrase($text);
        return array_values(array_filter(explode(' ', $n), function ($w) { return mb_strlen($w, 'UTF-8') > 2; }));
    }

    /**
     * Best spot in each related article, best articles first.
     *
     * @return array|\WP_Error array(target, suggestions[], checked, skipped{reason=>count})
     */
    public static function suggest($type, $id, $limit = null) {
        $target = self::target($type, $id);
        if (is_wp_error($target)) return $target;
        $limit = $limit ?: self::settings()['per_target'];
        $out = array('target' => $target, 'suggestions' => array(), 'checked' => 0, 'skipped' => array());
        if (!$target['phrases']) {
            $out['skipped']['no_phrase'] = 1;
            return $out;
        }
        $host = OrphanAudit::home_host();
        list(, $max_links) = SeoStandards::internal_range(Settings::KIND_ARTICLE);
        $seo = new SeoMetaService();
        $target_tokens = self::tokens($target['title'] . ' ' . implode(' ', $target['phrases']));
        $rows = array();
        foreach (self::candidate_ids($target) as $sid) {
            $post = get_post($sid);
            if (!$post) continue;
            $out['checked']++;
            if (get_post_meta($sid, '_elementor_edit_mode', true) === 'builder') {
                $out['skipped']['builder'] = (isset($out['skipped']['builder']) ? $out['skipped']['builder'] : 0) + 1;
                continue;
            }
            $source_seo = $seo->read_post_seo($sid);
            $r = HtmlLinkText::find($post->post_content, $target['phrases'], array(
                'target_key' => $target['key'], 'home_host' => $host, 'avoid' => array_filter(array($source_seo['focuskw'])),
                'max_total' => (int) $max_links, 'max_per_block' => 1, 'limit' => 1,
            ));
            if (!$r['spots']) {
                $out['skipped'][$r['reason']] = (isset($out['skipped'][$r['reason']]) ? $out['skipped'][$r['reason']] : 0) + 1;
                continue;
            }
            $spot = $r['spots'][0];
            $overlap = count(array_intersect(self::tokens(get_the_title($post)), $target_tokens));
            $rows[] = array(
                'source_id' => $sid, 'source_title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'), 'source_url' => get_permalink($post),
                'edit_url' => get_edit_post_link($sid, 'raw'), 'anchor' => $spot['anchor'], 'context' => $spot['context'],
                'spot' => array('token' => $spot['token'], 'offset' => $spot['offset'], 'anchor' => $spot['anchor']),
                'hash' => md5($post->post_content), 'rank' => $spot['rank'], 'relevance' => $overlap,
                'links' => HtmlLinkText::internal_count($post->post_content, $host),
            );
        }
        usort($rows, function ($a, $b) { return $b['relevance'] <=> $a['relevance'] ?: $a['rank'] <=> $b['rank'] ?: $a['links'] <=> $b['links']; });
        $out['suggestions'] = array_slice($rows, 0, $limit);
        return $out;
    }

    // ---- apply / undo ----

    /**
     * Adds the chosen links. A post that changed since the suggestion is checked again from scratch.
     *
     * @param array $items list of array(source_id, spot{token,offset,anchor}, hash)
     */
    public static function apply($type, $id, array $items, $by = 'manual') {
        $target = self::target($type, $id);
        if (is_wp_error($target)) return $target;
        $host = OrphanAudit::home_host();
        list(, $max_links) = SeoStandards::internal_range(Settings::KIND_ARTICLE);
        $seo = new SeoMetaService();
        $results = array();
        $applied = 0;
        foreach (array_slice($items, 0, 20) as $item) {
            $sid = isset($item['source_id']) ? (int) $item['source_id'] : 0;
            $post = $sid ? get_post($sid) : null;
            if (!$post || $post->post_status !== 'publish' || !current_user_can('edit_post', $sid)) {
                $results[] = array('source_id' => $sid, 'ok' => false, 'message' => 'مقاله در دسترس نیست.');
                continue;
            }
            $html = $post->post_content;
            $spot = isset($item['spot']) && is_array($item['spot']) ? array('token' => (int) ($item['spot']['token'] ?? -1), 'offset' => (int) ($item['spot']['offset'] ?? -1), 'anchor' => (string) ($item['spot']['anchor'] ?? '')) : null;
            // the anchor must be one of the page's own phrases, never free text from the request
            if ($spot && !self::is_target_phrase($spot['anchor'], $target['phrases'])) $spot = null;
            if (!$spot || !isset($item['hash']) || $item['hash'] !== md5($html)) {
                // the article changed: look again with the same rules
                $r = HtmlLinkText::find($html, $target['phrases'], array('target_key' => $target['key'], 'home_host' => $host, 'avoid' => array_filter(array($seo->read_post_seo($sid)['focuskw'])), 'max_total' => (int) $max_links, 'limit' => 1));
                $spot = $r['spots'] ? $r['spots'][0] : null;
                if (!$spot) {
                    $results[] = array('source_id' => $sid, 'ok' => false, 'message' => $r['reason'] === 'already_linked' ? 'این مقاله از قبل به صفحه لینک دارد.' : 'جای مناسبی در متن فعلی مقاله پیدا نشد.');
                    continue;
                }
            }
            $new = HtmlLinkText::insert($html, $spot, $target['url']);
            if ($new === null || $new === $html) {
                $results[] = array('source_id' => $sid, 'ok' => false, 'message' => 'متن مقاله تغییر کرده است؛ دوباره پیشنهاد بگیرید.');
                continue;
            }
            $saved = wp_update_post(wp_slash(array('ID' => $sid, 'post_content' => $new)), true);
            if (is_wp_error($saved)) {
                $results[] = array('source_id' => $sid, 'ok' => false, 'message' => $saved->get_error_message());
                continue;
            }
            (new \Khazan\Services\Scan\ScannerService())->scan_item($sid, $post->post_type);
            self::log(array('source_id' => $sid, 'source_title' => get_the_title($sid), 'target_type' => $target['type'], 'target_id' => $target['id'], 'target_title' => $target['title'], 'url' => $target['url'], 'anchor' => $spot['anchor'], 'by' => $by));
            $results[] = array('source_id' => $sid, 'ok' => true, 'anchor' => $spot['anchor']);
            $applied++;
        }
        if ($applied) OrphanAudit::mark_linked($target['type'], $target['id']);
        if ($applied && !taxonomy_exists($target['type'])) {
            $q = self::queue();
            if (isset($q[(string) $target['id']])) {
                $q[(string) $target['id']]['pending'] = 0;
                $q[(string) $target['id']]['applied'] += $applied;
                update_option(self::OPT_QUEUE, $q, false);
            }
        }
        return array('applied' => $applied, 'results' => $results);
    }

    /** Pure: is the anchor (in any spelling) one of the phrases? */
    public static function is_target_phrase($anchor, array $phrases) {
        $a = HtmlLinkText::norm_phrase($anchor);
        foreach ($phrases as $p) if ($a !== '' && HtmlLinkText::norm_phrase($p) === $a) return true;
        return false;
    }

    private static function log(array $entry) {
        $log = self::get_log();
        $entry['id'] = wp_generate_password(10, false);
        $entry['at'] = current_time('mysql');
        $log[] = $entry;
        update_option(self::OPT_LOG, array_slice($log, -self::LOG_MAX), false);
    }

    public static function get_log() {
        $l = get_option(self::OPT_LOG, array());
        return is_array($l) ? $l : array();
    }

    /** Pure: removes one link we added (exact href and anchor) and keeps its text. */
    public static function unlink_html($html, $url, $anchor) {
        $pattern = '#<a href="' . preg_quote(htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), '#') . '">' . preg_quote($anchor, '#') . '</a>#u';
        $new = preg_replace($pattern, str_replace(array('\\', '$'), array('\\\\', '\\$'), $anchor), $html, 1, $count);
        return $count ? $new : null;
    }

    public static function undo($log_id) {
        $log = self::get_log();
        foreach ($log as $i => $e) {
            if ($e['id'] !== $log_id) continue;
            $post = get_post((int) $e['source_id']);
            if (!$post || !current_user_can('edit_post', $post->ID)) return new \WP_Error('khazan_undo', 'مقاله در دسترس نیست.', array('status' => 404));
            $new = self::unlink_html($post->post_content, $e['url'], $e['anchor']);
            if ($new === null) return new \WP_Error('khazan_undo', 'این لینک در متن فعلی پیدا نشد (شاید دستی تغییر کرده است).', array('status' => 409));
            $saved = wp_update_post(wp_slash(array('ID' => $post->ID, 'post_content' => $new)), true);
            if (is_wp_error($saved)) return $saved;
            (new \Khazan\Services\Scan\ScannerService())->scan_item($post->ID, $post->post_type);
            array_splice($log, $i, 1);
            update_option(self::OPT_LOG, $log, false);
            return array('undone' => true);
        }
        return new \WP_Error('khazan_undo', 'این مورد در گزارش نیست.', array('status' => 404));
    }

    /** Latest published articles with their incoming links (from the link index) and any pending suggestions. */
    public static function recent($days = 60) {
        $posts = get_posts(array('post_type' => Settings::blog_post_type(), 'post_status' => 'publish', 'posts_per_page' => 20, 'date_query' => array(array('after' => $days . ' days ago')), 'orderby' => 'date', 'order' => 'DESC'));
        $ids = wp_list_pluck($posts, 'ID');
        $counts = $ids ? (new \Khazan\Repository\LinkRepository())->inbound_counts($ids, Settings::blog_post_type()) : array();
        $queue = self::queue();
        $out = array();
        foreach ($posts as $p) {
            $out[] = array(
                'id' => $p->ID, 'type' => $p->post_type, 'title' => html_entity_decode(get_the_title($p), ENT_QUOTES, 'UTF-8'), 'url' => get_permalink($p),
                'date' => get_the_date('Y-m-d', $p), 'inbound' => isset($counts[$p->ID]) ? $counts[$p->ID] : 0,
                'pending' => isset($queue[(string) $p->ID]) ? (int) $queue[(string) $p->ID]['pending'] : 0,
            );
        }
        return $out;
    }
}
