<?php
/**
 * Keyphrase density and distribution guard for product-category descriptions (Yoast "keyphrase density" and
 * "keyphrase distribution"). The exact focus keyphrase must appear 4-6 times and be spread over the whole text:
 * the intro paragraph, at least two H2 headings, a middle paragraph and the closing paragraph.
 *
 * A model text that is too sparse gets a short natural sentence in the sections that miss the keyphrase; a text that is
 * too dense loses its surplus repetitions (clustered ones first) to a neutral phrase. Occurrences inside links are
 * counted but never edited, so links stay intact.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content\Guard;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class CategoryKeyphraseDensityGuard {

    const NEUTRAL = array('این دسته‌بندی', 'این مجموعه', 'این مدل‌ها');

    const HEADINGS = array(
        'راهنمای خرید و انتخاب بهترین %s',
        'عوامل موثر بر قیمت %s و ارزش خرید آن',
        'سوالات متداول درباره خرید %s',
    );

    const SENTENCES = array(
        'intro' => array(
            ' در این صفحه، انواع %s را با قیمت مناسب و کیفیت تضمین‌شده مشاهده می‌کنید.',
            ' اگر به دنبال خرید %s هستید، این راهنما انتخاب را برایتان ساده می‌کند.',
        ),
        'middle' => array(
            ' هنگام خرید %s، به جنس چرم، دوخت و راحتی توجه کنید.',
            ' در انتخاب %s، کیفیت مواد اولیه مهم‌ترین معیار است.',
        ),
        'end' => array(
            ' با توجه به این نکات، انتخاب %s مناسب دیگر کار دشواری نیست.',
            ' اکنون می‌توانید %s مورد نظر را با اطمینان سفارش دهید.',
        ),
        'extra' => array(
            ' %s را می‌توان در سبک‌ها و رنگ‌های متنوع پیدا کرد.',
            ' برای هر سلیقه‌ای، %s متفاوتی در این مجموعه وجود دارد.',
        ),
    );

    /**
     * @param string $html  description HTML (headings, paragraphs, lists)
     * @param string $focus exact focus keyphrase (the category name)
     * @param int    $seed  picks the sentence variants deterministically
     */
    public static function enforce($html, $focus, $seed = 0, $min = Settings::CATEGORY_KEYWORD_MIN, $max = Settings::CATEGORY_KEYWORD_MAX) {
        $focus = trim(preg_replace('/\s+/u', ' ', (string) $focus));
        $html = (string) $html;
        if ($focus === '' || trim($html) === '') return $html;

        $html = self::ensure_headings($html, $focus, $max);
        $html = self::trim_surplus($html, $focus, $seed, $max);
        return self::fill_missing($html, $focus, $seed, $min, $max);
    }

    /** Total exact occurrences in the visible text (headings and link texts included, like Yoast). */
    public static function count($html, $focus) {
        return $focus === '' ? 0 : substr_count(html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8'), $focus);
    }

    /** Per-section report used by the unit tests and the admin diagnostics. */
    public static function distribution($html, $focus) {
        $blocks = self::scan((string) $html, $focus)['blocks'];
        $paragraphs = array_values(array_filter($blocks, function ($b) { return $b['tag'] === 'p'; }));
        $h2 = array_filter($blocks, function ($b) use ($focus) { return $b['tag'] === 'h2' && $b['count'] > 0; });
        $middle = self::middle_index($paragraphs);
        return array(
            'total'      => self::count($html, $focus),
            'intro'      => $paragraphs ? $paragraphs[0]['count'] : 0,
            'h2'         => count($h2),
            'middle'     => $middle >= 0 ? $paragraphs[$middle]['count'] : 0,
            'conclusion' => $paragraphs ? $paragraphs[count($paragraphs) - 1]['count'] : 0,
        );
    }

    // ---- headings ----

    /** At least two H2 contain the keyphrase (templates for the missing ones); at most three keep it. */
    private static function ensure_headings($html, $focus, $max) {
        $scan = self::scan($html, $focus);
        $tokens = $scan['tokens'];
        $h2 = array_values(array_filter($scan['blocks'], function ($b) { return $b['tag'] === 'h2'; }));
        $with = array_values(array_filter($h2, function ($b) { return $b['count'] > 0; }));

        $templates = self::HEADINGS;
        if (count($with) < 2) {
            $need = 2 - count($with);
            foreach ($h2 as $block) {
                if ($need < 1 || !$templates) break;
                if ($block['count'] > 0) continue;
                $tokens = self::set_text($tokens, $block, sprintf(array_shift($templates), $focus));
                $need--;
            }
        } elseif (count($with) > 3) {
            foreach (array_slice($with, 3) as $block) {
                $tokens = self::replace_in_block($tokens, $block, $focus, self::NEUTRAL[0], PHP_INT_MAX);
            }
        }
        return implode('', $tokens);
    }

    // ---- too dense ----

    private static function trim_surplus($html, $focus, $seed, $max) {
        $excess = self::count($html, $focus) - $max;
        if ($excess < 1) return $html;
        $scan = self::scan($html, $focus);
        $tokens = $scan['tokens'];
        $paragraphs = array_values(array_filter($scan['blocks'], function ($b) { return $b['tag'] === 'p'; }));
        $protected = array();
        if ($paragraphs) {
            $protected[0] = true;
            $protected[count($paragraphs) - 1] = true;
            $middle = self::middle_index($paragraphs);
            if ($middle >= 0) $protected[$middle] = true;
        }

        // pass 0: clustered repetitions shrink to one per paragraph; pass 1: then unprotected paragraphs lose theirs
        // (the intro, the middle and the closing paragraph keep the keyphrase for the distribution check)
        for ($pass = 0; $pass < 2 && $excess > 0; $pass++) {
            for ($i = count($paragraphs) - 1; $i >= 0 && $excess > 0; $i--) {
                $removable = $paragraphs[$i]['editable'] - ($pass === 0 ? 1 : 0);
                if ($removable < 1 || ($pass === 1 && !empty($protected[$i]))) continue;
                $take = min($removable, $excess);
                $tokens = self::replace_in_block($tokens, $paragraphs[$i], $focus, self::NEUTRAL[(abs((int) $seed) + $i) % count(self::NEUTRAL)], $take);
                $excess -= $take;
                $scan = self::scan(implode('', $tokens), $focus);
                $tokens = $scan['tokens'];
                $paragraphs = array_values(array_filter($scan['blocks'], function ($b) { return $b['tag'] === 'p'; }));
            }
        }
        return implode('', $tokens);
    }

    // ---- too sparse / badly distributed ----

    private static function fill_missing($html, $focus, $seed, $min, $max) {
        $guard = 0;
        while ($guard++ < 8) {
            $scan = self::scan($html, $focus);
            $paragraphs = array_values(array_filter($scan['blocks'], function ($b) { return $b['tag'] === 'p'; }));
            if (!$paragraphs) return $html;
            $total = self::count($html, $focus);
            $slot = self::next_slot($paragraphs, $total, $min, $max);
            if ($slot === null) return $html;

            $variants = self::SENTENCES[$slot[1]];
            $sentence = sprintf($variants[(abs((int) $seed) + $slot[0]) % count($variants)], $focus);
            $html = implode('', self::append_sentence($scan['tokens'], $paragraphs[$slot[0]], $sentence));
        }
        return $html;
    }

    /**
     * @return array|null [paragraph index, kind] of the next paragraph that needs the keyphrase
     */
    private static function next_slot(array $paragraphs, $total, $min, $max) {
        $last = count($paragraphs) - 1;
        $middle = self::middle_index($paragraphs);
        $wanted = array(array(0, 'intro'), array($last, 'end'));
        if ($middle > 0 && $middle < $last) $wanted[] = array($middle, 'middle');

        foreach ($wanted as $slot) {
            if ($paragraphs[$slot[0]]['count'] < 1 && $total < $max) return $slot;
        }
        if ($total >= $min) return null;
        // still under the minimum: the longest keyphrase-free body paragraphs, spread from the start
        foreach ($paragraphs as $i => $p) {
            if ($p['count'] < 1 && $p['words'] >= 12) return array($i, 'extra');
        }
        return null;
    }

    private static function middle_index(array $paragraphs) {
        $n = count($paragraphs);
        return $n >= 3 ? (int) floor(($n - 1) / 2) : -1;
    }

    // ---- token helpers ----

    /**
     * @return array{tokens:string[],blocks:array[]} blocks = top-level p / h2 / h3 / li with open/close token index,
     *         total keyphrase count, count outside links (editable) and word count
     */
    private static function scan($html, $focus) {
        $tokens = preg_split('~(<[^>]+>)~', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $blocks = array();
        $current = null;
        $anchor = 0;
        foreach ($tokens as $i => $token) {
            if ($token[0] === '<') {
                if (preg_match('~^<(p|h2|h3|li)\b~i', $token, $m) && $current === null) {
                    $current = array('tag' => strtolower($m[1]), 'open' => $i, 'close' => $i, 'count' => 0, 'editable' => 0, 'words' => 0);
                } elseif ($current !== null && preg_match('~^</' . $current['tag'] . '>~i', $token)) {
                    $current['close'] = $i;
                    $blocks[] = $current;
                    $current = null;
                    $anchor = 0;
                } elseif (preg_match('~^<a\b~i', $token)) {
                    $anchor++;
                } elseif (preg_match('~^</a>~i', $token)) {
                    $anchor = max(0, $anchor - 1);
                }
                continue;
            }
            if ($current === null) continue;
            $text = html_entity_decode($token, ENT_QUOTES, 'UTF-8');
            $hits = substr_count($text, $focus);
            $current['count'] += $hits;
            if ($anchor === 0) $current['editable'] += $hits;
            $current['words'] += count(array_filter(preg_split('/\s+/u', trim($text)), 'strlen'));
        }
        return array('tokens' => $tokens, 'blocks' => $blocks);
    }

    /** Replaces the whole text of a heading block (inline markup inside it is dropped). */
    private static function set_text(array $tokens, array $block, $text) {
        for ($i = $block['open'] + 1; $i < $block['close']; $i++) $tokens[$i] = '';
        $tokens[$block['open'] + 1] = esc_html($text);
        if ($block['close'] === $block['open'] + 1) $tokens[$block['close']] = esc_html($text) . $tokens[$block['close']];
        return $tokens;
    }

    /** Replaces up to $limit occurrences in the text tokens of a block that are not inside a link. */
    private static function replace_in_block(array $tokens, array $block, $needle, $replacement, $limit) {
        $anchor = 0;
        for ($i = $block['open'] + 1; $i < $block['close'] && $limit > 0; $i++) {
            $token = $tokens[$i];
            if ($token[0] === '<') {
                if (preg_match('~^<a\b~i', $token)) $anchor++;
                elseif (preg_match('~^</a>~i', $token)) $anchor = max(0, $anchor - 1);
                continue;
            }
            if ($anchor > 0) continue;
            $parts = explode($needle, $token);
            if (count($parts) === 1) continue;
            $out = $parts[0];
            for ($k = 1; $k < count($parts); $k++) {
                $out .= ($limit > 0 ? $replacement : $needle) . $parts[$k];
                $limit--;
            }
            $tokens[$i] = $out;
        }
        return $tokens;
    }

    private static function append_sentence(array $tokens, array $block, $sentence) {
        $plain = rtrim(html_entity_decode(strip_tags(implode('', array_slice($tokens, $block['open'], $block['close'] - $block['open']))), ENT_QUOTES, 'UTF-8'));
        $stop = preg_match('~[.!؟?…:]$~u', $plain) ? '' : '.';
        $tokens[$block['close']] = $stop . esc_html($sentence) . $tokens[$block['close']];
        return $tokens;
    }
}
