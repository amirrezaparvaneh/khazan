# معماری افزونه خزان

این سند وضعیت فعلی (Audit)، ساختار هدف (PSR-4) و نقشه مهاجرت گام‌به‌گام را ثبت می‌کند.

## ۱. گزارش وضعیت (Audit)

### توابع چاق (بیش از ~۶۰ خط)
| محل | تابع | طول | مشکل |
|---|---|---|---|
| ai/class-wila-avalai-client.php | `parse_full_content_payload` | ~100 | پارس، اصلاح لینک، شمارش و ساخت خروجی در یک تابع |
| class-wila-bulk-generator.php | `process` | ~93 | ساخت زمینه، تولید AI، درج، متا و ثبت سابقه |
| class-wila-db.php | `create_tables` | ~85 | اسکیما سه جدول + مهاجرت در یک تابع |
| class-wila-insights.php | `get_search_console_quick_wins` | ~83 | HTTP، پارس و امتیازدهی مخلوط (کلاس فعلاً بارگذاری نمی‌شود) |
| api/class-wila-rest-controller.php | `content_generator_params` | ~62 | اعتبارسنجی/پاک‌سازی ورودی داخل کنترلر |
| ai/class-wila-avalai-client.php | `generate_link_suggestions` | ~62 | کوئری، پرامپت، فراخوانی و پارس |
| assets/js/wila-admin.js | `render` | 500+ | تمام تب‌ها در یک تابع؛ فایل ۱۵۰۰+ خطی |

### نقض SRP
- `WILA_AvalAI_Client` (~700 خط): انتقال HTTP + ساخت پرامپت + پارس پاسخ + پس‌پردازش HTML. **(مرحله ۱: انتقال HTTP و دیکودر جدا شد.)**
- `WILA_REST_Controller` (~30 مسیر): اعتبارسنجی، منطق کسب‌وکار (`calculate_orphans`, `create_content_draft`) و پاسخ‌دهی.
- `WILA_Content_Targets`: خواندن زمینه، برچسب‌ها، نوشتن محتوا، تنظیمات پست‌تایپ.
- `WILA_Bulk_Generator`: تطبیق ردیف‌ها + پردازش + ساخت پیش‌نویس. **(مرحله ۱: تطبیق جدا شد.)**
- `WILA_DB`: اسکیما + کوئری سابقه + حذف لینک‌ها.
- `wila-admin.js`: state، API، رندر HTML، رویدادها و پارس CSV. **(مرحله ۱: پارس CSV جدا شد.)**

### سایر بوی بد کد
- همه‌چیز `static` است و تزریق وابستگی ندارد؛ تست‌پذیری پایین.
- مقادیر هاردکد: آدرس API دو بار تکرار شده بود، timeoutها (20/35/180)، سقف توکن (2048/12000)، نام آپشن‌ها (`wila_*`) پراکنده، مدل ثابت.
- ناسازگاری نسخه: هدر افزونه `1.0.0` ولی `WILA_VERSION = 1.6.0` (اسکریپت `build.sh` نسخه را از هدر می‌خواند).
- دو آپشن نسخه: `wila_db_version` (فعال‌سازی) و `wila_db_installed_version` (مهاجرت).
- `wila_ai_provider` ذخیره می‌شود ولی کلاینت همیشه AvalAI است.

### HTML داخل PHP
- `render_dashboard_view` و استایل منو (echo) → **منتقل شد** به `templates/admin/` و `assets/css/khazan-menu.css`.
- `class-wila-seo-repair.php` (متاباکس و ترم‌پنل) هنوز با `echo` HTML چاپ می‌کند → مرحله بعد.
- بخش بزرگی از HTML در رشته‌های JS است (طبق معماری SPA پروژه؛ فقط باید ماژولار شود).

## ۲. ساختار هدف (PSR-4، فضای نام `Khazan\`)

```
wp-internal-link-analyzer.php      ثابت‌ها + Autoloader + Plugin::boot
includes/
  Core/        Autoloader, Plugin (Orchestrator), View
  Admin/       AdminMenu, AdminAssets   (بعداً: صفحه‌ها و تنظیمات)
  Http/        کنترلرهای REST به تفکیک دامنه (Content, Bulk, Settings, Scan)
  Services/
    Ai/        AvalaiHttpClient, AiResponseDecoder (بعداً: PromptBuilder, ContentParser)
    Csv/       TextNormalizer, ProductMatcher  (بعداً: BulkProcessor)
    Seo/       (بعداً: SeoMeta, ContentGuard, HtmlSanitizer)
  Repository/  (بعداً: جدول‌ها، سابقه، لینک‌ها)
  Legacy کلاس‌های WILA_* تا پایان مهاجرت (بارگذاری از Plugin::$legacy_files)
templates/     قالب‌های PHP (admin/app-root, font-preconnect, ...)
assets/js/     khazan-csv.js (جدا شد)، khazan-leaves.js، wila-admin.js (در آینده ماژولار)
```

قاعده نام‌گذاری: `Khazan\Services\Csv\ProductMatcher` ⇢ `includes/Services/Csv/ProductMatcher.php` (حروف دقیقاً هم‌نام کلاس).

## ۳. نقشه مهاجرت
1. **انجام شد (مرحله ۱):** Autoloader + Plugin + Admin + View/templates؛ جداسازی `AvalaiHttpClient`، `AiResponseDecoder`، `TextNormalizer`، `ProductMatcher` و `khazan-csv.js`. کلاس‌های قدیمی فقط به نسخه جدید تفویض می‌کنند (API عمومی بدون تغییر).
2. `PromptBuilder` و `ContentParser` از `WILA_AvalAI_Client`؛ سپس کلاس قدیمی حذف یا به یک Facade کوچک تبدیل شود.
3. شکستن `WILA_REST_Controller` به کنترلرهای `Http\*` + `Http\Request\*` برای اعتبارسنجی ورودی.
4. `Repository\*` برای `WILA_DB` و `WILA_Bulk_History`؛ ادغام دو آپشن نسخه.
5. `Services\Seo\*` و `Services\Bulk\BulkProcessor` (شکستن `process`).
6. شکستن `wila-admin.js` به ماژول‌های تب (state/api/views).
7. یک منبع واحد برای تنظیمات (`Core\Settings`) و ثابت‌ها؛ هم‌ترازی نسخه هدر و ثابت.
