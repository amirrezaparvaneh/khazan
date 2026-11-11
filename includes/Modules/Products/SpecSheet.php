<?php
/**
 * Product features from a spreadsheet: CSV parsing, column guessing, matching rows to products and
 * borrowing features from similar products of the same category. Pure PHP (no WordPress).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Products;

use Khazan\Services\Links\HtmlLinkText;

if (!defined('ABSPATH')) {
    exit;
}

final class SpecSheet {

    /** Header words that suggest which feature a column holds (normalised, longest hint wins). */
    const HINTS = array(
        'sole_material' => array('زیره', 'کفی', 'sole'),
        'upper_material' => array('رویه', 'چرم', 'جنس چرم', 'upper', 'leather'),
        'lining_material' => array('آستر', 'استر', 'lining'),
        'stitching_type' => array('دوخت', 'stitch'),
        'other_features' => array('ویژگی', 'توضیح', 'مشخصات', 'feature', 'note'),
    );
    const KEY_HINTS = array('sku', 'کد', 'نام محصول', 'نام', 'محصول', 'عنوان', 'title', 'name', 'product', 'لینک', 'url', 'آدرس');

    // ---- CSV ----

    /** @return array rows of cells (first row is the header) */
    public static function parse_csv($text) {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', (string) $text);
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $first = strtok($text, "\n");
        $delim = ',';
        $best = substr_count((string) $first, ',');
        foreach (array(';', "\t") as $d) {
            if (substr_count((string) $first, $d) > $best) { $best = substr_count($first, $d); $delim = $d; }
        }
        $rows = array();
        $row = array();
        $cell = '';
        $quoted = false;
        $n = strlen($text);
        for ($i = 0; $i < $n; $i++) {
            $c = $text[$i];
            if ($quoted) {
                if ($c === '"') {
                    if ($i + 1 < $n && $text[$i + 1] === '"') { $cell .= '"'; $i++; }
                    else $quoted = false;
                } else $cell .= $c;
            } elseif ($c === '"' && $cell === '') {
                $quoted = true;
            } elseif ($c === $delim) {
                $row[] = $cell; $cell = '';
            } elseif ($c === "\n") {
                $row[] = $cell; $cell = '';
                $rows[] = $row; $row = array();
            } else $cell .= $c;
        }
        if ($cell !== '' || $row) { $row[] = $cell; $rows[] = $row; }
        $out = array();
        foreach ($rows as $r) {
            $r = array_map('trim', $r);
            if (implode('', $r) === '') continue;
            $out[] = $r;
        }
        return $out;
    }

    // ---- header row ----

    /**
     * Sheets often start with a title or blank-ish rows; the header is the first row among the top ones that
     * has the most filled cells.
     */
    public static function header_index(array $rows, $look = 15) {
        $best = 0;
        $best_n = -1;
        foreach (array_slice($rows, 0, $look, true) as $i => $r) {
            $n = count(array_filter($r, function ($c) { return trim((string) $c) !== ''; }));
            if ($n > $best_n) { $best_n = $n; $best = $i; }
        }
        return $best;
    }

    // ---- columns ----

    /**
     * Which column is the product key and which columns hold which feature, guessed from the header.
     *
     * @return array key (int|null), fields (field => int|null)
     */
    public static function guess_mapping(array $header) {
        $norm = array_map(function ($h) { return HtmlLinkText::norm_phrase((string) $h); }, $header);
        $fields = array();
        $used = array();
        foreach (self::HINTS as $field => $hints) {
            $fields[$field] = null;
            foreach ($norm as $i => $h) {
                if (isset($used[$i]) || $h === '') continue;
                foreach ($hints as $hint) {
                    if (mb_strpos($h, HtmlLinkText::norm_phrase($hint), 0, 'UTF-8') !== false) { $fields[$field] = $i; $used[$i] = true; break 2; }
                }
            }
        }
        $key = null;
        foreach (self::KEY_HINTS as $hint) {
            foreach ($norm as $i => $h) {
                if (isset($used[$i])) continue;
                if ($h !== '' && mb_strpos($h, HtmlLinkText::norm_phrase($hint), 0, 'UTF-8') !== false) { $key = $i; break 2; }
            }
        }
        if ($key === null) { foreach ($norm as $i => $h) { if (!isset($used[$i])) { $key = $i; break; } } }
        return array('key' => $key, 'fields' => $fields);
    }

    // ---- matching ----

    private static function tokens($s) {
        $n = HtmlLinkText::norm_phrase((string) $s);
        return $n === '' ? array() : array_values(array_unique(explode(' ', $n)));
    }

    /**
     * Matches sheet keys to products.
     *
     * @param string $key a cell from the key column
     * @param array  $products each: id, title, sku, slug
     * @return array state (matched|ambiguous|none), id, how
     */
    public static function match_key($key, array $products) {
        $key = trim((string) $key);
        if ($key === '') return array('state' => 'none', 'id' => 0, 'how' => '');
        $low = mb_strtolower($key, 'UTF-8');
        // a product page address: its last path part is the slug
        $slug = '';
        if (preg_match('#^https?://#i', $key)) $slug = mb_strtolower(rawurldecode(basename(rtrim((string) parse_url($key, PHP_URL_PATH), '/'))), 'UTF-8');
        foreach ($products as $p) {
            if ($p['sku'] !== '' && mb_strtolower($p['sku'], 'UTF-8') === $low) return array('state' => 'matched', 'id' => $p['id'], 'how' => 'sku');
            if ($slug !== '' && mb_strtolower(rawurldecode($p['slug']), 'UTF-8') === $slug) return array('state' => 'matched', 'id' => $p['id'], 'how' => 'url');
        }
        $nk = HtmlLinkText::norm_phrase($key);
        foreach ($products as $p) {
            if (HtmlLinkText::norm_phrase($p['title']) === $nk) return array('state' => 'matched', 'id' => $p['id'], 'how' => 'title');
        }
        $kt = self::tokens($key);
        if (count($kt) < 2) return array('state' => 'none', 'id' => 0, 'how' => '');
        $hits = array();
        foreach ($products as $p) {
            $pt = self::tokens($p['title']);
            if (!$pt) continue;
            if (!array_diff($kt, $pt)) $hits[] = array('id' => $p['id'], 'extra' => count($pt) - count($kt));
        }
        if (!$hits) return array('state' => 'none', 'id' => 0, 'how' => '');
        usort($hits, function ($a, $b) { return $a['extra'] <=> $b['extra']; });
        if (count($hits) > 1 && $hits[0]['extra'] === $hits[1]['extra']) return array('state' => 'ambiguous', 'id' => 0, 'how' => 'part');
        return array('state' => 'matched', 'id' => $hits[0]['id'], 'how' => 'part');
    }

    /**
     * @param array $rows   sheet rows without the header
     * @param array $mapping key (int), fields (field => int|null)
     * @return array matched (id => specs), unmatched (key[]), ambiguous (key[])
     */
    public static function import(array $rows, array $mapping, array $products) {
        $out = array('matched' => array(), 'unmatched' => array(), 'ambiguous' => array(), 'duplicate' => 0);
        foreach ($rows as $r) {
            $key = isset($r[$mapping['key']]) ? $r[$mapping['key']] : '';
            if (trim($key) === '') continue;
            $specs = array();
            foreach ($mapping['fields'] as $field => $col) {
                if ($col === null || !isset($r[$col])) continue;
                $v = trim(preg_replace('/\s+/u', ' ', $r[$col]));
                if ($v !== '') $specs[$field] = $v;
            }
            if (!$specs) continue;
            $m = self::match_key($key, $products);
            if ($m['state'] === 'none') { $out['unmatched'][] = $key; continue; }
            if ($m['state'] === 'ambiguous') { $out['ambiguous'][] = $key; continue; }
            if (isset($out['matched'][$m['id']])) $out['duplicate']++;
            $out['matched'][$m['id']] = array('specs' => $specs, 'how' => $m['how'], 'key' => $key);
        }
        return $out;
    }

    // ---- borrowing from similar products ----

    /**
     * Features for a product without any, from products of the same category that have them.
     *
     * @param array $product id, title, cats (term ids)
     * @param array $pool    each: id, title, cats, specs
     * @param array $fields  feature keys to fill
     * @return array specs (field => value), from (product ids)
     */
    public static function infer(array $product, array $pool, array $fields) {
        $pt = self::tokens($product['title']);
        $scored = array();
        foreach ($pool as $q) {
            if ($q['id'] === $product['id']) continue;
            $shared_cats = array_intersect($product['cats'], $q['cats']);
            if (!$shared_cats) continue;
            $qt = self::tokens($q['title']);
            $overlap = $pt ? count(array_intersect($pt, $qt)) / count($pt) : 0;
            $score = $overlap + 0.15 * min(2, count($shared_cats));
            if ($score < 0.2) continue;
            $scored[] = array('q' => $q, 'score' => $score);
        }
        usort($scored, function ($a, $b) { return $b['score'] <=> $a['score']; });
        $top = array_slice($scored, 0, 5);
        $specs = array();
        $from = array();
        foreach ($fields as $field) {
            $votes = array();
            $voters = array();
            $total = 0.0;
            $count = 0;
            foreach ($top as $t) {
                if (empty($t['q']['specs'][$field])) continue;
                $v = $t['q']['specs'][$field];
                $votes[$v] = (isset($votes[$v]) ? $votes[$v] : 0) + $t['score'];
                $total += $t['score'];
                $count++;
                $voters[$t['q']['id']] = $v;
            }
            if (!$votes) continue;
            arsort($votes);
            $winner = key($votes);
            $share = $votes[$winner] / $total;
            $agree = 0;
            foreach ($top as $t) { if (isset($t['q']['specs'][$field]) && $t['q']['specs'][$field] === $winner) $agree++; }
            if ($share >= 0.5 && ($agree >= 2 || ($count === 1 && $votes[$winner] >= 0.5))) {
                $specs[$field] = (string) $winner;
                foreach ($voters as $id => $v) { if ($v === $winner) $from[$id] = true; }
            }
        }
        return array('specs' => $specs, 'from' => array_keys($from));
    }
}
