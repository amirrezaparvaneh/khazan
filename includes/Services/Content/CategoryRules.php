<?php
/**
 * Prompt rules for bulk product-category descriptions: transactional (purchase intent) structure, headings, balanced
 * keyphrase distribution and the SEO title / meta formulas. Kept apart from ContentRules so the product and article
 * rules stay untouched.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class CategoryRules {

    /** Target body length: the middle of the allowed range. */
    public static function target_words() {
        return (int) round((SeoStandards::get('body_words_min') + SeoStandards::get('body_words_max')) / 2);
    }

    /** Allowed number of exact main-keyword occurrences for a body of $words words (density range of the standards). */
    public static function keyword_range($words) {
        return array((int) ceil($words * SeoStandards::get('density_min') / 100), (int) floor($words * SeoStandards::get('density_max') / 100));
    }

    public static function keywords_block($main, array $secondary) {
        $line = "[کلمات کلیدی]\nکلمه اصلی: «{$main}»\n";
        $line .= $secondary ? 'کلمات فرعی (فقط همین‌ها؛ کلمه دیگری به‌عنوان کلیدواژه نساز): «' . implode('»، «', $secondary) . "»\n"
            : "کلمه فرعی ثبت‌نشده است؛ از مترادف‌های طبیعی استفاده کن و کلمه کلیدی جدید نساز.\n";
        return $line;
    }

    /** Structure, length, density and heading rules - all numbers come from SeoStandards. */
    public static function structure_rules($main, $words) {
        $s = SeoStandards::all();
        list($kmin, $kmax) = self::keyword_range($words);
        return "[طول، ساختار و تراکم]\n" .
            "۱. متن اصلی (بدون H1) حدود {$words} کلمه باشد و هرگز کمتر از {$s['body_words_min']} کلمه نباشد؛ طول را از پوشش واقعی موضوع بگیر، نه از پرکردن. سقف معمول {$s['body_words_max']} کلمه است و فقط با محتوای واقعی می‌تواند بیشتر شود.\n" .
            "۲. کلمه اصلی «{$main}» عیناً در کل متن (با هدینگ‌ها و لینک‌ها) بین {$kmin} تا {$kmax} بار بیاید (تراکم {$s['density_min']} تا {$s['density_max']} درصد)؛ در ۱۰۰ کلمه اول، در حداقل یک زیرعنوان و در بخش‌های مختلف پخش شود، نه پشت‌سرهم. بقیه جاها از کلمات فرعی، مترادف و عبارت‌های بلند طبیعی استفاده کن.\n" .
            "۳. حداقل {$s['h2_min']} زیرعنوان H2 با محتوای واقعی: راهنمای انتخاب، تفاوت مدل‌ها یا انواع، نکات خرید یا نگهداری و پرسش‌های متداول (هر پرسش H3 با پاسخ در <p>). کلمه اصلی فقط در ۱ یا ۲ عنوان H2 و فقط وقتی کاملاً طبیعی است؛ در بقیه زیرعنوان‌ها از کلمات فرعی و عبارت‌های بلند استفاده کن.\n" .
            "۴. حداقل یک جدول <table> مفید (مقایسه مدل‌ها، راهنمای انتخاب یا نکات خرید) داخل متن بگذار؛ در جدول فقط متن ساده بیاید، بدون لینک.\n" .
            "۵. فاصله بین دو زیرعنوان حداکثر {$s['section_words_max']} کلمه؛ هر پاراگراف داخل <p> و حداکثر ۹۰ کلمه؛ جمله‌ها کوتاه، معلوم و روان. فقط <h2>، <h3>، <p>، <ul>، <li>، <strong>، <table>، <thead>، <tbody>، <tr>، <th>، <td> و <a>؛ HTML فشرده بدون خط خالی.\n" .
            "۶. هر بخش باید اطلاعات واقعی و مفید برای خریدار بدهد؛ هر صفحه ساختار و جمله‌های خودش را دارد و قالب ثابت تکراری ممنوع است. تجربه، کارگاه، آمار و نظر مشتری نساز.\n" .
            "۷. هیچ تصویر، باکس تصویر یا پرامپت تصویر ننویس.\n";
    }

    /** Link instructions: the plan with natural anchor options, once each, spread over sections. */
    public static function link_rules(array $links, array $verified_sources) {
        $lines = array();
        foreach ($links as $link) {
            if (empty($link['url']) || empty($link['anchor'])) continue;
            $options = isset($link['anchor_options']) ? implode(' | ', $link['anchor_options']) : $link['anchor'];
            $lines[] = '- [' . (isset($link['role']) ? $link['role'] : $link['type']) . '] ' . $link['url'] . ' ← انکر طبیعی، یکی از: ' . $options;
        }
        $out = "[لینک‌های داخلی؛ دقیقاً همین‌ها، نه کمتر و نه بیشتر]\n" . ($lines ? implode("\n", $lines) . "\n" : "لینک داخلی نساز.\n");
        if ($lines) {
            $out .= "هر آدرس فقط یک‌بار و در اولین جای مناسب لینک شود؛ در هر پاراگراف حداکثر ۲ لینک؛ لینک‌ها بین بخش‌های مختلف پخش شوند، نه کنار هم. انکر باید یکی از گزینه‌های بالا یا شکل طبیعی و کوتاه همان باشد و هر بار فرق کند؛ «اینجا» و «کلیک کنید» ممنوع. لینک محصول با نام دقیق مدل. به خود این صفحه لینک نده. در جدول لینک نگذار. دسته‌های والد و زیردسته‌ها با لینک دوطرفه به هم وصل‌اند.\n";
        }
        if ($verified_sources) {
            $out .= "لینک خارجی: فقط از این منابع تأییدشده، ۱ تا ۲ مورد و فقط اگر واقعاً مرتبط است (target=\"_blank\" rel=\"noopener\"):\n";
            foreach ($verified_sources as $src) $out .= '- ' . $src['url'] . ($src['title'] !== '' ? ' (' . $src['title'] . ')' : '') . "\n";
        } else {
            $out .= "هیچ لینک خارجی ننویس (منبع تأییدشده‌ای ثبت نشده است).\n";
        }
        return $out;
    }

    /** Title / meta rules of the standards. */
    public static function seo_rules($main, $brand) {
        $s = SeoStandards::all();
        $b = $brand !== '' ? $brand : 'نام برند';
        return "[عنوان سئو و متا؛ هر دو یکتا و بدون ایموجی]\n" .
            "۱. عنوان سئو {$s['title_min']} تا {$s['title_max']} کاراکتر، کلمه اصلی «{$main}» در ابتدا و پایان دقیقاً « | {$b}». الگو: «{$main}؛ خرید انواع مدل، قیمت و اقساط | {$b}» (در صورت نیاز کوتاه‌تر یا با تنوع). عبارت «ارسال رایگان» هرگز در عنوان نیاید.\n" .
            "۲. متا {$s['meta_min']} تا {$s['meta_max']} کاراکتر، شامل کلمه اصلی، و فقط ویژگی‌های تأییدشده فروشگاه (ارسال رایگان، بازگشت کالا تا ۷ روز، اقساط ۴ قسطه اسنپ‌پی و ترب‌پی) و یک دعوت طبیعی به اقدام.\n";
    }
}
