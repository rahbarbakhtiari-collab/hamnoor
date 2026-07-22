# گزارش ساخت و آزمون انتشار Hamnoor 2.8.1

## وضعیت

- وضعیت گزارش: **Notice ارتقا رفع شد؛ در انتظار commit اصلاح و بازسازی ZIP رسمی**.
- وضعیت: **علت Notice ترجمه با backtrace واقعی مشخص، اصلاح محدود اعمال و نصب تمیز و ارتقای واقعی دوباره با موفقیت اجرا شد**.
- Notice مرتبط با بارگذاری زودهنگام textdomain هم‌نور پس از اصلاح در بازآزمون نصب تمیز و ارتقا تکرار نشد.
- ZIP رسمی موجود پیش از این اصلاح ساخته شده است و برای رعایت محدودیت مأموریت حذف یا بازنویسی نشد؛ بنابراین شامل اصلاح جدید نیست.
- commit، push، Tag، GitHub Release و انتشار روی سایت اصلی انجام نشده‌اند.

## مبنای بسته

| مورد | مقدار |
|---|---|
| شاخه | `main` |
| commit انتشار | `a4875f9fc85137169fc97ac2b71ecd4acb469c4d` |
| `origin/main` پس از `git fetch origin` | `a4875f9fc85137169fc97ac2b71ecd4acb469c4d` |
| SHA مستقیم remote با `git ls-remote` | `a4875f9fc85137169fc97ac2b71ecd4acb469c4d` |
| نسخه افزونه | `2.8.1` |
| نسخه دیتابیس بسته | `2.7.3` |
| روش استخراج | `git archive` مستقیم روی SHA قطعی با پیشوند `hamnoor/` |

بسته از working tree ساخته نشد. تغییر محلی `.gitignore` و فایل‌های ignored یا untracked در فرایند استخراج قابل ورود به artifact نبودند. stash زیر نیز دست‌نخورده باقی ماند:

`On main: local changes before restore to v2.8.0 - 2026-07-16`

ZIP معتبر `hamnoor-2.8.0.zip`، نگهداری‌شده خارج از مخزن، با SHA-256 برابر `097032815AE704F9F434670D6F1750A90686313F833FFAB9F9FEA7622A567388` بررسی شد. manifest آن ۶۵ فایل runtime داشت و فهرست مسیرهای runtime commit ۲.۸.۱ با آن مسیر‌به‌مسیر برابر بود؛ اختلاف مسیر صفر بود.

## مشخصات ZIP

| مورد | مقدار |
|---|---|
| نام | `hamnoor-2.8.1.zip` |
| محل نگهداری | `hamnoor-2.8.1.zip`، ساخته‌شده خارج از مخزن |
| زمان ساخت | `2026-07-22 20:49:58 +03:30` |
| اندازه | `156247` بایت |
| تعداد کل entry | `74` |
| تعداد فایل | `65` |
| تعداد پوشه | `9` |
| تعداد PHP | `53` |
| تعداد JavaScript | `2` |
| SHA-256 | `FA65C2B21A716506EC692FD6F991AFBE20C81D43D0C045D90A509AB4021AFBB7` |
| تنها پوشه سطح اول | `hamnoor/` |
| manifest | ساخته‌شده خارج از مخزن |
| فایل hash | ساخته‌شده خارج از مخزن |

**وضعیت artifact: مسدودشده و غیرقابل انتشار.** این ZIP اصلاح Notice را ندارد و نباید به Tag یا GitHub Release متصل شود.

کنترل نهایی پس از پاک‌سازی دوباره انجام شد: ZIP وجود داشت، بدون خطا باز شد، ۷۴ entry و تنها پوشه سطح اول `hamnoor/` داشت و اندازه و SHA-256 دوباره محاسبه‌شده با مقادیر بالا برابر بود.

## محتوای بسته و یکپارچگی

