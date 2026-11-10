<?php
/**
 * Finds natural places for an internal link inside existing HTML and inserts it without touching anything else.
 *
 * The HTML is split into tags and text pieces; only text that sits in a paragraph-like context (not in a heading, an
 * existing link, a caption, a table, code or a shortcode) is a candidate. The same tokenizer is used to find and to
 * insert, so a spot that was found is exactly the spot that gets linked, and every other byte of the post stays as it
 * was (block comments, attributes, spacing).
 *
 * Pure PHP (no WordPress), so it is fully unit tested.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

if (!defined('ABSPATH')) {
    exit;
}

final class HtmlLinkText {

    /** Text inside these is never linked. */
    const SKIP = array('a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'code', 'pre', 'button', 'figcaption', 'table', 'select', 'textarea', 'label', 'nav', 'svg', 'iframe', 'noscript');
    /** A paragraph-like container: one "block" for the per-paragraph link limit. */
    const BLOCKS = array('p', 'li', 'dd', 'blockquote');
    /** Block-level tags that end a free-text flow (text outside <p>, e.g. classic-editor posts). */
    const FLOW_BREAKS = array('div', 'p', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'blockquote', 'figure', 'section', 'article', 'hr', 'br');
    const VOID = array('br', 'hr', 'img', 'input', 'meta', 'link', 'source', 'wbr', 'col', 'area', 'base', 'embed', 'param', 'track');

    // ---- text normalisation (one character in, one character out, so positions stay aligned) ----

    private static $map = array(
        'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
        "\u{200C}" => ' ', "\u{00A0}" => ' ', "\t" => ' ', "\n" => ' ', "\r" => ' ',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    );

    /** @return string[] characters, normalised one-to-one */
    public static function norm_chars($text) {
        $chars = preg_split('//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) return array();
        foreach ($chars as $i => $c) {
            $c = isset(self::$map[$c]) ? self::$map[$c] : $c;
            $lower = mb_strtolower($c, 'UTF-8');
            $chars[$i] = mb_strlen($lower, 'UTF-8') === 1 ? $lower : $c;
        }
        return $chars;
    }

    public static function norm($text) {
        return implode('', self::norm_chars($text));
    }

    /** A phrase in matching form: normalised, single spaces, trimmed. */
    public static function norm_phrase($phrase) {
        return trim(preg_replace('/ +/u', ' ', self::norm(html_entity_decode((string) $phrase, ENT_QUOTES, 'UTF-8'))));
    }

    // ---- URLs ----

    /**
     * Comparable form of a link: decoded lower-case path without query, fragment or trailing slash. Null for links to
     * other sites, anchors, mailto and the like. Relative links count as internal.
     */
    public static function url_key($href, $home_host) {
        $href = trim(html_entity_decode((string) $href, ENT_QUOTES, 'UTF-8'));
        if ($href === '' || $href[0] === '#' || preg_match('#^(mailto|tel|javascript|data|sms|whatsapp):#i', $href)) return null;
        if (strpos($href, '//') === 0) $href = 'https:' . $href;
        $parts = parse_url($href);
        if ($parts === false) return null;
        if (!empty($parts['host'])) {
            $host = preg_replace('/^www\./', '', strtolower($parts['host']));
            $home = preg_replace('/^www\./', '', strtolower((string) $home_host));
            if ($host !== $home) return null;
        } elseif (!empty($parts['scheme'])) {
            return null;
        }
        $path = isset($parts['path']) ? $parts['path'] : '/';
        if ($path === '' || ($path[0] !== '/' && empty($parts['host']))) {
            // a bare relative path like "foo/bar" is relative to an unknown page: compare it as a root path
            $path = '/' . $path;
        }
        $path = mb_strtolower(rawurldecode($path), 'UTF-8');
        $path = preg_replace('#/+#', '/', $path);
        $path = preg_replace('#/(amp|feed)$#', '', rtrim($path, '/'));
        return $path === '' ? '/' : $path;
    }

    // ---- tokenizer ----

    /**
     * @return array list of tokens: array('t' => 'tag'|'text'|'comment', 'raw' => string, 'name' => tag name,
     *               'close' => bool, 'href' => string|null) plus, for text: 'ctx' => 'ok'|'skip', 'block' => key
     *               and per document: 'links' => array(block key => count), 'hrefs' => string[]
     */
    public static function tokenize($html) {
        $parts = preg_split('/(<!--.*?-->|<[^>]*>)/s', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $tokens = array();
        $stack = array();       // open element names
        $block_ids = array();   // stack of open block ids (parallel to block elements)
        $next_block = 0;
        $flow = 0;              // free-text paragraph counter
        $links = array();
        $hrefs = array();
        foreach ($parts as $raw) {
            if (strpos($raw, '<!--') === 0) {
                $tokens[] = array('t' => 'comment', 'raw' => $raw);
                continue;
            }
            if ($raw !== '' && $raw[0] === '<' && preg_match('#^<\s*(/)?\s*([a-zA-Z][a-zA-Z0-9-]*)([^>]*)>$#s', $raw, $m)) {
                $name = strtolower($m[2]);
                $close = $m[1] === '/';
                $self = !$close && (in_array($name, self::VOID, true) || substr(rtrim($m[3]), -1) === '/');
                $href = null;
                if (!$close && $name === 'a' && preg_match('#\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $m[3], $h)) {
                    $href = isset($h[4]) && $h[4] !== '' ? $h[4] : (isset($h[3]) && $h[3] !== '' ? $h[3] : $h[2]);
                }
                if (in_array($name, self::FLOW_BREAKS, true)) $flow++;
                if ($close) {
                    // pop to the matching element (tolerates unclosed children)
                    for ($i = count($stack) - 1; $i >= 0; $i--) {
                        if ($stack[$i] === $name) {
                            $removed = array_splice($stack, $i);
                            foreach ($removed as $r) if (in_array($r, self::BLOCKS, true)) array_pop($block_ids);
                            break;
                        }
                    }
                } elseif (!$self) {
                    // an implicitly closed <p> or <li> (a new one opens before the old one closed)
                    if (in_array($name, array('p', 'li'), true) && $stack && end($stack) === $name) {
                        array_pop($stack);
                        array_pop($block_ids);
                    }
                    $stack[] = $name;
                    if (in_array($name, self::BLOCKS, true)) $block_ids[] = 'b' . ($next_block++);
                }
                if ($name === 'a' && !$close) {
                    $key = $block_ids ? end($block_ids) : 'f' . $flow;
                    $links[$key] = (isset($links[$key]) ? $links[$key] : 0) + 1;
                    if ($href !== null) $hrefs[] = $href;
                }
                $tokens[] = array('t' => 'tag', 'raw' => $raw, 'name' => $name, 'close' => $close, 'href' => $href);
                continue;
            }
            // text
            $skip = (bool) array_intersect($stack, self::SKIP);
            if ($block_ids) {
                $tokens[] = array('t' => 'text', 'raw' => $raw, 'ctx' => $skip ? 'skip' : 'ok', 'block' => end($block_ids), 'flow' => false);
            } else {
                $tokens[] = array('t' => 'text', 'raw' => $raw, 'ctx' => $skip ? 'skip' : 'ok', 'block' => 'f' . $flow, 'flow' => true, 'flow_base' => $flow);
                // blank lines split free text into paragraphs; count them so later links land in the right block
                $flow += substr_count(preg_replace("/\r\n?/", "\n", $raw), "\n\n");
            }
        }
        return array('tokens' => $tokens, 'links' => $links, 'hrefs' => $hrefs);
    }

    /** Plain text of the whole document (for word counts and context). */
    public static function plain($html) {
        $out = '';
        foreach (self::tokenize($html)['tokens'] as $t) if ($t['t'] === 'text') $out .= $t['raw'];
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($out, ENT_QUOTES, 'UTF-8')));
    }

    private static function is_letter($c) {
        return $c !== null && $c !== '' && preg_match('/^[\p{L}\p{N}\p{M}_]$/u', $c);
    }

    /** Block key of a match inside a free-text token (counts blank lines before it). */
    private static function flow_block(array $token, $char_offset) {
        if (!$token['flow']) return $token['block'];
        $before = mb_substr($token['raw'], 0, $char_offset, 'UTF-8');
        return 'f' . ($token['flow_base'] + substr_count(preg_replace("/\r\n?/", "\n", $before), "\n\n"));
    }

    /**
     * Places where a link could go.
     *
     * @param string   $html
     * @param string[] $phrases   wanted anchors, best first
     * @param array    $opts      target_key (url_key of the target), home_host, avoid (phrases the anchor must not
     *                            contain: the source's own keyword), max_total (internal links already in the source
     *                            that make it full), max_per_block (links a paragraph may already have), limit
     * @return array array('reason' => ''|'already_linked'|'full'|'no_phrase', 'spots' => list of
     *               array(token, offset, anchor, block, rank, context))
     */
    public static function find(string $html, array $phrases, array $opts) {
        $opts += array('target_key' => '', 'home_host' => '', 'avoid' => array(), 'max_total' => 0, 'max_per_block' => 1, 'limit' => 3);
        $doc = self::tokenize($html);
        $internal = 0;
        foreach ($doc['hrefs'] as $href) {
            $key = self::url_key($href, $opts['home_host']);
            if ($key === null) continue;
            $internal++;
            if ($opts['target_key'] !== '' && $key === $opts['target_key']) return array('reason' => 'already_linked', 'spots' => array());
        }
        if ($opts['max_total'] > 0 && $internal >= $opts['max_total']) return array('reason' => 'full', 'spots' => array());

        $avoid = array_filter(array_map(array(__CLASS__, 'norm_phrase'), (array) $opts['avoid']));
        $wanted = array();
        foreach ($phrases as $rank => $p) {
            $n = self::norm_phrase($p);
            $words = $n === '' ? 0 : count(explode(' ', $n));
            if ($words < 1 || $words > 6 || mb_strlen($n, 'UTF-8') < 3) continue;
            $bad = false;
            foreach ($avoid as $a) if ($a !== '' && (mb_strpos($n, $a, 0, 'UTF-8') !== false || mb_strpos($a, $n, 0, 'UTF-8') !== false)) $bad = true;
            if (!$bad && !isset($wanted[$n])) $wanted[$n] = $rank;
        }
        if (!$wanted) return array('reason' => 'no_phrase', 'spots' => array());

        $spots = array();
        $used_blocks = array();
        foreach ($doc['tokens'] as $ti => $t) {
            if ($t['t'] !== 'text' || $t['ctx'] !== 'ok') continue;
            $chars = self::norm_chars($t['raw']);
            $orig = preg_split('//u', $t['raw'], -1, PREG_SPLIT_NO_EMPTY);
            $hay = implode('', $chars);
            foreach ($wanted as $needle => $rank) {
                $from = 0;
                while (($pos = mb_strpos($hay, $needle, $from, 'UTF-8')) !== false) {
                    $from = $pos + 1;
                    $len = mb_strlen($needle, 'UTF-8');
                    $prev = $pos > 0 ? $orig[$pos - 1] : null;
                    $next = isset($orig[$pos + $len]) ? $orig[$pos + $len] : null;
                    // whole words only; a zero-width non-joiner glues a suffix (کیف‌ها) so it is not a boundary
                    if (self::is_letter($prev) || self::is_letter($next) || $prev === "\u{200C}" || $next === "\u{200C}") continue;
                    // inside a [shortcode ...]
                    $before = implode('', array_slice($orig, 0, $pos));
                    if (substr_count($before, '[') > substr_count($before, ']')) continue;
                    // inside an HTML entity such as &nbsp;
                    if (preg_match('/&[a-z#0-9]*$/i', $before)) continue;
                    $block = self::flow_block($t, $pos);
                    $existing = isset($doc['links'][$block]) ? $doc['links'][$block] : 0;
                    if ($existing >= $opts['max_per_block'] || isset($used_blocks[$block])) continue;
                    $anchor = implode('', array_slice($orig, $pos, $len));
                    $ctx_from = max(0, $pos - 90);
                    $spots[] = array(
                        'token' => $ti, 'offset' => $pos, 'anchor' => $anchor, 'block' => $block, 'rank' => $rank,
                        'context' => array(
                            'before' => ($ctx_from > 0 ? '…' : '') . trim(html_entity_decode(implode('', array_slice($orig, $ctx_from, $pos - $ctx_from)), ENT_QUOTES, 'UTF-8')),
                            'anchor' => $anchor,
                            'after' => trim(html_entity_decode(implode('', array_slice($orig, $pos + $len, 90)), ENT_QUOTES, 'UTF-8')) . (count($orig) > $pos + $len + 90 ? '…' : ''),
                        ),
                    );
                    $used_blocks[$block] = true;
                    break; // one spot per phrase in this piece of text; the other phrases are still tried
                }
            }
        }
        // best phrase first, then the earliest place in the text
        usort($spots, function ($a, $b) { return $a['rank'] <=> $b['rank'] ?: $a['token'] <=> $b['token'] ?: $a['offset'] <=> $b['offset']; });
        return array('reason' => $spots ? '' : 'no_phrase', 'spots' => array_slice($spots, 0, max(1, (int) $opts['limit'])));
    }

    /**
     * Wraps the anchor at a spot from find() in a link. Returns the new HTML, or null when the text at that spot is not
     * the expected anchor any more (the post changed) — nothing is written then.
     */
    public static function insert(string $html, array $spot, string $url) {
        $doc = self::tokenize($html);
        $ti = (int) $spot['token'];
        if (!isset($doc['tokens'][$ti]) || $doc['tokens'][$ti]['t'] !== 'text' || $doc['tokens'][$ti]['ctx'] !== 'ok') return null;
        $raw = $doc['tokens'][$ti]['raw'];
        $chars = preg_split('//u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $len = mb_strlen((string) $spot['anchor'], 'UTF-8');
        $found = implode('', array_slice($chars, (int) $spot['offset'], $len));
        if ($len === 0 || $found !== $spot['anchor']) return null;
        $link = '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $found . '</a>';
        $new = implode('', array_slice($chars, 0, (int) $spot['offset'])) . $link . implode('', array_slice($chars, (int) $spot['offset'] + $len));
        $out = '';
        foreach ($doc['tokens'] as $i => $t) $out .= $i === $ti ? $new : $t['raw'];
        return $out;
    }

    /** Internal links in a document (count). */
    public static function internal_count($html, $home_host) {
        $n = 0;
        foreach (self::tokenize($html)['hrefs'] as $h) if (self::url_key($h, $home_host) !== null) $n++;
        return $n;
    }
}
