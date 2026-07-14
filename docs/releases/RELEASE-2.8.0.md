# گزارش انتشار Hamnoor 2.8.0

## شناسه انتشار

| مورد | مقدار |
|---|---|
| نسخه افزونه | `2.8.0` |
| تاریخ انتشار | ۱۴ ژوئیه ۲۰۲۶ |
| نسخه دیتابیس | `2.7.2` |
| شاخه | `main` |
| Tag | `v2.8.0` |

## commitهای انتشار

- `f7347b126fb933c4ae4402d405467c51a96acd1f` — `feat: add khatm discovery, support, and repeat limit improvements`
- `54bc42513e75103e3401af8b5d7fdfa84815815d` — `chore(release): prepare version 2.8.0`

Tag `v2.8.0` به commit انتشار `54bc42513e75103e3401af8b5d7fdfa84815815d` اشاره می‌کند.

## Added

- CTA واضح ایجاد ختم در صفحه عمومی ختم‌ها.
- نمایش نام ایجادکننده در کارت ختم.
- چیدمان برجسته و وزن‌محور ختم‌های ویژه.
- بخش حمایت مالی داوطلبانه و قابل مدیریت؛ ایجاد ختم به پرداخت وابسته نیست.
- تنظیم عمومی حداکثر تکرار سوره.
- override اختصاصی حداکثر تکرار برای هر ختم توسط مدیر.
- تنظیم دکمه ارتباط با مدیر برای درخواست سقف بیشتر.

## Changed

- تصاویر کارت ختم در دسکتاپ بزرگ‌تر و متعادل‌تر شدند.
- چیدمان ختم‌های ویژه در موبایل و دسکتاپ بهبود یافت.
- نام قرائت‌کننده بدون عبارت «خوانده شده توسط» نمایش داده می‌شود.
- مقدار واقعی تکرار سوره در فرم ویرایش استفاده می‌شود.
- override ذخیره‌شده برای مالک ختم اعمال می‌شود، بدون اعطای اجازه تغییر آن.
- cache busting assetهای فرانت‌اند از نسخه `2.8.0` استفاده می‌کند.

## Fixed

- Warning مربوط به shortlink صفحات مجازی ختم.
- `Undefined variable $release_min_ts`.
- نمایش سقف مصنوعی `100` در فرم ویرایش ختم.
- نادیده‌گرفتن سقف عمومی یا اختصاصی تکرار سوره.
- سازگاری ختم قدیمی دارای تعداد تکرار بیشتر از سقف جدید.

## دیتابیس و migration

- ستون nullable `max_repeat_count` به جدول منطقی `hamnoor_khatms` اضافه شد.
- نسخه اصلی دیتابیس روی `2.7.2` باقی ماند.
- migration مستقل با option `hamnoor_repeat_limit_schema_version` اجرا می‌شود.
- migration از `dbDelta()` و بررسی وجود ستون استفاده می‌کند و تکرارپذیر طراحی شده است.
- داده و تعداد سهم ختم‌های قبلی بازنویسی نمی‌شود.
- `uninstall.php` option migration مستقل را در پاک‌سازی اختیاری افزونه پوشش می‌دهد.

## فایل‌های اصلی تغییرکرده

- `assets/css/hamnoor-frontend.css`
- `assets/js/hamnoor-frontend.js`
- `hamnoor.php`
- `includes/class-hamnoor-admin.php`
- `includes/class-hamnoor-db.php`
- `includes/class-hamnoor-frontend.php`
- `includes/class-hamnoor-helpers.php`
- `includes/class-hamnoor-rest-api.php`
- `includes/class-hamnoor-router.php`
- `includes/class-hamnoor-validator.php`
- `readme.txt`
- `templates/frontend/khatm-card.php`
- `templates/frontend/khatm-list.php`
- `templates/frontend/part-card.php`
- `uninstall.php`

## تست‌های اجراشده

