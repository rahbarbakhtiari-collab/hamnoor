# گزارش آمادگی انتشار هم‌نور ۲.۶.۰

تاریخ بررسی: ۱۹ تیر ۱۴۰۵ (۱۰ ژوئیه ۲۰۲۶)

## جمع‌بندی اجرایی

نسخه بررسی‌شده پس از اصلاحات این گزارش، **فاقد مانع بحرانی شناخته‌شده در بازبینی ایستا است و برای انتشار به‌عنوان `2.6.0 Release Candidate` آماده است**. تبدیل برچسب RC به نسخه پایدار عمومی باید پس از smoke test روی یک وردپرس واقعی، یک قالب بلوکی و یک قالب کلاسیک انجام شود؛ چنین محیط اجرایی در فضای این بررسی در دسترس نبود.

پیشنهاد نسخه `2.6.0` است، نه `2.5.x`؛ زیرا علاوه بر رفع خطا، نسخه دیتابیس، indexها، امنیت توکن، قرارداد ادان و hookهای عمومی تغییر کرده‌اند. نسخه `1.0.0` نیز با تاریخچه و API فعلی افزونه سازگار نیست.

## دامنه بررسی

کل افزونه شامل فایل اصلی، نصب/ارتقا/حذف، پنج جدول، repository/serviceها، کنترلرهای فرانت‌اند و مدیریت، REST، AJAX، cron، نقش‌ها، خروجی CSV/XLSX، routing، SEO، templateها، JavaScript، CSS و سه visual mode بررسی شد. مبنای نتیجه کد واقعی نسخه ورودی `2.5.17-menu-template-fix` و تفاوت آن با خروجی حاضر است.

## موانع انتشار پیدا و اصلاح‌شده

| شدت | مشکل واقعی | اصلاح |
|---|---|---|
| بحرانی | ثبت‌نام فرانت‌اند همیشه نقش `hamnoor_manager` می‌داد و تنظیم `auto_grant_manager=0` را نادیده می‌گرفت؛ در نتیجه سیاست `approved` عملاً دور زده می‌شد. | نقش مدیر فقط هنگام فعال بودن صریح تنظیم اعطا می‌شود؛ در حالت خاموش نقش پیش‌فرض وردپرس حفظ می‌شود. |
| زیاد | توکن ختم جدید فقط ۶ کاراکتر بود و برای URL خصوصی در مقیاس انتشار عمومی آنتروپی کافی نداشت. | طول توکن ختم‌های جدید به ۱۲ کاراکتر افزایش یافت؛ routeهای توکن قدیمی حفظ شدند. |
| زیاد | query آزادسازی خودکار ابتدا ۵۰۰ سهم رزروشده دلخواه را می‌خواند و موعد را در PHP می‌سنجید؛ سهم‌های موعددار خارج از آن batch ممکن بود دائماً عقب بمانند. | فقط رکوردهای واقعاً موعددار در SQL انتخاب، مرتب و حداکثر ۵۰۰ مورد پردازش می‌شوند. |
| زیاد | ثبت‌نام، رزرو عمومی REST/AJAX، پیگیری و عملیات تماس‌محور محدودسازی نرخ کافی نداشتند. | rate limit مبتنی بر IP و هویت محدودشده برای ثبت‌نام، رزرو، پیگیری، تکمیل و آزادسازی افزوده شد. |
| زیاد | ویرایش حساب خطای `wp_update_user()` و تکراری بودن ایمیل/موبایل را نادیده می‌گرفت و می‌توانست موفقیت کاذب یا ورود موبایلی مبهم ایجاد کند. | مالکیت یکتای ایمیل/موبایل کنترل، خطای وردپرس مدیریت و رمز کوتاه گزارش می‌شود. ذخیره ضمنی موبایل نیز شماره متعلق به حساب دیگر را بازنویسی نمی‌کند. |
| متوسط | indexهای ترکیبی لازم برای لیست‌ها، پیگیری، cron و cleanup ناقص بود. | indexهای هدفمند برای مالک/وضعیت، public index، ختم/تماس، ختم/وضعیت/round، وضعیت/زمان رزرو، تاریخ لاگ و انقضای OTP اضافه شد. |
| متوسط | پاک‌سازی اختیاری uninstall فقط جدول‌ها و چهار option را حذف می‌کرد. | cron، برگه‌های واقعاً ساخته‌شده توسط هم‌نور، role/capability، optionها، user meta و transientهای namespaced نیز پاک می‌شوند؛ فقط وقتی گزینه حذف داده فعال باشد. |
| متوسط | چارچوب ادان فقط یک action عمومی و provider پیامک بدون قرارداد داشت. | interface سبک provider اعلان، registry، dispatch سازگار با provider قدیمی و hookهای چرخه عمر/خروجی/template اضافه شد. |
| متوسط | برگه‌های login/register با وجود noindex بودن، از sitemap برگه‌های هسته وردپرس حذف نمی‌شدند. | هر دو برگه به فهرست حذف از sitemap افزوده شدند. |
| کم | تشخیص «participant جدید» بر تساوی timestamp تکیه داشت. | نتیجه واقعی insert/update در metadata عملیات ثبت می‌شود و hookها پس از commit اجرا می‌شوند. |
| کم | رویداد تکمیل خودکار ختم می‌توانست بدون guard صریح دوباره اجرا شود. | تغییر وضعیت تکمیل شرطی و idempotent شد. |

