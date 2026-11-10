<?php
/**
 * Role-based internal-link plan for a product-category page.
 *
 * Roles follow the content rules: the parent category, the sub-categories (parent -> every child, child -> parent),
 * related sibling categories, 3-4 model products by their exact name, and one guide article. The number of links is
 * derived from LinkPolicy (5-8 for a category) and is never larger than the number of REAL targets - a URL that does
 * not exist is never invented. Each target is linked once; every link carries a few natural anchor options (the model
 * picks one per sentence) so anchors vary instead of repeating one locked phrase.
 *
 * Pure: the WordPress data is passed in, which keeps the planner unit-testable.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

use Khazan\Core\Settings;
use Khazan\Services\Content\ContentAudit;

if (!defined('ABSPATH')) {
    exit;
}

final class CategoryLinkPlanner {

    /** Products wanted in a category text (the rule: «۳ تا ۴ محصول مدل‌دار»). */
    const PRODUCTS_MIN = 3;
    const PRODUCTS_MAX = 4;

    /**
     * @param array   $category   {id, name, url, parent}
     * @param array[] $categories every site category {id, name, url, parent}
     * @param array[] $products   products of this category {id, title, url, model}
     * @param array[] $articles   guide-article candidates {id, title, url}
     * @param int     $word_count planned body length
     * @return array{links:array[],target:int,available:int,missing_roles:string[]}
     */
    public static function plan(array $category, array $categories, array $products, array $articles, $word_count) {
        $self_id = (int) $category['id'];
        $pools = array('parent' => array(), 'child' => array(), 'sibling' => array(), 'product' => array(), 'article' => array());

        foreach ($categories as $other) {
            $id = (int) $other['id'];
            if ($id === $self_id || empty($other['url']) || empty($other['name'])) continue;
            if ((int) $category['parent'] === $id) $pools['parent'][] = $other;
            elseif ((int) $other['parent'] === $self_id) $pools['child'][] = $other;
            elseif ((int) $category['parent'] > 0 && (int) $other['parent'] === (int) $category['parent']) $pools['sibling'][] = $other;
        }
        $pools['sibling'] = self::rank_by_relevance($pools['sibling'], $category['name'], 'name');
        $pools['product'] = array_slice(self::rank_by_relevance($products, $category['name'], 'title'), 0, self::PRODUCTS_MAX);
        $pools['article'] = array_slice(self::rank_by_relevance($articles, $category['name'], 'title', true), 0, 1);

        $available = 0;
        foreach ($pools as $pool) $available += count($pool);
        $target = LinkPolicy::internal_target(Settings::KIND_CATEGORY, $word_count, $available);

        // priority: parent, children, products, article, siblings. Products and the article are kept even when many
        // children exist, so a parent page still shows models and a guide.
        $reserve = min(count($pools['product']), self::PRODUCTS_MIN) + min(count($pools['article']), 1);
        $picked = array();
        $take = function ($role, $limit) use (&$picked, &$pools, &$target) {
            while ($limit > 0 && count($picked) < $target && $pools[$role]) {
                $picked[] = array('role' => $role, 'entry' => array_shift($pools[$role]));
                $limit--;
            }
        };
        $take('parent', 1);
        $take('child', max(0, $target - count($picked) - $reserve));
        $take('product', self::PRODUCTS_MIN);
        $take('article', 1);
        $take('sibling', PHP_INT_MAX);
        $take('product', PHP_INT_MAX);
        $take('child', PHP_INT_MAX);

        $links = array();
        $used = array();
        foreach ($picked as $row) {
            $link = self::link($row['role'], $row['entry'], $category['name']);
            $key = untrailingslashit($link['url']);
            if (isset($used[$key])) continue;
            $used[$key] = true;
            $links[] = $link;
        }
        $missing = array();
        if (!$pools['parent'] && (int) $category['parent'] === 0) $missing[] = 'parent';
        if (!$products) $missing[] = 'product';
        if (!$articles) $missing[] = 'article';
        return array('links' => $links, 'target' => $target, 'available' => $available, 'missing_roles' => $missing);
    }

    /** Anchor options never equal the page's own focus keyword (no cannibalisation). */
    private static function link($role, array $entry, $current_name) {
        if ($role === 'product') {
            $model = isset($entry['model']) && $entry['model'] !== '' ? $entry['model'] : $entry['title'];
            return array('type' => 'product', 'role' => $role, 'url' => $entry['url'], 'title' => $entry['title'], 'anchor' => $model, 'anchor_options' => array($model, $entry['title']));
        }
        if ($role === 'article') {
            return array('type' => 'article', 'role' => $role, 'url' => $entry['url'], 'title' => $entry['title'], 'anchor' => $entry['title'], 'anchor_options' => array($entry['title']));
        }
        $name = trim($entry['name']);
        $options = array($name, 'انواع ' . $name, 'مشاهده ' . $name);
        if (ContentAudit::normalize($name) === ContentAudit::normalize($current_name)) $options = array('انواع ' . $name);
        return array('type' => 'category', 'role' => $role, 'url' => $entry['url'], 'title' => $name, 'anchor' => $options[0], 'anchor_options' => array_values(array_unique($options)));
    }

    /** Orders entries by the number of distinctive words shared with the category name (stable, then by id). */
    private static function rank_by_relevance(array $rows, $name, $field, $require_overlap = false) {
        $stop = array_map(array('Khazan\\Services\\Content\\ContentAudit', 'normalize'), Settings::ARTICLE_GENERIC_WORDS);
        $tokens = array_diff(ContentAudit::words($name), $stop);
        foreach ($rows as $i => $row) {
            $rows[$i]['_score'] = count(array_intersect($tokens, array_diff(ContentAudit::words(isset($row[$field]) ? $row[$field] : ''), $stop)));
            $rows[$i]['_i'] = $i;
        }
        if ($require_overlap) $rows = array_filter($rows, function ($row) { return $row['_score'] > 0; });
        usort($rows, function ($a, $b) { return $a['_score'] === $b['_score'] ? $a['_i'] - $b['_i'] : $b['_score'] - $a['_score']; });
        foreach ($rows as $i => $row) unset($rows[$i]['_score'], $rows[$i]['_i']);
        return array_values($rows);
    }
}