- فایل‌های الزامی `hamnoor.php`، `readme.txt` و `uninstall.php` و پوشه‌های `assets/`، `includes/`، `templates/` و `languages/` موجودند.
- `.git/`، `.github/`، `.gitignore`، `.gitattributes`، `AGENTS.md`، اسناد توسعه، test، log، SQL، SQLite، backup، patch، ZIP تو‌در‌تو، `node_modules/`، `vendor/`، credential و secret در بسته وجود ندارند.
- جست‌وجوی مسیرهای محلی، دامنه توسعه و نشانگر داده آزمایشی بدون نتیجه بود.
- جست‌وجوی credential فقط نام فیلدها و APIهای عمومی رمز عبور/token در کد runtime را یافت؛ مقدار credential، API key، اتصال دیتابیس یا token خصوصی وجود نداشت.
- Junction، symlink یا ReparsePoint در نسخه استخراج‌شده وجود نداشت.
- سه فایل `.mo` صفر بایت‌اند، اما دقیقاً همان فایل‌های version-controlled موجود در commit انتشار و ZIP معتبر ۲.۸.۰ هستند؛ فایل صفر بایت غیرمنتظره دیگری وجود نداشت.
- hash blob شش فایل کلیدی در نسخه استخراج‌شده دقیقاً با commit مبنا برابر بود:

| فایل | blob commit و ZIP |
|---|---|
| `hamnoor.php` | `e4379a99210b971ba8f78ad0f5fb3cc3923c276e` |
| `readme.txt` | `da2907f042019e2092cd00bd4ad2f0096b6fff63` |
| `assets/js/hamnoor-frontend.js` | `207e11e4953c0e98cff0e183a797b46108806e1f` |
| `assets/css/hamnoor-frontend.css` | `aba7e97521315966265c29e170f08d6379f83b5b` |
| `includes/class-hamnoor-db.php` | `b962f201aeefa4fbbfe322afc8c4ec13661a78be` |
| `includes/class-hamnoor-frontend.php` | `efb0db1fe18d481f5ffba05806e3da19d442639b` |

## کنترل نسخه و آزمون ایستا

- Plugin Header Version: `2.8.1`
- `HAMNOOR_VERSION`: `2.8.1`
- `HAMNOOR_DB_VERSION`: `2.7.3`
- Stable tag: `2.8.1`
- Tested up to: `7.0`
- PHP 8.2.29 محیط Local: هر ۵۳ فایل PHP نسخه استخراج‌شده با `-n -l` بررسی شد؛ خطای syntax صفر.
- Node.js v20.18.1 محیط آزمون: هر دو فایل JavaScript، شامل `assets/js/hamnoor-frontend.js`، با `--check` موفق بودند.

## نصب تمیز ZIP

محیط موقت:

| مورد | مقدار |
|---|---|
| WordPress | `7.0.2` |
| PHP | `8.2.29` |
| MySQL | `8.4.0` |
| WP-CLI | `2.12.0` |
| محیط | محیط ایزوله نصب تمیز |

نتایج:

- ZIP نهایی با WP-CLI از خود artifact نصب و فعال شد؛ پوشه افزونه Junction نبود.
- نسخه افزونه `2.8.1` و ثابت و option دیتابیس هر دو `2.7.3` بودند.
- پنج جدول ساخته شدند. تعداد ستون‌های بررسی‌شده: khatms برابر ۵۱، parts برابر ۱۷، participants برابر ۱۰، logs برابر ۱۰ و otps برابر ۹.
- option تنظیمات و ۸ صفحه نصب اولیه ایجاد شدند؛ صفحات list، create و track منتشرشده بودند.
- deactivate/activate دوباره تعداد جدول‌ها، صفحه‌ها، ختم‌ها یا سهم‌ها را تغییر نداد.
- کاربر عادی ختم `pending_review` با صفر سهم ساخت؛ مدیر آن را فعال کرد و دقیقاً ۳۰ سهم تولید شد؛ فعال‌سازی دوباره تعداد را روی ۳۰ نگه داشت.
- مدیر ختم active مستقیم با ۳ سهم ایجاد کرد.
- سه تنظیم کارت مقدار پیش‌فرض `1` داشتند.
- URL خارجی با POST دست‌کاری‌شده در حالت غیرفعال با خطای `hamnoor_external_cover_disabled` رد شد.
- فرم ایجاد ختم واقعاً رندر شد و `auto_done_on_claim` انتخاب پیش‌فرض بود.
- خانه، فهرست ختم‌ها، ایجاد ختم، پیگیری، REST، JS، CSS، پیشخوان، تنظیمات، فهرست مدیریت، فرم ویرایش و فرم authenticated همگی HTTP 200 بودند.
- REST عمومی هم با `rest_route` و هم پس از فعال‌کردن permalink در محیط موقت از `/wp-json/hamnoor/v1/khatms` پاسخ JSON معتبر داد.
- مسیرهای `/h/{token}` و `/khatm/{token}` HTTP 200 و محتوای واقعی هم‌نور داشتند.
- assetهای فرانت‌اند با `ver=2.8.1` در HTML دیده شدند و HTML فاقد خطای PHP، مسیر خصوصی و host توسعه بود.

