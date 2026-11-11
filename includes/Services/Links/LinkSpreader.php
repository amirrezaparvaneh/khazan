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
    );

    public static function spread($html, $home_host = '') {
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
        foreach ($paras as $pos => $i) {
            while ($links[$i] > 1) {
                // target: the free paragraph farthest from every linked one
                $best = null; $best_dist = 0;
                foreach ($paras as $tpos => $t) {
                    if ($links[$t] > 0 || isset($closed[$t]) || mb_strlen(trim(strip_tags($parts[$t]))) < 60) continue;
                    $dist = PHP_INT_MAX;
                    foreach ($paras as $opos => $o) if ($links[$o] > 0) $dist = min($dist, abs($tpos - $opos));
                    if ($dist > $best_dist) { $best_dist = $dist; $best = $t; }
                }
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
