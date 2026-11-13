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
        // a short paragraph left behind the closing call to action that only points to other pages goes away with its links
        $tail = preg_replace_callback('~<p\b[^>]*>.*?</p>\s*~is', function ($pm) use (&$links, $home_host) {
            if (!preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', $pm[0], $am, PREG_SET_ORDER)) return $pm[0];
            $words = count(preg_split('/\s+/u', trim(html_entity_decode(strip_tags($pm[0]), ENT_QUOTES, 'UTF-8')), -1, PREG_SPLIT_NO_EMPTY));
            if ($words > 40) return $pm[0];
            $rows = array();
            foreach ($am as $m) {
                $href = trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
                $h = strtolower((string) parse_url($href, PHP_URL_HOST));
                $internal = $href !== '' && $href[0] !== '#' && ($h === '' || ($home_host !== '' && ($h === $home_host || $h === 'www.' . $home_host)));
                if (!$internal) return $pm[0];
                $rows[] = array('url' => $href, 'anchor' => trim(strip_tags($m[3])));
            }
            foreach ($rows as $r) $links[] = $r;
            return '';
        }, $tail);
        $anchor_re = '~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is';
        for ($guard = 0; $guard < 40; $guard++) {
            $found = null;
            if (preg_match_all($anchor_re, $tail, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    $href = trim(html_entity_decode($m[2][0], ENT_QUOTES, 'UTF-8'));
                    $h = strtolower((string) parse_url($href, PHP_URL_HOST));
                    $internal = $href !== '' && $href[0] !== '#' && ($h === '' || ($home_host !== '' && ($h === $home_host || $h === 'www.' . $home_host)));
                    if ($internal) { $found = array($m, $href); break; }
                }
            }
            if ($found === null) break;
            list($m, $href) = $found;
            $links[] = array('url' => $href, 'anchor' => trim(strip_tags($m[3][0])));
            list($tail) = self::detach_at($tail, $m[0][1], strlen($m[0][0]));
        }
        // links that are still in the body stay there; only the ones that would be lost are handed back
        $kept = array();
        foreach ($links as $l) {
            if (strpos($head, 'href="' . htmlspecialchars($l['url'], ENT_QUOTES, 'UTF-8') . '"') === false && strpos($head, 'href="' . $l['url'] . '"') === false) $kept[] = $l;
        }
        return array('html' => $head . $tail, 'links' => $kept);
    }

    /**
     * Two internal links closer than $min_gap words are separated: the non-protected one is unwrapped (its words stay) and
     * written into the free paragraph farthest from the other links. Category links ($keep) stay where they are. Stops when
     * no free paragraph is left; a link is never dropped.
     *
     * @param string[] $keep urls that must not move
     */
    public static function separate($html, $home_host = '', array $keep = array(), $min_gap = 70) {
        $html = (string) $html;
        $home_host = strtolower((string) $home_host);
        $keep = array_map(function ($u) { return self::trim_slash(html_entity_decode((string) $u, ENT_QUOTES, 'UTF-8')); }, $keep);
        $anchor = '~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is';
        $is_internal = function ($href) use ($home_host) {
            $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
            if ($href === '' || $href[0] === '#') return false;
            $h = strtolower((string) parse_url($href, PHP_URL_HOST));
            return $h === '' || ($home_host !== '' && ($h === $home_host || $h === 'www.' . $home_host));
        };
        for ($round = 0; $round < 6; $round++) {
            // mark every internal link, strip the tags and read the word distance between consecutive markers
            $n = 0; $urls = array();
            $marked = preg_replace_callback($anchor, function ($m) use (&$n, &$urls, $is_internal) {
                if (!$is_internal($m[2])) return $m[3];
                $urls[$n] = self::trim_slash(html_entity_decode(trim($m[2]), ENT_QUOTES, 'UTF-8'));
                return ' ' . "\x01" . $n++ . ' ';
            }, $html);
            $text = html_entity_decode(strip_tags(preg_replace('~</(?:p|li|h[1-6]|div|tr)>~i', ' ', $marked)), ENT_QUOTES, 'UTF-8');
            $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
            $at = array();
            foreach ($words as $i => $w) if (preg_match('/^\x01(\d+)$/', $w, $mm)) $at[(int) $mm[1]] = $i;
            $move = null;
            for ($k = 1; $k < $n; $k++) {
                if (!isset($at[$k], $at[$k - 1]) || $at[$k] - $at[$k - 1] - 1 >= $min_gap) continue;
                $later_free = !in_array($urls[$k], $keep, true);
                $earlier_free = !in_array($urls[$k - 1], $keep, true);
                if ($later_free) $move = $k; elseif ($earlier_free) $move = $k - 1;
                if ($move !== null) break;
            }
            if ($move === null) return $html;
            $moved = null; $without = $html; $seen = 0;
            if (preg_match_all($anchor, $html, $am, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                foreach ($am as $m) {
                    if (!$is_internal($m[2][0])) continue;
                    if ($seen++ !== $move) continue;
                    list($without, $moved) = self::detach_at($html, $m[0][1], strlen($m[0][0]));
                    break;
                }
            }
            if ($moved === null) return $html;
            $placed = self::place_far($without, $home_host, $moved, $min_gap);
            if ($placed === null) $placed = self::run($without, $home_host, $moved);
            if ($placed === $without) return $html;
            $html = $placed;
        }
        return $html;
    }

    /**
     * A URL is linked once: later links to an already linked internal URL are taken out (a pure mention sentence goes
     * with them, otherwise only the tag is dropped). Links to $self_url are always taken out.
     */
    public static function dedupe($html, $home_host = '', $self_url = '') {
        $html = (string) $html;
        $home_host = strtolower((string) $home_host);
        $self = $self_url !== '' ? self::trim_slash(html_entity_decode((string) $self_url, ENT_QUOTES, 'UTF-8')) : '';
        $anchor = '~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is';
        for ($guard = 0; $guard < 60; $guard++) {
            if (!preg_match_all($anchor, $html, $am, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) return $html;
            $seen = array(); $hit = null;
            foreach ($am as $m) {
                $href = trim(html_entity_decode($m[2][0], ENT_QUOTES, 'UTF-8'));
                if ($href === '' || $href[0] === '#') continue;
                $h = strtolower((string) parse_url($href, PHP_URL_HOST));
                if (!($h === '' || ($home_host !== '' && ($h === $home_host || $h === 'www.' . $home_host)))) continue;
                $key = self::trim_slash(preg_replace('~#.*$~', '', $href));
                if (($self !== '' && $key === $self) || isset($seen[$key])) { $hit = $m; break; }
                $seen[$key] = true;
            }
            if ($hit === null) return $html;
            list($html) = self::detach_at($html, $hit[0][1], strlen($hit[0][0]));
        }
        return $html;
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
                // take the last internal link out of the crowded paragraph (a pure mention sentence goes with it)
                $moved = null; $seen = 0; $total = $links[$i];
                if (preg_match_all($anchor, $parts[$i], $am, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                    foreach ($am as $m) {
                        if (!$internal($m[2][0])) continue;
                        if (++$seen < $total) continue;
                        list($parts[$i], $moved) = self::detach_at($parts[$i], $m[0][1], strlen($m[0][0]));
                        break;
                    }
                }
                if ($moved === null) break;
                $sentence = sprintf(self::TEMPLATES[$used++ % count(self::TEMPLATES)], $moved);
                $parts[$best] = preg_replace('~\s*</p>\s*$~i', ' ' . $sentence . '</p>', $parts[$best], 1);
                $links[$i]--; $links[$best] = 1;
            }
        }
        return implode('', $parts);
    }

    /**
     * Takes the link at byte offset $off (length $len) out of its place. A short sentence that only exists to mention
     * the link (the template sentences, "see X") is removed together with it, so moving a link never leaves a second,
     * unlinked mention behind; in any other place only the tag is dropped and the words stay.
     *
     * @return array{0:string,1:string} new html, the removed anchor tag
     */
    private static function detach_at($html, $off, $len) {
        $tag   = substr($html, $off, $len);
        $words = function ($t) { return count(preg_split('/\s+/u', trim(html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8')), -1, PREG_SPLIT_NO_EMPTY)); };
        $plain = substr($html, 0, $off) . strip_tags($tag) . substr($html, $off + $len);
        $unwrapped = substr($html, 0, $off) . preg_replace('~^<a\b[^>]*>|</a>$~i', '', $tag) . substr($html, $off + $len);

        $prefix = substr($html, 0, $off);
        $block = null; $block_end = 0;
        if (preg_match_all('~<(p|li|td|th|h[1-6])\b[^>]*>~i', $prefix, $bm, PREG_OFFSET_CAPTURE)) {
            $last = end($bm[0]); $block = strtolower($bm[1][count($bm[1]) - 1][0]); $block_end = $last[1] + strlen($last[0]);
        }
        if ($block !== 'p') return array($unwrapped, $tag);
        $start = $block_end;
        if (preg_match_all('~[.!?؟…]+(?:\s|&nbsp;)+~u', $prefix, $sm, PREG_OFFSET_CAPTURE)) {
            $last = end($sm[0]);
            if ($last[1] + strlen($last[0]) > $start) $start = $last[1] + strlen($last[0]);
        }
        $after = substr($html, $off + $len);
        $close = stripos($after, '</p>');
        $in_p = $close === false ? $after : substr($after, 0, $close);
        $end = $off + $len + strlen($in_p);
        if (preg_match('~^.*?[.!?؟…]+\s*~su', $in_p, $em)) $end = $off + $len + strlen($em[0]);
        $sentence = substr($html, $start, $end - $start);
        if (substr_count($sentence, '<a ') !== 1 || $words($sentence) > $words($tag) + 10) return array($unwrapped, $tag);

        $out = substr($html, 0, $start) . substr($html, $end);
        $out = preg_replace('~\s+</p>~', '</p>', $out);
        $out = preg_replace('~<p\b[^>]*>\s*</p>\s*~i', '', $out);
        return array($out, $tag);
    }

    /**
     * Writes a link sentence at the end of the link-free body paragraph that is the farthest (in words) from every other
     * internal link; null when no paragraph is at least $min_gap words away (the caller falls back to run()).
     */
    private static function place_far($html, $home_host, $extra, $min_gap) {
        $html = (string) $html;
        $parts = preg_split('~(<p\b[^>]*>.*?</p>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!$parts || count($parts) < 5) return null;
        $home_host = strtolower((string) $home_host);
        $anchor = '~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is';
        $internal = function ($href) use ($home_host) {
            $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
            if ($href === '' || $href[0] === '#') return false;
            $h = strtolower((string) parse_url($href, PHP_URL_HOST));
            return $h === '' || ($home_host !== '' && ($h === $home_host || $h === 'www.' . $home_host));
        };
        $words = function ($t) { return count(preg_split('/\s+/u', trim(html_entity_decode(strip_tags((string) $t), ENT_QUOTES, 'UTF-8')), -1, PREG_SPLIT_NO_EMPTY)); };
        $pos = 0; $faq = false; $para = array(); $link_at = array(); $last_p = null;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                if (preg_match('~<h2\b[^>]*>[^<]*(?:سوال|سؤال|پرسش)[^<]*</h2>~iu', $part)) $faq = true;
                $pos += $words(preg_replace('~</(?:li|h[1-6]|div|tr)>~i', ' ', $part));
                continue;
            }
            $len = $words($part);
            $has = false;
            if (preg_match_all($anchor, $part, $am, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                foreach ($am as $m) {
                    if (!$internal($m[2][0])) continue;
                    $has = true;
                    $link_at[] = $pos + $words(substr($part, 0, $m[0][1]));
                }
            }
            $para[$i] = array('end' => $pos + $len, 'has' => $has, 'faq' => $faq, 'len' => mb_strlen(trim(strip_tags($part))));
            $last_p = $i;
            $pos += $len;
        }
        $best = null; $best_d = -1;
        foreach ($para as $i => $p) {
            if ($p['has'] || $p['faq'] || $i === $last_p || $p['len'] < 60) continue;
            $d = PHP_INT_MAX;
            foreach ($link_at as $at) $d = min($d, abs($p['end'] - $at));
            if ($d > $best_d) { $best_d = $d; $best = $i; }
        }
        if ($best === null || $best_d < $min_gap) return null;
        $used = 0;
        foreach (self::TEMPLATES as $t) {
            $tail = trim(preg_replace('~^.*%s~u', '', $t));
            if ($tail !== '' && $tail !== '.') $used += substr_count($html, preg_replace('~\.$~u', '', $tail) . '.');
        }
        $parts[$best] = preg_replace('~\s*</p>\s*$~i', ' ' . sprintf(self::TEMPLATES[$used % count(self::TEMPLATES)], $extra) . '</p>', $parts[$best], 1);
        return implode('', $parts);
    }

    private static function trim_slash($url) {
        return rtrim((string) $url, '/');
    }
}
