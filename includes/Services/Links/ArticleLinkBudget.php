<?php
/**
 * How many internal links an article carries: the range comes from SeoStandards (articles: 3 to 8) and the position in
 * the range from the planned word count (LinkPolicy). No numbers live here.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class ArticleLinkBudget {

    public static function for_words($words) {
        return LinkPolicy::internal_target(Settings::KIND_ARTICLE, max(0, (int) $words));
    }
}
