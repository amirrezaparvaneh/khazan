<?php
/**
 * Verified business facts the writer may use, and the claims it must never invent.
 *
 * Only facts confirmed by the shop owner live here; everything else is forbidden in generated copy. Values are
 * overridable through the `wila_business_facts` filter / option, so the owner can confirm more facts without code.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class BusinessFacts {

    const OPTION = 'wila_business_facts';
    const FILTER = 'wila_business_facts';

    public static function defaults() {
        return array(
            'free_shipping'      => true,                 // all purchases; allowed in meta and text, never in the SEO title
            'return_days'        => 7,                    // "ضمانت بازگشت کالا تا ۷ روز"; the word «گارانتی» and any 6-month guarantee are forbidden
            'installments'       => 'خرید اقساطی ۴ قسطه با اسنپ‌پی و ترب‌پی',
            'hardware'           => 'یراق‌آلات وارداتی، باکیفیت و ضدزنگ', // only for products that have hardware
            // categories whose products are plain «چرم» (never «طبیعی» / «تبریز»): casual, formal, shoulder and office bags
            'plain_leather_slugs' => array('casual-leather-bags', 'formal-leather-bags', 'shoulder-leather-bags', 'office-leather-bags'),
            // shoe categories: «تبریز» is part of the keyword when GSC shows demand
            'tabriz_keyword_slugs' => array('leather-shoes', 'men-leather-shoes', 'women-leather-shoes', 'men-sport-shoes', 'women-sport-shoes', 'women-formal-shoes', 'men-formal-shoes', 'men-orthopedic-shoes', 'women-orthopedic-shoes', 'men-leather-boots', 'women-boots', 'women-sandals'),
            'verified_sources'   => array(),              // external sources the owner approved: [['url' => ..., 'title' => ...], ...]
        );
    }

    public static function all() {
        $values = self::defaults();
        $saved = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        if (is_array($saved)) {
            foreach ($saved as $key => $value) if (array_key_exists($key, $values)) $values[$key] = $value;
        }
        $filtered = apply_filters(self::FILTER, $values);
        return is_array($filtered) ? array_merge($values, $filtered) : $values;
    }

    /** Slug of a category/product URL path (last non-empty segment). */
    public static function slug_from_url($url) {
        $path = trim((string) parse_url((string) $url, PHP_URL_PATH), '/');
        $parts = $path === '' ? array() : explode('/', $path);
        return $parts ? rawurldecode(end($parts)) : '';
    }

    /** 'plain' => «چرم» only; 'natural_tabriz' => «چرم طبیعی تبریز» may be written. */
    public static function leather_claim($slug) {
        $facts = self::all();
        return in_array((string) $slug, (array) $facts['plain_leather_slugs'], true) ? 'plain' : 'natural_tabriz';
    }

    public static function tabriz_in_keyword($slug) {
        $facts = self::all();
        return in_array((string) $slug, (array) $facts['tabriz_keyword_slugs'], true);
    }

    /** Verified external sources the owner approved (url + title). */
    public static function verified_sources() {
        $facts = self::all();
        $out = array();
        foreach ((array) $facts['verified_sources'] as $row) {
            if (is_array($row) && !empty($row['url']) && stripos((string) $row['url'], 'https://') === 0) {
                $out[] = array('url' => (string) $row['url'], 'title' => isset($row['title']) ? (string) $row['title'] : '');
            }
        }
        return $out;
    }

    /** Prompt block with the confirmed facts and the explicit prohibitions. */
    public static function prompt_block($slug = '') {
        $f = self::all();
        $lines = array("[واقعیت‌های تأییدشده فروشگاه؛ فقط همین‌ها را می‌توانی بنویسی]");
        $lines[] = $f['free_shipping'] ? '- ارسال برای تمام خریدها رایگان است (در متن و متا مجاز؛ در عنوان سئو ممنوع).' : '';
        $lines[] = '- «ضمانت بازگشت کالا تا ' . (int) $f['return_days'] . ' روز» برای تمام محصولات وجود دارد. واژه «گارانتی» و «ضمانت ۶ ماهه» را هرگز ننویس.';
        $lines[] = '- ' . $f['installments'] . ' برای تمام محصولات وجود دارد (در عنوان سئو، متا و متن مجاز، بدون تکرار زیاد).';
        $lines[] = '- ' . $f['hardware'] . ' (فقط برای محصولاتی که یراق‌آلات دارند).';
        $lines[] = self::leather_claim($slug) === 'plain'
            ? '- این دسته فقط «چرم» نامیده می‌شود؛ عبارت‌های «چرم طبیعی» و «تبریز» را برای این دسته هرگز ننویس.'
            : '- محصولات این دسته چرم طبیعی و چرم تبریز هستند؛ در متن یک‌بار و طبیعی بنویس.';
        $lines[] = '- ممنوع: قیمت عددی، تعداد دقیق محصول یا مدل (به‌جای آن «انواع مدل‌ها»)، هدیه یا هر چیزی که فروشگاه ندارد، نوع چرم (گاوی، گوسفندی)، «دست‌دوز»، «ساخت ایران»، ضمانت اصالت یا دوخت، ادعای پزشکی، ادعای اغراق‌آمیز (ضدآب مطلق، عمر ابدی)، اندازه، ضخامت و زمان ارسال دقیق، و تجربه یا کارگاه فرضی.';
        $lines[] = '- اگر درباره ادعای مهمی مطمئن نیستی، آن را ننویس و در بخش یادداشت‌ها بیاور.';
        return implode("\n", array_filter($lines)) . "\n";
    }
}
