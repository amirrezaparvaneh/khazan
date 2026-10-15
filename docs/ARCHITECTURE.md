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
- `WILA_AvalAI_Client` (~700 خط): انتقال HTTP + ساخت پرامپت + پارس پاسخ + پس‌پردازش HTML. **(مرحله ۱ و ۲: به `AvalaiHttpClient`، `PromptBuilder`، `ContentParser` و `AiResponseDecoder` شکسته شد؛ مرحله ۷: هماهنگ‌کننده به `Services\Ai\AiService` رفت و کلاس قدیمی فقط Wrapper است.)**
- `WILA_REST_Controller` (~25 مسیر): اعتبارسنجی، منطق کسب‌وکار (`calculate_orphans`, `create_content_draft`) و پاسخ‌دهی. **(مرحله ۲: به `Http\Controllers\{Queue,Report,Content,Settings}Controller` شکسته شد؛ کلاس قدیمی فقط Dispatcher ثبت مسیرهاست.)**
- `WILA_Content_Targets`: خواندن زمینه، برچسب‌ها، نوشتن محتوا، تنظیمات پست‌تایپ. **(مرحله ۳: دسترسی به داده به `Repository\ContentTargetRepository` رفت؛ کلاس قدیمی ~۱۴۰ خط با تنظیمات/نوع‌ها و delegateهای سازگاری است.)**
- `WILA_Bulk_Generator`: تطبیق ردیف‌ها + پردازش + ساخت پیش‌نویس. **(مرحله ۱ و ۴: تطبیق به `ProductMatcher` و پردازش به `BulkRowProcessor` رفت؛ کلاس ~۴۰ خط ارجاع‌دهنده است؛ مرحله ۷: `QueueController` مستقیماً از آن‌ها استفاده می‌کند.)**
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
  Core/        Autoloader, Plugin (Orchestrator)، View, Settings (کلیدهای آپشن/متا، timeout، سقف توکن، انواع محتوا)
  Admin/       AdminMenu, AdminAssets, SeoRepairPanel
  Http/        RestRouter (ثبت همه مسیرهای wila/v1)
  Http/Controllers/  QueueController, ReportController, ContentController, SettingsController, SeoRepairController
  Services/Report/   SiteStatsService (آمار سلامت محتوایی داشبورد)
  Services/Links/    OrphanFinder, SmartLinkDistributor (+ LinkCandidateProvider, WordPressLinkCandidateProvider,
                     AssignmentLedger, TransientAssignmentLedger, InMemoryAssignmentLedger)
  Services/
    Ai/        AiService (هماهنگ‌کننده)، AvalaiHttpClient, AiResponseDecoder, PromptBuilder, ContentParser,
               BalanceReport (نرمال‌سازی و وضعیت اعتبار)، CostEstimator (برآورد هزینه)
    Csv/       TextNormalizer, ProductMatcher, BulkRowProcessor
    Seo/       SeoMetaService
    Content/   HtmlSanitizer, ContentGuard (هماهنگ‌کننده)، ContentRules
    Content/Guard/  WrapperGuard, LinkGuard, KeywordDensityGuard, HeadingGuard
    Scan/      ScannerService
  Repository/  SiteEntityRepository, ContentTargetRepository, DatabaseRepository,
               BulkHistoryRepository, LinkRepository
  Http/Requests/     ContentGenerationRequest, ContentDraftRequest, SettingsUpdateRequest, ConnectionTestRequest,
                     AvailableTaxonomiesRequest, ScanItemRequest, BulkMatchRequest, BulkProcessRequest, ItemsReportRequest,
                     SiteProductsRequest, AiBalanceRequest
  Legacy کلاس‌های WILA_* تا پایان مهاجرت (بارگذاری از Plugin::$legacy_files)
