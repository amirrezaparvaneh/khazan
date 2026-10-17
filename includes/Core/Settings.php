<?php
/**
 * Central configuration: option keys, meta keys, filter names, network limits and the
 * content-kind / target settings that used to live in WILA_Content_Targets.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class Settings {

    // ---- WordPress option keys ----
    const OPT_AVALAI_API_KEY             = 'wila_avalai_api_key';
    const OPT_AI_PROVIDER                = 'wila_ai_provider';
    const OPT_HOMEPAGE_KEYWORDS          = 'wila_homepage_keywords';
    const OPT_BRAND_NAME                 = 'wila_brand_name';
    const OPT_PRODUCT_POST_TYPE          = 'wila_product_post_type';
    const OPT_PRODUCT_CATEGORY_TAXONOMY  = 'wila_product_category_taxonomy';
    const OPT_AUTO_IMAGE_ENABLED         = 'wila_auto_image_enabled';
    const OPT_DB_VERSION                 = 'wila_db_version';
    const OPT_DB_INSTALLED_VERSION_LEGACY = 'wila_db_installed_version';

    /** Third-party (Yoast) option holding taxonomy SEO data. */
    const OPT_YOAST_TAXONOMY_META = 'wpseo_taxonomy_meta';

    // ---- post / term meta keys ----
    const META_CSV_GENERATED         = '_wila_csv_generated_at';
    const META_CSV_FOCUS             = '_wila_csv_focus_keyword';
    const META_PREVIOUS_CONTENT      = '_wila_previous_content';
    const META_PREVIOUS_DESCRIPTION  = '_wila_previous_description';
    const META_SOURCE_PRODUCT        = '_wila_source_product';

    // ---- public filter hooks (names are part of the plugin API) ----
    const FILTER_PRODUCT_POST_TYPE         = 'wila_product_post_type';
    const FILTER_PRODUCT_CATEGORY_TAXONOMY = 'wila_product_category_taxonomy';
    const FILTER_BLOG_POST_TYPE            = 'wila_blog_post_type';
    const FILTER_ANCHOR_TERMS              = 'wila_anchor_terms';
    const FILTER_REFERENCE_URL_TEMPLATES   = 'wila_reference_url_templates';

    // ---- transients ----
    const TRANSIENT_SUGGEST_PREFIX     = 'wila_suggest_v2_';
    const TRANSIENT_REPAIR_LOCK_PREFIX = 'wila_repair_';
    const TRANSIENT_LINK_LEDGER_PREFIX = 'wila_link_ledger_';
    const LINK_LEDGER_TTL              = 21600; // 6 hours
    const TRANSIENT_SITE_STATS         = 'wila_site_stats';
    const SITE_STATS_TTL               = 300;   // 5 minutes

    // ---- database table suffixes (prefix comes from $wpdb) ----
    const TABLE_LINKS           = 'wila_internal_links';
    const TABLE_BULK_ITEMS      = 'wila_bulk_items';
    const TABLE_CONTENT_HISTORY = 'wila_content_history';

    // ---- AI service ----
    const AI_PROVIDER = 'avalai';
    const AI_MODEL    = 'gpt-5.4-mini';
    const AI_ENDPOINT = 'https://api.avalai.ir/v1/chat/completions';

    // ---- in-article image generation (AvalAI, OpenAI-compatible images API) ----
    const IMAGE_MODEL      = 'flux.2-pro';
    const IMAGE_ENDPOINT   = 'https://api.avalai.ir/v1/images/generations';
    /** Landscape blog / featured-image ratio first; the safe square size is the retry when the API rejects it. */
    const IMAGE_SIZE       = '1024x576';
    const IMAGE_SIZE_SAFE  = '1024x1024';
    const IMAGE_CSS_CLASS  = 'aka-content-img';
    const META_IMAGE_ALT   = '_wp_attachment_image_alt';
    const META_GENERATED_IMAGE = '_wila_generated_image';
    const FILTER_IMAGE_SIZE = 'wila_image_size';

    /** Account credit endpoint (AvalAI user API: GET /credit -> limit, remaining_irt, remaining_unit, total_unit, exchange_rate, ...). */
    const AI_BALANCE_ENDPOINT = 'https://api.avalai.ir/user/v1/credit';

    // ---- AI credit: cache, warning thresholds (UNIT = USD) and cost estimation ----
    const TRANSIENT_AI_BALANCE  = 'wila_ai_balance';
    const AI_BALANCE_CACHE_TTL  = 60;     // seconds; the refresh button bypasses it
    const AI_BALANCE_WARN_UNIT  = 1.0;    // below this remaining credit: yellow warning
    const AI_BALANCE_CRITICAL_UNIT = 0.2; // below this (or empty): red
    const FILTER_AI_PRICING     = 'wila_ai_pricing';

    /**
     * Approximate list prices in USD per 1,000 tokens (input / output) by model.
     * Provider prices change; override with the `wila_ai_pricing` filter. Figures are estimates only.
     */
    const AI_DEFAULT_PRICING = array('input_per_1k' => 0.00075, 'output_per_1k' => 0.0045);

    /** Typical tokens of one bulk product row (long prompt rules in, ~1000-word Persian HTML out). */
    const AI_EST_INPUT_TOKENS_PER_ROW  = 3000;
    const AI_EST_OUTPUT_TOKENS_PER_ROW = 3000;

    // ---- focus keyphrase / anchor text standard: 2-4 meaningful words, never a full product title ----
    const KEYPHRASE_MAX_WORDS = 4;
    const KEYPHRASE_MIN_WORDS = 2;
    const KEYPHRASE_MODEL_MARKER = 'مدل';
    const FILTER_KEYPHRASE_WORDS = 'wila_keyphrase_words';

    // انکر لینک دسته‌بندی = پیشوند توصیفی + نام واقعی دسته (هرگز نام خام که با کلیدواژه صفحه یکی شود)؛ ۴ تا ۵ کلمه
    const CATEGORY_ANCHOR_PREFIXES  = array('انواع', 'مجموعه مدل‌های', 'کلکسیون');
    const CATEGORY_ANCHOR_MAX_WORDS = 5;
    // اگر بیش از این نسبت از کلمات انکر یک لینک داخلی پیش‌بینی‌نشده در کلیدواژه کانونی صفحه باشد، انکر رقیب است
    const COMPETING_ANCHOR_RATIO = 0.5;

    // عنوان سئوی محصول: «کلیدواژه + مدل | مزیت + ایموجی | نام برند» با ۵۰ تا ۶۰ کاراکتر
    const SEO_TITLE_MIN_CHARS = 50;
    const SEO_TITLE_MAX_CHARS = 60;
    const SEO_TITLE_SEPARATOR = ' | ';
    const FILTER_BRAND_NAME   = 'wila_brand_name';
    // ایموجی مجاز به‌ازای نوع کالا؛ ترتیب مهم است (اولین تطابق برنده است)
    const SEO_TITLE_EMOJIS = array(
        array('👝', array('کیف پول', 'کمربند', 'جاکارتی')),
        array('🎒', array('کوله')),
        array('👜', array('کیف')),
        array('👢', array('نیم‌بوت', 'نیمبوت', 'بوت', 'چکمه')),
        array('👠', array('پاشنه', 'مجلسی', 'صندل', 'کفش زنانه')),
        array('👟', array('کتانی', 'اسپرت', 'روزمره', 'کفش ورزشی')),
        array('👞', array('کفش مردانه', 'رسمی', 'کفش')),
    );
    const SEO_TITLE_DEFAULT_EMOJI = '👞';
    // مزیت پیش‌فرض بخش دوم وقتی خروجی AI مزیت قابل‌استفاده ندارد؛ با فیلتر wila_seo_title_feature قابل تغییر است
    const SEO_TITLE_DEFAULT_FEATURE = 'چرم طبیعی تبریز';
    const FILTER_SEO_TITLE_FEATURE  = 'wila_seo_title_feature';
    // کلمات عمومی فروشگاهی که در بخش مزیت (شعار سایت) حذف می‌شوند
    const SEO_TITLE_FEATURE_STOPWORDS = array('فروشگاه', 'فروشگاهی', 'محصولات', 'محصول', 'خرید', 'آنلاین', 'اینترنتی', 'مرکز', 'عرضه', 'بهترین', 'تولیدی', 'و', 'از', 'در', 'با', 'برای');

    /** Generic / promotional words that never belong in a keyphrase. */
    const KEYPHRASE_FILLERS = array('طبیعی', 'طرح', 'کد', 'اصل', 'اصلی', 'اورجینال', 'مدل', 'کیفیت', 'باکیفیت', 'عالی', 'ارزان', 'جدید', 'ویژه', 'فوق', 'درجه', 'یک', 'لوکس', 'لاکچری', 'شیک', 'زیبا', 'پرفروش', 'پرطرفدار', 'تخفیفی', 'دست‌دوز', 'دستدوز', 'دست‌ساز', 'مناسب', 'روزمره', 'راحت', 'سبک', 'رنگ', 'رنگی', 'رنگبندی', 'سایز', 'جفت', 'عدد', 'بسته', 'و', 'با', 'برای', 'از', 'در', 'به', 'فروش', 'خرید', 'قیمت');

    /** Other materials: dropped unless the phrase would become too short. */
    const KEYPHRASE_MATERIALS = array('جیر', 'پارچه', 'پارچه‌ای', 'مصنوعی');

    /**
     * Conditional modifiers in priority order: never part of the core [type + gender + model], but used to fill the
     * keyphrase up to 4 words - right after the product type - when the core is shorter and the title has them.
     * 1) distinguishing usage (طبی، مجلسی، اسپرت، اداری، راحتی، کلاسیک), 2) material (چرم، چرمی), 3) روزمره.
     */
    const KEYPHRASE_CONDITIONAL = array('طبی', 'مجلسی', 'اسپرت', 'اداری', 'راحتی', 'کلاسیک', 'چرم', 'چرمی', 'روزمره');

    /** Audience / style qualifiers that are kept next to the product type. */
    const KEYPHRASE_AUDIENCE = array('مردانه', 'زنانه', 'دخترانه', 'پسرانه', 'بچگانه', 'بچه‌گانه', 'نوجوان', 'اسپرت', 'رسمی', 'مجلسی');

    /** Gender words: kept first among the audience qualifiers. */
    const KEYPHRASE_GENDER = array('مردانه', 'زنانه', 'دخترانه', 'پسرانه', 'بچگانه', 'بچه‌گانه');

    /** City / origin names. */
    const KEYPHRASE_CITIES = array('تبریز', 'تهران', 'اصفهان', 'شیراز', 'مشهد', 'کرج', 'قم', 'اهواز', 'رشت', 'ارومیه', 'یزد', 'کرمان', 'همدان', 'اراک', 'زنجان', 'ایران', 'ایرانی', 'تبریزی');

    // ---- network timeouts (seconds) ----
    const TIMEOUT_DEFAULT = 20;
    const TIMEOUT_LONG    = 35;
    const TIMEOUT_BULK    = 180;
    const TIMEOUT_IMAGE   = 120;

    // ---- token ceilings ----
    const TOKENS_SUGGESTION = 2048;
    const TOKENS_GENERATION = 12000;

    // ---- content kinds ----
    const KIND_ARTICLE  = 'article';
    const KIND_PRODUCT  = 'product';
    const KIND_CATEGORY = 'product_cat';

    /**
     * Per-model pricing (USD / 1K tokens), filterable.
     *
     * @return array{input_per_1k:float,output_per_1k:float}
     */
    public static function ai_pricing($model = self::AI_MODEL) {
        $table = apply_filters(self::FILTER_AI_PRICING, array(self::AI_MODEL => self::AI_DEFAULT_PRICING));
        $row = (is_array($table) && isset($table[$model]) && is_array($table[$model])) ? $table[$model] : self::AI_DEFAULT_PRICING;
        return array(
            'input_per_1k'  => isset($row['input_per_1k']) ? (float) $row['input_per_1k'] : self::AI_DEFAULT_PRICING['input_per_1k'],
            'output_per_1k' => isset($row['output_per_1k']) ? (float) $row['output_per_1k'] : self::AI_DEFAULT_PRICING['output_per_1k'],
        );
    }

    /**
     * Keyphrase word lists (fillers, materials, audience, gender, cities), extendable with the `wila_keyphrase_words` filter.
     *
     * @return array{fillers:string[],materials:string[],audience:string[],gender:string[],cities:string[]}
     */
    public static function keyphrase_words() {
        $lists = array('fillers' => self::KEYPHRASE_FILLERS, 'materials' => self::KEYPHRASE_MATERIALS, 'audience' => self::KEYPHRASE_AUDIENCE, 'gender' => self::KEYPHRASE_GENDER, 'cities' => self::KEYPHRASE_CITIES, 'conditional' => self::KEYPHRASE_CONDITIONAL);
        if (function_exists('apply_filters')) {
            $filtered = apply_filters(self::FILTER_KEYPHRASE_WORDS, $lists);
            if (is_array($filtered)) {
                foreach ($lists as $name => $default) {
                    if (isset($filtered[$name]) && is_array($filtered[$name])) $lists[$name] = array_values(array_map('strval', $filtered[$name]));
                }
            }
        }
        return $lists;
    }

    /** Auto in-article image generation switch (off by default: every image costs credit). */
    public static function auto_image_enabled() {
        return get_option(self::OPT_AUTO_IMAGE_ENABLED, '0') === '1';
    }

    public static function avalai_api_key() {
        return trim(get_option(self::OPT_AVALAI_API_KEY, ''));
    }

    /**
     * نام برند/فروشگاه برای انتهای عنوان سئو: تنظیم افزونه، سپس فیلتر wila_brand_name، سپس نام سایت وردپرس
     */
    public static function brand_name() {
        $brand = trim((string) get_option(self::OPT_BRAND_NAME, ''));
        if ($brand === '') $brand = trim((string) apply_filters(self::FILTER_BRAND_NAME, get_bloginfo('name')));
        $brand = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($brand)));
        // فقط نام کوتاه برند: هر چه بعد از جداکننده‌ای مثل « | » یا « - » آمده شعار است
        $parts = preg_split('/\s+[|\x{2013}\x{2014}\-:·]\s+/u', $brand);
        return trim($parts[0]);
    }

    /**
     * شعار سایت (توضیح کوتاه وردپرس)؛ فقط برای شناسایی و حذف از عنوان سئو
     */
    public static function site_tagline() {
        return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) get_bloginfo('description'))));
    }

    public static function seo_title_feature() {
        return trim((string) apply_filters(self::FILTER_SEO_TITLE_FEATURE, self::SEO_TITLE_DEFAULT_FEATURE));
    }

    public static function homepage_keywords() {
        return get_option(self::OPT_HOMEPAGE_KEYWORDS, '');
    }

    /**
     * نوع محتوای محصول: تنظیمات افزونه، سپس فیلتر، سپس product و در نهایت اولین نوع عمومی که نامش شامل product باشد
     */
    public static function product_post_type() {
        $type = sanitize_key(get_option(self::OPT_PRODUCT_POST_TYPE, ''));
        if ($type === '') $type = sanitize_key(apply_filters(self::FILTER_PRODUCT_POST_TYPE, 'product'));
        if (post_type_exists($type)) return $type;
        foreach (get_post_types(array('public' => true), 'names') as $name) {
            if (stripos($name, 'product') !== false) return $name;
        }
        return $type;
    }

    /**
     * تاکسونومی دسته‌بندی محصول: تنظیمات، فیلتر، product_cat، سپس تاکسونومی سلسله‌مراتبی عمومی متصل به نوع محصول
     */
    public static function category_taxonomy() {
        $tax = sanitize_key(get_option(self::OPT_PRODUCT_CATEGORY_TAXONOMY, ''));
        if ($tax === '') $tax = sanitize_key(apply_filters(self::FILTER_PRODUCT_CATEGORY_TAXONOMY, 'product_cat'));
        if (taxonomy_exists($tax)) return $tax;
        foreach (get_object_taxonomies(self::product_post_type(), 'objects') as $object) {
            if ($object->public && $object->hierarchical) return $object->name;
        }
        return $tax;
    }

    public static function normalize_kind($kind) {
        $kind = sanitize_key($kind);
        return in_array($kind, array(self::KIND_PRODUCT, self::KIND_CATEGORY), true) ? $kind : self::KIND_ARTICLE;
    }

    public static function is_target_kind($kind) {
        return $kind === self::KIND_PRODUCT || $kind === self::KIND_CATEGORY;
    }

    public static function kind_label($kind) {
        $labels = array(
            self::KIND_ARTICLE  => 'مقاله',
            self::KIND_PRODUCT  => 'محصول',
            self::KIND_CATEGORY => 'دسته‌بندی محصول',
        );
        return isset($labels[$kind]) ? $labels[$kind] : $labels[self::KIND_ARTICLE];
    }

    public static function kind_available($kind) {
        if ($kind === self::KIND_PRODUCT) return post_type_exists(self::product_post_type());
        if ($kind === self::KIND_CATEGORY) return taxonomy_exists(self::category_taxonomy());
        return true;
    }
}
