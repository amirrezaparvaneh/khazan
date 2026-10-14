# معماری افزونه خزان

این سند وضعیت فعلی (Audit)، ساختار هدف (PSR-4) و نقشه مهاجرت گام‌به‌گام را ثبت می‌کند.

## ۱. گزارش وضعیت (Audit)

### توابع چاق (بیش از ~۶۰ خط)
| محل | تابع | طول | مشکل |
|---|---|---|---|
| ai/class-wila-avalai-client.php | `parse_full_content_payload` | ~100 | پارس، اصلاح لینک، شمارش و ساخت خروجی در یک تابع |
| class-wila-bulk-generator.php | `process` | ~93 | **(مرحله ۴: به `Services\Csv\BulkRowProcessor` با ۱۰ زیرمرحله کوچک منتقل شد)** |
| class-wila-db.php | `create_tables` | ~85 | **(مرحله ۴: در `DatabaseRepository` به متدهای خصوصی هر جدول شکسته شد)** |
| class-wila-insights.php | `get_search_console_quick_wins` | ~83 | HTTP، پارس و امتیازدهی مخلوط (کلاس فعلاً بارگذاری نمی‌شود) |
| api/class-wila-rest-controller.php | `content_generator_params` | ~62 | **(مرحله ۳: به `Http\Requests\ContentGenerationRequest` منتقل شد)** |
| ai/class-wila-avalai-client.php | `generate_link_suggestions` | ~62 | کوئری، پرامپت، فراخوانی و پارس |
| assets/js/wila-admin.js | `render` | 500+ | تمام تب‌ها در یک تابع؛ فایل ۱۵۰۰+ خطی |

### نقض SRP
- `WILA_AvalAI_Client` (~700 خط): انتقال HTTP + ساخت پرامپت + پارس پاسخ + پس‌پردازش HTML. **(مرحله ۱ و ۲: به `AvalaiHttpClient`، `PromptBuilder`، `ContentParser` و `AiResponseDecoder` شکسته شد؛ کلاس قدیمی ~۱۷۰ خط و فقط هماهنگ‌کننده است.)**
- `WILA_REST_Controller` (~25 مسیر): اعتبارسنجی، منطق کسب‌وکار (`calculate_orphans`, `create_content_draft`) و پاسخ‌دهی. **(مرحله ۲: به `Http\Controllers\{Queue,Report,Content,Settings}Controller` شکسته شد؛ کلاس قدیمی فقط Dispatcher ثبت مسیرهاست.)**
- `WILA_Content_Targets`: خواندن زمینه، برچسب‌ها، نوشتن محتوا، تنظیمات پست‌تایپ. **(مرحله ۳: دسترسی به داده به `Repository\ContentTargetRepository` رفت؛ کلاس قدیمی ~۱۴۰ خط با تنظیمات/نوع‌ها و delegateهای سازگاری است.)**
- `WILA_Bulk_Generator`: تطبیق ردیف‌ها + پردازش + ساخت پیش‌نویس. **(مرحله ۱ و ۴: تطبیق به `ProductMatcher` و پردازش به `BulkRowProcessor` رفت؛ کلاس ~۴۰ خط ارجاع‌دهنده است.)**
- `WILA_DB`: اسکیما + کوئری سابقه + حذف لینک‌ها. **(مرحله ۴: به `Repository\DatabaseRepository` رفت؛ `WILA_DB` فقط ارجاع‌دهنده است.)**
- `wila-admin.js`: state، API، رندر HTML، رویدادها و پارس CSV. **(مرحله ۱: پارس CSV جدا شد؛ مرحله ۶: صف، تب‌ها و ابزارها به ماژول‌های `khazan-*.js` رفت و فایل از ~۱۵۷۰ به ~۷۶۰ خط رسید.)**

### سایر بوی بد کد
- همه‌چیز `static` است و تزریق وابستگی ندارد؛ تست‌پذیری پایین.
- مقادیر هاردکد: آدرس API دو بار تکرار شده بود، timeoutها (20/35/180)، سقف توکن (2048/12000)، نام آپشن‌ها (`wila_*`) پراکنده، مدل ثابت.
- ناسازگاری نسخه: هدر افزونه `1.0.0` ولی `WILA_VERSION = 1.6.0` **(مرحله ۲: هدر روی 1.6.0 هم‌تراز شد)**.
- دو آپشن نسخه: **(مرحله ۴: ادغام شد در `wila_db_version`؛ مهاجرت نرم مقدار `wila_db_installed_version` را منتقل و آن را حذف می‌کند.)**
- `wila_ai_provider` ذخیره می‌شود ولی کلاینت همیشه AvalAI است.

