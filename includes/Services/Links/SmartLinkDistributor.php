<?php
/**
 * Internal-link planner for product content.
 *
 * - Category link: always the product's own primary category.
 * - Product links: a GLOBAL pool (every published product, see GlobalLinkPool); relatedness (categories, title
 *   similarity) only ranks candidates whose inbound counts are equal, so no product stays without inbound links.
 * - Fair load balancing: candidates with the fewest inbound links (database + links already assigned in
 *   the current run) always win; ties prefer products whose own row is still pending in the queue
 *   (they have fewer future chances to be linked), then similarity, then id. Every assignment is
 *   recorded so the next row sees the updated counts.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

if (!defined('ABSPATH')) {
    exit;
}

final class SmartLinkDistributor {

    const DEFAULT_LIMIT = 2;

    /** Minimum title similarity (Jaccard) that makes a candidate related without sharing a category. */
    const TITLE_RELATED_THRESHOLD = 0.34;

    /** @var LinkCandidateProvider */
    private $provider;

    /** @var AssignmentLedger */
    private $ledger;

    public function __construct(LinkCandidateProvider $provider, AssignmentLedger $ledger) {
        $this->provider = $provider;
        $this->ledger   = $ledger;
    }

    /**
     * Plans the internal links of one product row.
     *
     * @param int    $product_id
     * @param string $run_id    Bulk-run identifier; empty means a stateless plan (database counts only).
     * @param int[]  $queue_ids Every product of the current queue (to know which rows are still pending).
     * @return array{category:?array,related:array,internal_links:array}
     */
    public function plan($product_id, $run_id = '', array $queue_ids = array(), $limit = self::DEFAULT_LIMIT) {
        $product_id = (int) $product_id;
        $product = $this->provider->product($product_id);
        if (!$product) {
            return array('category' => null, 'related' => array(), 'internal_links' => array());
        }

        $state = $run_id !== '' ? $this->ledger->load($run_id) : array('assigned' => array(), 'done' => array());
        $state['done'][$product_id] = true;

        $category = $this->own_category($product);
        if ($category) {
            $category['anchor'] = $this->category_anchor($category['name'], $product);
        }
        $related  = $this->pick_related($product, $state, $queue_ids, (int) $limit);

        foreach ($related as $entry) {
            $state['assigned'][$entry['id']] = (isset($state['assigned'][$entry['id']]) ? $state['assigned'][$entry['id']] : 0) + 1;
        }
        if ($run_id !== '') {
            $this->ledger->save($run_id, $state);
        }

        $links = array();
        if ($category) {
            $links[] = array('anchor' => $category['anchor'], 'url' => $category['url'], 'required' => true, 'type' => 'category', 'category_name' => $category['name']);
        }
        foreach ($related as $entry) {
            $links[] = array('anchor' => \Khazan\Services\Seo\KeyphraseExtractor::cap($entry['anchor']), 'url' => $entry['url'], 'locked' => true, 'required' => true, 'type' => 'product');
        }
        return array('category' => $category, 'related' => $related, 'internal_links' => $links);
    }

    /**
     * Anchor of the category link: a descriptive prefix + the REAL category name («انواع بوت چرم زنانه»,
     * «مجموعه مدل‌های …», «کلکسیون …»), 4-5 words. The raw name alone would equal / sit inside the page's own focus
     * keyphrase and Yoast / Rank Math would report a competing link; the prefix keeps the name and makes it unique.
     */
    public function category_anchor($name, array $product) {
        return self::compose_category_anchor($name, isset($product['id']) ? (int) $product['id'] : 0);
    }

    /**
     * Pure helper (also used by LinkGuard): prefix chosen by $seed, shortened to fit the 5-word ceiling.
     */
    public static function compose_category_anchor($name, $seed = 0) {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
        if ($name === '') return '';
        $prefixes = \Khazan\Core\Settings::CATEGORY_ANCHOR_PREFIXES;
        $max = \Khazan\Core\Settings::CATEGORY_ANCHOR_MAX_WORDS;
        $k = '\\Khazan\\Services\\Seo\\KeyphraseExtractor';
        $count = count($prefixes);
        for ($i = 0; $i < $count; $i++) {
            $prefix = $prefixes[(abs((int) $seed) + $i) % $count];
            if ($k::count_words($prefix) + $k::count_words($name) <= $max) return $prefix . ' ' . $name;
        }
        return $prefixes[0] . ' ' . $k::limit($name, $max - $k::count_words($prefixes[0]));
    }

    /**
     * Does $anchor compete with the product's focus keyphrase / synonyms?
     * $either_direction=true also rejects anchors contained in them; false only equal / containing anchors.
     * Synonyms always use the strict (equal / containing) test so short generic synonyms do not reject everything.
     */
    private function cannibalizes($anchor, array $product, $either_direction) {
        $k = '\\Khazan\\Services\\Seo\\KeyphraseExtractor';
        $focus = isset($product['anchor']) ? (string) $product['anchor'] : '';
        if ($focus !== '' && $k::overlaps($anchor, $focus) && ($either_direction || $k::count_words($anchor) >= $k::count_words($focus))) {
            return true;
        }
        foreach (isset($product['synonyms']) ? (array) $product['synonyms'] : array() as $synonym) {
            if ($synonym !== '' && $k::overlaps($anchor, $synonym) && $k::count_words($anchor) >= $k::count_words($synonym)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Related products for a standalone (non-queue) content generation.
     */
    public function related($product_id, $limit = self::DEFAULT_LIMIT) {
        $plan = $this->plan($product_id, '', array(), $limit);
        return $plan['related'];
    }

    /**
     * The product's own category: Yoast / Rank Math primary, otherwise the deepest assigned category.
     *
     * @return array|null {id, name, url}
     */
    public function own_category(array $product) {
        $ids = array_map('intval', $product['category_ids']);
        if (!$ids) {
            return null;
        }
        $chosen = (int) $product['primary_category_id'];
        if (!$chosen || !in_array($chosen, $ids, true)) {
            $chosen = 0;
            $best_depth = -1;
            foreach ($ids as $id) {
                $depth = count($this->ancestor_ids($id));
                if ($depth > $best_depth || ($depth === $best_depth && $id < $chosen)) {
                    $best_depth = $depth;
                    $chosen = $id;
                }
            }
        }
        $category = $this->provider->category($chosen);
        return $category ? array('id' => $category['id'], 'name' => $category['name'], 'url' => $category['url']) : null;
    }

    /**
     * @return array<int,array> entries {id, title, url, anchor} (max $limit)
     */
    private function pick_related(array $product, array $state, array $queue_ids, $limit) {
        if ($limit < 1) {
            return array();
        }
        $candidates = array();
        foreach ($this->provider->candidates_for($product, isset($state['assigned']) ? $state['assigned'] : array()) as $candidate) {
            if ((int) $candidate['id'] === (int) $product['id'] || (string) $candidate['anchor'] === '') {
                continue;
            }
            // انکر لینک خروجی نباید با کلیدواژه کانونی یا مترادف‌های اصلی همین محصول هم‌پوشانی داشته باشد (Cannibalization)
            if ($this->cannibalizes($candidate['anchor'], $product, true)) {
                continue;
            }
            // a men's product never links to a women's one (and vice versa)
            if (self::gender_conflict($product['title'], $candidate['title'])) {
                continue;
            }
            $candidate['score'] = $this->similarity($product, $candidate);
            $candidates[(int) $candidate['id']] = $candidate;
        }
        if (!$candidates) {
            return array();
        }
        // relevance first: same / parent category or near-identical titles. Unrelated products only fill the gap
        // when the relevant ones are fewer than the links needed (tiny categories).
        $relevant = array_filter($candidates, function ($c) { return $c['score'] > 0; });
        if (count($relevant) >= $limit) {
            $candidates = $relevant;
        }

        $inbound = $this->provider->inbound_counts(array_keys($candidates));
        $pending = array();
        foreach ($queue_ids as $queued) {
            if (!isset($state['done'][(int) $queued])) {
                $pending[(int) $queued] = true;
            }
        }
        $impressions = method_exists($this->provider, 'impressions') ? $this->provider->impressions(array_keys($candidates)) : array();
        foreach ($candidates as $id => $candidate) {
            $assigned = isset($state['assigned'][$id]) ? $state['assigned'][$id] : 0;
            $candidates[$id]['inbound'] = (isset($inbound[$id]) ? $inbound[$id] : 0) + $assigned;
            $candidates[$id]['pending'] = isset($pending[$id]) ? 0 : 1;
            // a page without any link always goes first; after that the share follows the page's value
            $candidates[$id]['load'] = $candidates[$id]['inbound'] === 0 ? -1.0 : $candidates[$id]['inbound'] / self::value_weight(isset($impressions[$id]) ? $impressions[$id] : 0);
        }

        usort($candidates, function ($a, $b) {
            if (abs($a['load'] - $b['load']) > 1e-9) return $a['load'] < $b['load'] ? -1 : 1;
            if ($a['pending'] !== $b['pending']) return $a['pending'] < $b['pending'] ? -1 : 1;
            if ($a['score'] !== $b['score']) return $a['score'] > $b['score'] ? -1 : 1;
            return $a['id'] < $b['id'] ? -1 : 1;
        });

        $picked = array();
        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            $picked[] = array('id' => (int) $candidate['id'], 'title' => $candidate['title'], 'anchor' => \Khazan\Services\Seo\KeyphraseExtractor::cap($candidate['anchor']), 'url' => $candidate['url']);
        }
        return $picked;
    }

    /**
     * Value weight of a page from its Search Console impressions: 1.0 (no data) up to 2.0 (about 10 000 impressions).
     * Inbound links are shared in proportion to it, so a page that earns traffic can take up to twice the links of a
     * page nobody searches for, but every page still gets its first link before any page gets a second.
     */
    public static function value_weight($impressions) {
        $i = max(0, (float) $impressions);
        return 1.0 + min(1.0, log10(1.0 + $i) / 4.0);
    }

    /** @return string 'f' | 'm' | 'k' (kids) | '' when the title names no audience */
    public static function gender_of($title) {
        $t = (string) $title;
        if (preg_match('/دخترانه|پسرانه|بچگانه|بچه‌گانه|کودک/u', $t)) return 'k';
        if (preg_match('/زنانه|بانوان|خانم/u', $t)) return 'f';
        if (preg_match('/مردانه|آقایان/u', $t)) return 'm';
        return '';
    }

    public static function gender_conflict($a, $b) {
        $x = self::gender_of($a);
        $y = self::gender_of($b);
        return $x !== '' && $y !== '' && $x !== $y;
    }

    /**
     * Topical similarity: shared primary / shared categories dominate, ancestor overlap is weaker,
     * title token overlap only adds a fractional tie-break (or qualifies near-identical titles).
     */
    private function similarity(array $product, array $candidate) {
        $mine   = array_map('intval', $product['category_ids']);
        $theirs = array_map('intval', $candidate['category_ids']);
        $shared = array_intersect($mine, $theirs);

        $score = 0.0;
        $p1 = (int) $product['primary_category_id'];
        $p2 = (int) $candidate['primary_category_id'];
        if ($p1 && $p1 === $p2) {
            $score += 3;
        } elseif (($p2 && in_array($p2, $mine, true)) || ($p1 && in_array($p1, $theirs, true))) {
            $score += 2;
        }
        $score += min(count($shared), 3);

        if (!$shared) {
            $mine_anc = array();
            foreach ($mine as $id) $mine_anc = array_merge($mine_anc, $this->ancestor_ids($id));
            $their_anc = array();
            foreach ($theirs as $id) $their_anc = array_merge($their_anc, array($id), $this->ancestor_ids($id));
            if (array_intersect(array_merge($mine, $mine_anc), $their_anc)) {
                $score += 0.5;
            }
        }

        $title = $this->title_similarity($product['title'], $candidate['title']);
        if ($score > 0) {
            return $score + $title * 0.49;
        }
        return $title >= self::TITLE_RELATED_THRESHOLD ? $title * 0.49 + 0.01 : 0.0;
    }

    private function title_similarity($a, $b) {
        $ta = $this->tokens($a);
        $tb = $this->tokens($b);
        if (!$ta || !$tb) {
            return 0.0;
        }
        $inter = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));
        return $union ? $inter / $union : 0.0;
    }

    private function tokens($title) {
        $parts = preg_split('/[\s\-_,،.:;|\/]+/u', mb_strtolower((string) $title));
        return array_values(array_unique(array_filter($parts, function ($t) {
            return mb_strlen($t) > 1;
        })));
    }

    /**
     * @return int[] parent chain (nearest first)
     */
    private function ancestor_ids($category_id) {
        $chain = array();
        $guard = 0;
        $current = $this->provider->category($category_id);
        while ($current && !empty($current['parent']) && $guard++ < 8) {
            $chain[] = (int) $current['parent'];
            $current = $this->provider->category((int) $current['parent']);
        }
        return $chain;
    }
}
