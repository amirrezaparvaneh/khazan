<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * گزارش‌های تحلیلی: خلاصه داشبورد، فهرست آیتم‌ها، جزئیات لینک و صفحات یتیم
 */
final class ReportController extends BaseController {

    public function get_dashboard_summary() {
        $links = new \Khazan\Repository\LinkRepository();
        $scanner = new \Khazan\Services\Scan\ScannerService();
        $all_items = $scanner->get_all_scannable_items();

        $breakdown = array();
        
        foreach ($all_items as $item) {
            $type = $item['type'];
            if (!isset($breakdown[$type])) {
                $breakdown[$type] = array(
                    'label' => $this->get_type_label($type),
                    'count' => 0
                );
            }

            $breakdown[$type]['count']++;
        }

        // Keep the dashboard usable when the index table is unavailable.
        if (!$links->table_exists()) {
            return rest_ensure_response(array(
                'total_items'  => count($all_items),
                'total_links'  => 0,
                'orphan_count' => count($all_items),
                'breakdown'    => $breakdown
            ));
        }

        $total_links = $links->total_links();
        $orphans = \Khazan\Services\Links\OrphanFinder::calculate($all_items);

        return rest_ensure_response(array(
            'total_items'  => count($all_items), 
            'total_links'  => $total_links, 
            'orphan_count' => count($orphans['list']),
            'breakdown'    => $breakdown
        ));
    }

    public function get_items_report($request) {
        $links = new \Khazan\Repository\LinkRepository();
        $filter = sanitize_text_field($request->get_param('type'));
        $scanner = new \Khazan\Services\Scan\ScannerService();
        $items = $scanner->get_all_scannable_items();
        $report = array();

        foreach ($items as $item) {
            if (!empty($filter) && $filter !== 'all' && $item['type'] !== $filter) continue;
            
            $out = $links->outgoing_count($item['id'], $item['type']);
            $in  = $links->incoming_count($item['id'], $item['type']);
            
            $report[] = array(
                'id'         => $item['id'], 
                'type'       => $item['type'], 
                'type_label' => $this->get_type_label($item['type']),
                'title'      => $item['title'], 
                'url'        => $item['url'], 
                'out_links'  => $out, 
                'in_links'   => $in, 
                'is_orphan'  => ($in === 0)
            );
        }
        return rest_ensure_response($report);
    }

    public function get_item_details($request) {
        $links = new \Khazan\Repository\LinkRepository();
        $id = absint($request->get_param('id'));
        $type = sanitize_text_field($request->get_param('type'));
        
        $out = $links->outgoing_links($id, $type);
        $in  = $links->incoming_links($id, $type);
        
        foreach ($in as &$link) {
            $link['type_label'] = $this->get_type_label($link['source_type']);
        }

        return rest_ensure_response(array('id' => $id, 'type' => $type, 'incoming_count' => count($in), 'outgoing_count' => count($out), 'incoming_links' => $in, 'outgoing_links' => $out));
    }

    public function get_orphan_items() {
        $scanner = new \Khazan\Services\Scan\ScannerService();
        $orphans = \Khazan\Services\Links\OrphanFinder::calculate($scanner->get_all_scannable_items());
        
        $priorities = array('product_cat' => 1, 'product' => 2, 'post' => 3, 'page' => 4);
        foreach ($orphans['list'] as &$item) {
            $item['type_label'] = $this->get_type_label($item['type']);
            $item['priority'] = isset($priorities[$item['type']]) ? $priorities[$item['type']] : 3;
        }
        unset($item);
        usort($orphans['list'], function ($a, $b) {
            if ($a['priority'] !== $b['priority']) return $a['priority'] - $b['priority'];
            return strcasecmp($a['title'], $b['title']);
        });
        return rest_ensure_response($orphans['list']);
    }
}