### HTML داخل PHP
- `render_dashboard_view` و استایل منو (echo) → **منتقل شد** به `templates/admin/` و `assets/css/khazan-menu.css`.
- `class-wila-seo-repair.php` (متاباکس و ترم‌پنل): **منتقل شد (مرحله ۳)** به `templates/admin/seo-repair-panel.php` و `seo-repair-term-row.php` با `Core\View`؛ خروجی HTML با نسخه قبلی یکسان است.
- بخش بزرگی از HTML در رشته‌های JS است (طبق معماری SPA پروژه؛ فقط باید ماژولار شود).

## ۲. ساختار هدف (PSR-4، فضای نام `Khazan\`)

```
wp-internal-link-analyzer.php      ثابت‌ها + Autoloader + Plugin::boot
includes/
  Core/        Autoloader, Plugin (Orchestrator), View, Settings (کلیدهای آپشن/متا، timeout، سقف توکن، انواع محتوا)
  Admin/       AdminMenu, AdminAssets   (بعداً: صفحه‌ها و تنظیمات)
  Http/Controllers/  QueueController, ReportController, ContentController, SettingsController
  Services/Links/    OrphanFinder
  Services/
    Ai/        AvalaiHttpClient, AiResponseDecoder, PromptBuilder, ContentParser
    Csv/       TextNormalizer, ProductMatcher, BulkRowProcessor
    Seo/       SeoMetaService
    Content/   HtmlSanitizer, ContentGuard, ContentRules
    Scan/      ScannerService
  Repository/  SiteEntityRepository, ContentTargetRepository, DatabaseRepository,
               BulkHistoryRepository, LinkRepository
  Http/Requests/     ContentGenerationRequest
  Legacy کلاس‌های WILA_* تا پایان مهاجرت (بارگذاری از Plugin::$legacy_files)
templates/     قالب‌های PHP (admin/app-root, font-preconnect, ...)
assets/js/     khazan-utils.js، khazan-csv.js، khazan-queue.js (state و کنترل صف)، khazan-tabs.js (ناوبری و نماهای تب‌ها)،
               khazan-leaves.js، wila-admin.js (Entry/Orchestrator ~۷۶۰ خط: state اصلی، API و رویدادهای فرم‌ها)
```

قاعده نام‌گذاری: `Khazan\Services\Csv\ProductMatcher` ⇢ `includes/Services/Csv/ProductMatcher.php` (حروف دقیقاً هم‌نام کلاس).