tests/         تست‌های واحد مستقل از وردپرس (php tests/SmartLinkDistributorTest.php)
templates/     قالب‌های PHP (admin/app-root, font-preconnect, ...)
assets/js/     khazan-utils.js، khazan-csv.js، khazan-api.js (REST)، khazan-forms.js (رویدادها و کمک‌های فرم)، khazan-queue.js (صف)،
               khazan-tabs.js (ناوبری و نماها)، khazan-leaves.js، wila-admin.js (Entry Point ~۱۶۰ خط: state، init، render، mixin ماژول‌ها)
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
7. **انجام شد (مرحله ۷):**
   - `Services\Ai\AiService`: هماهنگ‌کننده `AvalaiHttpClient`، `PromptBuilder`، `ContentParser` و `AiResponseDecoder`. هر ۹ ارجاع کلاس‌های جدید به `WILA_AvalAI_Client` (کنترلرهای محتوا و تنظیمات، `BulkRowProcessor`، `SeoRepairController`) به `AiService` منتقل شد؛ `WILA_AvalAI_Client` فقط Wrapper با ثابت `MODEL` و همان متدهای عمومی است. ارجاع `QueueController` به `WILA_Bulk_Generator` هم به `ProductMatcher` و `BulkRowProcessor` تبدیل شد (در `ContentController` چنین ارجاعی نبود).
   - `Khazan\Http\RestRouter` همه مسیرها (شامل `/seo-repair`) را ثبت می‌کند؛ `Khazan\Admin\SeoRepairPanel` و `Http\Controllers\SeoRepairController` جایگزین `WILA_SEO_Repair` شدند. `Core\Plugin` مستقیماً `RestRouter` و `SeoRepairPanel` را وصل می‌کند؛ `WILA_REST_Controller` و `WILA_SEO_Repair` فقط Wrapper سازگاری‌اند. هیچ کلاس `Khazan\` به کلاس سراسری `WILA_*` ارجاع نمی‌دهد.
   - `khazan-api.js`: `apiFetch` و همه ۲۳ متد وابسته به REST (fetchها، تولید محتوا، رفع سئو، تنظیمات، اسکن). ترتیب بارگذاری: `utils` و `csv` ← `api` و `queue` و `tabs` ← `wila-admin`. `wila-admin.js` از ~۷۶۰ به ~۳۹۰ خط رسید.
8. **انجام شد (مرحله ۸):**
   - `khazan-forms.js`: ۱۴ متد کمکی فرم‌ها (برنامه‌ریز، مولد محتوا، رفع سئو، کپی) و `bindFormEvents` (همه رویدادهای input/change/click فرم‌ها). `wila-admin.js` به ~۱۶۰ خط رسید (state، `init`، `render`، `modalHtml`، `bindEvents` و سوار کردن ماژول‌ها). اعتبارسنجی مقادیر ارسالی همچنان در متدهای API/سمت سرور است؛ ماژول فرم‌ها فقط مقادیر را به state می‌رساند.
   - `Http\Requests\*`: `SettingsUpdateRequest`، `ConnectionTestRequest`، `AvailableTaxonomiesRequest`، `ScanItemRequest`، `BulkMatchRequest`، `BulkProcessRequest`، `ItemsReportRequest` و `ContentDraftRequest`؛ متدهای `SettingsController`، `QueueController`، `ReportController` و `create_content_draft` ۳ تا ۶ خطی شدند.
   - `ContentGuard` به `Guard\WrapperGuard`، `LinkGuard`، `KeywordDensityGuard` و `HeadingGuard` شکسته شد؛ `ContentGuard` با همان امضا و ثابت‌ها فقط هماهنگ‌کننده است (۵۹ خط).
9. **انجام شد (ویژگی‌های جدید پس از مرحله ۸):**
   - **تب مستقل «آرشیو محتوا»** (`tab: 'archive'`، نمای `viewArchive` در `khazan-tabs.js`): با ورود به تب، `/content/history` خودکار خوانده می‌شود؛ عملیات «مشاهده و کپی»، «بازیابی» (`restoreHistoryItem`: محتوا را به تب «تولید محتوای تکی» برمی‌گرداند) و «حذف». بخش آرشیو از پایین صفحه تولید محتوا حذف شد.
   - **دکمه‌های «تولید طرح ساختاری» و «تایید و تولید محتوا»** با همان `btn3d` (دکمه سه‌بعدی) تولید انبوه جایگزین شدند؛ `id`ها و منطق دست‌نخورده.
   - **تولید انبوه بدون CSV:** انتخاب منبع (CSV یا «محصولات سایت»). مسیر جدید `GET /wila/v1/bulk/site-products` (`QueueController::get_site_products` ← `SiteProductsRequest` ← `ContentTargetRepository::list_site_products`) فهرست محصولات **منتشرشده** را با دسته‌بندی، وضعیت محتوا (`has_description`)، سابقه تولید و متای سئو برمی‌گرداند؛ فیلتر محتوا: `all` / `empty` (بدون توضیحات) / `has_content` (دارای توضیحات)، به‌علاوه دسته‌بندی و جستجو (`no_description` و `ungenerated` برای سازگاری API پذیرفته می‌شوند). پاسخ شامل شمارنده‌های `total_count`، `empty_count` و `has_content_count` (هم سطح بالا و هم زیر `counts`) است که برای همان دامنه (دسته‌بندی + جستجو) و مستقل از فیلتر محتوا محاسبه می‌شوند؛ پس همیشه `total = empty + has_content`. در فرانت‌اند منوی کشویی وضعیت محتوا با سه کارت آماری کلیک‌پذیر (`.khazan-stat-card`: «همه محصولات»، «بدون توضیحات و خالی»، «دارای توضیحات قبلی») جایگزین شد؛ کلیک روی هر کارت (`setSiteFilter`) فهرست را فوراً از سرور فیلتر می‌کند، تغییر دسته‌بندی کارت فعال را حفظ و شمارنده‌ها و فهرست را به‌روز می‌کند و جستجو با Enter یا «بارگذاری محصولات» اعمال می‌شود. زیر کارت‌ها جدول پیش‌نمایش با تیک انتخاب (همه پیش‌فرض انتخاب‌اند) و دکمه «افزودن X محصول به صف تولید انبوه» است؛ فقط انتخاب‌شده‌ها با `via: 'site'` به همان جدول و صف تولید انبوه اضافه می‌شوند. پس از افزودن، بخش انتخاب (کارت‌ها، فیلترها، نوارها و جدول) به نوار خلاصه‌ی «تعداد X محصول از سایت به صف اضافه شد — تغییر یا انتخاب مجدد محصولات» جمع می‌شود (`site.collapsed`) و صفحه نرم به تنظیمات صف (`#wila-queue-settings`) اسکرول می‌کند؛ دکمه‌ی تغییر، بخش را با حفظ فهرست و انتخاب‌ها دوباره باز می‌کند. توجه: فهرست و شمارنده‌ها فقط محصولات منتشرشده را شامل می‌شوند (قبلاً پیش‌نویس/در انتظار/خصوصی هم بود).
   - **`SmartLinkDistributor`** (جایگزین منطق لینک‌سازی `BulkRowProcessor` و زمینه تولید محتوای تکی): لینک دسته = دسته‌بندی اصلی خودِ محصول (Primary در Yoast/Rank Math، وگرنه عمیق‌ترین دسته)؛ لینک محصول فقط از میان محصولات هم‌دسته (در صورت کمبود، دسته‌های والد) با شباهت دسته‌ای و عنوانی؛ توزیع عادلانه: اولویت با کمترین «ورودی» (لینک ورودی در پایگاه‌داده از `LinkRepository::inbound_counts` + لینک‌های اختصاص‌یافته در ردیف‌های قبلی همین اجرا)، سپس محصولاتی که ردیفشان هنوز در صف مانده (چون فرصت کمتری برای دریافت لینک دارند)، سپس شباهت و شناسه. هر انتخاب بلافاصله در `AssignmentLedger` (transient با `run_id` که فرانت‌اند برای هر اجرا می‌سازد) ثبت می‌شود تا ردیف بعدی (درخواست REST جدا) شمارنده به‌روز را ببیند. استخر کاندیدا برای هر ردیف با کوئری محدود به دسته‌های همان محصول ساخته می‌شود (نه اسکن کل کاتالوگ)؛ دسته‌بندی‌ها از `SiteEntityRepository` (`category_tree`، `product_categories`، `primary_category_id`) و محصولات منتشرشده از `published_product_ids_in_categories` می‌آیند؛ محصول صف که منتشر نشده باشد لینک نمی‌گیرد (آدرس پیش‌نویس برای بازدیدکننده ۴۰۴ است).
   - **تست:** `tests/SmartLinkDistributorTest.php` (بدون وابستگی به وردپرس): ۲۰ محصول هم‌دسته در ۵۰ ترتیب تصادفی صف ⇒ اختلاف max−min تعداد لینک ورودی صفر (حداکثر مجاز ۱)، لینک دسته همیشه دسته خودِ محصول، بدون لینک تصادفی بین دسته‌های نامرتبط، اولویت محصول کم‌لینک در برابر ۱۳ لینک موجود، چرخش بین ردیف‌ها.
