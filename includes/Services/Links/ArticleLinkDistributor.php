<?php
/**
 * Internal-link planner for blog articles (mesh architecture).
 *
 * The budget follows the word count (ArticleLinkBudget) and is filled in this order: 1 category, 1 product,
 * 1 article, brand page (max 1), 2nd product, 2nd article - unavailable kinds are skipped, so the result is at
 * most 1 category + 3 products + 3 articles + 1 page. Products and articles come from a GLOBAL pool: the targets
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

    const SLOT_ORDER = array('category', 'product', 'article', 'page', 'product', 'article', 'product', 'article');

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

        // distinctive topic words only: «چرم»، «خرید»، «راهنما» match every page and must never decide relevance
        $topic    = self::tokens((isset($params['h1_title']) ? $params['h1_title'] : '') . ' ' . (isset($params['focus_keyword']) ? $params['focus_keyword'] : ''));
        $state    = $this->ledger->load(self::RUN_ID);
        $assigned = $state['assigned'];
        $skip     = array_flip($skip_urls);

        $categories = $this->source->categories();
        $matched    = $this->matched_categories($categories, $topic);
        $related    = $this->related_category_ids($categories, $matched);
        $category   = $matched ? $matched[0] : null;
        $matched_ids = array_map(function ($c) { return (int) $c['id']; }, $matched);
        $pools = array(
            'category' => $category ? array($category) : array(),
            'product'  => $this->ranked($this->source->product_candidates($assigned, $matched_ids, $related, $topic), $topic, $assigned, $matched_ids, $related, $skip),
            'article'  => $this->ranked($this->source->article_candidates($assigned), $topic, $assigned, array(), array(), $skip),
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

    /**
     * Categories whose distinctive name words occur in the topic, best match first (ties: lower id).
     *
     * @return array[]
     */
    private function matched_categories(array $categories, array $topic) {
        $rows = array();
        foreach ($categories as $category) {
            $name = self::tokens($category['name']);
            if (!$name || empty($category['url'])) continue;
            $ratio = count(array_intersect($name, $topic)) / count($name);
            if ($ratio > 0) $rows[] = array('ratio' => $ratio, 'category' => $category);
        }
        usort($rows, function ($a, $b) {
            return $a['ratio'] !== $b['ratio'] ? ($a['ratio'] > $b['ratio'] ? -1 : 1) : ($a['category']['id'] < $b['category']['id'] ? -1 : 1);
        });
        return array_map(function ($row) { return $row['category']; }, $rows);
    }

    /** Parent, siblings and children of the matched categories (the complementary Tier 2 groups). */
    private function related_category_ids(array $categories, array $matched) {
        $matched_ids = array_map(function ($c) { return (int) $c['id']; }, $matched);
        $related = array();
        foreach ($matched as $m) {
            $parent = isset($m['parent']) ? (int) $m['parent'] : 0;
            if ($parent) $related[$parent] = true;
            foreach ($categories as $c) {
                $c_parent = isset($c['parent']) ? (int) $c['parent'] : 0;
                if ($c_parent && ($c_parent === (int) $m['id'] || $c_parent === $parent)) $related[(int) $c['id']] = true;
            }
        }
        return array_values(array_diff(array_keys($related), $matched_ids));
    }

    /**
     * Relevance first, balance inside it:
     *   Tier 1 = matched category or a distinctive topic word in the title, Tier 2 = related category, Tier 3 = anything.
     * Inside a tier: fewest inbound links (database + assigned), then topical fit, then id. A lower tier is used
     * only when the higher tiers have no candidate left.
     */
    private function ranked(array $candidates, array $topic, array $assigned, array $matched_ids, array $related_ids, array $skip) {
        $rows = array();
        foreach ($candidates as $c) {
            if (empty($c['url']) || trim((string) $c['anchor']) === '' || isset($skip[untrailingslashit($c['url'])])) continue;
            $categories = !empty($c['category_ids']) ? array_map('intval', $c['category_ids']) : array();
            $overlap = count(array_intersect(self::tokens($c['title'] . ' ' . $c['anchor']), $topic));
            if ($overlap > 0 || array_intersect($categories, $matched_ids)) $tier = 1;
            elseif (array_intersect($categories, $related_ids)) $tier = 2;
            else $tier = 3;
            $c['tier'] = $tier;
            $c['effective'] = (isset($c['inbound']) ? (int) $c['inbound'] : 0) + (isset($assigned[$c['id']]) ? (int) $assigned[$c['id']] : 0);
            $c['fit'] = $overlap;
            $rows[] = $c;
        }
        usort($rows, function ($a, $b) {
            foreach (array('tier', 'effective') as $field) {
                if ($a[$field] !== $b[$field]) return $a[$field] < $b[$field] ? -1 : 1;
            }
            if ($a['fit'] !== $b['fit']) return $a['fit'] > $b['fit'] ? -1 : 1;
            return $a['id'] < $b['id'] ? -1 : 1;
        });
        return $rows;
    }

    /** Distinctive words: normalised, without generic shop / guide / material / audience words. */
    private static function tokens($text) {
        static $stop = null;
        if ($stop === null) {
            $lists = Settings::keyphrase_words();
            $generic = array_merge($lists['fillers'], $lists['materials'], $lists['audience'], $lists['conditional'], Settings::ARTICLE_GENERIC_WORDS);
            $stop = array_flip(array_map(array('Khazan\\Services\\Seo\\KeyphraseExtractor', 'key'), $generic));
        }
        $out = array();
        foreach (KeyphraseExtractor::words($text) as $word) {
            $key = KeyphraseExtractor::key($word);
            if (mb_strlen($key) > 1 && !isset($stop[$key])) $out[$key] = true;
        }
        return array_keys($out);
    }
}
