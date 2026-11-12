<?php
namespace Khazan\Services\Links;

if (!defined('ABSPATH')) exit;

/**
 * Spreads internal links through the text after generation: a paragraph keeps one internal link; every further link of that
 * paragraph is unwrapped in place (its words stay) and re-linked in a short sentence at the end of a link-free paragraph
 * elsewhere in the body. Pure: no link is dropped (when no free paragraph exists the link stays where it was).
 */
class LinkSpreader {
    const TEMPLATES = array(
        'برای مقایسه‌ی بیشتر، نگاهی به %s هم بیندازید.',
        '%s هم گزینه‌ای است که می‌توانید کنار این مدل بررسی کنید.',
        'اگر می‌خواهید انتخاب دیگری را هم ببینید، %s ارزش نگاه‌کردن دارد.',
        'اگر سلیقه‌تان کمی فرق دارد، %s را هم در نظر بگیرید.',
        'برای پیدا کردن مدل نزدیک به این سبک، به %s سر بزنید.',
        'در همین فضا، %s هم می‌تواند انتخاب شما باشد.',
    );


    /**
     * Planned links the model left out are written into link-free paragraphs of the body (same short sentences as spread()).
     * Returns the html and the links that still found no free paragraph.
     *
     * @param array[] $missing rows with url + anchor
     * @return array{html:string,missing:array[]}
     */
    public static function place($html, array $missing, $home_host = '') {
        $html = (string) $html;
        $left = array();
        foreach ($missing as $link) {
            if (empty($link['url']) || empty($link['anchor'])) { $left[] = $link; continue; }
            $a = '<a href="' . htmlspecialchars((string) $link['url'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $link['anchor'], ENT_QUOTES, 'UTF-8') . '</a>';
            $out = self::run($html, $home_host, $a);
            if ($out === $html) { $left[] = $link; continue; }
            $html = $out;
        }
        return array('html' => $html, 'missing' => $left);
    }

    /**
     * Paragraphs after the last FAQ answer (the closing call to action and anything the model appended after it) carry no links:
     * their internal links are unwrapped (the words stay) and handed back so place() can write them into the body.
     *
     * @return array{html:string,links:array[]} links: rows with url + anchor
     */
    public static function evict_closing($html, $home_host = '') {
        $html = (string) $html;
        $home_host = strtolower((string) $home_host);
        $pos = strripos($html, '<h3');
        if ($pos === false) return array('html' => $html, 'links' => array());
        $end = stripos($html, '</p>', $pos);
        if ($end === false) return array('html' => $html, 'links' => array());
        $head = substr($html, 0, $end + 4); $tail = substr($html, $end + 4);
        $links = array();
        $tail = preg_replace_callback('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', function ($m) use (&$links, $home_host) {
            $href = trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
            $h = strtolower((string) parse_url($href, PHP_URL_HOST));
            $internal = $href !== '' && $href[0] !== '#' && ($h === '' || ($home_host !== '' && ($h === $home_host || $h === 'www.' . $home_host)));
            if (!$internal) return $m[0];
            $links[] = array('url' => $href, 'anchor' => trim(strip_tags($m[3])));
            return $m[3];
        }, $tail);
        // links that are still in the body stay there; only the ones that would be lost are handed back
        $kept = array();
        foreach ($links as $l) {
            if (strpos($head, 'href="' . htmlspecialchars($l['url'], ENT_QUOTES, 'UTF-8') . '"') === false && strpos($head, 'href="' . $l['url'] . '"') === false) $kept[] = $l;
        }
        return array('html' => $head . $tail, 'links' => $kept);
    }

    public static function spread($html, $home_host = '') {
        return self::run($html, $home_host, null);
    }

    /** $extra: a ready anchor to write into the best free paragraph (place); null: move crowded links (spread). */
    private static function run($html, $home_host, $extra) {
        $html = (string) $html;
        $parts = preg_split('~(<p\b[^>]*>.*?</p>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!$parts || count($parts) < 5) return $html;
        $home_host = strtolower((string) $home_host);
        $anchor = '~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is';
        $internal = function ($href) use ($home_host) {
            $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
            if ($href === '' || $href[0] === '#') return false;
            $h = strtolower((string) parse_url($href, PHP_URL_HOST));
            return $h === '' || ($home_host !== '' && ($h === $home_host || $h === 'www.' . $home_host));
        };
        $count = function ($block) use ($anchor, $internal) {
            if (!preg_match_all($anchor, $block, $m)) return 0;
            return count(array_filter($m[2], $internal));
        };

        // paragraph indexes in document order; the FAQ and the closing call to action never receive a moved link
        $paras = array(); $faq = false; $closed = array();
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                if (preg_match('~<h2\b[^>]*>[^<]*(?:سوال|سؤال|پرسش)[^<]*</h2>~iu', $part)) $faq = true;
                continue;
            }
            $paras[] = $i;
            if ($faq) $closed[$i] = true;
        }
        if (count($paras) < 3) return $html;
        $closed[end($paras)] = true;

        $links = array();
        foreach ($paras as $i) $links[$i] = $count($parts[$i]);
        $used = 0;
        foreach (self::TEMPLATES as $t) {
            $tail = trim(preg_replace('~^.*%s~u', '', $t));
            if ($tail !== '' && $tail !== '.') $used += substr_count($html, preg_replace('~\.$~u', '', $tail) . '.');
        }
        $pick = function () use (&$links, $paras, $closed, &$parts) {
            $best = null; $best_dist = 0;
            foreach ($paras as $tpos => $t) {
                if ($links[$t] > 0 || isset($closed[$t]) || mb_strlen(trim(strip_tags($parts[$t]))) < 60) continue;
                $dist = PHP_INT_MAX;
                foreach ($paras as $opos => $o) if ($links[$o] > 0) $dist = min($dist, abs($tpos - $opos));
                if ($dist > $best_dist) { $best_dist = $dist; $best = $t; }
            }
            return $best;
        };
        if ($extra !== null) {
            $best = $pick();
            if ($best === null) return $html;
            $parts[$best] = preg_replace('~\s*</p>\s*$~i', ' ' . sprintf(self::TEMPLATES[$used % count(self::TEMPLATES)], $extra) . '</p>', $parts[$best], 1);
            return implode('', $parts);
        }
        foreach ($paras as $pos => $i) {
            while ($links[$i] > 1) {
                $best = $pick();
                if ($best === null) break;
                // unwrap the last internal link of the crowded paragraph, keep its words
                $moved = null; $seen = 0; $total = $links[$i];
                $parts[$i] = preg_replace_callback($anchor, function ($m) use (&$moved, &$seen, $total, $internal) {
                    if (!$internal($m[2])) return $m[0];
                    if (++$seen < $total) return $m[0];
                    $moved = $m[0];
                    return $m[3];
                }, $parts[$i]);
                if ($moved === null) break;
                $sentence = sprintf(self::TEMPLATES[$used++ % count(self::TEMPLATES)], $moved);
                $parts[$best] = preg_replace('~\s*</p>\s*$~i', ' ' . $sentence . '</p>', $parts[$best], 1);
                $links[$i]--; $links[$best] = 1;
            }
        }
        return implode('', $parts);
    }
}