## وضعیت معماری پایه

- لایه‌های Repository و Service برای ختم، سهم و شرکت‌کننده وجود دارند؛ SQL اصلی همچنان به‌صورت متمرکز در `Hamnoor_DB` است و در کنترلرها پراکنده نشده است.
- Validator، Permission helper، Text manager، SEO، Router، Export، Admin و Frontend controller مستقل‌اند.
- AJAX در همان Frontend controller مانده است؛ جداسازی فایل آن برای انتشار پایه ضرورت ندارد و بازنویسی کم‌فایده محسوب می‌شد.
- HTMLهای بزرگ در templateها قرار دارند؛ بخشی از render صفحه ختم هنوز در Frontend controller تولید می‌شود، اما بازنویسی آن در این RC ریسک regression بالاتری از منفعت دارد.
- REST عمومی DTO محدود برمی‌گرداند؛ شماره موبایل و ایمیل owner در DTO عمومی ختم وجود ندارند و تماس شرکت‌کننده فقط با مجوز خروجی اضافه می‌شود.

## مرز هسته و ادان

| مورد | وضعیت فعلی | تصمیم | کفایت برای هسته/اقدام بعدی |
|---|---|---|---|
| OTP | جدول، ایجاد، تأیید و hook پایه موجود؛ در مسیر ساده پیگیری اختیاری است. | هسته | برای پایه کافی؛ ورود OTP مستقل ادان باشد. |
| پیامک | provider واقعی ندارد. | ادان | interface و registry هسته آماده شد؛ IPPanel ادان جدا. |
| اعلان پیشرفته | service پایه بدون provider داخلی. | ادان | قرارداد channel-agnostic کافی است. |
| QR Code | وجود ندارد. | ادان | URL دعوت پایدار ورودی ادان است. |
| پرداخت/نذر | وجود ندارد. | ادان | جدول و lifecycle پرداخت باید متعلق به ادان باشد. |
| گزارش پیشرفته | گزارش پایه شرکت‌کننده و progress موجود. | ادان | filterهای داده/ستون/سطر آماده‌اند. |
| Excel چند-sheet | XLSX پایه streaming موجود. | ادان | نسخه پیشرفته ادان باشد. |
| قالب‌های مناسبتی | styleهای پایه موجود. | ادان | template path، card data و visual mode قابل فیلتر است. |
| یادآوری قرائت | وجود ندارد. | ادان | بر `claimed_at`، hookهای claim/complete/release و notification provider بنا شود. |
| آزادسازی سهم | دستی و خودکار موجود. | هسته | query موعددار اصلاح و index اضافه شد. |
| انتقال سهم | workflow مستقل ندارد. | ادان | در هسته لازم نیست؛ ادان باید مالک احراز هویت و audit آن باشد. |
| لینک دعوت | `/h/{token}/` و سازگاری slug/legacy موجود. | هسته | کافی؛ توکن جدید سخت‌تر شد. |
| متن دعوت | تنظیم و placeholder موجود. | هسته | کافی. |
| تصویر پیش‌فرض | تنظیم، upload و cover fallback موجود. | هسته | کافی. |
| تنظیمات ظاهری | سه mode و تنظیمات رنگ/ابعاد موجود. | هسته | کافی و CSS scoped است. |
| Text manager | موجود. | هسته | کافی. |
| SEO | canonical/noindex/meta/schema موجود. | هسته | پایه کافی؛ تست یکپارچگی زنده با نسخه‌های جاری Yoast/RankMath لازم است. |
| Sitemap | provider ختم و حذف برگه‌های خصوصی کاربردی موجود. | هسته | پایه کافی. |
| نقش‌ها | admin و `hamnoor_manager` موجود. | هسته | با اصلاح auto-grant کافی است. |
| multi-khatm rounds | موجود. | هسته | کافی؛ ساختار پس از ایجاد عمداً قفل است. |
| archive | وضعیت و UI پایه موجود. | هسته | کافی. |
| dashboard | ختم‌های من، سهم‌ها، آرشیو، profile و مدیریت owner موجود. | هسته | کافی. |
| admin reports | لیست/فیلتر/شرکت‌کننده/لاگ/خروجی پایه موجود. | هسته | گزارش تحلیلی پیشرفته ادان باشد. |
| REST API | `hamnoor/v1` موجود و قابل غیرفعال‌سازی است. | هسته | برای integration پایه کافی. |
| add-on framework | پیش‌تر حداقلی بود. | هسته | با provider registry و hookهای جدید برای RC کافی است. |