## آزمون ارتقا از ۲.۸.۰

مبنای ۲.۸.۰ همان ZIP معتبر ثبت‌شده در بالا بود. این ZIP در WordPress دوم نصب و فعال شد؛ نسخه افزونه `2.8.0`، ثابت دیتابیس `2.7.2` و option دیتابیس `2.7.2` بودند.

نسخه منتشرشده `2.8.0` دارای DB version `2.7.2` بود و نسخه `2.8.1` دارای DB version `2.7.3` است. migration از مسیر `Hamnoor_DB::maybe_upgrade()` با اجرای `create_tables()` و `dbDelta()`، ستون nullable با نام `selected_surah_number` را اضافه می‌کند. این فرایند idempotent و backward-compatible است و داده‌ها، شناسه‌ها، tokenها، سهم‌ها و تنظیمات موجود را حفظ می‌کند.

### snapshot پیش از ارتقا

- چهار ختم نماینده، ۳۵ سهم، ۲ participant و ۷ log پیش از ارتقا وجود داشتند.
- حالت‌های فعال، تکمیل دستی، تصویر خارجی و در انتظار بررسی پوشش داده شدند.
- داده ساخته‌شده به‌علت باگ شناخته‌شده نسخه ۲.۸.۰ در دیتابیس ایزوله اصلاح شد تا حالت در انتظار بررسی با صفر سهم آزموده شود.
- شناسه‌ها و tokenهای نمونه بدون درج مقدار واقعی ثبت شدند و پس از ارتقا معتبر ماندند.
- hash option تنظیمات قدیمی ثبت شد و مقدار واقعی آن در گزارش درج نشد.
- تنظیمات غیرپیش‌فرض رنگ، حمایت مالی و ارتباط مدیر ذخیره شدند.

### جایگزینی و نتیجه

- ZIP ۲.۸.۱ با `wp plugin install ... --force` جایگزین استاندارد شد؛ افزونه active باقی ماند و پوشه آن Junction نبود.
- نسخه افزونه `2.8.1` و ثابت و option دیتابیس `2.7.3` شدند.
- هر پنج جدول حفظ شدند. چهار جدول parts، participants، logs و otps hash schema یکسان با پیش از ارتقا داشتند.
- در جدول khatms ستون nullable شناخته‌شده `selected_surah_number` اضافه شد؛ این تغییر از مسیر ارتقای DB version به `2.7.3`، `create_tables()` و `dbDelta()` انجام شد و option مستقل repeat schema روی `1` ماند.
- پیش از اجرای flowهای پس از ارتقا، همان چهار ختم، ۳۵ سهم، ۲ participant و ۷ log با شناسه‌ها و tokenهای قبلی حفظ شده بودند.
- سهم‌های claimed و done، حالت `manual_done`، تصویر قبلی و همه تنظیمات قبلی حفظ شدند.
- با حذف سه کلید جدید کارت از option پس از ارتقا، hash تنظیمات قدیمی بدون تغییر ماند؛ هر سه کلید جدید کارت مقدار `1` داشتند.
- ختم pending پیش از تأیید صفر سهم داشت؛ فعال‌سازی ۳۰ سهم ساخت و فعال‌سازی دوباره همان ۳۰ سهم را نگه داشت.
- پس از ارتقا، create، claim، release، claim دوباره، done و tracking با موفقیت اجرا شدند. سهم نهایی flow وضعیت `done` داشت و tracking سه نتیجه مرتبط برگرداند.
- مسیر کوتاه جدید `/h/{token}` و مسیر قبلی `/khatm/{token}` هر دو HTTP 200 بودند.
- همان مجموعه صفحه‌های عمومی، مدیریت، فرم ویرایش، REST و assetها در محیط ایزوله ارتقا HTTP 200 بودند و HTML خطا یا مسیر خصوصی نداشت.