10. **انجام شد (کنترل صف و ویجت آمار داشبورد):**
   - **کنترل‌های صف تولید انبوه:** دکمه‌های «شروع تولید محتوا»، «مکث موقت» (در حالت مکث «ادامه») و «توقف کامل» هم بالا و هم پایین جدول صف قرار دارند (`queueControls('top'|'bottom')` در `viewBulk`؛ شناسه‌های بالا همان قبلی و پایین با پسوند `-bottom`) و از یک state رندر می‌شوند، پس وضعیت (فعال، در حال پردازش، غیرفعال، در حال توقف) همیشه هم‌گام است. شنونده‌ها با کلاس‌های `.wila-bulk-start|pause|stop` بسته می‌شوند و DOM در هر رندر نو می‌شود، پس هر دکمه دقیقاً یک شنونده دارد. نوار پیشرفت فقط بالای جدول است و شامل «تکمیل شده: X از Y (Z٪)» و «زمان باقی‌مانده تقریبی» (میانگین زمان محصولات تمام‌شده × باقی‌مانده؛ `bulk.startedAt`) است؛ لاگ پایین صفحه ماند.
   - **`GET /wila/v1/report/site-stats`** (`ReportController::get_site_stats` ← `Services\Report\SiteStatsService` ← `SiteEntityRepository::content_stats` + `DatabaseRepository::count_content_history` + `LinkRepository::total_links`): فقط ۴ کوئری تجمیعی `COUNT` با `$wpdb->prepare` (بدون حلقه روی ردیف‌ها): محصولات و مقالات (پست + نوع وبلاگ) منتشرشده با/بدون متن، دسته‌های محصول و مقاله با/بدون توضیحات، شاخص پوشش محتوایی = (محصولات دارای متن + مقالات دارای متن + دسته‌های دارای توضیحات) ÷ کل صفحات، تعداد محتواهای تولیدشده با خزان و لینک‌های داخلی ایندکس‌شده. نتیجه ۵ دقیقه در transient کش می‌شود (`?refresh=1` کش را دور می‌زند). توجه: «دارای متن» یعنی `TRIM(post_content) <> ''` (محتوای فقط‌تگ مثل `<p></p>` محتوا حساب می‌شود؛ در لیست «محصولات سایت» تگ‌ها حذف می‌شوند، پس ممکن است عددها در موارد حاشیه‌ای کمی فرق کنند).
   - **داشبورد:** `viewDashboard` (زیر کارت‌های تب خانه): کارت‌های KPI (مقالات، محصولات، دسته‌ها، لینک‌های داخلی فعال، محتواهای تولیدشده)، دو حلقه SVG انیمیشن‌دار (پوشش محتوای محصولات و شاخص کل سایت)، نوارهای تفکیک دارای/بدون توضیح برای دسته‌ها، محصولات و مقالات و دکمه «تولید محتوا برای X محصول بدون توضیحات» که مستقیم به تولید انبوه با منبع «محصولات سایت» و فیلتر «بدون توضیحات» می‌رود (`goBulkEmptyProducts`). آمار در `init` و هنگام ورود به تب خانه (در صورت نبود) خوانده می‌شود و دکمه «به‌روزرسانی آمار» کش را دور می‌زند. همه استایل‌ها زیر `#wila-app-root` هستند.