## دیتابیس و migration

- پنج جدول `khatms`, `parts`, `participants`, `logs`, `otps` مفاهیم پایه را پوشش می‌دهند.
- DB version به `2.6.0` افزایش یافت و `dbDelta()` نصب تازه و upgrade را idempotent اجرا می‌کند.
- جدول یا داده‌ای در migration حذف نمی‌شود و توکن‌های قدیمی برای حفظ لینک‌ها rotate نمی‌شوند.
- رابطه‌ها در سطح برنامه کنترل می‌شوند و foreign key سخت وجود ندارد؛ این تصمیم با الگوی نصب/حذف وردپرس و `dbDelta` سازگار است.
- برای پیامک، پرداخت و گزارش پیشرفته نیازی به افزودن ستون‌های مبهم به جداول هسته نیست؛ ادان‌ها می‌توانند جدول namespaced خود را با کلید `khatm_id`/`part_id` نگه دارند.

جزئیات کامل در `HAMNOOR-DATABASE-ARCHITECTURE.md` آمده است.

## امنیت

- فرم‌های حساس nonce دارند؛ AJAXها nonce مشترک فرانت‌اند و endpointهای مدیریت capability/ownership check دارند.
- endpointهای REST مدیریتی `permission_callback` دارند و endpointهای عمومی فقط DTO محدود ارائه می‌کنند.
- رزرو اتمیک با شرط `status=available` حفظ شده و منطق رزرو/قرائت/آزادسازی بازنویسی نشده است.
- tracking token سهم ۲۴ کاراکتر است و توکن ختم جدید ۱۲ کاراکتر شد.
- upload تصویر از allowlist نوع، `wp_check_filetype_and_ext` و محدودیت اندازه استفاده می‌کند.
- redirectها از `wp_safe_redirect`/`wp_validate_redirect` عبور می‌کنند.
- CSV در برابر formula injection و XLSX در برابر XML injection escape می‌شود.
- ریسک پذیرفته‌شده: پیگیری ساده با دانستن موبایل/ایمیل، طبق فلسفه محصول، OTP اجباری ندارد. برای سایت‌های حساس باید `track_requires_otp` یا ادان احراز هویت فعال شود.
- rate limiter مبتنی بر transient برای دفاع پایه است و در چند وب‌سرور بدون object cache مشترک، دفاع سراسری محسوب نمی‌شود.

