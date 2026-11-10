<?php
/**
 * Analysis of the links and content the site already has (pure functions over the link index rows).
 *
 *   - link counts per page against the SEO standards of its kind (too few / too many internal links);
 *   - anchors: generic anchors, one anchor used for many different targets (cannibalization), one anchor flooding a
 *     target (over-optimization);
 *   - inbound balance: pages nobody links to and the most linked pages;
 *   - content gaps: categories whose description is shorter than the standard.
 * The result feeds the dashboard report and tells the owner what to regenerate first; nothing here changes the site.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Report;

use Khazan\Core\Settings;
use Khazan\Services\Content\ContentAudit;
use Khazan\Services\Content\SeoStandards;

if (!defined('ABSPATH')) {
    exit;
}

final class LinkAnalysis {

    const GENERIC_ANCHORS = array('اینجا', 'کلیک', 'کلیک کنید', 'ادامه مطلب', 'بیشتر', 'بیشتر بخوانید', 'مشاهده', 'لینک', 'این لینک', 'این صفحه', 'click here', 'here', 'read more');

    /** Link index source/target type -> content kind used by the standards. */
    public static function kind_of($type) {
        $map = array('product' => Settings::KIND_PRODUCT, 'product_cat' => Settings::KIND_CATEGORY, 'post' => Settings::KIND_ARTICLE);
        return isset($map[$type]) ? $map[$type] : '';
    }

    /**
     * @param array[] $outgoing   LinkRepository::outgoing_totals()
     * @param array[] $anchors    LinkRepository::anchor_groups()
     * @param array[] $inbound    LinkRepository::inbound_totals()
     * @param array[] $categories category entries {id, name, url, products, words}
     * @param array[] $pages      every page that should be linked {id, type, title, url}; optional (finds pages with no inbound links)
     */
    public static function build(array $outgoing, array $anchors, array $inbound, array $categories = array(), array $pages = array(), $limit = 30) {
        $report = array(
            'links_per_page' => self::links_per_page($outgoing, $limit),
            'anchors'        => self::anchor_report($anchors, $inbound, $limit),
            'inbound'        => self::inbound_report($inbound, $pages, $limit),
            'content_gaps'   => self::content_gaps($categories, $limit),
        );
        $report['summary'] = array(
            'pages_with_too_few_links'  => $report['links_per_page']['counts']['few'],
            'pages_with_too_many_links' => $report['links_per_page']['counts']['many'],
            'generic_anchor_uses'       => $report['anchors']['generic_total'],
            'cannibalizing_anchors'     => count($report['anchors']['shared']),
            'flooded_targets'           => count($report['anchors']['flooded']),
            'pages_without_inbound'     => $report['inbound']['orphan_total'],
            'thin_categories'           => count($report['content_gaps']),
        );
        return $report;
    }

    private static function links_per_page(array $outgoing, $limit) {
        $few = $many = array();
        $counts = array('few' => 0, 'many' => 0, 'ok' => 0);
        foreach ($outgoing as $row) {
            $kind = self::kind_of($row['source_type']);
            if ($kind === '') continue;
            list($min, $max) = SeoStandards::internal_range($kind);
            $n = (int) $row['links'];
            $entry = array('id' => (int) $row['source_id'], 'type' => $row['source_type'], 'title' => $row['source_title'], 'url' => $row['source_url'], 'links' => $n, 'min' => $min, 'max' => $max);
            if ($n < $min) { $counts['few']++; $few[] = $entry; }
            elseif ($n > $max) { $counts['many']++; $many[] = $entry; }
            else $counts['ok']++;
        }
        usort($few, function ($a, $b) { return $a['links'] - $b['links']; });
        usort($many, function ($a, $b) { return $b['links'] - $a['links']; });
        return array('counts' => $counts, 'few' => array_slice($few, 0, $limit), 'many' => array_slice($many, 0, $limit));
    }

    private static function anchor_report(array $anchors, array $inbound, $limit) {
        $generic = array_flip(array_map(array(ContentAudit::class, 'normalize'), self::GENERIC_ANCHORS));
        $generic_total = 0;
        $generic_rows = array();
        $by_anchor = array();
        $by_target = array();
        foreach ($anchors as $row) {
            $key = ContentAudit::normalize($row['anchor_text']);
            if ($key === '') continue;
            $uses = (int) $row['uses'];
            if (isset($generic[$key])) { $generic_total += $uses; $generic_rows[] = array('anchor' => $row['anchor_text'], 'uses' => $uses); }
            $by_anchor[$key]['anchor'] = $row['anchor_text'];
            $by_anchor[$key]['targets'][(string) $row['target_id'] . ':' . $row['target_type']] = $row['target_url'];
            $by_anchor[$key]['uses'] = (isset($by_anchor[$key]['uses']) ? $by_anchor[$key]['uses'] : 0) + $uses;
            $tk = (string) $row['target_id'] . ':' . $row['target_type'];
            $by_target[$tk][] = array('anchor' => $row['anchor_text'], 'uses' => $uses, 'url' => $row['target_url']);
        }

        $shared = array();
        foreach ($by_anchor as $key => $info) {
            if (isset($generic[$key]) || count($info['targets']) < 3) continue;
            $shared[] = array('anchor' => $info['anchor'], 'targets' => count($info['targets']), 'uses' => $info['uses']);
        }
        usort($shared, function ($a, $b) { return $b['targets'] - $a['targets']; });

        $flooded = array();
        foreach ($by_target as $rows) {
            $total = array_sum(array_column($rows, 'uses'));
            if ($total < 5) continue;
            usort($rows, function ($a, $b) { return $b['uses'] - $a['uses']; });
            if ($rows[0]['uses'] / $total >= 0.7 && !isset($generic[ContentAudit::normalize($rows[0]['anchor'])])) {
                $flooded[] = array('anchor' => $rows[0]['anchor'], 'url' => $rows[0]['url'], 'uses' => $rows[0]['uses'], 'total' => $total, 'share' => (int) round($rows[0]['uses'] / $total * 100));
            }
        }
        usort($flooded, function ($a, $b) { return $b['total'] - $a['total']; });
        usort($generic_rows, function ($a, $b) { return $b['uses'] - $a['uses']; });

        return array(
            'generic_total' => $generic_total,
            'generic'       => array_slice($generic_rows, 0, $limit),
            'shared'        => array_slice($shared, 0, $limit),
            'flooded'       => array_slice($flooded, 0, $limit),
        );
    }

    private static function inbound_report(array $inbound, array $pages, $limit) {
        $count = array();
        foreach ($inbound as $row) $count[(string) $row['target_id'] . ':' . $row['target_type']] = (int) $row['inbound'];
        $orphans = array();
        foreach ($pages as $page) {
            if (self::kind_of($page['type']) === '') continue;
            if (empty($count[(string) $page['id'] . ':' . $page['type']])) $orphans[] = $page;
        }
        $top = $inbound;
        usort($top, function ($a, $b) { return (int) $b['inbound'] - (int) $a['inbound']; });
        return array(
            'orphan_total' => count($orphans),
            'orphans'      => array_slice($orphans, 0, $limit),
            'top'          => array_slice(array_map(function ($r) { return array('id' => (int) $r['target_id'], 'type' => $r['target_type'], 'url' => $r['target_url'], 'inbound' => (int) $r['inbound']); }, $top), 0, 10),
        );
    }

    private static function content_gaps(array $categories, $limit) {
        list($min) = SeoStandards::words_range(Settings::KIND_CATEGORY);
        $gaps = array();
        foreach ($categories as $cat) {
            if ((int) $cat['products'] < 1 || (int) $cat['words'] >= $min) continue;
            $gaps[] = array('id' => (int) $cat['id'], 'name' => $cat['name'], 'url' => $cat['url'], 'products' => (int) $cat['products'], 'words' => (int) $cat['words'], 'missing' => $min - (int) $cat['words']);
        }
        usort($gaps, function ($a, $b) { return $b['products'] - $a['products']; });
        return array_slice($gaps, 0, $limit);
    }
}
