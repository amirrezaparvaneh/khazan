<?php
/**
 * How many internal links an article may carry, by word count (about one link per 250-300 words).
 *
 *   < 800 words      : at most 3
 *   800 - 1500 words : 4 to 5
 *   > 1500 words     : at most 6
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

if (!defined('ABSPATH')) {
    exit;
}

final class ArticleLinkBudget {

    public static function for_words($words) {
        $words = max(0, (int) $words);
        if ($words < 800) {
            return (int) min(3, max(1, ceil($words / 250)));
        }
        if ($words <= 1500) {
            return (int) min(5, max(4, round($words / 300)));
        }
        return 6;
    }
}