## Performance

- سهم‌های صفحه ختم، لیست‌های مدیریت و داشبورد pagination دارند.
- تولید سهم batch است و سقف سخت پیش‌فرض ۱۲٬۰۰۰ سهم دارد.
- assetهای فرانت‌اند فقط در route یا shortcode هم‌نور enqueue می‌شوند.
- CSV streaming و XLSX مبتنی بر فایل موقت است.
- cron آزادسازی حداکثر ۵۰۰ رکورد موعددار در هر اجرا پردازش می‌کند و query آن اکنون indexپذیرتر است.
- `ORDER BY RAND()` فقط در انتخاب تصادفی و در محدوده سقف فعلی باقی مانده؛ مانع انتشار نیست، اما اگر سقف عملیاتی در آینده بسیار بزرگ شود باید الگوریتم sampling ادان/نسخه بعدی جایگزین شود.

## UX، قالب و visual mode

- template اختصاصی classic صریحاً `get_header()` و `get_footer()` را اجرا می‌کند.
- block themeها از virtual page و template عادی قالب استفاده می‌کنند.
- مسیرهای `/h/{token}/`، `/h/{token}/{slug}/` و `/khatm/{token}/` در rewrite حفظ شده‌اند.
- CSS فرانت‌اند ۲۰۳ rule بررسی شد و هیچ selector خارج از `.hamnoor-wrap` یا `.hamnoor-modal` ندارد.
- چهار asset CSS/JS نسبت به ورودی تغییر نکردند؛ بنابراین اصلاحات این RC ظاهر سه mode یا event delegation موجود را تغییر نداده است.
- تست دیداری واقعی برای overflow موبایل، modal و منوی قالب همچنان جزو gate انتشار پایدار است.

## SEO

- canonical ختم روی URL کوتاه دعوت باقی مانده است.
- ختم خصوصی، محتوای ناکافی، queryهای part/access/filter/pagination و برگه‌های حساب noindex می‌شوند.
- sitemap اختصاصی فقط ختم عمومی/فعال/indexable با محتوای کافی را خروجی می‌دهد.
- login/register اکنون از sitemap برگه‌های وردپرس نیز حذف می‌شوند.
- Event schema فقط با تاریخ شروع/پایان معتبر ساخته می‌شود.
- منطق meta/schema/canonical تغییر داده نشد؛ تنها حذف sitemap برگه‌های noindex اصلاح شد.

## ادان‌پذیری

- قرارداد `Hamnoor_Notification_Provider_Interface` و registry بدون وابستگی به provider خاص اضافه شد.
- hookهای before/after برای ایجاد/ویرایش ختم و claim/complete/release سهم، تکمیل/آرشیو ختم و lifecycle شرکت‌کننده موجودند.
- filterهای notification، report/export، template path، card data و visual mode موجودند.
- payment، QR، SMS provider، reminder scheduler و analytics در هسته پیاده‌سازی نشدند.

جزئیات امضاها و نمونه provider در `HAMNOOR-ADDON-ARCHITECTURE.md` است.

## تست‌های انجام‌شده

همه موارد زیر موفق بودند:

1. syntax-check تمام ۵۲ فایل PHP با موتور PHP 7.4 WebAssembly؛ هر فایل به‌طور مستقل parse/اجرا شد.
2. parse مستقل همان ۵۲ فایل با `php-parser`.
3. parse هر دو فایل JavaScript با ECMAScript latest.
4. parse کامل CSS فرانت‌اند با PostCSS و بررسی scope تمام selectorها؛ selector خارج از wrapper: صفر.
5. آزمون خودکار release برای version/DB version، indexها، طول توکن، query cron، rate limitها، role setting، routeهای جدید/legacy، header/footer template، سه visual mode، canonical/noindex، DTO عمومی، uninstall و قرارداد ادان.
6. مقایسه hash چهار asset CSS/JS با نسخه ورودی؛ هر چهار فایل بدون تغییر.
7. بررسی نبود فایل‌های موقت، log، `.DS_Store` و `Thumbs.db` در بسته.
8. بازبینی ایستای nonce، capability، REST permission و export permission در تمام endpointها.