- PHP lint تمام ۵۳ فایل PHP مخزن: موفق.
- PHP lint تمام ۵۳ فایل PHP نسخه استخراج‌شده ZIP: موفق.
- `git diff --check`: موفق.
- کنترل conflict marker، فایل untracked، مسیر Local و داده `[TEST-CODEX]` در مخزن: بدون مورد ناخواسته.
- HTTP فهرست ختم‌ها، صفحه ایجاد ختم و صفحه ختم عمومی: `200`.
- REST API عمومی: پاسخ موفق بدون Fatal Error.
- پاسخ‌های HTML بررسی‌شده: بدون Warning، Notice، Deprecated، Fatal، Parse error یا Undefined variable.
- پنج جدول، ستون `max_repeat_count` و option migration در Local کنترل شدند.
- shortlink صفحه ختم و asset version `2.8.0` کنترل شدند.
- CTA، نام ایجادکننده، حذف عبارت قدیمی نام قرائت‌کننده، override آزمایشی و سازگاری رکورد قدیمی بررسی شدند.
- ZIP extract و ساختار، header نسخه و نبود فایل ممنوع کنترل شد.

## محدودیت‌های تست

- Node در محیط انتشار موجود نبود؛ `node --check` اجرا نشد و JavaScript دستی بازبینی شد.
- نصب واقعی ZIP روی WordPress تمیز و جداگانه اجرا نشد؛ سایت Junction موجود جایگزین نشد.
- صفحه مدیریت و فرم ویرایش با نشست authenticated به‌صورت کامل خودکار آزموده نشدند.
- آزمون دیداری کامل موبایل/دسکتاپ و قالب بلوکی/کلاسیک نیازمند تأیید انسانی است.
- GitHub Release با ابزار رسمی در منابع فعلی قابل اثبات نیست؛ وضعیت آن نیازمند تأیید است.

## بسته ZIP

اطلاعات زیر در ۱۵ ژوئیه ۲۰۲۶ از artifact موجود دوباره اعتبارسنجی شد:

| مورد | مقدار |
|---|---|
| نام فایل | `hamnoor-2.8.0.zip` |
| اندازه | `152079` بایت |
| تعداد فایل ثبت‌شده هنگام اعتبارسنجی انتشار | `65` |
| SHA-256 | `097032815AE704F9F434670D6F1750A90686313F833FFAB9F9FEA7622A567388` |
| پوشه سطح اول | `hamnoor/` |

ZIP شامل فایل‌های runtime است و اسناد توسعه، Git metadata، backup، log، SQL و داده آزمایشی در آن قرار ندارند.

## نکات نصب

1. پیش از نصب یا ارتقا، از دیتابیس و فایل‌های سایت backup معتبر تهیه شود.
2. ZIP از پیشخوان وردپرس نصب/جایگزین و افزونه فعال شود.
3. یک درخواست عادی WordPress برای اجرای bootstrap و migration انجام شود.
4. نسخه `2.8.0`، پنج جدول، ستون `max_repeat_count` و option migration کنترل شوند.
5. فهرست ختم‌ها، ایجاد ختم، یک صفحه عمومی، مدیریت و REST smoke test شوند.
6. error log پس از آزمون از نظر Warning/Fatal بررسی شود.

## Rollback

- rollback فایل‌ها باید با ZIP نسخه قبلی انجام شود و backup دیتابیس تا پایان تأیید انتشار نگه داشته شود.
- چون نسخه 2.8.0 ستون nullable جدید اضافه می‌کند و داده قبلی را بازنویسی نمی‌کند، rollback کد نباید با حذف کورکورانه ستون همراه باشد.
- در صورت خطای migration یا ناسازگاری runtime، ابتدا کد نسخه قبلی و سپس در صورت ضرورت واقعی backup دیتابیس بازگردانده شود.
- پس از rollback، فعال‌بودن افزونه، پنج جدول، URLهای کوتاه و مسیرهای create/claim/complete/release دوباره smoke test شوند.
