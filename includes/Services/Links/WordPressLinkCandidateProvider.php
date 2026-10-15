<?php
/**
 * WordPress-backed candidate source: the global product catalogue (SiteEntityRepository) shortlisted by inbound counts (LinkRepository).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

use Khazan\Repository\ContentTargetRepository;
use Khazan\Repository\LinkRepository;
use Khazan\Repository\SiteEntityRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class WordPressLinkCandidateProvider implements LinkCandidateProvider {

    /** Fewer same-category candidates than this pulls in the ancestor categories as well. */
    const MIN_POOL = 12;

    /** @var SiteEntityRepository */
    private $entities;

    /** @var LinkRepository */
    private $links;

    /** @var ContentTargetRepository */
    private $targets;

    /** @var array<int,array|null> */
    private $category_cache = array();

    public function __construct(SiteEntityRepository $entities = null, LinkRepository $links = null, ContentTargetRepository $targets = null) {
        $this->entities = $entities ?: new SiteEntityRepository();
        $this->links    = $links ?: new LinkRepository();
        $this->targets  = $targets ?: new ContentTargetRepository();
    }

    public function product($product_id) {
        $product_id = (int) $product_id;
        $post = get_post($product_id);
        if (!$post) {
            return null;
        }
        $categories = $this->entities->product_categories($product_id);
        foreach ($categories as $id => $category) {
            $this->category_cache[$id] = $category;
        }
        $ids = array_keys($categories);
        return array(
            'id'                  => $product_id,
            'title'               => get_the_title($product_id),
            'url'                 => get_permalink($product_id),
            'anchor'              => $this->targets->product_link_anchor($product_id),
            'synonyms'            => $this->targets->product_synonyms($product_id),
            'category_ids'        => $ids,
            'primary_category_id' => $this->entities->primary_category_id($product_id, $ids),
        );
    }

    public function category($category_id) {
        $category_id = (int) $category_id;
        if (!array_key_exists($category_id, $this->category_cache)) {
            $this->category_cache[$category_id] = $this->entities->category($category_id);
        }
        return $this->category_cache[$category_id];
    }

    public function candidates_for(array $product, array $assigned = array()) {
        $all = $this->entities->published_product_ids($product['id']);
        $counts = $this->links->inbound_counts($all, \Khazan\Core\Settings::product_post_type());
        $ids = GlobalLinkPool::shortlist($all, $counts, $assigned, $product['id'], $this->related_ids($product));
        $out = array();
        foreach ($ids as $id) {
            $entry = $this->product($id);
            if ($entry) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Same-category products (ancestor categories too when the category is small): preferred inside the global pool.
     *
     * @return int[]
     */
    private function related_ids(array $product) {
        $ids = $this->entities->published_product_ids_in_categories($product['category_ids'], $product['id']);
        if (count($ids) < self::MIN_POOL) {
            $ancestors = array();
            foreach ($product['category_ids'] as $category_id) {
                $current = $this->category($category_id);
                $guard = 0;
                while ($current && !empty($current['parent']) && $guard++ < 8) {
                    $ancestors[] = (int) $current['parent'];
                    $current = $this->category((int) $current['parent']);
                }
            }
            $ids = array_values(array_unique(array_merge($ids, $this->entities->published_product_ids_in_categories($ancestors, $product['id']))));
        }
        return $ids;
    }

    public function inbound_counts(array $product_ids) {
        return $this->links->inbound_counts($product_ids, \Khazan\Core\Settings::product_post_type());
    }
}
