<?php
/**
 * Global, balanced pool of link targets for product-category pages (spider architecture).
 *
 * Every other category of the site is a candidate. The order is:
 *   1. fewest inbound links (database + links already assigned in the current run) - the least-linked always win;
 *   2. categories whose own row is still pending in the queue (they have fewer future chances to be linked);
 *   3. relatives: shared parent / parent-child first, then a shared gender word;
 *   4. cyclic term_id order from the current category (id+1, id+2, ... then wrap around).
 * Rule 2 is what keeps the spread exact: with a queue that covers the whole site, every category ends with the same
 * number of inbound links (difference <= 1) regardless of the order of the rows.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

use Khazan\Services\Seo\KeyphraseExtractor;

if (!defined('ABSPATH')) {
    exit;
}

final class GlobalCategoryPool {

    /** Gender words that make two categories "of the same family". */
    const GENDER_WORDS = array('مردانه', 'زنانه', 'دخترانه', 'پسرانه', 'بچگانه', 'بچه‌گانه');

    /**
     * @param array[]        $categories  all categories {id, name, url, parent, anchor}
     * @param array<int,int> $db_counts   inbound counts from descriptions (missing = 0)
     * @param array<int,int> $assigned    links assigned in the current run (missing = 0)
     * @param array          $self        the category that receives the links {id, name, parent}
     * @param int[]          $pending_ids queue rows not processed yet
     * @return array[] ranked candidates (self removed), each with count / pending / family / wrap
     */
    public static function rank(array $categories, array $db_counts, array $assigned, array $self, array $pending_ids = array()) {
        $self_id = (int) $self['id'];
        $pending = array_flip(array_map('intval', $pending_ids));
        $rows = array();
        foreach ($categories as $category) {
            $id = (int) $category['id'];
            if ($id === $self_id || $id <= 0 || isset($rows[$id]) || empty($category['url'])) continue;
            $category['id']      = $id;
            $category['count']   = (isset($db_counts[$id]) ? (int) $db_counts[$id] : 0) + (isset($assigned[$id]) ? (int) $assigned[$id] : 0);
            $category['pending'] = isset($pending[$id]) ? 0 : 1;
            $category['family']  = self::family($self, $category);
            $category['wrap']    = $id > $self_id ? 0 : 1;
            $rows[$id] = $category;
        }
        usort($rows, function ($a, $b) {
            foreach (array('count', 'pending', 'family', 'wrap', 'id') as $field) {
                if ($a[$field] !== $b[$field]) return $a[$field] < $b[$field] ? -1 : 1;
            }
            return 0;
        });
        return $rows;
    }

    /** 0 = shared parent or parent/child, 1 = shared gender word, 2 = unrelated. */
    public static function family(array $self, array $other) {
        $self_parent  = isset($self['parent']) ? (int) $self['parent'] : 0;
        $other_parent = isset($other['parent']) ? (int) $other['parent'] : 0;
        if (($self_parent && $self_parent === $other_parent) || ($self_parent && $self_parent === (int) $other['id']) || ($other_parent && $other_parent === (int) $self['id'])) {
            return 0;
        }
        $genders = array_map(array('Khazan\\Services\\Seo\\KeyphraseExtractor', 'key'), self::GENDER_WORDS);
        $mine = array_intersect(array_map(array('Khazan\\Services\\Seo\\KeyphraseExtractor', 'key'), KeyphraseExtractor::words(isset($self['name']) ? $self['name'] : '')), $genders);
        $theirs = array_intersect(array_map(array('Khazan\\Services\\Seo\\KeyphraseExtractor', 'key'), KeyphraseExtractor::words(isset($other['name']) ? $other['name'] : '')), $genders);
        return array_intersect($mine, $theirs) ? 1 : 2;
    }
}
