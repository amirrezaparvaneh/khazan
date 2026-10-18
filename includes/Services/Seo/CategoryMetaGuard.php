<?php
/**
 * Meta description guard for product categories (pure): 120-155 characters with the exact focus keyphrase, an online
 * purchase mention, a fair-price mention, a quality guarantee and a call to action. A model description that already
 * satisfies every rule is kept; otherwise a deterministic template is composed and fitted to the length window.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Seo;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class CategoryMetaGuard {

    const CTA_WORDS = array('سفارش', 'ببینید', 'مشاهده', 'بخرید', 'دریافت');

    public static function fit($description, $focus, $brand = '') {
        $focus = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $focus)));
        if ($focus === '') return trim((string) $description);
        $description = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $description)));
        if (self::is_valid($description, $focus)) return $description;
        return self::compose($focus, trim((string) $brand));
    }

    public static function is_valid($description, $focus) {
        $n = mb_strlen((string) $description);
        if ($n < Settings::CATEGORY_META_MIN_CHARS || $n > Settings::CATEGORY_META_MAX_CHARS) return false;
        if (mb_strpos($description, $focus) === false) return false;
        foreach (array('خرید', 'قیمت', 'ضمانت') as $word) {
            if (mb_strpos($description, $word) === false) return false;
        }
        foreach (self::CTA_WORDS as $word) {
            if (mb_strpos($description, $word) !== false) return true;
        }
        return false;
    }

    private static function compose($focus, $brand) {
        $core   = 'خرید اینترنتی ' . $focus . ' با قیمت مناسب و ضمانت کیفیت';
        $from   = $brand !== '' ? ' از ' . $brand : '';
        $cta    = '؛ جدیدترین مدل‌ها را ببینید و همین حالا سفارش دهید.';
        $short  = '؛ همین حالا سفارش دهید.';
        $extras = array(' ارسال سریع به سراسر ایران.', ' ارسال سریع.');

        $options = array();
        foreach (array($from, '') as $f) {
            foreach (array($cta, $short) as $c) {
                $options[] = $core . $f . $c;
                foreach ($extras as $e) $options[] = $core . $f . $c . $e;
            }
        }
        $best = null;
        foreach ($options as $option) {
            $n = mb_strlen($option);
            if ($n >= Settings::CATEGORY_META_MIN_CHARS && $n <= Settings::CATEGORY_META_MAX_CHARS) return $option;
            if ($best === null || abs($n - 138) < abs(mb_strlen($best) - 138)) $best = $option;
        }
        return $best;
    }
}