## لاگ‌ها و مشکل انتشار

- چهار log وب‌سرور PHP موقت بررسی شدند؛ Fatal، Warning، Notice، Deprecated، Parse error، Uncaught Error/Exception، خطای دیتابیس WordPress یا پیام `[Hamnoor]` نداشتند.
- statusهای 302 ثبت‌شده مربوط به redirect عادی صفحه ایجاد برای کاربر مهمان و تلاش اولیه harness برای ورود بودند؛ مقصد نهایی درخواست‌های گزارش‌شده ۲۰۰ بود.
- `debug.log` نصب clean فقط هشدارهای WordPress درباره ناتوانی اتصال به WordPress.org در محیط محدود شبکه داشت و خطای Hamnoor نداشت.
- `debug.log` محیط upgrade علاوه بر هشدارهای شبکه، یک Notice مرتبط با Hamnoor ثبت کرد:
  - `_load_textdomain_just_in_time was called incorrectly` برای domain `hamnoor`؛ بارگذاری ترجمه پیش از hook مناسب هنگام bootstrap ارتقا رخ داد.
- Fatal، Warning، Parse error یا خطای SQL مرتبط با Hamnoor ثبت نشد، اما Notice بالا معیار سخت‌گیرانه نبود خطای تازه مرتبط با افزونه را نقض می‌کند و باید پیش از Tag بررسی/رفع و آزمون ارتقا تکرار شود.
- هشدار شناخته‌شده بارگذاری‌نشدن `php_imagick.dll` فقط در اجرای PHP CLI دیده شد و در logهای HTTP ثبت نشد.
- Console مرورگر بررسی نشد؛ موفقیت Console ادعا نمی‌شود.

## ثبت backtrace واقعی و اصلاح Notice ترجمه

پیش از هر اصلاح، ارتقای ایزوله از ZIP معتبر ۲.۸.۰ به ZIP رسمی موجود ۲.۸.۱ دوباره اجرا شد و یک mu-plugin صرفاً تشخیصی، backtrace واقعی Notice را از hook `doing_it_wrong_run` ثبت کرد. زنجیره مرتبط چنین بود:

`plugins_loaded` → `Hamnoor_Plugin::init()` → `Hamnoor_DB::maybe_upgrade()` → `Hamnoor_DB::create_options()` → `Hamnoor_Helpers::defaults()` → `__()` → `_load_textdomain_just_in_time()`

فریم‌های مستقیم افزونه در backtrace:

- `includes/class-hamnoor-helpers.php:44` — فراخوانی `__()` در `Hamnoor_Helpers::defaults()`
- `includes/class-hamnoor-db.php:59` — فراخوانی defaults از `Hamnoor_DB::create_options()`
- `includes/class-hamnoor-db.php:30` — فراخوانی create_options از `Hamnoor_DB::maybe_upgrade()`
- `includes/class-hamnoor-plugin.php:23` — اجرای migration در bootstrap افزونه
- `hamnoor.php:59` — اجرای bootstrap روی `plugins_loaded`

علت قطعی این بود که migration دیتابیس روی `plugins_loaded` اجرا می‌شد و پیش از `init` به رشته‌های ترجمه‌پذیر تنظیمات می‌رسید. اصلاح فقط به زمان‌بندی i18n و migration محدود شد:

- `Hamnoor_I18n::init()` بارگذاری textdomain را با اولویت `0` روی `init` ثبت می‌کند.
- `Hamnoor_DB::maybe_upgrade()` با اولویت `1` روی `init` اجرا می‌شود.
- سایر bootstrapها، schema، optionها، hookها، routeها و رفتارهای افزونه تغییر نکردند.

### بازآزمون پس از اصلاح

- نصب تمیز ZIP آزمایشی ۲.۸.۱ اصلاح‌شده از صفر انجام شد؛ option نسخه دیتابیس `2.7.3` بود و ستون `selected_surah_number` با `IS_NULLABLE=YES` وجود داشت.
- ارتقای واقعی دوباره از ZIP معتبر ۲.۸.۰ اجرا شد. پیش از ارتقا option دیتابیس `2.7.2` و شمار ستون `selected_surah_number` صفر بود.
- پس از جایگزینی با ZIP آزمایشی اصلاح‌شده، option دیتابیس به `2.7.3` رسید و ستون `selected_surah_number` با قابلیت `NULL` اضافه شد.
- `debug.log` در نصب تمیز و ارتقای پس از اصلاح ایجاد نشد؛ Notice مربوط به domain `hamnoor` تکرار نشد.
- هشدار شناخته‌شده CLI درباره `php_imagick.dll` همچنان فقط در خروجی CLI دیده شد و مربوط به Hamnoor نیست.
- ZIP آزمایشی فقط داخل محیط موقت ساخته شد و ZIP رسمی موجود حذف یا بازنویسی نشد.

## پاک‌سازی

- وب‌سرورهای موقت پس از تطبیق command line متوقف شدند و listener آزمایشی باقی نماند.
- دیتابیس‌های ایزوله نصب تمیز و ارتقا پس از تطبیق دقیق حذف شدند و شمار آن‌ها پس از حذف صفر بود.
- سایت‌ها، کاربران، داده‌ها، نسخه استخراج‌شده و همه logهای موقت محیط‌های ایزوله حذف شدند.
- محیط بازآزمون اصلاح i18n و دیتابیس ایزوله آن پس از پایان آزمون حذف شدند؛ شمار دیتابیس پس از حذف صفر و مسیر موقت ناموجود بود.
- worktree موقت Git استفاده نشد.
- فایل ZIP نهایی، manifest و فایل SHA-256 خارج مخزن باقی ماندند.
- سرویس‌های MySQL، nginx و PHP-CGI سایت اصلی همان پردازه‌های ابتدای مأموریت را داشتند و اتصال سایت آزمون به working tree حفظ شد؛ وضعیت روشن/خاموش Local اصلی تغییر نکرد.

## آزمون‌های نیازمند تأیید انسانی

موارد زیر اجرا نشده و به‌عنوان موفق ثبت نشده‌اند:

- نمایش واقعی موبایل و دسکتاپ.
- رفتار `sessionStorage` با reload و Back.
- تعامل radio، checkbox و مراحل wizard.
- انتخاب فایل تصویر و هشدار فوری حجم.
- ارسال multipart واقعی شامل فایل و URL.
- بررسی Console و Network مرورگر.
- قالب کلاسیک و قالب بلوکی.

## Rollback

- ZIP معتبر `hamnoor-2.8.0.zip` حذف یا بازنویسی نشد.
- بازگشت کد با ZIP ۲.۸.۰ ممکن است، اما ستون nullable `selected_surah_number` نباید کورکورانه حذف شود.
- پیش از هر انتشار اصلی، backup معتبر دیتابیس و فایل‌ها الزامی است.

## موارد در انتظار

- انجام شد: ثبت backtrace واقعی، رفع Notice بارگذاری textdomain و تکرار نصب تمیز و آزمون upgrade.
- انجام نشد و در این مأموریت ممنوع بود: commit، push، Tag و GitHub Release.
- ZIP رسمی موجود شامل اصلاح تازه نیست و در این مأموریت عمداً بازنویسی نشد.
- در انتظار: backup سایت اصلی.
- در انتظار: نصب روی سایت اصلی.
- در انتظار: smoke test پس از انتشار اصلی.

نسخه روی سایت اصلی منتشر نشده است.
