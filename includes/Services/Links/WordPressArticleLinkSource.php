<?php
/**
 * WordPress-backed source for the article link distributor.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

use Khazan\Core\Settings;
use Khazan\Repository\ContentTargetRepository;
use Khazan\Repository\LinkRepository;
use Khazan\Repository\SiteEntityRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class WordPressArticleLinkSource implements ArticleLinkSource {

    /** Hydrated candidates per kind (the pool itself is global; only the shortlist is loaded). */
    const SHORTLIST = 30;

    private $entities;
    private $links;
    private $targets;

    public function __construct(SiteEntityRepository $entities = null, LinkRepository $links = null, ContentTargetRepository $targets = null) {
        $this->entities = $entities ?: new SiteEntityRepository();
        $this->links    = $links ?: new LinkRepository();
        $this->targets  = $targets ?: new ContentTargetRepository();
    }

    public function categories() {
        return array_values($this->entities->category_tree());
    }

    public function product_candidates(array $assigned, array $category_ids, array $related_ids, array $topic_tokens) {
        $all    = $this->entities->published_product_ids();
        $counts = $this->links->inbound_counts($all, Settings::product_post_type());

        $tier1 = array_values(array_unique(array_merge(
            $category_ids ? $this->entities->published_product_ids_in_categories($category_ids) : array(),
            $this->entities->product_ids_matching_title($topic_tokens)
        )));
        $tier2 = $related_ids ? $this->entities->published_product_ids_in_categories($related_ids) : array();

        $ids = array_unique(array_merge(
            GlobalLinkPool::shortlist($tier1, $counts, $assigned, 0, array(), self::SHORTLIST),
            GlobalLinkPool::shortlist($tier2, $counts, $assigned, 0, array(), self::SHORTLIST),
            GlobalLinkPool::shortlist($all, $counts, $assigned, 0, array(), self::SHORTLIST)
        ));
        $out = array();
        foreach ($ids as $id) {
            $out[] = array(
                'id'           => $id,
                'title'        => get_the_title($id),
                'url'          => get_permalink($id),
                'anchor'       => $this->targets->product_link_anchor($id),
                'inbound'      => isset($counts[$id]) ? $counts[$id] : 0,
                'category_ids' => array_keys($this->entities->product_categories($id)),
            );
        }
        return $out;
    }

    public function article_candidates(array $assigned) {
        $type   = Settings::blog_post_type();
        $all    = $this->entities->published_post_ids($type);
        $counts = $this->links->inbound_counts($all, $type);
        $out = array();
        foreach (GlobalLinkPool::shortlist($all, $counts, $assigned, 0, array(), self::SHORTLIST) as $id) {
            $out[] = array(
                'id'      => $id,
                'title'   => get_the_title($id),
                'url'     => get_permalink($id),
                'anchor'  => $this->targets->article_link_anchor($id),
                'inbound' => isset($counts[$id]) ? $counts[$id] : 0,
            );
        }
        return $out;
    }

    public function brand_page() {
        $anchor = trim((string) get_option(Settings::OPT_BRAND_NAME, ''));
        if ($anchor === '') {
            $keywords = preg_split('/[,،]/u', (string) Settings::homepage_keywords());
            $anchor = trim((string) $keywords[0]);
        }
        return $anchor === '' ? null : array('url' => home_url('/'), 'anchor' => $anchor);
    }
}
