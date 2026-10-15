<?php
/**
 * Internal-link planner for product content.
 *
 * - Category link: always the product's own primary category.
 * - Product links: only related candidates (same / ancestor categories, title similarity as a tie-break).
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
        $related  = $this->pick_related($product, $state, $queue_ids, (int) $limit);

        foreach ($related as $entry) {
            $state['assigned'][$entry['id']] = (isset($state['assigned'][$entry['id']]) ? $state['assigned'][$entry['id']] : 0) + 1;
        }
        if ($run_id !== '') {
            $this->ledger->save($run_id, $state);
        }

        $links = array();
        if ($category) {
            $links[] = array('anchor' => $category['name'], 'url' => $category['url'], 'required' => true, 'type' => 'category');
        }
        foreach ($related as $entry) {
            $links[] = array('anchor' => $entry['anchor'], 'url' => $entry['url'], 'locked' => true, 'required' => true, 'type' => 'product');
        }
        return array('category' => $category, 'related' => $related, 'internal_links' => $links);
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
        foreach ($this->provider->candidates_for($product) as $candidate) {
            if ((int) $candidate['id'] === (int) $product['id'] || (string) $candidate['anchor'] === '') {
                continue;
            }
            $score = $this->similarity($product, $candidate);
            if ($score <= 0) {
                continue;
            }
            $candidate['score'] = $score;
            $candidates[(int) $candidate['id']] = $candidate;
        }
        if (!$candidates) {
            return array();
        }

        $inbound = $this->provider->inbound_counts(array_keys($candidates));
        $pending = array();
        foreach ($queue_ids as $queued) {
            if (!isset($state['done'][(int) $queued])) {
                $pending[(int) $queued] = true;
            }
        }
        foreach ($candidates as $id => $candidate) {
            $assigned = isset($state['assigned'][$id]) ? $state['assigned'][$id] : 0;
            $candidates[$id]['inbound'] = (isset($inbound[$id]) ? $inbound[$id] : 0) + $assigned;
            $candidates[$id]['pending'] = isset($pending[$id]) ? 0 : 1;
        }

        usort($candidates, function ($a, $b) {
            if ($a['inbound'] !== $b['inbound']) return $a['inbound'] < $b['inbound'] ? -1 : 1;
            if ($a['pending'] !== $b['pending']) return $a['pending'] < $b['pending'] ? -1 : 1;
            if ($a['score'] !== $b['score']) return $a['score'] > $b['score'] ? -1 : 1;
            return $a['id'] < $b['id'] ? -1 : 1;
        });

        $picked = array();
        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            $picked[] = array('id' => (int) $candidate['id'], 'title' => $candidate['title'], 'anchor' => $candidate['anchor'], 'url' => $candidate['url']);
        }
        return $picked;
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
