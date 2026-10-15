<?php
/**
 * Assembles the dashboard "site health" statistics: content counters, coverage score and Khazan activity.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Report;

use Khazan\Core\Settings;
use Khazan\Repository\DatabaseRepository;
use Khazan\Repository\LinkRepository;
use Khazan\Repository\SiteEntityRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteStatsService {

    /** @var SiteEntityRepository */
    private $entities;

    /** @var DatabaseRepository */
    private $database;

    /** @var LinkRepository */
    private $links;

    public function __construct(SiteEntityRepository $entities = null, DatabaseRepository $database = null, LinkRepository $links = null) {
        $this->entities = $entities ?: new SiteEntityRepository();
        $this->database = $database ?: new DatabaseRepository();
        $this->links    = $links ?: new LinkRepository();
    }

    /**
     * @param bool $refresh Skip the 5-minute transient cache.
     * @return array
     */
    public function stats($refresh = false) {
        if (!$refresh) {
            $cached = get_transient(Settings::TRANSIENT_SITE_STATS);
            if (is_array($cached)) {
                $cached['cached'] = true;
                return $cached;
            }
        }
        $stats = $this->build();
        set_transient(Settings::TRANSIENT_SITE_STATS, $stats, Settings::SITE_STATS_TTL);
        $stats['cached'] = false;
        return $stats;
    }

    /**
     * Pure assembly from repository counters (public for unit tests).
     */
    public function build() {
        $raw = $this->entities->content_stats();

        $products = $raw['products'];
        $articles = $raw['articles'];
        $categories = $raw['categories'];

        $products['coverage_percent'] = self::percent($products['has_content'], $products['total']);
        $articles['coverage_percent'] = self::percent($articles['has_content'], $articles['total']);
        $categories['coverage_percent'] = self::percent($categories['with_description'], $categories['total']);

        $pages_total = $products['total'] + $articles['total'] + $categories['total'];
        $covered = $products['has_content'] + $articles['has_content'] + $categories['with_description'];

        return array(
            'products'   => $products,
            'articles'   => $articles,
            'categories' => $categories,
            'coverage'   => array('score' => self::percent($covered, $pages_total), 'covered' => $covered, 'total' => $pages_total),
            'khazan'     => array('generated_contents' => $this->database->count_content_history(), 'internal_links' => $this->links->total_links()),
            'generated_at' => current_time('mysql'),
        );
    }

    /**
     * Whole-number percentage (0 when the denominator is 0).
     */
    public static function percent($part, $whole) {
        return $whole > 0 ? (int) round(($part / $whole) * 100) : 0;
    }
}