## تست‌های انجام‌نشده در این محیط

در این workspace، WordPress، MySQL/MariaDB، PHP CLI بومی، WP-CLI، Docker و مرورگر headless نصب نبود. بنابراین موارد زیر **ادعا نمی‌شوند** و باید در staging اجرا شوند:

1. نصب/فعال‌سازی واقعی، اجرای `dbDelta` روی MySQL و upgrade از ۲.۵.۱۷.
2. سناریوهای end-to-end ایجاد چهار نوع ختم، multi-round، ویرایش و خروجی واقعی CSV/XLSX.
3. race test هم‌زمان رزرو یک سهم با چند درخواست واقعی.
4. اجرای cron در timezoneهای مختلف و batch بیش از ۵۰۰ مورد.
5. تست دیداری سه mode در Twenty Twenty-Five و یک قالب کلاسیک، منو/header/footer، modal و overflow موبایل.
6. تست console و شبکه AJAX در مرورگر واقعی.
7. تست یکپارچگی با نسخه‌های جاری Yoast SEO و Rank Math.

## ریسک‌های باقی‌مانده

- پیگیری ساده تماس‌محور ذاتاً assurance کمتری از OTP دارد و یک انتخاب محصول است.
- transient rate limit در زیرساخت چندسروری باید با object cache مشترک یا WAF تکمیل شود.
- `ORDER BY RAND()` در سقف‌های بسیار بالاتر از مقدار فعلی می‌تواند گران شود.
- خروجی XLSX واقعی به وجود `ZipArchive` وابسته است و در نبود آن به CSV fallback می‌کند.
- پشتیبانی PHP اعلام‌شده ۷.۴ است؛ هرچند syntax با موتور ۷.۴ بررسی شد، matrix واقعی PHP 7.4/8.1/8.3 روی وردپرس باید در CI محصول برقرار شود.

## شرط انتشار پایدار

پس از عبور smoke test نصب تازه، migration، یک قالب بلوکی، یک قالب کلاسیک، سه visual mode و یک دور کامل claim/complete/release/cron، همین کد می‌تواند با نسخه `2.6.0` پایدار منتشر شود. تا آن زمان نام بسته و وضعیت توصیه‌شده `2.6.0 Release Candidate` است.

## فایل‌های کد تغییرکرده

- `hamnoor.php` — نسخه و بارگذاری interface.
- `readme.txt` — Stable tag و changelog نسخه.
- `includes/class-hamnoor-db.php` — index/migration، token، cron و hookهای lifecycle.
- `includes/class-hamnoor-frontend.php` — role، هویت یکتا و rate limit.
- `includes/class-hamnoor-rest-api.php` — rate limit رزروهای عمومی.
- `includes/class-hamnoor-helpers.php` — filterهای template و visual mode.
- `includes/class-hamnoor-export.php` — filterهای report/export.
- `includes/class-hamnoor-seo.php` — حذف login/register از sitemap برگه‌ها.
- `includes/class-hamnoor-addons.php` — registry provider.
- `includes/class-hamnoor-notification-service.php` — dispatch provider/filter.
- `includes/interface-hamnoor-notification-provider.php` — قرارداد جدید اعلان.
- `templates/frontend/khatm-card.php` و `templates/frontend/part-card.php` — card data filters.
- `uninstall.php` — پاک‌سازی کنترل‌شده کامل‌تر.
- سه گزارش آمادگی/معماری و changelog این RC افزوده شدند.

هیچ فایل CSS یا JavaScript تغییر نکرد.
