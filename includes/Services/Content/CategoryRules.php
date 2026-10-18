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

    /** Purchase-guide heading structure (the name is the exact focus keyphrase). */
    public static function blueprint($focus) {
        $f = trim((string) $focus);
        return "[ساختار ثابت صفحه دسته‌بندی؛ نیت جستجو تجاری/تراکنشی است نه آموزشی]\n" .
            "۱. مقدمه (یک پاراگراف کوتاه): معرفی دسته «{$f}» و نیاز خریدار؛ کلیدواژه کانونی در همین پاراگراف اول بیاید.\n" .
            "۲. <h2>راهنمای خرید و انتخاب بهترین {$f}</h2> (۲ پاراگراف کوتاه): معیارهای واقعی انتخاب.\n" .
            "۳. <h2>عوامل موثر بر قیمت {$f} و ارزش خرید آن</h2> (۲ پاراگراف کوتاه و یک فهرست <ul><li>): فقط عوامل کلی؛ عدد و قیمت ننویس.\n" .
            "۴. <h2>ویژگی‌های ظاهری و کیفیت دوخت در مدل‌های این دسته</h2> (۲ پاراگراف کوتاه؛ در این هدینگ نام کامل دسته را تکرار نکن). اگر زیردسته (target.subcategories) هست در همین بخش معرفی‌شان کن.\n" .
            "۵. <h2>سوالات متداول درباره خرید {$f}</h2>: دقیقاً ۳ پرسش کاربردی، هر کدام با ساختار <h3>سوال</h3><p>پاسخ کوتاه</p>.\n" .
            "۶. یک پاراگراف پایانی کوتاه (جمع‌بندی) بعد از پرسش‌ها، با دعوت طبیعی به انتخاب و خرید؛ کلیدواژه کانونی در آن هم بیاید.\n" .
            "H1 و بخش اضافه نساز. هدینگ‌ها ترغیب‌کننده خرید و راهنمای انتخاب باشند. فاصله بین دو زیرعنوان حداکثر ۲۲۰ واژه.\n";
    }

    /** Exact-match density 4-6 and the balanced distribution Yoast checks. */
    public static function density_rule() {
        return "[تراکم و پخش کلیدواژه کانونی]\n" .
            "کلیدواژه کانونی (نام دقیق دسته) را عیناً و با همان ترتیب کلمات، در کل متن (هدینگ‌ها هم حساب می‌شوند) بین " . Settings::CATEGORY_KEYWORD_MIN . " تا " . Settings::CATEGORY_KEYWORD_MAX . " بار بیاور، هرگز بیشتر. توزیع باید مساوی باشد: ۱ بار در پاراگراف اول، در ۲ تا ۳ هدینگ H2، ۱ بار در یک پاراگراف میانی و ۱ بار در پاراگراف پایانی. هیچ بخشی نباید همه تکرارها را جمع کند؛ در بقیه جاها از «این دسته‌بندی»، «این مدل‌ها» یا توصیف دیگر استفاده کن.\n";
    }

    /** Short writing rules shared with the product pipeline in spirit (active voice, explicit <p>, no invented facts). */
    public static function writing_rules() {
        return "[قواعد نگارش]\n" .
            "فقط HTML ساده با <h2>، <h3>، <p>، <ul>، <li>، <strong> و <a>؛ HTML فشرده بدون خط خالی بین تگ‌ها. هر پاراگراف داخل <p> و حداکثر ۶۰ تا ۹۰ واژه (۳ تا ۴ جمله کوتاه). جمله‌ها کوتاه، معلوم و فاعل‌محور؛ مجهول (ساخته شده است، عرضه می‌شود) حداکثر ۵٪.\n" .
            "فقط از داده‌های target استفاده کن؛ قیمت، تخفیف، گارانتی، آمار، موجودی، رتبه و نظر مشتری نساز. هیچ تصویر، باکس تصویر یا پرامپت تصویر ننویس (سیستم خودکار می‌سازد). لینک خارجی نساز.\n";
    }

    /** Link instructions: exactly the planned links, each once, with the locked anchor texts. */
    public static function link_rules(array $links) {
        $lines = array();
        foreach ($links as $link) {
            if (empty($link['url']) || empty($link['anchor'])) continue;
            $lines[] = '[لینک: ' . $link['url'] . ' | انکرتکست اجباری: ' . $link['anchor'] . ']';
        }
        if (!$lines) return "هیچ لینک داخلی ننویس.\n";
        return "[لینک‌های داخلی؛ دقیقاً همین‌ها]\n" . implode("\n", $lines) . "\n" .
            "هر لینک را فقط یک‌بار با HTML <a href=\"URL\">انکرتکست</a> در جمله‌ای طبیعی و در بخش‌های مختلف متن بیاور (نه پشت‌سرهم)، با انکرتکست کلمه‌به‌کلمه همان مقدار اجباری بدون تغییر یا کوتاه‌کردن. لینک صفحه اصلی دقیقاً یک‌بار و ترجیحاً در پاراگراف پایانی. هیچ لینک داخلی دیگری و هرگز لینک به خود این دسته نساز.\n";
    }

    /** Title / meta formulas. */
    public static function seo_rules($focus, $brand) {
        $f = trim((string) $focus);
        $b = $brand !== '' ? $brand : 'نام برند';
        return "[قواعد عنوان سئو و متا]\n" .
            "۱. عنوان سئو: دقیقاً این رشته در ابتدای عنوان بیاید: «{$f}»، سپس « | »، سپس عبارت خرید و قیمت مانند «" . Settings::CATEGORY_TITLE_HOOK . "» همراه دقیقاً یک ایموجی مرتبط، سپس « | » و نام برند «{$b}». ایموجی فقط درست قبل از « | » یا در انتها باشد و هرگز اول عنوان نباشد. حداکثر ۶۰ کاراکتر. نمونه: «{$f} | " . Settings::CATEGORY_TITLE_HOOK . " 🛍️ | {$b}».\n" .
            "۲. توضیحات متا: بین " . Settings::CATEGORY_META_MIN_CHARS . " تا " . Settings::CATEGORY_META_MAX_CHARS . " کاراکتر، شامل عبارت دقیق «{$f}»، اشاره به خرید اینترنتی، قیمت مناسب، ضمانت کیفیت و یک دعوت به اقدام (CTA) مانند «همین حالا سفارش دهید».\n";
    }
}
