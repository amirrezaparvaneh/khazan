<?php
/**
 * Focus keyphrase / anchor text standard: 2-4 meaningful words, never a full product title.
 * Pure functions (no WordPress calls except the optional `wila_keyphrase_words` filter via Settings).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Seo;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class KeyphraseExtractor {

    /**
     * Words of a phrase: whitespace separated, surrounding punctuation stripped (ZWNJ stays inside a word).
     *
     * @return string[]
     */
    public static function words($phrase) {
        $phrase = html_entity_decode(strip_tags((string) $phrase), ENT_QUOTES, 'UTF-8');
        $out = array();
        foreach (preg_split('/\s+/u', trim($phrase)) as $token) {
            // مرزهای نقطه‌گذاری/نماد (یونیکد) حذف می‌شود؛ ZWNJ داخل کلمه می‌ماند
            $token = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $token);
            if ($token !== '') $out[] = $token;
        }
        return $out;
    }

    /**
     * Focus keyphrase of a blog ARTICLE: a natural long-tail phrase, not the product formula. The user's keyword (or the
     * topic when empty) is kept COMPLETE up to 6 words - connectors are never dropped from the middle, so
     * «جلوگیری از پوسته پوسته شدن کاپشن» never turns into «جلوگیری شدن کاپشن». Longer phrases lose generic lead-in words
     * («راهنمای کامل …») first, are then cut to 6 words and never end on a dangling connector.
     */
    public static function extract_for_article($topic, $raw_keyword = '') {
        $words = self::words($raw_keyword);
        if (!$words) $words = self::words($topic);
        $max = Settings::ARTICLE_KEYPHRASE_MAX_WORDS;
        if (count($words) <= $max) return implode(' ', $words);

        $lead = array_flip(array_map(array(__CLASS__, 'key'), Settings::ARTICLE_KEYPHRASE_LEADINS));
        $connectors = array_flip(array_map(array(__CLASS__, 'key'), Settings::ARTICLE_KEYPHRASE_CONNECTORS));
        while (count($words) > $max && isset($lead[self::key($words[0])])) array_shift($words);
        $words = array_slice($words, 0, $max);
        while (count($words) > 2 && isset($connectors[self::key(end($words))])) array_pop($words);
        return implode(' ', $words);
    }

    public static function count_words($phrase) {
        return count(self::words($phrase));
    }

    /**
     * Hard cap: the first $max words (default 4), single-spaced.
     */
    public static function limit($phrase, $max = Settings::KEYPHRASE_MAX_WORDS) {
        return implode(' ', array_slice(self::words($phrase), 0, max(1, (int) $max)));
    }

    /**
     * Cap for storing / inserting a keyphrase or anchor: never more than 4 words.
     * Over-long phrases are reduced to their most meaningful words (see from_title), not just cut at the 4th word.
     */
    public static function cap($phrase) {
        $phrase = self::strip_stop_words(self::limit($phrase, PHP_INT_MAX));
        if (self::count_words($phrase) <= Settings::KEYPHRASE_MAX_WORDS) {
            return $phrase;
        }
        return self::limit(self::from_title($phrase), Settings::KEYPHRASE_MAX_WORDS);
    }

    /**
     * Two phrases overlap when they are equal or one is a contiguous word sequence of the other
     * (case / Arabic-yeh-kaf / ZWNJ insensitive). Empty phrases never overlap.
     */
    public static function overlaps($a, $b) {
        $x = array_map(array(__CLASS__, 'key'), self::words($a));
        $y = array_map(array(__CLASS__, 'key'), self::words($b));
        if (!$x || !$y) return false;
        if (count($x) > count($y)) { $t = $x; $x = $y; $y = $t; }
        for ($i = 0, $n = count($y) - count($x); $i <= $n; $i++) {
            if (array_slice($y, $i, count($x)) === $x) return true;
        }
        return false;
    }

    public static function is_valid($phrase) {
        $n = self::count_words($phrase);
        return $n >= 1 && $n <= Settings::KEYPHRASE_MAX_WORDS;
    }

    /**
     * Best keyphrase: the stored one when it has 1-4 words, otherwise derived from the product title.
     */
    public static function optimal($stored, $title, $category = '') {
        $stored = trim(preg_replace('/\s+/u', ' ', (string) $stored));
        $derived = self::from_title($title, $category);
        if ($stored === '' || !self::is_valid($stored)) {
            return $derived;
        }
        $clean = self::strip_stop_words($stored);
        return self::conforms($clean, $derived) ? $clean : $derived;
    }

    /**
     * Stored keyphrases (Yoast / Rank Math / sheet) are not trusted blindly: a stored phrase is kept only when it
     * keeps every gender word and every model word of the title-derived phrase and is not shorter than it
     * (e.g. stored «کفش چرم مارکو» loses «مردانه» -> the title-derived «کفش طبی مردانه مارکو» wins).
     */
    public static function conforms($stored, $derived) {
        $derived_words = array_map(array(__CLASS__, 'key'), self::words($derived));
        if (!$derived_words) return true;
        $stored_words = array_map(array(__CLASS__, 'key'), self::words($stored));
        if (count($stored_words) < count($derived_words)) return false;
        $lists = Settings::keyphrase_words();
        $optional = array_flip(array_map(array(__CLASS__, 'key'), $lists['conditional']));
        foreach ($derived_words as $i => $word) {
            if ($i === 0 || isset($optional[$word])) continue;   // the product type may be phrased differently; modifiers are optional
            if (!in_array($word, $stored_words, true)) return false;
        }
        return true;
    }

    /**
     * Normalises a short phrase to [type] + [gender] + [model]: unconditional stop words (طبیعی، تبریز و شهرها، مدل، طرح،
     * اصل، دست‌دوز، کد، other fillers/materials, digits) are always removed. Conditional modifiers (چرم، چرمی، روزمره،
     * مجلسی) are removed from the core but put back right after the product type - in that priority - only while the
     * core has fewer than 4 words. The phrase is kept as it is when fewer than 2 words would survive.
     */
    public static function strip_stop_words($phrase) {
        $tokens = self::words($phrase);
        if (count($tokens) < 2) return implode(' ', $tokens);
        $lists = Settings::keyphrase_words();
        $stop = array();
        foreach (array('fillers', 'materials', 'cities') as $name) {
            foreach ($lists[$name] as $word) $stop[self::key($word)] = true;
        }
        $cond = array_flip(array_map(array(__CLASS__, 'key'), $lists['conditional']));
        $core = array();
        foreach ($tokens as $t) {
            $k = self::key($t);
            if (isset($stop[$k]) || isset($cond[$k]) || preg_match('/^[\d۰-۹٠-٩]+$/u', $k)) continue;
            $core[] = $t;
        }
        $result = self::fill_conditionals($core, $tokens);
        return count($result) >= Settings::KEYPHRASE_MIN_WORDS ? implode(' ', $result) : implode(' ', $tokens);
    }

    /**
     * Puts conditional modifiers found in $source back into $core, right after the first word (the product type),
     * highest priority first, until the phrase reaches the 4-word ceiling.
     */
    private static function fill_conditionals(array $core, array $source, array $prefer = array()) {
        $slots = Settings::KEYPHRASE_MAX_WORDS - count($core);
        if ($slots <= 0 || !$core) return $core;
        $lists = Settings::keyphrase_words();
        $have = array_map(array(__CLASS__, 'key'), $core);
        $picked = array();
        foreach ($lists['conditional'] as $word) {
            $ck = self::key($word);
            if (in_array($ck, $have, true)) continue;
            foreach ($source as $t) {
                if (self::key($t) === $ck) { $picked[] = $t; break; }
            }
        }
        // words that the category name itself carries (e.g. «روزمره» in «کفش روزمره مردانه») win over generic ones such as «چرم»
        if ($prefer) {
            $pk = array_map(array(__CLASS__, 'key'), $prefer);
            usort($picked, function ($a, $b) use ($pk) {
                $x = in_array(KeyphraseExtractor::key($a), $pk, true) ? 0 : 1;
                $y = in_array(KeyphraseExtractor::key($b), $pk, true) ? 0 : 1;
                return $x - $y;
            });
        }
        $picked = array_slice($picked, 0, $slots);
        if (!$picked) return $core;
        array_splice($core, 1, 0, $picked);
        return $core;
    }

    /**
     * Derives a 2-4 word keyphrase from a (possibly long) product title:
     * product type + audience (or the main category words) + the model name after «مدل»;
     * fillers, materials and city names are dropped. Titles up to 4 words are kept as they are.
     */
    public static function from_title($title, $category = '') {
        $tokens = self::words($title);
        if (!$tokens) return '';
        $max = Settings::KEYPHRASE_MAX_WORDS;
        $min = Settings::KEYPHRASE_MIN_WORDS;
        if (count($tokens) <= $max) return self::strip_stop_words(implode(' ', $tokens));

        $lists = Settings::keyphrase_words();
        $key = array(__CLASS__, 'key');
        $set = function ($list) use ($key) { return array_flip(array_map($key, $list)); };
        $fillers = $set($lists['fillers']); $materials = $set($lists['materials']); $conditional = $set($lists['conditional']); $audience = $set($lists['audience']); $gender = $set($lists['gender']); $cities = $set($lists['cities']);
        $marker = self::key(Settings::KEYPHRASE_MODEL_MARKER);

        $marker_at = null;
        foreach ($tokens as $i => $t) { if (self::key($t) === $marker) { $marker_at = $i; break; } }
        $core = $marker_at === null ? $tokens : array_slice($tokens, 0, $marker_at);
        $tail_raw = $marker_at === null ? array() : array_slice($tokens, $marker_at + 1);

        $generic = function ($t) use ($key, $fillers, $materials, $cities, $conditional) { $k = call_user_func($key, $t); return isset($fillers[$k]) || isset($materials[$k]) || isset($conditional[$k]) || isset($cities[$k]) || preg_match('/^[\d۰-۹٠-٩]+$/u', $k); };
        $kept = array_values(array_filter($core, function ($t) use ($generic) { return !$generic($t); }));
        if (!$kept) {
            // هسته پیش از «مدل» فقط واژه‌های عمومی بود؛ از کل عنوان واژه‌های متمایز را برمی‌دارد
            $kept = array_values(array_filter($tokens, function ($t) use ($generic, $marker) { return !$generic($t) && self::key($t) !== $marker; }));
            $marker_at = null;
        }

        // base: the main category words when the title contains all of them, else product type + audience qualifiers
        $core_keys = array_map($key, $core);
        $cat_all = array_values(array_filter(self::words($category), function ($t) use ($key, $fillers, $conditional) { $k = call_user_func($key, $t); return !isset($fillers[$k]) || isset($conditional[$k]); }));
        $cat_tokens = array_values(array_filter($cat_all, function ($t) use ($key, $conditional) { return !isset($conditional[call_user_func($key, $t)]); }));
        $cat_modifiers = array_values(array_filter($cat_all, function ($t) use ($key, $conditional) { return isset($conditional[call_user_func($key, $t)]); }));
        if ($cat_tokens && !array_diff(array_map($key, $cat_all), $core_keys)) {
            // [type + gender] stays; the category's own modifier (اسپرت، روزمره، طبی ...) is added back below when a slot is free
            $base = $cat_tokens;
        } else {
            $base = array();
            if ($kept) $base[] = $kept[0];
            // جنسیت (مردانه/زنانه) مقدم بر سایر صفت‌های مخاطب است
            foreach (array($gender) as $group) {
                foreach ($kept as $i => $t) {
                    $k = call_user_func($key, $t);
                    if ($i > 0 && isset($group[$k]) && !in_array($t, $base, true)) $base[] = $t;
                }
            }
            if (!$base) $base = array_slice($kept ?: $core, 0, $min);
        }

        // tail: the model name after «مدل»; without a marker the last distinctive title words
        $base_keys = array_map($key, $base);
        if ($marker_at !== null) {
            $tail = array_values(array_filter($tail_raw, function ($t) use ($generic) { return !$generic($t); }));
        } else {
            $tail = array_values(array_filter($kept, function ($t) use ($key, $base_keys) { return !in_array(call_user_func($key, $t), $base_keys, true); }));
            $tail = array_slice($tail, -2);
        }
        $tail = array_slice($tail, 0, max(1, $max - 1));

        // keep the model name: shrink the base before cutting the tail
        $tail_keep = min(count($tail), 2);
        $base = array_slice($base, 0, max(1, $max - $tail_keep));
        $result = array_merge($base, array_slice($tail, 0, $max - count($base)));

        // dedupe (case/shape insensitive)
        $seen = array(); $unique = array();
        foreach ($result as $t) { $k = self::key($t); if (!isset($seen[$k])) { $seen[$k] = true; $unique[] = $t; } }
        $result = $unique;

        // too short: add back distinctive title words (never fillers / cities / the marker) in their original order
        if (count($result) < $min) {
            foreach (array_merge($kept, $tokens) as $t) {
                $k = self::key($t);
                if (isset($seen[$k]) || isset($fillers[$k]) || isset($cities[$k]) || isset($conditional[$k]) && count($kept) >= $min || $k === $marker) continue;
                $seen[$k] = true; $result[] = $t;
                if (count($result) >= $min) break;
            }
        }
        // the core is [type + gender + model]; with free slots the conditional modifiers (چرم …) complete it to 4 words
        $result = self::fill_conditionals($result, $tokens, isset($cat_modifiers) ? $cat_modifiers : array());
        return self::limit(implode(' ', $result), $max);
    }

    /**
     * Comparison key: Arabic yeh/kaf unified, ZWNJ/tatweel removed, lowercase.
     */
    public static function key($word) {
        $word = strtr((string) $word, array('ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', "\u{200c}" => '', "\u{200d}" => '', 'ـ' => ''));
        return mb_strtolower(trim($word));
    }
}
