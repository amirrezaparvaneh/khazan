<?php
/**
 * Internal-link planner for product-category descriptions (spider / mesh architecture, no orphan categories).
 *
 *  - exactly 1 link to the homepage, anchored with a brand / shop phrase;
 *  - 2-3 links to other categories (about one per 250 words) chosen by GlobalCategoryPool: the categories with the
 *    fewest inbound links first, then still-pending rows, relatives and cyclic id order;
 *  - anchor of a category link = the exact name / focus keyphrase of the TARGET category (locked afterwards by LinkGuard).
 *    A target whose words are 100% contained in the current focus keyphrase would cannibalize it, so it gets the
 *    distinguishing prefix «خرید »; the current category never links to itself.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

use Khazan\Core\Settings;
use Khazan\Services\Seo\KeyphraseExtractor;

if (!defined('ABSPATH')) {
    exit;
}

final class CategorySpiderLinker {

    const DISTINCT_PREFIX = 'خرید';

    /** @var CategoryLinkSource */
    private $source;

    /** @var AssignmentLedger */
    private $ledger;

    public function __construct(CategoryLinkSource $source, AssignmentLedger $ledger) {
        $this->source = $source;
        $this->ledger = $ledger;
    }

    public static function wordpress() {
        return new self(new WordPressCategoryLinkSource(), new TransientAssignmentLedger());
    }

    /** About one link per 250 words, never fewer than 2 nor more than 3. */
    public static function link_count($word_count) {
        $count = (int) round(max(0, (int) $word_count) / Settings::CATEGORY_WORDS_PER_LINK);
        return max(Settings::CATEGORY_LINKS_MIN, min(Settings::CATEGORY_LINKS_MAX, $count));
    }

    /**
     * @param array    $category  the page that receives the links {id, name, url, parent}
     * @param int      $word_count planned length of the description
     * @param string   $brand     shop / brand name for the homepage anchor
     * @param string   $run_id    bulk-run id; empty = stateless plan (database counts only)
     * @param int[]    $queue_ids every category of the current queue (to know which rows are still pending)
     * @return array{home:array,categories:array[],internal_links:array[]}
     */
    public function plan(array $category, $word_count = Settings::CATEGORY_WORD_COUNT, $brand = '', $run_id = '', array $queue_ids = array()) {
        $self_id = (int) $category['id'];
        $state = $run_id !== '' ? $this->ledger->load($run_id) : array('assigned' => array(), 'done' => array());
        $state['done'][$self_id] = true;

        $exclude = array_map('intval', array_keys($state['done']));
        $pending = array();
        foreach ($queue_ids as $queued) {
            if (!isset($state['done'][(int) $queued])) $pending[] = (int) $queued;
        }
        $ranked = GlobalCategoryPool::rank($this->source->categories(), $this->source->inbound_counts($exclude), $state['assigned'], $category, $pending);

        $focus  = isset($category['anchor']) && $category['anchor'] !== '' ? $category['anchor'] : $category['name'];
        $picked = array();
        foreach (array_slice($ranked, 0, self::link_count($word_count)) as $target) {
            $picked[] = $this->category_link($target, $focus);
            $state['assigned'][$target['id']] = (isset($state['assigned'][$target['id']]) ? $state['assigned'][$target['id']] : 0) + 1;
        }
        if ($run_id !== '') $this->ledger->save($run_id, $state);

        $home = $this->home_link($brand, $self_id);
        return array('home' => $home, 'categories' => $picked, 'internal_links' => array_merge($picked, array($home)));
    }

    /** Anchor = the target's exact name / focus keyphrase; a target fully inside the current focus gets the «خرید » prefix. */
    private function category_link(array $target, $current_focus) {
        $anchor = trim(preg_replace('/\s+/u', ' ', isset($target['anchor']) && $target['anchor'] !== '' ? $target['anchor'] : $target['name']));
        if (KeyphraseExtractor::overlaps($anchor, $current_focus) && KeyphraseExtractor::count_words($anchor) <= KeyphraseExtractor::count_words($current_focus)) {
            $anchor = self::DISTINCT_PREFIX . ' ' . $anchor;
        }
        return array('anchor' => $anchor, 'url' => $target['url'], 'locked' => true, 'required' => true, 'type' => 'category', 'category_id' => (int) $target['id'], 'category_name' => $target['name']);
    }

    private function home_link($brand, $seed) {
        $brand = trim((string) $brand);
        $anchors = Settings::CATEGORY_HOME_ANCHORS;
        $anchor = $brand === '' ? Settings::CATEGORY_HOME_ANCHOR_FALLBACK : sprintf($anchors[abs((int) $seed) % count($anchors)], $brand);
        return array('anchor' => $anchor, 'url' => home_url('/'), 'locked' => true, 'required' => true, 'type' => 'page');
    }
}
