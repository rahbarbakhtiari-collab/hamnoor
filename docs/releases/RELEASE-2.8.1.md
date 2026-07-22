# گزارش ساخت و آزمون انتشار Hamnoor 2.8.1

## وضعیت

- **ZIP نهایی ساخته و آزمون شد؛ در انتظار commit گزارش، Tag و GitHub Release**.
- نسخه روی سایت اصلی منتشر نشده است.
- commit، push، Tag و GitHub Release در این مأموریت انجام نشد.

## مبنای artifact

| مورد | مقدار |
|---|---|
| شاخه | `main` |
| commit قطعی | `d9d181dbccae647cffc098b09e726389ead1884a` |
| `HEAD`، `origin/main` و remote مستقیم | برابر با commit قطعی |
| نسخه افزونه | `2.8.1` |
| نسخه دیتابیس | `2.7.3` |
| روش ساخت | `git archive` مستقیم از SHA قطعی با پیشوند `hamnoor/` و manifest runtime معتبر |

working tree منبع بسته نبود؛ تغییر محلی `.gitignore` وارد artifact نشد. manifest معتبر ZIP نسخه ۲.۸.۰ شامل ۶۵ فایل runtime بود و فهرست مسیرهای بسته جدید با آن اختلافی نداشت.

## artifact مسدودشده قبلی

| مورد | مقدار |
|---|---|
| نام بایگانی | `blocked/hamnoor-2.8.1-pre-i18n-fix-blocked.zip` |
| SHA-256 | `FA65C2B21A716506EC692FD6F991AFBE20C81D43D0C045D90A509AB4021AFBB7` |
| وضعیت | محفوظ، مسدود و غیرقابل انتشار |

SHA پیش و پس از انتقال برابر بود. ZIP، manifest و فایل hash قبلی با نام روشن بایگانی شدند و artifact قبلی نباید به Tag یا GitHub Release متصل شود.

## ZIP رسمی جدید

| مورد | مقدار |
|---|---|
| نام | `hamnoor-2.8.1.zip` |
| زمان ساخت | `2026-07-23 00:45:39 +03:30` |
| اندازه | `156375` بایت |
| تعداد entry | `74` |
| تعداد فایل | `65` |
| تعداد پوشه | `9` |
| تعداد PHP | `53` |
| تعداد JavaScript | `2` |
| SHA-256 | `8775F07E98FA6770F770747143792CF579ADC606C938341D41B39DCE80D21610` |
| پوشه سطح اول | فقط `hamnoor/` |

فایل‌های `hamnoor-2.8.1.manifest.txt` و `hamnoor-2.8.1.sha256.txt` کنار ZIP و خارج از مخزن ساخته شدند. SHA جدید با artifact مسدودشده متفاوت است.

## اعتبارسنجی ایستا

- همه فایل‌ها و پوشه‌های الزامی وجود دارند.
- manifest مسیرها با بسته معتبر ۲.۸.۰ برابر و اختلاف مسیر صفر است.
- فایل یا مسیر ممنوع، ZIP تو‌در‌تو، junction، symlink یا ReparsePoint وجود ندارد.
- تنها فایل‌های صفر بایت، سه فایل `.mo` شناخته‌شده‌اند که blob آن‌ها از commit قطعی استخراج شده است.
- Plugin Header و `HAMNOOR_VERSION` برابر `2.8.1`، `HAMNOOR_DB_VERSION` برابر `2.7.3`، Stable tag برابر `2.8.1` و Tested up to برابر `7.0` است.
- هر ۵۳ فایل PHP با PHP 8.2.29 و `-n -l` بررسی شدند؛ ۵۳ موفق و صفر ناموفق.
- هر دو فایل JavaScript با Node.js v22.12.0 و `--check` بررسی شدند؛ صفر خطا.
- hash هر هشت فایل کلیدی در نسخه استخراج‌شده با blob همان مسیر در commit قطعی برابر بود: `hamnoor.php`، `readme.txt`، frontend JS و CSS و کلاس‌های i18n، plugin، DB و frontend.

## نصب تمیز با ZIP نهایی

- یک WordPress 7.0.2 با دیتابیس و پوشه مستقل ایجاد شد؛ junction و اتصال به سایت توسعه وجود نداشت.
- خود ZIP رسمی نهایی نصب و فعال شد؛ نسخه افزونه `2.8.1` و option دیتابیس `2.7.3` بود.
- پنج جدول اختصاصی ایجاد شدند.
- ستون `selected_surah_number` دقیقاً یک بار وجود داشت و nullable بود.
- option صفحات اولیه شامل هشت صفحه Hamnoor ایجاد شد.
- deactivate و reactivate تعداد جدول‌ها، صفحات، ختم‌ها و سهم‌ها را تغییر نداد.
- سه تنظیم کارت در option نصب تمیز مقدار پیش‌فرض `1` داشتند.
- frontend، admin، فهرست، ایجاد و پیگیری پاسخ HTTP 200 دادند؛ REST عمومی نیز پاسخ JSON با HTTP 200 داد.
- آزمون قبلی پس از اصلاح i18n، ایجاد ختم pending توسط کاربر عادی، فعال‌سازی یک‌باره سهم‌ها، ایجاد مستقیم ختم فعال توسط مدیر، پیش‌فرض `auto_done_on_claim` و رد URL خارجی دست‌کاری‌شده را پوشش داده بود؛ محتوای runtime ZIP رسمی با همان commit قطعی تطبیق داده شد.

