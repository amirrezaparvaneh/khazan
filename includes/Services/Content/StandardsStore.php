<?php
/**
 * Saves the owner-confirmed rules: business facts, verified external sources, the SEO standards and the keyword map.
 * Every value is validated here (pure functions), so the writer can trust what it reads from the options.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content;

if (!defined('ABSPATH')) {
    exit;
}

final class StandardsStore {

    /** Verified sources from lines «URL | title»; only https URLs are kept. */
    public static function parse_sources($text) {
        $out = array();
        foreach (preg_split('/\R/u', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line, 2));
            $url = esc_url_raw($parts[0], array('https'));
            if ($url === '' || stripos($url, 'https://') !== 0) continue;
            $out[$url] = array('url' => $url, 'title' => isset($parts[1]) ? sanitize_text_field($parts[1]) : '');
        }
        return array_values($out);
    }

    public static function sources_text(array $sources) {
        $lines = array();
        foreach ($sources as $s) $lines[] = $s['url'] . ($s['title'] !== '' ? ' | ' . $s['title'] : '');
        return implode("\n", $lines);
    }

    private static function slugs($text) {
        $out = array();
        foreach (preg_split('/[\s,،]+/u', (string) $text) as $slug) {
            $slug = sanitize_title($slug);
            if ($slug !== '') $out[$slug] = $slug;
        }
        return array_values($out);
    }

    /** @return array facts that can be stored in BusinessFacts::OPTION */
    public static function sanitize_facts(array $in) {
        $facts = array();
        if (array_key_exists('free_shipping', $in)) $facts['free_shipping'] = !empty($in['free_shipping']);
        if (isset($in['return_days'])) $facts['return_days'] = max(0, min(60, (int) $in['return_days']));
        foreach (array('installments', 'hardware') as $key) {
            if (isset($in[$key])) $facts[$key] = sanitize_text_field($in[$key]);
        }
        foreach (array('plain_leather_slugs', 'tabriz_keyword_slugs') as $key) {
            if (isset($in[$key])) $facts[$key] = self::slugs(is_array($in[$key]) ? implode(' ', $in[$key]) : $in[$key]);
        }
        if (isset($in['verified_sources'])) {
            $facts['verified_sources'] = is_array($in['verified_sources']) ? self::parse_sources(self::sources_text(array_map(function ($s) {
                return array('url' => isset($s['url']) ? $s['url'] : '', 'title' => isset($s['title']) ? $s['title'] : '');
            }, $in['verified_sources']))) : self::parse_sources($in['verified_sources']);
        }
        return $facts;
    }

    /** Only the known numeric / list keys of SeoStandards, with the type of their default. */
    public static function sanitize_seo(array $in) {
        $defaults = SeoStandards::defaults();
        $out = array();
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $in)) continue;
            $value = $in[$key];
            if (is_array($default)) {
                if (is_string($value)) $value = preg_split('/[\s,،\-]+/u', trim($value));
                if (!is_array($value)) continue;
                if ($key === 'title_forbidden') { $out[$key] = array_values(array_filter(array_map('sanitize_text_field', $value))); continue; }
                $nums = array_values(array_map('intval', array_filter($value, 'strlen')));
                if (count($nums) === 2 && $nums[0] >= 0 && $nums[1] >= $nums[0]) $out[$key] = $nums;
            } elseif (is_bool($default)) {
                $out[$key] = (bool) $value;
            } elseif (is_float($default)) {
                $out[$key] = max(0.0, min(100.0, (float) $value));
            } else {
                $out[$key] = max(0, min(100000, (int) $value));
            }
        }
        if (isset($out['density_min'], $out['density_max']) && $out['density_min'] > $out['density_max']) unset($out['density_min'], $out['density_max']);
        if (isset($out['title_min'], $out['title_max']) && $out['title_min'] > $out['title_max']) unset($out['title_min'], $out['title_max']);
        if (isset($out['meta_min'], $out['meta_max']) && $out['meta_min'] > $out['meta_max']) unset($out['meta_min'], $out['meta_max']);
        return $out;
    }

    /** Parses the text of the project's ownership.csv into the keyword map (rows with a header line). */
    public static function keyword_map_from_csv($text) {
        $lines = preg_split('/\R/u', trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $text)));
        if (count($lines) < 2) return array();
        $header = array_map('trim', str_getcsv(array_shift($lines)));
        $rows = array();
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cells = str_getcsv($line);
            if (count($cells) < count($header)) $cells = array_pad($cells, count($header), '');
            $rows[] = array_combine($header, array_slice($cells, 0, count($header)));
        }
        return KeywordMap::from_ownership($rows);
    }
}
