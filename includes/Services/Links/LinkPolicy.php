<?php
/**
 * How many internal / external links a page should carry. The numbers come from SeoStandards (the project's content
 * rules), never from a constant of the planner: the range depends on the content kind, the position inside the range on
 * the planned text length, and the final count never exceeds the number of REAL candidates (a link is never invented).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

use Khazan\Services\Content\SeoStandards;

if (!defined('ABSPATH')) {
    exit;
}

final class LinkPolicy {

    /**
     * Wanted internal links: the rule range scaled by text length (the minimum body length gives the range minimum,
     * the maximum body length gives the range maximum), capped by the candidates that really exist.
     *
     * @param string $kind       Settings::KIND_*
     * @param int    $word_count planned or actual body length
     * @param int    $available  real candidates (-1 = unlimited)
     */
    public static function internal_target($kind, $word_count, $available = -1) {
        list($min, $max) = SeoStandards::internal_range($kind);
        $floor = max(1, (int) SeoStandards::get('body_words_min'));
        $span  = max(1, (int) SeoStandards::get('body_words_max') - $floor);
        $ratio = max(0.0, min(1.0, ((int) $word_count - $floor) / $span));
        $target = (int) round($min + ($max - $min) * $ratio);
        if ($available >= 0) $target = min($target, (int) $available);
        return max(0, $target);
    }

    /** Is the number of internal links inside the rule range? */
    public static function internal_ok($kind, $count) {
        list($min, $max) = SeoStandards::internal_range($kind);
        return $count >= $min && $count <= $max;
    }

    /** External links: only when a verified source exists; never more than the rule maximum. */
    public static function external_target($verified_sources) {
        list($min, $max) = SeoStandards::external_range();
        return min($max, max(0, (int) $verified_sources));
    }
}