## ارتقای واقعی از 2.8.0

- محیط دوم با ZIP معتبر ۲.۸.۰ و SHA-256 ثبت‌شده `097032815AE704F9F434670D6F1750A90686313F833FFAB9F9FEA7622A567388` ساخته شد.
- پیش از ارتقا: نسخه افزونه `2.8.0`، DB version برابر `2.7.2` و شمار ستون `selected_surah_number` صفر بود.
- داده نماینده شامل ختم‌های active، pending، manual، دارای تصویر، participant و سهم‌های claimed و done و تنظیم غیرپیش‌فرض ایجاد شد.
- همان ZIP رسمی نهایی با جایگزینی استاندارد `--force` نصب شد.
- پس از ارتقا: نسخه افزونه `2.8.1`، DB version برابر `2.7.3` و ستون nullable دقیقاً یک بار موجود بود.
- اجرای دوباره bootstrap/migration موفق و idempotent بود.
- تعداد ختم‌ها، سهم‌ها و participantها ثابت ماند؛ claimed و done، حالت `manual_done`، تصویر و تنظیم غیرپیش‌فرض حفظ شدند.
- ختم pending پیش از تأیید سهم نداشت و token تکراری ایجاد نشد.
- frontend، admin و REST پس از ارتقا HTTP 200 بودند.
- مسیرهای create، claim، release، claim مجدد، done، tracking و URL کوتاه و legacy در آزمون کامل پس از اصلاح i18n موفق بودند؛ بسته رسمی از همان محتوای commit آزموده‌شده ساخته و hashهای کلیدی آن با commit تطبیق داده شد.

## Notice ترجمه و لاگ‌ها

- `WP_DEBUG` و `WP_DEBUG_LOG` در هر دو محیط فعال و `WP_DEBUG_DISPLAY` غیرفعال بود.
- activation، نخستین bootstrap، درخواست‌های frontend/admin/REST، deactivate/reactivate، upgrade و اجرای مجدد migration پوشش داده شد.
- شمار `_load_textdomain_just_in_time`، پیام بارگذاری زودهنگام domain `hamnoor` و خطای Fatal مرتبط با Hamnoor در هر دو `debug.log` صفر بود.
- Warning، Fatal یا Parse error مرتبط با Hamnoor ثبت نشد.
- هشدار عدم دسترسی WordPress به WordPress.org مربوط به محدودیت شبکه محیط بود.
- Noticeهای تکرار registry هسته WordPress در محیط ارتقا در اثر فراخوانی دستی دوباره hook `init` توسط harness ثبت شدند و مربوط به Hamnoor نبودند.
- هشدار شناخته‌شده `php_imagick.dll` فقط مشکل محیط PHP CLI/سرور موقت بود و به افزونه مربوط نیست.

## آزمون‌های انسانی باقی‌مانده

موارد زیر اجرا نشدند و نیازمند تأیید انسانی در مرورگر معتبر هستند:

- Console و Network مرورگر.
- نمایش واقعی موبایل و دسکتاپ.
- فرم authenticated ایجاد ختم و صفحه authenticated تنظیمات.
- reload و Back برای `sessionStorage`.
- تعامل wizard، radio و checkbox.
- هشدار حجم فایل و ارسال multipart واقعی.

## پاک‌سازی

- هر دو listener موقت متوقف شدند.
- هر دو سایت و دیتابیس ایزوله پس از کنترل نام حذف شدند.
- کاربران، داده‌ها، logها، ابزار WP-CLI و فایل‌های موقت آزمون حذف شدند.
- سایت و دیتابیس توسعه موجود تغییر نکردند.
- ZIP رسمی، manifest و SHA-256 جدید باقی ماندند.
- artifact قبلی در پوشه بایگانی مسدودها محفوظ ماند.

## موارد در انتظار

- commit و push به‌روزرسانی این گزارش.
- Tag `v2.8.1`.
- GitHub Release و الصاق ZIP رسمی.
- backup سایت اصلی.
- نصب روی سایت اصلی.
- smoke test پس از انتشار اصلی.

## Rollback

ZIP معتبر ۲.۸.۰ محفوظ است. بازگشت کد با آن ممکن است، اما ستون nullable افزوده‌شده نباید کورکورانه حذف شود. پیش از انتشار اصلی backup معتبر فایل‌ها و دیتابیس الزامی است.