11. **انجام شد (اعتبار زنده API و برآورد هزینه):**
   - **منبع داده:** مستندات رسمی AvalAI (`docs.avalai.ir/fa/api-reference/user`) اندپوینت `GET https://api.avalai.ir/user/v1/credit` با هدر `Authorization: Bearer <key>` را معرفی می‌کند که `limit` (کل، تومان)، `remaining_irt`، `remaining_unit` (دلار)، `total_unit`، `exchange_rate`، `account_tier` و `credit_sources` برمی‌گرداند. مسیر `/v1/dashboard/billing/credit_grants` (سبک OpenAI) در AvalAI مستند نشده و استفاده نشد. (این مشخصات از خلاصه‌ی صفحه‌ی مستندات خوانده شد؛ قبل از انتشار یک بار با کلید واقعی تأیید شود.)
   - **بک‌اند:** `AvalaiHttpClient::balance()` (خطاها: `missing_api_key`، `invalid_api_key` برای ۴۰۱/۴۰۳، `api_busy` برای ۴۲۹/۵۰۳، `connection_failed`، `invalid_response`، `api_error`)، `BalanceReport::from_api()` (کل/مصرف‌شده/باقی‌مانده به تومان و دلار؛ فیلدهای ناموجود از نرخ تبدیل ساخته می‌شوند؛ منفی‌ها صفر می‌شوند) و `BalanceReport::status()` (`ok` ≥ ۱$، `low` زرد < ۱$، `critical` قرمز < ۰٫۲$، `empty` قرمز = ۰؛ آستانه‌ها در `Settings::AI_BALANCE_*`). `AiService::get_balance()` فقط با کلید ذخیره‌شده کار می‌کند (کلید هرگز از URL خوانده نمی‌شود)، نتیجه را ۶۰ ثانیه در transient با هش کلید کش می‌کند (تغییر کلید کش را باطل می‌کند، خطا کش نمی‌شود). مسیر `GET /wila/v1/settings/ai-balance` (`SettingsController::get_ai_balance` ← `AiBalanceRequest`؛ `?refresh=1` کش را دور می‌زند) خطاها را با وضعیت ۴۰۰/۵۰۲/۵۰۳ برمی‌گرداند (عمداً ۴۰۱ نه، تا با خطای احراز هویت وردپرس اشتباه نشود).
   - **برآورد هزینه:** `CostEstimator::per_row()` با نرخ‌های `Settings::AI_DEFAULT_PRICING` (مدل پیش‌فرض `gpt-5.4-mini`: ورودی ۰٫۰۰۰۷۵$ و خروجی ۰٫۰۰۴۵$ به‌ازای هر ۱۰۰۰ توکن؛ برگرفته از فهرست قیمت عمومی OpenAI و تقریبی است، نه قیمت قطعی AvalAI) و فرض ۳۰۰۰ توکن ورودی و ۳۰۰۰ توکن خروجی برای هر ردیف ⇒ حدود ۰٫۰۱۵۷۵$ برای هر محصول. نرخ‌ها با فیلتر `wila_ai_pricing` قابل تغییرند. `GET /settings` اکنون `ai_pricing` را هم برمی‌گرداند تا برآورد حتی در خطای استعلام اعتبار نمایش داده شود.
   - **فرانت‌اند:** `getAiBalance(force)` در `khazan-api.js`؛ بج `aiBalanceBadge('compact'|'full')` در `khazan-tabs.js`: نوار بالای همه تب‌ها (compact) و زیر فیلد کلید API در تنظیمات (full با سه خانه‌ی کل/مصرف‌شده/باقی‌مانده)، دکمه‌ی چرخان استعلام مجدد بدون بارگذاری صفحه، رنگ سبز/زرد/قرمز و پیام هشدار، حالت‌های «کلید ثبت نشده»، «کلید نامعتبر» و «اعتبار نامشخص» برای خطاها (بدون کرش؛ خطای شبکه یا JSON نامعتبر هم گرفته می‌شود). اعداد با جداکننده‌ی هزارگان و ارقام فارسی (`formatNumber`، `formatUsd` در `khazan-utils.js`). `costEstimateHtml(n)` در نوار وضعیت صف و نوارهای انتخاب «محصولات سایت» نشان می‌دهد: «هزینه تخمینی این پردازش: ~X تومان (≈ $Y)»؛ اگر برآورد از اعتبار باقی‌مانده بیشتر باشد قرمز و با «اعتبار فعلی کافی نیست» علامت می‌خورد.
12. **باقی‌مانده:** تست نهایی در وردپرس واقعی (فاز ۹)، از جمله تأیید پاسخ واقعی `/user/v1/credit` و نرخ واقعی مدل در AvalAI؛ مدیریت خطای شبکه یکپارچه در `apiFetch`؛ `Http\Requests\*` برای `fix_seo_errors`/`apply_content_to_target`؛ حذف Wrapperهای `WILA_*` پس از اطمینان از نبود فراخوان بیرونی؛ فعال‌سازی یا حذف رسمی قابلیت پارک‌شده Insights؛ صفحه‌بندی واقعی لیست محصولات سایت (فعلاً سقف ۵۰۰ و نشانگر `has_more`).