## ۳. نقشه مهاجرت
1. **انجام شد (مرحله ۱):** Autoloader + Plugin + Admin + View/templates؛ جداسازی `AvalaiHttpClient`، `AiResponseDecoder`، `TextNormalizer`، `ProductMatcher` و `khazan-csv.js`. کلاس‌های قدیمی فقط به نسخه جدید تفویض می‌کنند (API عمومی بدون تغییر).
2. **انجام شد (مرحله ۲):** `PromptBuilder` و `ContentParser` از `WILA_AvalAI_Client`؛ شکستن `WILA_REST_Controller` به چهار کنترلر + `OrphanFinder` + هم‌ترازی نسخه. پرامپت‌ها بایت‌به‌بایت و پارسرها خروجی‌به‌خروجی با کد قبلی مقایسه شدند و ۲۵ مسیر REST بدون تغییر ماندند.
3. **انجام شد (مرحله ۳):** `SiteEntityRepository` (کوئری پست/ترم برای کلاینت AI؛ متدهای خصوصی کلاینت فقط ارجاع می‌دهند)، `ContentTargetRepository` (فهرست/زمینه/محتوای فعلی/اعمال/پیش‌نویس بازبینی و `create_blog_draft`)، `ContentGenerationRequest` (اعتبارسنجی ورودی تولید محتوا)، انتقال HTML متاباکس سئو به `templates/`. بدنه متدهای منتقل‌شده با نسخه قبلی مقایسه و یکسان تأیید شد؛ `WILA_Content_Targets` همان API استاتیک را نگه می‌دارد.
4. **انجام شد (مرحله ۴):** `DatabaseRepository` (جدول‌ها، اسکیما در متدهای خصوصی، CRUD، ادغام آپشن نسخه با مهاجرت نرم)، `SeoMetaService` (نگاشت و خواندن/نوشتن متای سئوی پست و ترم برای Yoast و RankMath؛ `ContentTargetRepository` دیگر به `WILA_Seo_Meta` وابسته نیست)، `BulkRowProcessor` (اعتبارسنجی سطر، تعیین/ساخت محصول، زمینه و لینک‌ها، پارامترهای پرامپت، تولید، ادغام مقادیر شیت، ذخیره و ثبت در آرشیو). کلاس‌های قدیمی `WILA_DB`، `WILA_Seo_Meta` و `WILA_Bulk_Generator` فقط ارجاع‌دهنده‌اند (الگوی Strangler Fig). SQL جداول، متای نوشته‌شده و خروجی `process` در ۷ سناریو با نسخه قبلی مقایسه و یکسان تأیید شد.
5. **انجام شد (مرحله ۵):** `Services\Content\{HtmlSanitizer,ContentGuard,ContentRules}` (قوانین نگارش، پالایش HTML، سقف تراکم کلیدواژه، نسبت کلیدواژه در هدینگ‌ها و قفل انکر لینک‌ها)، `Repository\BulkHistoryRepository`، `Repository\LinkRepository` (کوئری‌های گزارش و درج لینک‌های ایندکس) و متدهای آرشیو در `DatabaseRepository` (`list/find/delete_content_history`)، `Services\Scan\ScannerService` (اسکن، استخراج پیوند، حل URL و `find_orphans`). کنترلرهای `Http\Controllers\*` دیگر هیچ `$wpdb` یا SQL مستقیم ندارند. کلاس‌های قدیمی `WILA_Html_Sanitizer`، `WILA_Content_Guard`، `WILA_Content_Rules`، `WILA_Bulk_History` و `WILA_Scanner` فقط ارجاع‌دهنده‌اند و کد جدید مستقیماً از کلاس‌های `Khazan\` استفاده می‌کند.
6. **انجام شد (مرحله ۶):**
   - `Core\Settings`: نام آپشن‌ها و متاها، نام فیلترهای عمومی، پسوند جدول‌ها، مدل/آدرس AI، `TIMEOUT_DEFAULT=20`، `TIMEOUT_LONG=35`، `TIMEOUT_BULK=180`، سقف توکن‌ها و انواع محتوا (`KIND_*`، `kind_label`، `product_post_type`، `category_taxonomy`، `kind_available`). `ContentTargetRepository`، `Services\Content\*`، `ScannerService`، `AvalaiHttpClient`، `DatabaseRepository` و کنترلرها مستقیماً به `Settings` وصل شدند؛ `WILA_Content_Targets` فقط ارجاع‌دهنده است (متدهای `product_link_anchor`، `remember_csv_focus`، `product_id_from_url` به `ContentTargetRepository` رفت).
   - فرانت‌اند: `khazan-utils.js`، `khazan-queue.js`، `khazan-tabs.js`. ماژول‌ها به‌صورت mixin روی `app` سوار می‌شوند (`Object.assign(app, KhazanQueue.methods, KhazanTabs.methods)`) تا `this` و state سراسری بدون تغییر بماند. ترتیب بارگذاری در `AdminAssets`: `utils` و `csv` ← `queue` و `tabs` ← `wila-admin`.
   - `class-wila-insights.php`: **پارک‌شده**. در `Plugin::$legacy_files` بارگذاری نمی‌شود، مسیرهای `/insights/*` ثبت نمی‌شوند و `wila-insights.js` المان `#wila-insights-root` را ندارد؛ هیچ ارجاع شکسته‌ای نیست (وابستگی‌هایش به لایه‌های ارجاع‌دهنده معتبرند). فعال‌سازی نیازمند صفحه/تب مجزا، ثبت مسیر در `Khazan\Http` و ورودی Access Token است.
7. **باقی‌مانده:** `WILA_AvalAI_Client` هنوز کلاس سراسری است و کلاس‌های جدید (`BulkRowProcessor` و کنترلرها) آن را صدا می‌زنند → انتقال به `Khazan\Services\Ai\AiService`؛ `ContentGuard` (~۳۳۰ خط) برای تقسیم به لینک/تراکم/هدینگ؛ `Http\Requests\*` برای بقیه کنترلرها؛ بخش‌های بزرگ `wila-admin.js` (متدهای API و رویدادهای فرم‌ها) برای ماژول‌های `khazan-api.js` و `khazan-forms.js`؛ حذف Wrapperهای `WILA_*` پس از انتقال همه فراخوان‌ها.
