<?php
/**
 * Review & repair of stored content (products, product categories, blog articles).
 *
 * 1. Free: ContentFixer applies the plugin's own guards (links, anchors, Yoast keyphrase / passive rules, copy polish);
 *    for products the focus keyphrase is re-derived (whole model name, unique among siblings).
 * 2. Optional AI: only the plain paragraphs / list items are sent with the remaining audit failures; the model returns
 *    small text patches, never a new text. The AI result is kept only when it leaves fewer failures.
 * Every change is previewed first or applied with a backup that ContentUpdater::restore() puts back.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Fix;

use Khazan\Core\Settings;
use Khazan\Modules\Search\ContentUpdater;
use Khazan\Repository\ContentTargetRepository;
use Khazan\Services\Content\ContentAudit;
use Khazan\Services\Content\ContentFixer;
use Khazan\Services\Seo\KeyphraseExtractor;
use Khazan\Services\Seo\SeoMetaService;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentFixService {

    const DRAFT    = 'khazan_fix_draft_';
    const PER_PAGE = 20;
    const AI_MEMO  = '_khazan_fix_ai_hash';

    /** Audit failures a text patch can fix; link counts, spacing, length and meta never go to the model (it would only cost credit). */
    const AI_FIXABLE = array('density_low', 'density_high', 'passive_high', 'subheading_keyword_low', 'subheading_keyword_high', 'keyword_not_in_first', 'keyword_not_in_heading', 'keyword_h2_many', 'section_long');

    /** @var ContentTargetRepository */
    private $targets;
    /** @var SeoMetaService */
    private $seo;

    public function __construct(ContentTargetRepository $targets = null, SeoMetaService $seo = null) {
        $this->seo = $seo ?: new SeoMetaService();
        $this->targets = $targets ?: new ContentTargetRepository($this->seo);
    }

    public static function kinds() {
        $out = array(Settings::KIND_ARTICLE);
        if (Settings::kind_available(Settings::KIND_PRODUCT)) array_unshift($out, Settings::KIND_PRODUCT, Settings::KIND_CATEGORY);
        return $out;
    }

    // ---- loading ----

    /** @return array|\WP_Error kind, id, type ('post'|'term'), object (post type / taxonomy), title, url, html, seo */
    public function load($kind, $id) {
        $id = absint($id);
        if (!in_array($kind, self::kinds(), true) || !$id) return new \WP_Error('khazan_fix', 'صفحه انتخاب‌شده معتبر نیست.', array('status' => 400));
        if ($kind === Settings::KIND_CATEGORY) {
            $tax = Settings::category_taxonomy();
            $term = get_term($id, $tax);
            if (!$term || is_wp_error($term)) return new \WP_Error('khazan_fix', 'دسته‌بندی پیدا نشد.', array('status' => 404));
            $link = get_term_link($term);
            return array('kind' => $kind, 'id' => $id, 'type' => 'term', 'object' => $tax, 'title' => $term->name, 'url' => is_wp_error($link) ? '' : $link,
                'html' => (string) $term->description, 'seo' => $this->seo->read_term_seo($tax, $id));
        }
        $post = get_post($id);
        $want = $kind === Settings::KIND_PRODUCT ? Settings::product_post_type() : Settings::blog_post_type();
        if (!$post || $post->post_type !== $want) return new \WP_Error('khazan_fix', 'صفحه پیدا نشد.', array('status' => 404));
        if (!current_user_can('edit_post', $id)) return new \WP_Error('khazan_fix', 'اجازه ویرایش این صفحه را ندارید.', array('status' => 403));
        return array('kind' => $kind, 'id' => $id, 'type' => 'post', 'object' => $post->post_type, 'title' => get_the_title($id), 'url' => (string) get_permalink($id),
            'html' => (string) $post->post_content, 'seo' => $this->seo->read_post_seo($id));
    }

    /** Focus keyphrase the fix works with (products: re-derived, whole model name, unique among siblings). */
    private function focus(array $page) {
        $stored = trim((string) $page['seo']['focuskw']);
        if ($page['kind'] !== Settings::KIND_PRODUCT) return $stored;
        $focus = $this->targets->product_focus($page['id'], $stored, $page['title']);
        return $focus !== '' ? $focus : $stored;
    }

    private function host() {
        return strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    }

    /** Correct anchors of the linked products and the urls that must not move (everything that is not a product). */
    private function link_context($html) {
        $anchors = array(); $keep = array();
        if (!preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~is', (string) $html, $m)) return array($anchors, $keep);
        foreach (array_unique($m[2]) as $url) {
            $url = html_entity_decode(trim($url), ENT_QUOTES, 'UTF-8');
            $pid = $this->targets->product_id_from_url($url);
            if ($pid) $anchors[$url] = array('anchor' => $this->targets->product_link_anchor($pid), 'title' => get_the_title($pid));
            else $keep[] = $url;
        }
        return array($anchors, $keep);
    }

    private function audit($html, $focus, $kind) {
        $a = ContentAudit::analyze(\Khazan\Services\Content\Guard\WrapperGuard::unwrap($html), $focus, array('kind' => $kind, 'home_host' => $this->host()));
        return $a['failures'];
    }

    /** Free fix in memory: nothing is saved. */
    public function check(array $page) {
        $focus = $this->focus($page);
        $linked = $page['kind'] === Settings::KIND_PRODUCT ? $this->add_missing_links($page) : array('html' => $page['html'], 'added' => 0);
        list($anchors, $keep) = $this->link_context($linked['html']);
        $fixed = ContentFixer::fix($linked['html'], array('kind' => $page['kind'], 'focus' => $focus, 'home_host' => $this->host(), 'self_url' => $page['url'], 'anchors' => $anchors, 'keep' => $keep));
        if ($linked['added']) array_unshift($fixed['changes'], 'افزودن ' . $linked['added'] . ' لینک داخلی کم‌بود (دسته‌بندی و محصولات مرتبط)');
        $stored = trim((string) $page['seo']['focuskw']);
        $focus_changed = $focus !== '' && KeyphraseExtractor::key($focus) !== KeyphraseExtractor::key($stored);
        if ($focus_changed) array_unshift($fixed['changes'], 'کلمه کلیدی اصلی: «' . ($stored !== '' ? $stored : 'خالی') . '» ← «' . $focus . '»');
        return array(
            'html' => $fixed['html'], 'changes' => $fixed['changes'], 'focus' => $focus, 'focus_changed' => $focus_changed,
            'before' => $this->audit($page['html'], $stored !== '' ? $stored : $focus, $page['kind']),
            'after' => $this->audit($fixed['html'], $focus, $page['kind']),
        );
    }

    /**
     * A product with fewer internal links than the standard gets the missing ones (own category first, then related products
     * with the fewest inbound links) written into link-free body paragraphs.
     *
     * @return array{html:string,added:int}
     */
    private function add_missing_links(array $page) {
        $html = (string) $page['html'];
        $none = array('html' => $html, 'added' => 0);
        $min = (int) \Khazan\Services\Content\SeoStandards::get('links_product');
        $host = $this->host();
        $have = array();
        if (preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $m)) {
            foreach ($m[2] as $u) {
                $u = html_entity_decode(trim($u), ENT_QUOTES, 'UTF-8');
                $h = strtolower((string) wp_parse_url($u, PHP_URL_HOST));
                if ($u !== '' && $u[0] !== '#' && ($h === '' || $h === $host || $h === 'www.' . $host)) $have[rtrim($u, '/')] = true;
            }
        }
        $need = $min - count($have);
        if ($need <= 0) return $none;
        try {
            $plan = $this->targets->link_distributor()->plan($page['id'], '', array(), $need + 2);
        } catch (\Exception $e) { return $none; }
        $self = rtrim((string) $page['url'], '/');
        $add = array();
        foreach ((array) $plan['internal_links'] as $link) {
            $u = rtrim((string) (isset($link['url']) ? $link['url'] : ''), '/');
            if ($u === '' || $u === $self || isset($have[$u])) continue;
            $add[] = array('url' => $link['url'], 'anchor' => isset($link['anchor']) ? $link['anchor'] : '');
            if (count($add) >= $need) break;
        }
        if (!$add) return $none;
        $placed = \Khazan\Services\Links\LinkSpreader::place($html, $add, $host);
        return array('html' => $placed['html'], 'added' => count($add) - count($placed['missing']));
    }

    /** Remaining failures go to the AI as text patches on plain blocks only. */
    private function ai_pass(array $page, array $result, $instructions) {
        $instructions = trim((string) $instructions);
        $fixable = array_intersect_key((array) $result['after'], array_flip(self::AI_FIXABLE));
        if (!$fixable && $instructions === '') {
            if ($result['after']) $result['ai_note'] = 'ایرادهای باقی‌مانده با بازنویسی متن رفع نمی‌شوند، پس اعتباری مصرف نشد.';
            return $result;
        }
        // the same final text was already sent once: asking again would only spend credit
        $memo = md5($result['html']);
        if ($instructions === '' && $this->ai_memo($page) === $memo) {
            $result['ai_note'] = 'این متن قبلاً با هوش مصنوعی بررسی شده و تغییری نکرده است، پس دوباره ارسال نشد.';
            return $result;
        }
        $heads = (bool) preg_grep('/زیرعنوان/u', $fixable);   // headings go to the model only when the keyphrase is missing from them
        $segments = ContentFixer::segments($result['html'], $heads);
        if (!$segments) return $result;
        $brief = array_values($fixable);
        if ($instructions !== '') $brief[] = $instructions;
        $meta = array('title' => (string) $page['seo']['title'], 'desc' => (string) $page['seo']['desc'], 'focuskw' => $result['focus']);
        // same model as generation, the lowest reasoning level: a maintenance patch needs no deep thinking (reasoning tokens bill as output)
        \Khazan\Services\Ai\AvalaiHttpClient::force_reasoning('minimal');
        try {
            $ai = (new \Khazan\Services\Ai\AiService())->suggest_seo_repairs($segments, implode("\n", $brief), $meta, $page['type'] === 'term' ? 'term' : ($page['kind'] === Settings::KIND_PRODUCT ? 'product' : 'post'));
        } finally {
            \Khazan\Services\Ai\AvalaiHttpClient::force_reasoning(null);
        }
        if (is_wp_error($ai)) { $result['ai_error'] = $ai->get_error_message(); return $result; }
        if (empty($ai['patches'])) { $result['ai_patches'] = 0; $this->ai_memo($page, $memo); return $result; }
        $patched = ContentFixer::apply_patches($result['html'], $ai['patches']);
        // the patched text goes through the free guards again (a rewrite can bring back a passive word or the keyphrase)
        list($anchors, $keep) = $this->link_context($patched);
        $again = ContentFixer::fix($patched, array('kind' => $page['kind'], 'focus' => $result['focus'], 'home_host' => $this->host(), 'self_url' => $page['url'], 'anchors' => $anchors, 'keep' => $keep));
        $after = $this->audit($again['html'], $result['focus'], $page['kind']);
        if (count($after) > count($result['after'])) { $result['ai_patches'] = 0; $this->ai_memo($page, $memo); return $result; }
        $result['html'] = $again['html'];
        $result['after'] = $after;
        $result['ai_patches'] = count($ai['patches']);
        $result['changes'][] = 'بازنویسی ' . count($ai['patches']) . ' جمله یا بند معیوب با هوش مصنوعی';
        $this->ai_memo($page, md5($again['html']));
        return $result;
    }

    /** Hash of the text last sent to the AI for this page (read, or written when $set is given). */
    private function ai_memo(array $page, $set = null) {
        if ($page['type'] === 'term') {
            if ($set === null) return (string) get_term_meta($page['id'], self::AI_MEMO, true);
            update_term_meta($page['id'], self::AI_MEMO, $set);
        } else {
            if ($set === null) return (string) get_post_meta($page['id'], self::AI_MEMO, true);
            update_post_meta($page['id'], self::AI_MEMO, $set);
        }
        return $set;
    }

    /** Preview (nothing is saved): free fix, optionally the AI pass; the draft can be applied for a day. */
    public function preview($kind, $id, $use_ai = false, $instructions = '') {
        $page = $this->load($kind, $id);
        if (is_wp_error($page)) return $page;
        if (trim(wp_strip_all_tags($page['html'])) === '') return new \WP_Error('khazan_fix', 'این صفحه هنوز محتوایی ندارد؛ ابتدا محتوا تولید کنید.', array('status' => 400));
        if ($use_ai && Settings::avalai_api_key() === '') return new \WP_Error('khazan_fix', 'کلید AvalAI در تنظیمات هوش مصنوعی ثبت نشده است.', array('status' => 400));
        $result = $this->check($page);
        if ($use_ai) $result = $this->ai_pass($page, $result, (string) $instructions);
        $draft = array('kind' => $page['kind'], 'id' => $page['id'], 'type' => $page['type'], 'object' => $page['object'], 'hash' => md5($page['html']),
            'html' => $result['html'], 'focus' => $result['focus_changed'] ? $result['focus'] : '');
        $draft_id = wp_generate_password(12, false);
        set_transient(self::DRAFT . $draft_id, $draft, DAY_IN_SECONDS);
        return array(
            'draft_id' => $draft_id, 'kind' => $page['kind'], 'id' => $page['id'], 'title' => $page['title'], 'url' => $page['url'],
            'changes' => $result['changes'], 'before' => array_values($result['before']), 'after' => array_values($result['after']),
            'changed' => $result['html'] !== $page['html'] || $result['focus_changed'],
            'focus' => $result['focus'], 'html' => $result['html'],
            'ai_error' => isset($result['ai_error']) ? $result['ai_error'] : '', 'ai_note' => isset($result['ai_note']) ? $result['ai_note'] : '', 'ai_patches' => isset($result['ai_patches']) ? $result['ai_patches'] : null,
            'backup' => ContentUpdater::has_backup($page['type'], $page['id']),
        );
    }

    /** Saves a previewed draft with a backup of the current content. */
    public function apply($draft_id) {
        $draft_id = preg_replace('/[^A-Za-z0-9]/', '', (string) $draft_id);
        $draft = get_transient(self::DRAFT . $draft_id);
        if (!is_array($draft)) return new \WP_Error('khazan_fix', 'این پیش‌نمایش منقضی شده است؛ دوباره بررسی کنید.', array('status' => 404));
        $page = $this->load($draft['kind'], $draft['id']);
        if (is_wp_error($page)) return $page;
        if (md5($page['html']) !== $draft['hash']) return new \WP_Error('khazan_fix', 'محتوای صفحه بعد از بررسی تغییر کرده است؛ دوباره بررسی کنید.', array('status' => 409));
        $saved = $this->save($page, $draft['html'], $draft['focus']);
        if (is_wp_error($saved)) return $saved;
        delete_transient(self::DRAFT . $draft_id);
        return array('applied' => true, 'backup' => ContentUpdater::has_backup($page['type'], $page['id']));
    }

    /** One step of the bulk run: free fix (and the AI pass when asked) saved right away with a backup. */
    public function run($kind, $id, $use_ai = false) {
        $page = $this->load($kind, $id);
        if (is_wp_error($page)) return $page;
        if (trim(wp_strip_all_tags($page['html'])) === '') return array('id' => $page['id'], 'title' => $page['title'], 'skipped' => 'بدون محتوا', 'changes' => array(), 'before' => 0, 'after' => 0);
        $result = $this->check($page);
        if ($use_ai && $result['after'] && Settings::avalai_api_key() !== '') $result = $this->ai_pass($page, $result, '');
        $changed = $result['html'] !== $page['html'] || $result['focus_changed'];
        if ($changed) {
            $saved = $this->save($page, $result['html'], $result['focus_changed'] ? $result['focus'] : '');
            if (is_wp_error($saved)) return $saved;
        }
        return array('id' => $page['id'], 'title' => $page['title'], 'changed' => $changed, 'changes' => $result['changes'],
            'before' => count($result['before']), 'after' => count($result['after']), 'remaining' => array_values($result['after']),
            'ai_error' => isset($result['ai_error']) ? $result['ai_error'] : '', 'ai_note' => isset($result['ai_note']) ? $result['ai_note'] : '');
    }

    private function save(array $page, $html, $focus) {
        if ($html !== $page['html']) {
            if ($page['type'] === 'term') {
                update_term_meta($page['id'], ContentUpdater::BACKUP, array('at' => current_time('mysql'), 'html' => $page['html']));
                $r = wp_update_term($page['id'], $page['object'], array('description' => $html));
            } else {
                update_post_meta($page['id'], ContentUpdater::BACKUP, wp_slash(array('at' => current_time('mysql'), 'html' => $page['html'])));
                $r = wp_update_post(wp_slash(array('ID' => $page['id'], 'post_content' => $html)), true);
            }
            if (is_wp_error($r)) return $r;
        }
        if ($focus !== '' && $page['type'] === 'post') {
            $seo = $page['seo'];
            $seo['focuskw'] = $focus;
            $seo['focuskw_exact'] = KeyphraseExtractor::count_words($focus) > Settings::KEYPHRASE_MAX_WORDS;
            if (trim((string) $seo['title']) !== '') $seo['title'] = \Khazan\Services\Seo\SeoMetaRules::fit_title($seo['title'], $focus, Settings::brand_name());
            $this->seo->write_post_seo($page['id'], $seo);
        }
        if ($page['type'] === 'post' && class_exists('\Khazan\Services\Scan\ScannerService')) (new \Khazan\Services\Scan\ScannerService())->scan_item($page['id'], $page['object']);
        return true;
    }

    public function restore($kind, $id) {
        $page = $this->load($kind, $id);
        if (is_wp_error($page)) return $page;
        return ContentUpdater::restore($page['type'], $page['object'], $page['id']);
    }

    // ---- list ----

    /** One page of items with the number of audit failures (before any fix). */
    public function items($kind, $page_no = 1, $search = '') {
        if (!in_array($kind, self::kinds(), true)) return new \WP_Error('khazan_fix', 'نوع محتوا معتبر نیست.', array('status' => 400));
        $page_no = max(1, (int) $page_no);
        $rows = array(); $total = 0;
        if ($kind === Settings::KIND_CATEGORY) {
            $args = array('taxonomy' => Settings::category_taxonomy(), 'hide_empty' => false, 'search' => $search, 'orderby' => 'name', 'fields' => 'ids');
            $all = get_terms($args);
            if (is_wp_error($all)) return $all;
            $total = count($all);
            $ids = array_slice($all, ($page_no - 1) * self::PER_PAGE, self::PER_PAGE);
        } else {
            $q = new \WP_Query(array('post_type' => $kind === Settings::KIND_PRODUCT ? Settings::product_post_type() : Settings::blog_post_type(),
                'post_status' => array('publish', 'draft', 'pending', 'private'), 'posts_per_page' => self::PER_PAGE, 'paged' => $page_no,
                'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids', 's' => $search, 'no_found_rows' => false));
            $ids = $q->posts; $total = (int) $q->found_posts;
        }
        foreach ($ids as $id) {
            $page = $this->load($kind, $id);
            if (is_wp_error($page)) continue;
            $empty = trim(wp_strip_all_tags($page['html'])) === '';
            $focus = trim((string) $page['seo']['focuskw']);
            $fails = $empty ? array() : $this->audit($page['html'], $focus, $kind);
            $rows[] = array('id' => (int) $id, 'title' => $page['title'], 'url' => $page['url'], 'empty' => $empty, 'focus' => $focus,
                'issues' => count($fails), 'issue_list' => array_values($fails), 'backup' => ContentUpdater::has_backup($page['type'], $id));
        }
        return array('items' => $rows, 'total' => $total, 'page' => $page_no, 'pages' => (int) max(1, ceil($total / self::PER_PAGE)));
    }

    /** All ids of a kind (for the bulk run), newest first. */
    public function all_ids($kind) {
        if ($kind === Settings::KIND_CATEGORY) {
            $ids = get_terms(array('taxonomy' => Settings::category_taxonomy(), 'hide_empty' => false, 'fields' => 'ids'));
            return is_wp_error($ids) ? array() : array_map('intval', $ids);
        }
        return array_map('intval', get_posts(array('post_type' => $kind === Settings::KIND_PRODUCT ? Settings::product_post_type() : Settings::blog_post_type(),
            'post_status' => array('publish', 'draft', 'pending', 'private'), 'posts_per_page' => 5000, 'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids')));
    }
}
