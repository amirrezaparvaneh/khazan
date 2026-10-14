<?php
/**
 * Validates and normalizes the content-generator payload (outline / full content):
 * sanitizes fields, resolves the target, builds the internal-link map.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

use Khazan\Services\Links\OrphanFinder;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentGenerationRequest {

    /** Orphan link priority: product category, product, blog post, page. */
    private static $orphan_priorities = array('product_cat' => 1, 'product' => 2, 'post' => 3, 'page' => 4);

    /** @var array */
    private $input;

    public function __construct($input) {
        $this->input = is_array($input) ? $input : array();
    }

    /**
     * @return array|\WP_Error Normalized parameters for the AI services.
     */
    public function validated() {
        $kind   = \WILA_Content_Targets::normalize_kind($this->value('content_kind', ''));
        $target = $this->resolve_target($kind);
        if (is_wp_error($target)) return $target;

        $links            = $this->internal_links();
        $use_orphan_links = !empty($this->input['use_orphan_links']);

        if ($use_orphan_links && empty($links)) {
            $links = $this->append_orphan_links($links, $target, $kind);
        }
        if ($target) {
            $links = $this->without_target_url($links, $target);
        }

        return $this->build($kind, $target, $links, $use_orphan_links);
    }

    private function value($key, $default) {
        return isset($this->input[$key]) ? $this->input[$key] : $default;
    }

    private function text($key) {
        return isset($this->input[$key]) ? sanitize_text_field($this->input[$key]) : '';
    }

    /**
     * @return array|null|\WP_Error
     */
    private function resolve_target($kind) {
        if (!\WILA_Content_Targets::is_target_kind($kind)) return null;
        return \WILA_Content_Targets::get_context($kind, $this->value('target_id', 0));
    }

    private function internal_links() {
        if (!isset($this->input['internal_links']) || !is_array($this->input['internal_links'])) return array();
        return array_map(function ($link) {
            return array('anchor' => isset($link['anchor']) ? sanitize_text_field($link['anchor']) : '', 'url' => isset($link['url']) ? esc_url_raw($link['url']) : '');
        }, $this->input['internal_links']);
    }

    private function append_orphan_links(array $links, $target, $kind) {
        $scanner = new \Khazan\Services\Scan\ScannerService();
        $orphans = OrphanFinder::calculate($scanner->get_all_scannable_items());
        $priorities = self::$orphan_priorities;
        usort($orphans['list'], function ($a, $b) use ($priorities) {
            $a_priority = isset($priorities[$a['type']]) ? $priorities[$a['type']] : 3;
            $b_priority = isset($priorities[$b['type']]) ? $priorities[$b['type']] : 3;
            return $a_priority === $b_priority ? strcasecmp($a['title'], $b['title']) : $a_priority - $b_priority;
        });
        foreach ($orphans['list'] as $orphan) {
            if ($target && (int) $orphan['id'] === (int) $target['id'] && $orphan['type'] === $kind) continue;
            if (!empty($orphan['url']) && !empty($orphan['title'])) {
                // محصول با کلیدواژه کانونی: انکر قفل‌شده؛ بدون آن، نام کامل محصول بدون دستکاری
                $focus_anchor = $orphan['type'] === \WILA_Content_Targets::product_post_type() ? \WILA_Content_Targets::product_link_anchor($orphan['id']) : '';
                $anchor = $focus_anchor !== '' ? $focus_anchor : $orphan['title'];
                $links[] = array('anchor' => $anchor, 'url' => esc_url_raw($orphan['url']), 'orphan' => true, 'locked' => $focus_anchor !== '');
            }
        }
        return $links;
    }

    private function without_target_url(array $links, array $target) {
        return array_values(array_filter($links, function ($link) use ($target) {
            return untrailingslashit($link['url']) !== untrailingslashit($target['url']);
        }));
    }

    private function build($kind, $target, array $links, $use_orphan_links) {
        $result = array(
            'content_kind'     => $kind,
            'target_id'        => $target ? $target['id'] : 0,
            'h1_title'         => $target ? $target['title'] : $this->text('h1_title'),
            'focus_keyword'    => $this->text('focus_keyword'),
            'long_tail'        => $this->text('long_tail'),
            'word_count'       => isset($this->input['word_count']) ? max(300, min(10000, absint($this->input['word_count']))) : 1200,
            'content_type'     => \WILA_Content_Targets::kind_label($kind),
            'funnel_stage'     => $this->text('funnel_stage'),
            'description'      => isset($this->input['description']) ? sanitize_textarea_field($this->input['description']) : '',
            'internal_links'   => $links,
            'use_orphan_links' => $use_orphan_links,
        );
        // The homepage link is a blog-article feature only.
        if ($target) {
            $result['target'] = $target;
        } else {
            $result['homepage_keywords'] = get_option('wila_homepage_keywords', '');
            $result['homepage_url']      = home_url('/');
        }
        return $result;
    }
}
