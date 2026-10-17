<?php
/**
 * Internal-link planner for blog articles (mesh architecture).
 *
 * The budget follows the word count (ArticleLinkBudget) and is filled in this order: 1 category, 1 product,
 * 1 article, brand page (max 1), 2nd product, 2nd article - unavailable kinds are skipped, so the result is at
 * most 1 category + 2 products + 2 articles + 1 page. Products and articles come from a GLOBAL pool: the targets
 * with the fewest inbound links (database + links assigned in earlier generations) win; topical fit only ranks
 * equal counts. Anchors are fixed here: product = 4-word keyphrase, article = target's exact focus keyword,
 * category = prefix + category name, page = brand name. LinkGuard locks them afterwards.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

use Khazan\Core\Settings;
use Khazan\Services\Seo\KeyphraseExtractor;

if (!defined('ABSPATH')) {
    exit;
}

final class ArticleLinkDistributor {

    const RUN_ID = 'article-pool';

    const SLOT_ORDER = array('category', 'product', 'article', 'page', 'product', 'article');

    /** @var ArticleLinkSource */
    private $source;

    /** @var AssignmentLedger */
    private $ledger;

    public function __construct(ArticleLinkSource $source, AssignmentLedger $ledger) {
        $this->source = $source;
        $this->ledger = $ledger;
    }

    public static function wordpress() {
        return new self(new WordPressArticleLinkSource(), new TransientAssignmentLedger());
    }

    /**
     * Returns $params with the planned internal_links (user-supplied links are kept and use up budget) and link_budget.
     */
    public function apply(array $params) {
        $manual = array();
        foreach (isset($params['internal_links']) ? (array) $params['internal_links'] : array() as $link) {
            if (!empty($link['url'])) $manual[] = $link;
        }
        $budget = ArticleLinkBudget::for_words(isset($params['word_count']) ? $params['word_count'] : 0);
        $skip = array_map(function ($link) { return untrailingslashit($link['url']); }, $manual);

        $params['internal_links'] = array_merge($manual, $this->plan($params, max(0, $budget - count($manual)), $skip));
        $params['link_budget']    = $budget;
        return $params;
    }

    /**
     * @return array[] links {anchor, url, type, required, locked}
     */
    public function plan(array $params, $slots, array $skip_urls = array()) {
        $slots = (int) $slots;
        if ($slots < 1) return array();

        $topic    = self::tokens((isset($params['h1_title']) ? $params['h1_title'] : '') . ' ' . (isset($params['focus_keyword']) ? $params['focus_keyword'] : '') . ' ' . (isset($params['long_tail']) ? $params['long_tail'] : ''));
        $state    = $this->ledger->load(self::RUN_ID);
        $assigned = $state['assigned'];
        $skip     = array_flip($skip_urls);

        $category = $this->match_category($topic);
        $pools = array(
            'category' => $category ? array($category) : array(),
            'product'  => $this->ranked($this->source->product_candidates($assigned, $category ? (int) $category['id'] : 0), $topic, $assigned, $category, $skip),
            'article'  => $this->ranked($this->source->article_candidates($assigned), $topic, $assigned, null, $skip),
            'page'     => array(),
        );
        $brand = $this->source->brand_page();
        if ($brand && !empty($brand['url']) && !empty($brand['anchor']) && !isset($skip[untrailingslashit($brand['url'])])) $pools['page'] = array($brand);

        $links = array();
        $anchors = array();
        foreach (self::SLOT_ORDER as $type) {
            if (count($links) >= $slots) break;
            while ($pools[$type]) {
                $entry = array_shift($pools[$type]);
                $link = $this->link($type, $entry, $params);
                $key = mb_strtolower($link['anchor']);
                if ($link['anchor'] === '' || isset($anchors[$key])) continue;
                $anchors[$key] = true;
                $links[] = $link;
                if ($type === 'product' || $type === 'article') {
                    $assigned[$entry['id']] = (isset($assigned[$entry['id']]) ? $assigned[$entry['id']] : 0) + 1;
                }
                break;
            }
        }

        $state['assigned'] = $assigned;
        $this->ledger->save(self::RUN_ID, $state);
        return $links;
    }

    private function link($type, array $entry, array $params) {
        if ($type === 'category') {
            $seed = abs(crc32(isset($params['focus_keyword']) ? (string) $params['focus_keyword'] : ''));
            return array('anchor' => SmartLinkDistributor::compose_category_anchor($entry['name'], $seed), 'url' => $entry['url'], 'required' => true, 'type' => 'category', 'category_name' => $entry['name']);
        }
        if ($type === 'page') {
            return array('anchor' => trim($entry['anchor']), 'url' => $entry['url'], 'locked' => true, 'required' => false, 'type' => 'page');
        }
        // product: the product keyphrase formula; article: the target's focus keyword exactly (only the 4-word ceiling applies)
        $anchor = $type === 'product' ? KeyphraseExtractor::cap($entry['anchor']) : KeyphraseExtractor::limit($entry['anchor'], Settings::KEYPHRASE_MAX_WORDS);
        return array('anchor' => $anchor, 'url' => $entry['url'], 'locked' => true, 'required' => true, 'type' => $type);
    }

    /** Category whose name shares the most words with the topic (null = no topical match). */
    private function match_category(array $topic) {
        $best = null;
        $best_ratio = 0.0;
        foreach ($this->source->categories() as $category) {
            $name = self::tokens($category['name']);
            if (!$name || empty($category['url'])) continue;
            $ratio = count(array_intersect($name, $topic)) / count($name);
            if ($ratio > $best_ratio || ($ratio === $best_ratio && $best !== null && $ratio > 0 && $category['id'] < $best['id'])) {
                $best = $category;
                $best_ratio = $ratio;
            }
        }
        return $best_ratio > 0 ? $best : null;
    }

    /** Fewest inbound links first (database + assigned); then topical fit; then id. */
    private function ranked(array $candidates, array $topic, array $assigned, $category, array $skip) {
        $rows = array();
        foreach ($candidates as $c) {
            if (empty($c['url']) || trim((string) $c['anchor']) === '' || isset($skip[untrailingslashit($c['url'])])) continue;
            $c['effective'] = (isset($c['inbound']) ? (int) $c['inbound'] : 0) + (isset($assigned[$c['id']]) ? (int) $assigned[$c['id']] : 0);
            $c['fit'] = count(array_intersect(self::tokens($c['title']), $topic))
                + ($category && !empty($c['category_ids']) && in_array((int) $category['id'], array_map('intval', $c['category_ids']), true) ? 2 : 0);
            $rows[] = $c;
        }
        usort($rows, function ($a, $b) {
            if ($a['effective'] !== $b['effective']) return $a['effective'] < $b['effective'] ? -1 : 1;
            if ($a['fit'] !== $b['fit']) return $a['fit'] > $b['fit'] ? -1 : 1;
            return $a['id'] < $b['id'] ? -1 : 1;
        });
        return $rows;
    }

    private static function tokens($text) {
        return array_values(array_unique(array_map('mb_strtolower', array_filter(KeyphraseExtractor::words($text), function ($t) {
            return mb_strlen($t) > 1;
        }))));
    }
}
