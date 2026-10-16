=== Hamnoor | هم‌نور ===
Contributors: rahbarbakhtiari
Tags: quran, khatm, islamic, community, persian
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 4.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

هم‌نور سامانه‌ای برای ایجاد و مدیریت ختم جمعی قرآن و مشارکت ساده با نام و شماره موبایل است.

== Description ==

در هم‌نور فرایند جداگانه‌ای برای ساخت حساب وجود ندارد. پنل کاربر هنگام نخستین مشارکت یا آغاز ایجاد ختم، خودکار ساخته می‌شود. کاربران عادی در جدول مستقل هم‌نور نگهداری می‌شوند و به کاربران وردپرس وابسته نیستند.

امکانات اصلی:

* مشارکت با نام و موبایل ایرانی، بدون OTP
* ساخت یا بازیابی خودکار پنل هنگام مشارکت
* داشبورد واحد برای سهم‌ها، سوابق، ختم‌های ساخته‌شده، اعلان‌های مهم و مشخصات
* نشست اختصاصی HttpOnly و زمان‌دار
* ورود و بازیابی داشبورد با تطابق موبایل بدون OTP؛ تأیید پیامکی در مرحله ایجاد ختم جدید
* ثبت ختم کاربران در وضعیت «در انتظار بررسی مدیر»
* غیرفعال بودن همه امکانات سهم تا پیش از انتشار ختم
* پشتیبانی از تقسیم بر صفحه، جزء، سوره و تکرار یک سوره
* خروجی CSV و XLSX، گزارش شرکت‌کنندگان و لاگ فعالیت
* اتصال OTP به افزونه Manahel IPPanel OTP یا provider سفارشی، بدون هاردکد اطلاعات API
* قرآن هم‌نور با متن عربی و ترجمه مکارم از افزونه Manahel Quran Core
* ذخیره آخرین آیه، ادامه قرائت و نمایش درصد پیشرفت هر سهم

== Installation ==

1. فایل ZIP را در پیشخوان وردپرس نصب و فعال کنید.
2. افزونه سه برگه «ختم‌های قرآن»، «داشبورد هم‌نور» و «ایجاد ختم جدید» را می‌سازد.
3. افزونه «هسته قرآن مناهل» را نصب، فعال و واردسازی ۶۲۳۶ آیه را کامل کنید.
4. تنظیمات هم‌نور و ارائه‌دهنده OTP را بررسی کنید.
5. کاربران از فهرست ختم‌ها مشارکت می‌کنند یا با دکمه «ایجاد ختم جدید» وارد صفحه مستقل ساخت ختم می‌شوند.

== Shortcodes ==

* `[hamnoor_khatm_list]`
* `[hamnoor_dashboard]`
* `[hamnoor_create_khatm]`
* `[hamnoor token="TOKEN"]`

== Changelog ==


= 4.0.7 - Mobile primary journey hierarchy =
* Keeps reading/continuing shares, campaign participation, core quick actions and pending shares ahead of optional Rewards/Referral dashboard cards on mobile web and embedded Eitaa surfaces.
* No database, event, identity, rewards, referral, or campaign-domain behavior changes.

= 4.0.6 - Mobile zero-state bottom navigation fix =
* افزودن آیکن‌های خانه، پویش‌ها، ایجاد، راهنما و بیشتر به ناوبری پایین موبایل در داشبورد اولیه/بدون فعالیت.
* بازگرداندن ناوبری استاندارد پنج‌ستونه و افزودن پنل «بیشتر» برای سهم‌ها، پویش‌های من، اعلان‌ها، پیشرفت، یادآوری‌ها و حساب.
* بدون تغییر در منطق، دیتابیس، API یا قراردادهای هسته.

= 4.0.5 - Internal Quran claim/completion notification allowlist fix =
* Adds `part_claimed` and `part_done` to the canonical internal notification allowlist.
* Claim/completion notifications are now persisted in the Hamnoor dashboard as originally emitted by the domain flow.
* External messenger/push dispatch policy is unchanged, so these high-frequency events remain internal-only by default.
* No database migration. Architecture/Core API/DB schema remain unchanged.

= 4.0.4 - Eitaa embedded-session contract RC1 =
* عمومی‌سازی نشست امن پیام‌رسان‌های تعبیه‌شده بدون وابسته‌کردن Core به یک provider خاص.
* امکان ثبت Cookie اختصاصی برای WebView/iframe با SameSite=None، Secure، HttpOnly و Partitioned (CHIPS).
* قرارگرفتن نشست پیام‌رسان معتبر پیش از نشست عادی وب برای جلوگیری از shadow شدن هویت Mini App توسط نشست قدیمی مرورگر.
* افزودن Cookieهای تعبیه‌شده به LiteSpeed vary/no-cache و پاک‌سازی/خروج یکپارچه تمام نشست‌ها.
* بدون تغییر DB schema (HAMNOOR_DB_VERSION = 4.5.0)، Architecture 3.5.0 یا Core API 2.0.0.

= 4.0.3 - production readiness RC3 =
* رفع Notice بارگذاری زودهنگام text-domain در WordPress 7.1 با انتقال ثبت مسیر ترجمه به `init`.
* جلوگیری از فراخوانی ترجمه در فیلتر برنامه زمانی ۱۵ دقیقه‌ای پیش از آماده‌شدن lifecycle ترجمه وردپرس؛ برچسب پس از `init` همچنان قابل ترجمه است.
* بدون تغییر در DB schema (HAMNOOR_DB_VERSION = 4.5.0)، Architecture 3.5.0 یا Core API 2.0.0.
* Scope این RC فقط closure مورد گزارش WordPress 7.1 است؛ هیچ قابلیت محصولی جدیدی اضافه نشده است.

= 4.0.2 - production readiness RC2 =
* اتمیک و یکنواخت‌شدن ذخیره پیشرفت قرائت؛ درخواست‌های هم‌زمان از چند تب/دستگاه دیگر نمی‌توانند محل قرائت را عقب ببرند.
* همسان‌سازی مجوز تغییر وضعیت قرائت با چرخه عمر پویش و مهلت تکمیل؛ سهم بسته‌شده دیگر پیشرفت جدید یا موقعیت صوت جدید نمی‌پذیرد.
* سخت‌سازی تکمیل دعای رزروشده؛ موفقیت DB اکنون شرط قطعی commit، event و تکمیل خودکار پویش است.
* سخت‌سازی یادآوری دعای در انتظار؛ claim شدن occurrence قبل از ثبت اعلان بررسی می‌شود و failure ثبت اعلان امکان retry امن دارد.
* هدف سازگاری Release: WordPress 7.1 و PHP 7.4 تا 8.3. تأیید نهایی runtime باید در محیط Release Test انجام شود.
* بدون تغییر در DB schema (HAMNOOR_DB_VERSION همچنان 4.5.0) و بدون بازطراحی Architecture 3.5.0.

= 4.0.1 - production readiness closure =
* اعمال قطعی بازه زمانی `starts_at` و `ends_at` برای ثبت جدید دعا و ذکر زیر قفل تراکنشی پویش؛ تأخیر Cron دیگر امکان مشارکت خارج از بازه ایجاد نمی‌کند.
* تکمیل دعای از قبل انتخاب‌شده از قرارداد مهلت تکمیل موجود قرآن (`completion_grace_days`) پیروی می‌کند و retry تکراری همچنان بدون ثبت دوباره باقی می‌ماند.
* رفع علت ریشه‌ای کشیده‌شدن کارت سهم قرآن پس از جایگزینی AJAX در موبایل با جلوگیری از Grid stretch؛ پاسخ Claim/Complete همچنان کارت canonical با context=`single` برمی‌گرداند.
* بدون تغییر در Architecture 3.5.0، Core API 2.0.0 یا DB 4.5.0 و بدون migration.

= 4.0.0 - final structural UX release candidate =
* یکپارچه‌سازی تجربه قرائت عمومی و شخصی قرآن با هندسه پایدار، ترجمه زیر متن عربی و کنترل‌های فشرده موبایل.
* تکمیل تجربه دعای سبک مفاتیح 3.3.1، صوت کامل/فراز/چندقاری، انتخاب Media Library، ترتیب پایدار و پاک‌سازی APIC.
* تجمیع قرآن، دعا و ذکر در مقصد «سهم‌های من» با فیلتر، وضعیت، اقدام و صفحه‌بندی محدودشده واحد.
* تثبیت کشف پویش‌ها، سهمیه‌ها، بازخورد claim، رنگ اصلی #1F63C3 و تنظیمات امن و قابل‌پیش‌بینی مدیریت.
* بدون تغییر در Foundation، Core API یا ساختار پایگاه داده 4.5.0.

= 4.0.0 - reading action consistency fix =
* نمایش دوباره دکمه شروع/ادامه قرائت سهم در داشبورد با اتصال صحیح شناسه کاربر مشارکت‌کننده.
* حذف نسبت‌دادن نادرست ثبت دستی قرائت به «قرآن چاپی»؛ دکمه و پیام ثبت دستی اکنون خنثی و دقیق هستند.
* متن‌های دکمه‌های قرآن هم‌نور و پیام کوتاه ثبت قرائت از بخش «متن‌ها» قابل ویرایش شدند.


= 4.0.0 =
* نخستین نسخه پایدار معماری ماژولار هم‌نور پس از آزمون کامل هسته و مجموعه اعلان‌ها.
* تثبیت داشبورد، پویش ختم قرآن، قرآن هم‌نور، احراز موبایل و ورود یکپارچه پیام‌رسان‌ها.
* پاک‌سازی زبان فرانت‌اند و مدیریت و آماده‌سازی رابط برای انتشار عمومی.
* حفظ ساختار پایگاه داده 3.9.1 و Core API نسخه 1.1.0؛ بدون مهاجرت جدید.

= 4.0.0-rc11 =
* نامزد نهایی انتشار پس از تأیید عملی مسیرهای اصلی، ورود پیام‌رسان‌ها و لغو اعلان‌ها.
* همسان‌سازی فراداده نسخه، Stable tag و فایل‌های زبان؛ بدون تغییر در منطق یا پایگاه داده.
* حفظ کامل رفتار تأییدشده نسخه rc10 برای داشبورد، پویش‌ها، قرائت و احراز موبایل.
* بازنویسی متن‌های فرانت‌اند و مدیریت با زبان انسانی‌تر و انتقال تنظیمات فنی به بخش‌های پیشرفته.
* حذف گزینه بی‌اثر تصویر خارجی از رابط و اصلاح عنوان تعداد سهم‌های صفحه پویش و مدیریت.

= 4.0.0-rc4 =
* کاهش واقعی فاصله دکمه ثبت آیه، پخش‌کننده صوت و لبه پایین نمایشگر در موبایل.

= 4.0.0-rc3 =
* بزرگ‌تر شدن لوگوی هدر موبایل و حذف عنوان تکراری «خانه».
* افزودن اندازه پیش‌فرض خط قرآن و ترجمه به تنظیمات مدیریت.
* فشرده‌سازی کنترل ثبت آیه و پخش‌کننده صوت برای افزایش فضای نمایش آیات.

= 4.0.0-rc2 =
* آغاز بازطراحی علمی و Mobile First رابط هم‌نور با Design System یکپارچه.
* بازطراحی تجربه اولین ورود با معرفی روشن محصول، یک اقدام اصلی و فرم دسترس‌پذیر.
* اصلاح ناوبری موبایل بر اساس فعالیت‌های پرتکرار؛ انتقال «ایجاد پویش» به بخش بیشتر.
* بهبود کنتراست، Focus، اندازه اهداف لمسی، حالت کاهش حرکت و سازگاری RTL.

= 4.0.0-rc1 =
* نامزد انتشار عمومی هسته هم‌نور پس از تثبیت واژگان «پویش ختم قرآن» و معماری افزونه‌پذیر.
* حفظ ساختار پایگاه داده 3.9.1 و Core API نسخه 1.1.0؛ بدون migration جدید.
* آماده‌سازی هماهنگ با Notification Suite نسخه 4.0.0-rc1.

= 4.0.0-beta4 =
* تغییر واژگان قابل‌مشاهده سامانه از «ختم» به «پویش» و نام کامل «پویش ختم قرآن» در فرانت‌اند، مدیریت، پیام‌ها و منوها.
* حفظ کامل نام‌های فنی، پایگاه داده، نشانی‌ها و shortcodeهای قبلی برای ارتقای بدون شکست.
* بازنویسی مفهوم تعداد ختم به «تعداد دور کامل قرآن در پویش» برای جلوگیری از ابهام.

= 4.0.0-beta3 =
* شماره فعلی در کادر فعال‌سازی موبایل از پیش درج شده و قابل ویرایش است.
* در صورت اصلاح شماره، کد به شماره جدید ارسال و فعالیت‌های پنل فعلی به شماره جدید منتسب می‌شود.
* سیاست ورود بدون پیامک Beta2 و الزام تأیید برای عملیات حساس بدون تغییر باقی ماند.
* پایگاه داده، Notifications Suite و کانال روبیکا تغییری نکردند.

= 4.0.0-beta2 =
* شماره موبایل معتبر اکنون می‌تواند بدون سهم قبلی و بدون پیامک یک داشبورد خالی ایجاد کند.
* الزام تأیید پیامکی برای ایجاد و ویرایش ختم و تغییر مشخصات حفظ و تقویت شد.
* متن‌های صفحه مهمان داشبورد با سیاست دسترسی موبایل‌محور یکسان شد.
* هیچ تغییری در پایگاه داده یا Notifications Suite و کانال روبیکا انجام نشد.

= 4.0.0-beta1 =
* نخستین Beta معماری ماژولار 4.0 با ساختار دو افزونه‌ای Core و Notifications Suite.
* تثبیت رفتار تأییدشده alpha10.1 بدون قابلیت جدید یا تغییر در ارسال، صف، Retry و کانال‌ها.
* همسان‌سازی نسخه Core، Suite و تمام ماژول‌های داخلی؛ Core API همچنان 1.1.0 است.
* نسخه پایگاه داده همچنان 3.9.1 است و migration جدیدی اجرا نمی‌شود.

= 4.0.0-alpha10.1 =
* جایگزینی افزونه‌های متعدد اعلان با یک Notifications Suite شامل Engine، Web Push، ایتا، بله و روبیکا.
* بارگذاری تأخیری پس از آماده‌شدن قراردادهای هسته برای جلوگیری از Fatal Error ناشی از ترتیب Load افزونه‌ها.
* هر کانال همچنان ماژول داخلی مستقل با مرز خطای جداگانه است؛ خرابی یک ماژول از Bootstrap سایت خارج نمی‌شود.
* تنظیمات، اتصال کاربران، صف، Retry، گزارش‌ها و نسخه پایگاه داده 3.9.1 بدون تغییر حفظ شده‌اند.

= 4.0.0-alpha10 =
* جداسازی فیزیکی Notification Engine و انتقال Dispatcher، Queue، Retry و گزارش تحویل به افزونه مستقل رسمی.
* قرارداد Engine، Facade سازگاری، Channel Registry و جداول داده در هسته باقی مانده‌اند.
* بدون افزونه موتور اعلان، منوی کانال‌های اعلان نمایش داده نمی‌شود و افزونه‌های کانال تا رفع وابستگی Boot نمی‌شوند.
* رفتار ارسال و نسخه پایگاه داده 3.9.1 بدون تغییر باقی مانده است.

= 4.0.0-alpha9 =
* جداسازی تب رزروشده Push اپلیکیشن از هسته و انتقال آن به افزونه رسمی مستقل.
* بدون افزودن Adapter، تنظیمات یا رفتار ارسال جدید برای Push اپلیکیشن.
* نسخه پایگاه داده همچنان 3.9.1 است و migration جدیدی اجرا نمی‌شود.

= 4.0.0-alpha8 =
* جداسازی تب رزروشده تلگرام از هسته و انتقال آن به افزونه رسمی مستقل.
* بدون افزودن Adapter، تنظیمات یا رفتار ارسال جدید برای تلگرام.
* نسخه پایگاه داده همچنان 3.9.1 است و migration جدیدی اجرا نمی‌شود.

= 4.0.0-alpha7 =
* جداسازی تب رزروشده واتساپ از هسته و انتقال آن به افزونه رسمی مستقل.
* بدون افزودن Adapter، تنظیمات یا رفتار ارسال جدید برای واتساپ.
* نسخه پایگاه داده همچنان 3.9.1 است و migration جدیدی اجرا نمی‌شود.

= 4.0.0-alpha6 =
* جداسازی فیزیکی روبیکا: تب و کارت روبیکا فقط با افزونه رسمی مستقل نمایش داده می‌شوند.
* حفظ کامل تنظیمات، اتصال‌ها، Endpoint و Worker موجود روبیکا؛ بدون تغییر رفتار عملیاتی.
* نسخه پایگاه داده همچنان 3.9.1 است و migration جدیدی اجرا نمی‌شود.

= 4.0.0-alpha5 =
* Bale adapter, webhook, handoff, REST Mini App endpoints and Mini App shell moved to the independently installable `hamnoor-bale` package.
* Core no longer requires or boots Bale implementation files.
* Bale admin tab and dashboard card appear only while the Bale add-on is active.
* Web Push and Eitaa tabs remain add-on-owned and are absent when their packages are inactive.
* Existing encrypted Bale settings, linked users, priority and DB version 3.9.1 are preserved.

= 4.0.0-alpha4 =
* Eitaa adapter, official Mini App bridge and Mini App JavaScript moved to the independently installable `hamnoor-eitaa` package.
* Core no longer requires or boots Eitaa implementation files.
* Eitaa admin tab and dashboard card are registered only while the Eitaa add-on is active.
* Existing encrypted settings, linked users, notification priority and DB version 3.9.1 are preserved.

= 4.0.0-alpha3.1 =
* Web Push admin tab registration and rendering are fully owned by the separate add-on.
* Without the Web Push add-on, Core no longer displays the Web Push tab or its empty diagnostics panel.

= 4.0.0-alpha3 =
* Web Push is physically separated into the independently installable `hamnoor-web-push` package.
* Core no longer requires, boots or serves Web Push implementation/assets.
* Existing subscriptions, VAPID keys, Notification Engine behavior and DB version 3.9.1 are preserved.
* Core, khatms, reading and other channels continue without the Web Push package.

= 4.0.0-alpha2 =
* First real module extraction: Notification Engine moved from Core/legacy includes into `addons/notifications/` without changing the 3.9.68 database schema or delivery behavior.
* Core now exposes a stable `Hamnoor_Notification_Engine_Interface`, `Hamnoor_Notifications` facade and service-container contract; Admin, Engagement and channel integrations no longer call the concrete Dispatcher directly.
* Notification Engine no longer knows Web Push, Eitaa, Bale or Rubika classes. All channels register through a Core-owned channel registry.
* Added a protected built-in add-on loader so a Notification add-on bootstrap failure is recorded and Core can continue booting.
* Notification queue cron lifecycle is now owned by the Notifications add-on through activation/deactivation hooks rather than Hamnoor Core.
* Core API bumped to 1.1.0 and architecture version to 1.1.0-alpha2. Database version remains 3.9.1.

= 4.0.0-alpha1 =
* First modular-architecture milestone; user-facing behavior and the 3.9.68 database schema remain unchanged.
* Added stable Core API, service registry, canonical Event Bus and a one-way bridge from established 3.x domain hooks.
* Added manifest-driven Add-on Manager with dependency/version checks, feature flags, isolated boot, runtime-error capture and health snapshots.
* Optional transitional modules (Web Push, Eitaa, Bale, Rubika, Engagement, Notification Dispatcher, Admin, SEO, REST and add-on discovery) now boot behind per-component Throwable boundaries.
* Existing Hamnoor_Addons notification provider/channel API remains backwards compatible while the new add-on contract is introduced.

= 3.9.68 =
* Rubika TOO_REQUESTS/provider rate-limit responses are normalized as retryable 429 errors even when the API returns HTTP 200.
* Notification Engine now honors provider retry_after when present and otherwise uses the existing safe backoff schedule.
* Rubika link-message compatibility fallback no longer fires an immediate second send on rate-limit, timeout, network or 5xx failures.

= 3.9.67 =
* Smart Reminder Engine v1: state-aware reminders for unstarted, stalled, near-complete and ready-to-finish shares.
* Defaults: 24h unstarted, 48h stalled/repeat, max 3 automatic reminders per share.
* Reminder routing continues through Notification Engine 2.0 with Web Push supplemental and Eitaa/Bale/Rubika durable fallback.
* Added admin settings and decision diagnostics for smart reminders.

= 3.9.66 =
* Bale gateway: full native reply-keyboard menu with direct Mini App targets for reading, shares, khatms, notifications, reminders, profile and progress.
* Bale bot now understands slash commands and Persian menu text instead of replying with the same generic message to every input.
* Bale Mini App target routing now supports continue/public/my-khatms/create/dashboard tabs and resolves the user's latest active reading server-side.
* Existing secure initData validation, requestContact onboarding, webhook and notification delivery are preserved.

= 3.9.65 =
* Rubika realtime: ReceiveUpdate Webhook is primary; getUpdates worker is adaptive fallback.
* Reduced fallback polling interval and throttled no-cursor recovery probes.
* Added Rubika transport/process/send latency diagnostics.

= 3.9.63 =
* Rubika becomes a first-class Hamnoor gateway with a full menu, Persian-text command fallback, and authenticated links for reading, khatms, notifications, profile and progress.


= 3.9.62 =
* Notification State Reconciliation: رفع Regression وضعیت کانال‌های قدیمی بدون بازنویسی Queue/Retry.
* تعریف واحد «اتصال فعال» در Adapter و Dispatcher؛ Dispatcher دیگر شرط DB موازی و متناقض را به‌عنوان gate مستقل اعمال نمی‌کند.
* اصلاح Bale: فقط enabled=1 + status=connected + external_id معتبر به‌عنوان اتصال فعال شناخته می‌شود.
* Migration امن و idempotent برای stateهای legacy؛ وضعیت‌های دارای خطای واقعی needs_reconnect حفظ می‌شوند.
* ثبت گزارش Audit مهاجرت در مدیریت و نمایش علت دقیق Skip هر کانال در تست تشخیصی.
* تغییر نام تست داشبورد به «تست Web Push این مرورگر» برای تفکیک از Notification Engine.


= 3.9.60 =
* Web Push: تشخیص اشتراک ناسازگار با کلید VAPID فعلی در مرورگر و بازسازی خودکار هنگام فعال‌سازی مجدد.
* Web Push: گزارش امن‌تر خطاهای HTTP 401/403 و راهنمای رفع 403 در مدیریت.
* اعلان‌ها: خطاهای 401/403 همچنان نهایی و بدون Retry غیرضروری باقی می‌مانند.


= 4.0.0 / UX 3.9.6 =
* تغییر عنوان «مشارکت‌ها» به «سهم‌های من».
* حذف پیشوند گیج‌کننده «پویش 1» از سهم‌های پویش تک‌دوره‌ای و استفاده از «دور N» فقط در پویش‌های چنددوره‌ای.
* افزودن فیلتر وضعیت/پویش، جستجو و مرتب‌سازی سهم‌ها در «سهم‌های من».

= 3.9.59 =
* Notification Engine 2.0: Web Push به‌صورت مکمل و مستقل از پیام‌رسان ماندگار پردازش می‌شود.
* صف پایدار ارسال و Retry برای خطاهای موقت اضافه شد (۱۵ دقیقه، ۱ ساعت و ۶ ساعت؛ حداکثر ۴ تلاش).
* پس از موفقیت یک پیام‌رسان ماندگار، Retry کانال‌های ماندگار دیگر برای جلوگیری از پیام تکراری لغو می‌شود.
* Worker پنج‌دقیقه‌ای، بازیابی ارسال‌های گیرکرده، پردازش دستی صف و Retry دستی از مدیریت اضافه شد.
* گزارش ارسال شامل وضعیت صف، تعداد تلاش‌ها، زمان تلاش بعدی و وضعیت‌های queued/retry_wait/cancelled شد.


= 3.9.58 =
* Refined standalone public-khatm desktop header, navigation hierarchy, and breadcrumb presentation.
* Kept mobile presentation unchanged.



= 3.9.56 =
* Desktop public khatm page now uses the Hamnoor/site Persian font stack consistently.
* Standalone renderer can reuse a local IRANSansXFaNum/IRANSansX/Vazirmatn font file from the active theme without loading theme layout CSS.
* Fixed duplicated desktop/mobile navigation controls in standalone khatm pages.
* Rebuilt public khatm desktop top navigation and added a contextual breadcrumb/back row.

= 3.9.55 =
* Desktop Phase 4: fixed duplicate active sidebar state.
* Progress center now shows 25 shares per page inside a bounded table with sticky headings.
* Reset legacy featured-card grid spans so public khatm cards remain genuinely two-column on desktop.
* Reduced public khatm cover height and tightened desktop card density.

= 3.9.54 =
* Desktop Phase 3: redesigned Quran reading layout for large screens.
* Desktop participation cards use a single-row responsive layout without awkward grid gaps.
* Removed the site-wide floating reading action on desktop; mobile behavior is unchanged.
* Refined guest/recovery desktop spacing.

= 3.9.53 =
* فاز دوم رابط مستقل دسکتاپ: انتقال Sidebar داشبورد به سمت راست در رابط RTL.
* یکپارچه‌سازی صفحات آغاز/تأیید ایجاد ختم با هدر دسکتاپ هم‌نور.
* بهینه‌سازی فرم‌های ورود/OTP دسکتاپ و کاهش فضای خالی.
* جمع‌وجورتر شدن ختم‌های عمومی در نمایشگر بزرگ و محدودسازی عرض کارت تکی.
* ناوبری دسکتاپ صفحه عمومی ختم: پنل من، ختم‌های عمومی و ایجاد ختم.
* مخفی‌شدن نوار مدیریت وردپرس در صفحات فرانت‌اند هم‌نور برای حفظ تجربه اپلیکیشنی، حتی در حساب مدیر.

= 3.9.52 =
* Desktop UI Phase 1: independent desktop stylesheet and two-column app shell.
* Desktop dashboard: branded sidebar navigation, focused reading card, stronger summary hierarchy and desktop-only quick actions.
* Mobile presentation and reading UI remain unchanged.

= 3.9.51 =
* یکپارچه‌سازی ناوبری موبایل در ایجاد ختم، ختم‌های عمومی و صفحات عمومی ختم.
* کوچک‌سازی و متناسب‌سازی لوگوی هم‌نور در هدرهای موبایل و قالب مستقل.
* حفظ رابط مینیمال صفحه قرائت بدون نوار ناوبری عمومی.
= 3.9.48 =
* افزودن لوگوی رسمی هم‌نور به هدر موبایل، صفحات ختم مستقل، صفحات ایجاد/فهرست ختم و ورودی Mini App بله.
* تثبیت نمایش ۱۰تایی سهم‌ها در موبایل با کلاس اختصاصی و جلوگیری از override شدن توسط استایل کارت‌ها.
* یکسان‌سازی قطعی کارت‌های سهم عمومی در همه سبک‌های ختم و تبدیل آن‌ها به ردیف فشرده موبایلی.
* افزودن شمارنده «نمایش X از Y سهم» و دکمه تدریجی نمایش سهم‌های بیشتر.
* بازطراحی کامل «مرکز پیشرفت» در موبایل با کارت‌های مستقل و حذف جدول شکسته/خط‌تیره‌های بدون عنوان.
* فشرده‌سازی کارت‌های «ختم‌های من» در موبایل و کاهش پیام‌های تکراری، بدون تغییر نمای دسکتاپ.

= 3.9.46 =
* بازطراحی موبایل صفحه عمومی ختم با کارت‌های سهم فشرده و مناسب لمس.
* انتقال اشتراک‌گذاری و جستجو/فیلتر سهم‌ها به بخش‌های جمع‌شونده در موبایل.
* نمایش تدریجی سهم‌ها در بسته‌های ۱۰تایی در موبایل برای جلوگیری از صفحه بسیار بلند.
* حفظ کامل منطق، URLها، دیتابیس و نمای دسکتاپ.

= 3.9.45 =
* Public khatm pages in dedicated mode now use a true standalone plugin shell, even on block themes such as Twenty Twenty-Five.
* Removed theme header/footer dependency from the dedicated khatm template.
* Added standalone CSS and theme-asset isolation so public khatm layout is controlled by Hamnoor.

= 3.9.44 =
* Mobile UI phase 2: compact participation cards, dashboard floating-action collision fix, two-column quick actions, and a single contextual reading-progress dock on mobile.

= 3.9.44 =
* آغاز معماری رابط دوگانه: منطق، دیتابیس و API مشترک باقی می‌ماند اما نمای موبایل از دسکتاپ جدا شده است.
* افزودن فایل استایل مستقل `hamnoor-mobile.css` و اسکریپت مستقل `hamnoor-mobile.js` برای جلوگیری از فشرده‌سازی صرف رابط دسکتاپ در موبایل.
* بازطراحی داشبورد موبایل به شکل اپلیکیشن: App Bar، کارت بزرگ ادامه قرائت، خلاصه وضعیت، دسترسی‌های سریع و فهرست سهم‌های در انتظار.
* افزودن Bottom Navigation ثابت با خانه، مشارکت‌ها، ایجاد ختم، اعلان‌ها و منوی «بیشتر».
* افزودن Bottom Sheet موبایل برای ختم‌های من، مرکز پیشرفت، یادآوری‌ها، پروفایل، راهنما، ختم‌های فعال و خروج.
* حفظ کامل نمای فعلی دسکتاپ؛ تغییرات این نسخه در عرض حداکثر ۷۸۲ پیکسل اعمال می‌شود.
* اصلاح متن توضیح اولویت اعلان‌ها: Web Push مکمل است و پس از آن اولین پیام‌رسان ماندگار موفق انتخاب می‌شود.

= 3.9.41 =
* بازگرداندن ورودی رسمی Mini App بله به فایل استاتیک مستقیم که در Bale Web عملاً باز شدن آن تأیید شد.
* جلوگیری از عبور Main Mini App و دکمه‌های web_app از مسیر PHP/Rewrite `/hamnoor-bale/`؛ این مسیر ممکن است در سطح LiteSpeed/Apache دوباره هدرهای framing دریافت کند.
* نمایش صریح URL مناسب BotFather در تنظیمات بله و توضیح اینکه مسیر `/wp-content/plugins/hamnoor/...` عمومی است و هیچ ارتباطی با `/wp-admin/` ندارد.
* حفظ `/hamnoor-bale/` فقط برای سازگاری و عیب‌یابی؛ نه به‌عنوان URL پیشنهادی BotFather.

= 3.9.40 =
* رفع قطعی 404 مسیر تمیز Mini App بله: `/hamnoor-bale/` اکنون در ابتدای `init` و قبل از parse/rewrite/canonical وردپرس مستقیماً با HTTP 200 سرو می‌شود.
* حذف rewrite به فایل فیزیکی داخل پوشه افزونه؛ مسیر اصلی دیگر به Permalink، `.htaccess` اختصاصی یا flush شدن rewrite وابسته نیست.
* افزودن هدر تشخیصی `X-Hamnoor-Bale-Entry: early-200-3.9.40` و no-cache صریح برای جلوگیری از 404 کش‌شده LiteSpeed.
* URL ثبت‌شده در BotFather همچنان ثابت است: `https://SITE/hamnoor-bale/`.


= 3.9.39 =
* رفع تفاوت موبایل و نسخه وب/دسکتاپ بله در Main Mini App: حذف redirect از `/hamnoor-bale/` و سرو پوسته استاتیک با internal rewrite سمت LiteSpeed/Apache.
* حفظ URL تمیز `/hamnoor-bale/` در نوار آدرس؛ BotFather همچنان فقط همین آدرس پایدار را نیاز دارد.
* افزودن fallback بدون redirect برای سرورهایی که external rewrite وردپرس را اجرا نمی‌کنند.
* پشتیبانی مستقیم پوسته استاتیک از `tgWebAppStartParam` برای Main Mini App و لینک‌های `?startapp=` بله.

= 3.9.38 =
* افزودن URL پایدار و تمیز `https://SITE/hamnoor-bale/` به عنوان ورودی رسمی Main Mini App و همه دکمه‌های تازه بله.
* مسیر تمیز فقط یک Redirect کوتاه هم‌دامنه به پوسته استاتیک آزمایش‌شده انجام می‌دهد؛ بنابراین مشکل iframe نسخه وب بله برنمی‌گردد و BotFather به مسیر داخلی wp-content/plugins وابسته نمی‌شود.
* حفظ target سهم/داشبورد هنگام Redirect به پوسته استاتیک؛ لینک‌های «ادامه در هم‌نور» همچنان مستقیماً همان سهم را باز می‌کنند.
* نمایش صریح URL پایدار در مدیریت بله و نگه‌داشتن URL فنی پوسته استاتیک فقط برای عیب‌یابی.

= 3.9.37 =
* انتقال URL اصلی Mini App بله به یک پوسته HTML استاتیک اختصاصی داخل افزونه تا باز شدن iframe به قالب/وردپرس وابسته نباشد.
* افزودن .htaccess اختصاصی برای LiteSpeed/Apache فقط در پوشه Mini App: حذف X-Frame-Options و CSP قبلی و اعمال سیاست سازگار با Bale Web.
* حفظ مسیر PHP قبلی به عنوان fallback/diagnostic؛ دکمه‌های تازه /start و یادآوری‌ها اکنون به پوسته استاتیک 3.9.37 اشاره می‌کنند.
* افزودن هدر تشخیصی X-Hamnoor-Bale-Static در پوسته استاتیک و نمایش URL استاتیک/داینامیک در تنظیمات بله.

= 3.9.36 =
* رفع بازنشدن Mini App بله در نسخه وب/دسکتاپ: پاسخ‌های Mini App و نشست اختصاصی بله دیگر X-Frame-Options مسدودکننده ندارند.
* افزودن CSP محدود به جریان بله با frame-ancestors و frame-src برای دامنه‌های رسمی bale.ai/ble.ir؛ سیاست framing کل سایت تغییر نمی‌کند.
* مسیر ورود یک‌بارمصرف، داشبورد و صفحات قرائت پس از ورود بله نیز با کوکی اختصاصی hamnoor_bale_session داخل iframe قابل ادامه هستند.
* افزودن هدر تشخیصی X-Hamnoor-Bale-Frame: allowed و no-cache مخصوص پاسخ‌های iframe بله برای عیب‌یابی آسان‌تر.

= 3.9.35 =
* اصلاح علت پاسخ‌ندادن /start در بله: ثبت Webhook دیگر مرحله دستی جداگانه نیست؛ با ذخیره تنظیمات بله، افزونه ابتدا getMe واقعی و سپس setWebhook و getWebhookInfo را خودکار اجرا و تطبیق URL را تأیید می‌کند.
* اگر توکن ظاهراً معتبر ولی در API نامعتبر باشد یا ثبت Webhook شکست بخورد، دیگر پیام مبهم «تنظیمات ذخیره شد» نمایش داده نمی‌شود و خطای واقعی همان مرحله به مدیر اعلام می‌شود.
* افزودن تشخیص ورودی Webhook در مدیریت: زمان آخرین Update دریافتی، update_id و متن آخرین پیام ثبت می‌شود تا فوراً مشخص شود /start واقعاً به وردپرس رسیده است یا نه.
* دکمه «تست getMe بله» نیز اکنون پس از موفقیت، Webhook را هم همگام و تأیید می‌کند؛ بنابراین خروجی موفق یعنی مسیر دریافت پیام نیز پیکربندی شده است، نه فقط ارسال خروجی API.
* وضعیت Webhook در جدول مدیریت با حالت‌های «فعال و تأییدشده»، «آدرس دیگر»، «خالی» و خطای همگام‌سازی شفاف‌تر نمایش داده می‌شود.

= 3.9.34 =
* افزودن دکمه واقعی «باز کردن هم‌نور» به Reply Keyboard بازوی بله با KeyboardButton.web_app؛ حتی بدون تنظیم Main Mini App در BotFather، کاربر یک ورودی Mini App در خود گفتگو دارد.
* در حالت اتصال‌نشده، صفحه‌کلید بازو هم دکمه Web App و هم «ارسال شماره موبایل من» را نشان می‌دهد؛ Mini App مسیر اصلی است و request_contact چت مسیر جایگزین باقی می‌ماند.
* پس از اتصال، دکمه Reply Keyboard حذف نمی‌شود و ورودهای بعدی مستقیماً به همان پنل هم‌نور انجام می‌شود؛ دکمه Inline زیر پیام نیز برای سازگاری حفظ شده است.
* تمام دکمه‌های یادآوری بله همچنان با InlineKeyboardButton.web_app به همان سهم هدف باز می‌شوند و لینک عادی وب نیستند.

= 3.9.33 =
* اصلاح منطق Dispatcher: Web Push اکنون کانال مکمل است و موفقیت آن زنجیره ارسال را متوقف نمی‌کند.
* پس از Web Push، کانال‌های ماندگار به‌ترتیب اولویت بررسی می‌شوند و اولین پیام‌رسان موفق زنجیره را متوقف می‌کند تا از ارسال تکراری در همه پیام‌رسان‌ها جلوگیری شود.
* اگر فقط Web Push برای کاربر در دسترس باشد و ارسال آن موفق شود، اعلان همچنان موفق ثبت می‌شود.
* گزارش اصلی ارسال، در صورت موفقیت هم‌زمان Web Push و یک پیام‌رسان، پیام‌رسان ماندگار را به‌عنوان کانال نهایی نمایش می‌دهد و ردیف Web Push نیز در گزارش تلاش‌ها حفظ می‌شود.
* متن‌های مدیریت و تست عملیاتی با منطق جدید «Web Push + اولین پیام‌رسان ماندگار موفق» هماهنگ شدند.

= 3.9.32 =
* افزودن Legacy Bale Mini App Bridge برای دکمه/مین‌اپ‌های قدیمی بله که مستقیماً آدرس داشبورد هم‌نور را باز می‌کردند؛ این مسیرها اکنون نیز initData رسمی بله را به سرور می‌فرستند و فقط پس از HMAC معتبر وارد پنل می‌شوند.
* در نبود توکن بازوی بله، اجرای واقعی Mini App روی URL قدیمی دیگر به داشبورد سایت fallback نمی‌کند و خطای پیکربندی نمایش داده می‌شود.
* اتصال اولیه از URL قدیمی نیز فقط یک‌بار با Bale.WebApp.requestContact انجام می‌شود و سپس Bale User ID به همان رکورد موبایل هم‌نور متصل می‌ماند.
* هنگام وجود هم‌زمان نشست عادی سایت و نشست اختصاصی بله، نشست معتبر بله اولویت دارد تا WebApp به حساب دستی/قدیمی WebView اشتباه وارد نشود.
* خروج از پنل هر دو نشست عادی و بله موجود در همان مرورگر را در دیتابیس revoke می‌کند.

= 3.9.31 =
* بخش «تست عملیاتی یادآوری» اکنون دو حالت مستقل دارد: تست مسیر واقعی اولویت‌ها (Web Push مکمل + اولین پیام‌رسان ماندگار موفق) و تست فنی همه کانال‌های متصل و فعال.
* تست «همه کانال‌ها» یک پیام واقعی را به تک‌تک کانال‌های واجد شرایط همان کاربر ارسال می‌کند، حتی اگر کانال قبلی موفق شده باشد؛ نتیجه هر کانال جداگانه در notification_deliveries ثبت می‌شود.
* نتیجه آخرین تست همه کانال‌ها، شامل تعداد کانال‌های واجد شرایط، موفق، ناموفق و وضعیت هر کانال، در نمای کلی مدیریت نمایش داده می‌شود.
* هر دو حالت تست زمان‌بندی یادآوری، reminder_count و last_reminder_at سهم را دست‌نخورده نگه می‌دارند.

= 3.9.30 =
* Added an admin operational reminder test that sends a selected claimed part through the real multichannel Dispatcher without changing the part reminder schedule, reminder count, or last-reminder timestamp.
* Added channel-route diagnostics for the selected user and a persistent summary of the last operational test.
* The test still creates a real Hamnoor notification and an actual external delivery attempt, so administrators can verify the complete delivery chain and report rows.
= 3.9.29 =
* یادآوری سهم‌ها اکنون نتیجه ارسال بیرونی را از Dispatcher دریافت و در لاگ/گزارش ثبت می‌کنند.
* تب «گزارش ارسال» با وضعیت موفق، ناموفق و فقط داخلی برای اعلان‌های چندکاناله اضافه شد.
* نتیجه آخرین اجرای زمان‌بند یادآوری و اجرای بعدی در نمای کلی کانال‌های اعلان نمایش داده می‌شود و مدیر می‌تواند بررسی یادآوری‌ها را دستی اجرا کند.
* برای اعلان‌هایی که کانال بیرونی فعالی ندارند، رکورد گزارش «فقط داخلی» ثبت می‌شود.
* محافظ ارتقای امن اضافه شد: پیش از تغییر نسخه از تنظیمات و توکن‌های کانال‌ها snapshot گرفته می‌شود و مقادیر محرمانه مفقودشده بازیابی می‌شوند.
* بسته ZIP همچنان با مسیر ثابت hamnoor/hamnoor.php تولید می‌شود تا نصب با گزینه Replace current with uploaded انجام شود.


= 3.9.28 =
* Rubika UX: لینک‌های خام از پیام‌های جدید حذف شدند و با Metadata رسمی نوع Link به عبارت‌های قابل‌کلیک مثل «ادامه قرائت سهم»، «ورود به سهم‌های من» و «ورود به داشبورد هم‌نور» تبدیل شدند.
* محاسبه from_index و length متادیتای روبیکا بر اساس UTF-16 انجام می‌شود؛ مطابق قرارداد رسمی MetadataPart و سازگار با متن فارسی/emoji.
* اگر یک نسخه کلاینت/API متادیتای Link را نپذیرد، ارسال به‌صورت خودکار به لینک کوتاه پایدار /r/{code}/ fallback می‌کند تا اعلان از دست نرود.
* پیام‌های یادآوری Dispatcher روبیکا اکنون همان لینک کوتاه پایدار را پشت متن قابل‌کلیک استفاده می‌کنند؛ ثبت موفق/ناموفق ارسال همچنان در notification_deliveries انجام می‌شود.

= 3.9.27 =
* Rubika: همه لینک‌های جدید ورود و یادآوری به مسیر کوتاه پایدار `/r/{code}/` تبدیل شدند؛ uid، target و sig دیگر در URL پیام دیده نمی‌شوند.
* هر کاربر + مقصد یک لینک کوتاه ثابت با کد تصادفی ۲۰ کاراکتری غیرقابل حدس دارد و همان لینک در یادآوری‌های بعدی دوباره استفاده می‌شود.
* مقصد واقعی، شناسه کاربر و اثر انگشت secret فقط در جدول اختصاصی `hamnoor_rubika_links` نگهداری می‌شوند.
* لینک کوتاه زمان انقضا ندارد و چندبارمصرف است؛ قطع/اتصال مجدد روبیکا secret را می‌چرخاند و لینک‌های اتصال قبلی را باطل می‌کند.
* GET لینک کوتاه فقط صفحه واسط noindex را نمایش می‌دهد و ورود واقعی با POST انجام می‌شود تا preview پیام‌رسان نشست غیرضروری نسازد.
* لینک‌های طولانی قدیمی `hamnoor_rubika_handoff` برای سازگاری با پیام‌های قبلی همچنان قابل استفاده‌اند.

= 3.9.26 =
* Rubika: لینک‌های ورود از پیام‌ها دیگر زمان‌دار یا یک‌بارمصرف نیستند؛ کاربر می‌تواند همان لینک را هر زمان دوباره باز کند و به همان مقصد مجاز برگردد.
* امنیت لینک پایدار روبیکا با امضای HMAC و secret اختصاصی هر اتصال حفظ می‌شود؛ لینک‌ها فقط تا زمانی معتبرند که اتصال همان حساب روبیکا فعال باشد.
* با قطع اتصال یا اتصال مجدد روبیکا، secret تغییر می‌کند و تمام لینک‌های قدیمی همان حساب فوراً باطل می‌شوند؛ بدون انقضای زمانی در استفاده عادی.
* حذف transient دو روزه و مصرف‌شدن لینک پس از اولین ورود؛ پیش‌نمایش لینک یا بازکردن بدون قرائت دیگر لینک پیام را خراب نمی‌کند.

= 3.9.25 =
* Rubika Android: accept a phone number returned as message text only when aux_data.button_id matches the official AskMyPhoneNumber button; arbitrary typed numbers remain rejected.
* Rubika diagnostics: show the last interactive button_id, whether contact_message existed, and whether the text was a valid Iranian mobile.
* اصلاح دستور Cron مخصوص DirectAdmin: زمان‌بندی از کادر دستور جدا شد و فرمان با /usr/local/bin/php آغاز می‌شود.
* نمایش جداگانه پنج فیلد زمان‌بندی (* * * * *) و خود دستور Worker برای جلوگیری از Invalid command.
* Rubika: تغییر روش عملیاتی پیشنهادی از اتکای Webhook به worker واقعی getUpdates؛ مطابق مستندات رسمی و پیاده‌سازی مرجع که polling پیوسته با offset را اجرا می‌کند.
* افزودن CLI worker برای DirectAdmin/cron: هر دقیقه یک پردازش حدود ۵۵ ثانیه‌ای اجرا می‌شود و هر ۵ ثانیه getUpdates را بررسی می‌کند؛ بدون وابستگی به ترافیک سایت یا WP-Cron.
* افزودن قفل flock برای جلوگیری از اجرای هم‌زمان worker و حفظ offset_id/next_offset_id و dedupe موجود.
* نمایش دستور Cron آماده، وضعیت آخرین اجرای worker، تعداد چرخه‌ها و رویدادهای دریافت/پردازش‌شده در تب روبیکا.
* Webhook ReceiveUpdate همچنان به‌صورت اختیاری/تشخیصی حفظ شده است؛ عدم دریافت POST دیگر بات را از کار نمی‌اندازد.

= 3.9.21 =
* بازبینی کامل اتصال روبیکا بر اساس مستندات رسمی botapi/methods/models: ReceiveUpdate برای پیام و ChatKeypad، getUpdates با offset_id/next_offset_id و AskMyPhoneNumber/ContactMessage.
* تغییر Endpoint عملیاتی روبیکا از /wp-json/ به مسیر مستقیم اختصاصی /hamnoor-rubika-hook/{secret}/ برای عبور از محدودیت‌های احتمالی REST/WAF هاست؛ مسیر REST فقط برای سازگاری و تشخیص باقی مانده است.
* پاسخ سریع HTTP 200 به Webhook پیش از ارسال پاسخ Bot API در FastCGI/LiteSpeed، مطابق هشدار مستندات روبیکا درباره توقف موقت تحویل در صورت Endpoint نامعتبر یا کند.
* ثبت دقیق منبع آخرین Webhook (direct path / REST)، زمان پایان پردازش، Content-Type و اندازه payload برای تشخیص قطعی مسیر تحویل.
* کاهش فاصله polling پشتیبان به ۳۰ ثانیه و مهاجرت خودکار برنامه WP-Cron قدیمی یک‌دقیقه‌ای؛ Webhook همچنان مسیر اصلی production است.
* حفظ امنیت اتصال موبایل: ContactMessage فقط در پاسخ به دکمه رسمی AskMyPhoneNumber با button_id اختصاصی هم‌نور پذیرفته می‌شود.

= 3.9.20 =
* Rubika: webhook receiver now parses raw JSON even when Rubika omits or changes Content-Type.
* Rubika: authenticated malformed webhook requests return HTTP 200 and are recovered by polling fallback instead of causing endpoint suspension.
* Rubika: optional one-minute getUpdates fallback enabled by default with message-id deduplication.
* Rubika: added webhook hit/content-type/parse diagnostics.
* Rubika: hardened aux_data/contact_message parsing and throttled duplicate connection prompts.

= 3.9.19 =
* اصلاح پروتکل Rubika getMe دقیقاً مطابق مستندات رسمی: POST بدون body برای متد بدون ورودی.
* حذف rawurlencode از توکن در مسیر Bot API و استفاده از همان رشته توکن پس از اعتبارسنجی امن.
* پاک‌سازی کاراکترهای نامرئی RTL/zero-width از توکن و نمایش تشخیص طول و path-safe بودن بدون افشای توکن.
* حفظ معماری Webhook ReceiveUpdate و تب‌بندی کانال‌های اعلان نسخه قبل.

= 3.9.18 =
* اصلاح اتصال روبیکا بر اساس مستندات رسمی: استفاده از Webhook تولیدی با مسیر تمیز REST و حذف Query Endpoint که می‌توانست INVALID_INPUT ایجاد کند.
* حذف polling خودکار getUpdates در حالت عملیاتی؛ getUpdates فقط ابزار تشخیصی دستی باقی ماند تا با Webhook تداخل نداشته باشد.
* افزودن تست داخلی Endpoint پیش از ثبت در Rubika Bot API و نمایش خطای مرحله‌ای دقیق برای setCommands/updateBotEndpoints.
* ثبت Endpoint عملیاتی فقط با نوع ReceiveUpdate و مسیر امن REST؛ مطابق دکمه‌های ChatKeypad مورد استفاده هم‌نور.
* تب‌بندی صفحه کانال‌های اعلان: نمای کلی، Web Push، ایتا، بله، روبیکا، واتساپ، تلگرام و Push اپلیکیشن.

= 3.9.17 =
* بازطراحی اتصال روبیکا بر اساس مستندات رسمی: Endpoint مستقیم query-based برای ReceiveUpdate به‌جای اتکای اصلی به REST /wp-json.
* پاسخ به هر پیام کاربرِ متصل‌نشده با دکمه رسمی AskMyPhoneNumber؛ دیگر تجربه کاربری به نمایش /start در کلاینت اندروید وابسته نیست.
* افزودن polling پشتیبان با getUpdates و offset_id/next_offset_id طبق مستندات رسمی روبیکا برای مواقعی که Webhook موقتاً متوقف یا مسدود شود.
* اصلاح dedupe: رویداد فقط پس از پردازش موفق علامت‌گذاری می‌شود تا خطای موقت sendMessage باعث از دست رفتن /start نشود.
* ثبت نتیجه آخرین پردازش و خطای ارسال برای عیب‌یابی دقیق‌تر، و حفظ setCommands با نام دستور بدون / طبق مدل BotCommand.

= 3.9.16 =
* Rubika ReceiveUpdate now accepts current Update payloads plus legacy/alternate inline_message envelopes.
* Added payload-shape diagnostics instead of silently ignoring unknown webhook bodies.
* Added manual getUpdates diagnostic/processing button for the latest 10 Rubika updates.
* /start and keypad actions are normalized through the same update processor.

= 3.9.15 =
* افزودن راه‌اندازی کامل بات روبیکا با setCommands و ReceiveUpdate.
* افزودن منوی عملیاتی روبیکا: سهم‌های من، داشبورد، راهنما و قطع اتصال.
* افزودن دستورهای /start، /connect، /parts، /dashboard، /help و /disconnect.
* ثبت آخرین رویداد Webhook برای عیب‌یابی Endpoint.

= 3.9.14 =
* افزودن کانال رسمی روبیکا به هسته اعلان چندکاناله با Bot API v3، Endpoint نوع ReceiveUpdate و اولویت چهارم پس از بله.
* اتصال امن حساب روبیکا به همان پنل هم‌نور با دکمه رسمی AskMyPhoneNumber و ContactMessage؛ بدون OTP برای اتصال اولیه.
* افزودن تنظیمات مدیریتی روبیکا شامل توکن رمز‌شده، getMe، ثبت Endpoint، پیام آزمایشی، لینک بات و شمارش کاربران متصل.
* افزودن پل ورود یک‌بارمصرف از لینک یادآوری روبیکا به سهم/داشبورد؛ GET فقط صفحه واسط noindex را نمایش می‌دهد و نشست با POST خودکار ساخته می‌شود تا پیش‌نمایش لینک پیام‌رسان توکن را مصرف نکند.
* افزودن کارت اتصال/قطع اتصال روبیکا در داشبورد و به‌روزرسانی ترتیب اولویت کانال‌ها.

= 3.9.13 =
* دکمه «ارسال کد تأیید ایجاد ختم» در فرم کاربر دارای نشست به submit واقعی فرم تبدیل شد تا حتی با کش شدن JavaScript قبلی در Bale Web/LiteSpeed عمل کند.

= 3.9.12 =
* اتصال صریح دکمه «ارسال کد تأیید ایجاد ختم» به sendCode در فرم کاربر دارای نشست.
* حفظ نشست فعلی Mini App بله هنگام تأیید OTP ایجاد ختم و اعطای مجوز ایجاد ختم به همان نشست، بدون ساخت نشست مرورگر جداگانه.
* جلوگیری از دوبار ارسال در فرم عمومی با ثبت click handler فقط برای دکمه‌های type=button.

= 3.9.11 =
* رفع عمل‌نکردن دکمه «ثبت پایان قرائت سهم» در Bale Web با حذف window.confirm بومی مرورگر و جایگزینی آن با دیالوگ تأیید داخل خود صفحه هم‌نور.
* ثبت پایان سهم پس از تأیید درون‌صفحه‌ای با همان AJAX موجود انجام می‌شود و پس از موفقیت، همان Mini App با location.replace به داشبورد برمی‌گردد.

= 3.9.10 =
* نگه‌داشتن handoff ورود بله داخل خود Mini App؛ حذف WebApp.openLink از مسیر احراز هویت تا Bale Web مرورگر سیستم را باز نکند.
* افزودن نشست اختصاصی hamnoor_bale_session برای Mini App با SameSite=None و Partitioned (CHIPS) روی HTTPS تا نشست در iframe وب بله پایدار بماند.
* یکپارچه‌سازی تشخیص کاربر و کنترل کش برای نشست عادی سایت و نشست Mini App بله؛ هر دو به همان رکورد کاربر و همان داده‌های هم‌نور متصل‌اند.


= 3.9.9 =
* یکپارچه‌سازی واقعی حساب بله و پنل هم‌نور: اگر شماره تأییدشده بله هنوز پنل نداشته باشد، همان پنل با شماره و نام کاربر به‌صورت خودکار ساخته و به Bale User ID متصل می‌شود؛ دیگر دکمه ساده «رفتن به هم‌نور» به داشبورد مهمان ارسال نمی‌شود.
* اتصال اولیه از داخل Mini App با requestContact رسمی بله انجام می‌شود؛ پس از اعتبارسنجی initData، شماره همان حساب به پنل موجود وصل یا پنل عادی همان‌جا ساخته می‌شود و handoff سشن سایت را ایجاد می‌کند.
* ورود Mini App در نبود توکن بله Fail-Closed شد: به‌جای نمایش/انتقال به داشبورد مهمان، خطای صریح «اتصال بله پیکربندی نشده» نمایش داده می‌شود.
* پیام آزمایشی مدیریت اکنون دکمه web_app تازه «تست ورود به هم‌نور» دارد تا تست نسخه جاری با دکمه‌های قدیمی بله اشتباه نشود.
* OTP همچنان فقط برای عملیات دارای نیاز به تأیید موبایل مانند ایجاد ختم باقی می‌ماند و برای ساخت/ورود پنل از بله لازم نیست.

= 3.9.8 =
* تثبیت ورود از یادآوری بله: پس از اعتبارسنجی initData، handoff در مرورگر داخلی بله به‌صورت top-level باز می‌شود تا کوکی نشست first-party ثبت شود.
* حذف وابستگی handoff به Set-Cookie روی پاسخ 302؛ نشست روی پاسخ HTML 200 ثبت و سپس با JavaScript به مقصد هدایت می‌شود.
* افزودن سازگاری رسمی با LiteSpeed برای کوکی hamnoor_session با vary cookie و no-cache روی نشست‌های خصوصی تا نسخه مهمان داشبورد از کش نمایش داده نشود.
* افزودن پارامتر یک‌بارمصرف cache-buster در اولین ورود بله برای جلوگیری از برخورد با نسخه قدیمی کش‌شده داشبورد.

= 3.9.7 =
* رفع 404 مسیر مینی‌اپ بله با ورودی Query بدون وابستگی به Rewrite.
* ارسال دکمه پیام بله با web_app مستقیم؛ دیگر برای بازشدن پیام به Main Mini App در BotFather وابسته نیست.
* ثبت Rewrite رسمی برای /hamnoor-bale/ و /hamnoor-bale-login/ به‌عنوان مسیر سازگار.
* انتقال handoff ورود بله به مسیر Query پایدار برای جلوگیری از 404 در LiteSpeed/وب‌سرور.


= 3.9.6 =
* سازگاری نشست هم‌نور با اجرای Mini App بله در iframe/WebView با کوکی cross-site امن (SameSite=None فقط برای ورود از بله).
* افزودن پشتیبانی رسمی از Main Mini App بله و لینک‌های startapp برای داشبورد و سهم مشخص.
* خواندن tgWebAppStartParam برای هدایت مستقیم کاربر به همان سهم پس از شناسایی امن حساب بله.
* نمایش آدرس Main Mini App و لینک تست startapp در تنظیمات بله برای پیکربندی @botfather.

= 3.9.5 =
* اصلاح ورود از مینی‌اپ بله با handoff یک‌بارمصرف در ناوبری اصلی؛ نشست دیگر به Set-Cookie داخل fetch وابسته نیست.
* حفظ دائمی اتصال بله پس از اولین اشتراک معتبر شماره؛ خطاهای موقت 404 دیگر کاربر را مجبور به اشتراک دوباره شماره نمی‌کنند.
* بازیابی خودکار اتصال needs_reconnect در اولین تعامل یا ارسال موفق، بدون تغییر منطق سالم sendMessage نسخه 3.9.0.
* هدایت مستقیم از پیام بله به همان سهم پس از ساخت نشست امن.

= 3.9.0 =
* اتصال واقعی بله بر اساس API رسمی بازو، webhook، request_contact و sendMessage.
* تطبیق شماره موبایل دریافت‌شده از بله با پنل هم‌نور بدون OTP.
* ارسال یادآوری خصوصی بله با دکمه ادامه همان سهم و اتصال به Dispatcher پس از Web Push و ایتا.
* مدیریت امن توکن، getMe، تنظیم/بررسی webhook و قطع اتصال کاربر.

= 3.8.1 =
* ورود به داشبورد و ادامه قرائت با تطابق شماره موبایل، بدون OTP.
* OTP فقط در مسیر ایجاد ختم جدید الزامی است و مجوز آن موقت/مصرف‌شونده است.
* لینک یادآوری ایتا برنامک را با start_param رسمی باز می‌کند و پس از SSO مستقیم به سهم مربوط می‌رود.

= 3.8.0 =
* بازطراحی کامل اتصال ایتا بر اساس مستندات رسمی developer.eitaa.com.
* بارگذاری SDK رسمی eitaa-web-app.js و استفاده از window.Eitaa.WebApp.initData.
* اعتبارسنجی سروری initData با HMAC-SHA256 و کلید WebAppData و کنترل auth_date.
* حذف فرایند آزمایشی کد خصوصی ایتا + OTP موبایل و جایگزینی با requestWriteAccess و requestContact رسمی.
* اعتبارسنجی پاسخ requestContact با امضای برنامه و تطبیق امن شماره موبایل با پنل هم‌نور.
* ورود خودکار امن از برنامک برای حساب‌های ایتای قبلاً متصل‌شده.
* ارسال خصوصی اعلان از endpoint رسمی https://eitaayar.ir/api/app/sendMessage با توکن برنامه در بدنه POST.
* اثبات عملی اجازه پیام با ارسال موفق API برنامه پیش از فعال‌شدن کانال ایتا.
* حفظ API عمومی ایتایار فقط برای کانال/گروه و جداسازی کامل آن از API برنامه.

= 3.7.6 =
* جداسازی کامل توکن عمومی ایتایار از توکن اختصاصی «برنامه» ایتا.
* حفظ خودکار توکن عمومی نسخه‌های قبلی به‌عنوان API عمومی و عدم استفاده از آن برای اعلان خصوصی.
* پذیرش امن توکن برنامه با کاراکترهای ویژه؛ حذف Regex حدسی و بررسی فقط طول و نبود فاصله/کاراکتر کنترلی.
* استفاده انحصاری از توکن برنامه برای پیام خصوصی کاربر، Dispatcher ایتا و آزمون امضای tgWebAppData.
* افزودن تست مستقل getMe برای API اختصاصی برنامه و وضعیت سلامت جداگانه در مدیریت.
* رمزگذاری مستقل هر دو توکن با AES-256-GCM و عدم نمایش مجدد مقدار ذخیره‌شده.

= 3.7.5 =
* اتصال خصوصی ایتا با کد یک‌بارمصرف ارسال‌شده به شناسه کاربر داخل برنامک.
* اتصال نهایی فقط پس از OTP موبایل حساب هم‌نور؛ SSO ایتا همچنان غیرفعال است.
* ذخیره external_id ایتا فقط پس از اثبات دوگانه و اتصال آن به Dispatcher چندکاناله.
* محدودسازی نرخ، انقضای چالش، سقف تلاش ناموفق و جلوگیری از اتصال یک حساب ایتا به چند پنل.


= 3.7.4 =
* افزودن بررسی امن tgWebAppData برنامک ایتا در سمت سرور بدون ذخیره payload خام.
* نمایش شکل فیلدهای launch data و تازگی auth_date در گزارش مدیریت.
* آزمون HMAC سازگار با WebAppData با توکن API فعلی فقط برای تشخیص کلید امضا؛ بدون ایجاد نشست یا اتصال حساب.

= 3.7.3 =
* رفع 404 پل ایتا با تشخیص مستقیم REQUEST_URI حتی بدون Rewrite فعال.
* افزودن URL پشتیبان Query String برای اجرای قطعی پل در برنامک ایتا.
* ثبت و Flush صریح Rewrite پل ایتا هنگام فعال‌سازی و ارتقا.
= 3.7.2 =
* افزودن پل تشخیصی برنامک ایتا در /hamnoor-eitaa/ برای مرحله ورود یکپارچه.
* ثبت گزارش حریم‌خصوصی‌محور از شکل launch data بدون ذخیره توکن، هش یا شناسه خام کاربر.
* آماده‌سازی مرحله اتصال برنامک هم‌نور زیر برنامه والد ایتا.


= 3.7.1 =
* آغاز اتصال واقعی ایتا با Adapter رسمی هسته چندکاناله.
* افزودن تنظیم امن توکن EitaaYar، تست getMe و ارسال پیام آزمایشی از مدیریت وردپرس.
* توکن ایتا با AES-256-GCM و کلید مشتق‌شده از Saltهای وردپرس ذخیره می‌شود و مجدداً نمایش داده نمی‌شود.
* اتصال خودکار کاربر هم‌نور به شناسه ایتا عمداً به مرحله بعد موکول شده تا ابتدا Transport روی هاست واقعی تأیید شود.

= 3.7.0 =
* هسته اعلان چندکاناله با اولویت Web Push، ایتا، بله، واتساپ، تلگرام و Push اپلیکیشن.
* Web Push واقعی و مستقل با VAPID، رمزنگاری aes128gcm و Service Worker در ریشه دامنه.
* فعال‌سازی، غیرفعال‌سازی و ارسال اعلان آزمایشی از داشبورد کاربر.
* ارسال خودکار یادآوری سهم ناتمام به Web Push و توقف پس از نخستین کانال بیرونی موفق.
* ثبت اشتراک‌های چنددستگاهی و گزارش نتیجه ارسال در دیتابیس.
* صفحه مدیریتی «کانال‌های اعلان» برای سلامت Web Push و اولویت کانال‌ها.
* آماده‌سازی Adapterهای آینده برای ایتا و بله بدون وابستگی مستقیم هسته به API آن‌ها.

= 3.6.0 =
* مرکز پیشرفت ختم برای بانی با وضعیت سهم‌ها، درصد پیشرفت و آخرین فعالیت.
* یادآوری داخلی خودکار و دستی برای سهم‌های ناتمام با محدودیت مزاحمت.
* برنامه قرائت چندروزه برای هر سهم و نمایش هدف روز جاری.
* بازیابی سهم‌ها و پنل در دستگاه جدید با تطابق موبایل؛ OTP برای ورود حذف شده است.

= 3.5.4 =
* قرارگرفتن پنل تنظیمات و پس‌زمینه آن بالاتر از لایه مطالعه بدون مزاحمت در همه مرورگرها.
* افزودن دکمه مستقل و همیشه‌در‌دسترس برای خروج از حالت مطالعه بدون مزاحمت.
* جلوگیری از پنهان‌شدن منوی تنظیمات پشت لایه قرائت در موبایل.

= 3.5.3 =
* نمایش منوی تنظیمات مطالعه در یک پنل واقعی متصل به viewport برای جلوگیری از بازشدن خارج از دید در موبایل.
* افزودن پس‌زمینه و محاسبه موقعیت پنل با Visual Viewport.
* افزودن دکمه همیشگی و فشرده بازگشت به داشبورد در نوار ثابت قرائت.

= 3.5.2 =
* Fix: keep the study settings panel fully inside the desktop viewport.
* Fix: open study settings as a stable bottom sheet on mobile without native details scrolling.
* Fix: remove the temporary “saving recitation” message after successful printed-Quran completion.

= 3.5.1 =
* بازطراحی فشرده کنترل‌های صفحه قرائت در موبایل و آزادشدن فضای بیشتر برای متن قرآن.
* نمایش دکمه پایان سهم فقط پس از رسیدن واقعی به انتهای سهم.
* تبدیل تنظیمات مطالعه به پنل مینیمال با بسته‌شدن خودکار پس از اعمال تنظیم.
* حذف کامل حالت Fullscreen مرورگر و تثبیت حالت «مطالعه بدون مزاحمت» با اسکرول پایدار.
* جمع‌وجورشدن پلیر صوت در موبایل و حفظ کنترل‌های اصلی پخش.
* حالت بدون مزاحمت دیگر به‌صورت ناخواسته در مراجعه بعدی فعال نمی‌ماند.

= 3.5.0 =
* تنظیمات مطالعه: ترجمه، اندازه متن، حالت تاریک، اسکرول خودکار و حالت بدون مزاحمت.
* اصلاح قرارگیری آیه جدید دقیقاً زیر نوار ثابت و جلوگیری از پوشانده‌شدن متن توسط پلیر.
* حذف مرحله اضافی OTP و ارسال خودکار کد در همان فرم آغاز ایجاد ختم.
* ایجاد نشست فقط پس از تأیید موفق کد پیامکی برای کاربر بدون نشست.


= 3.4.2 =
* نمایش کامل و غیرقابل‌ویرایش شماره موبایل کاربر شناسایی‌شده در انتخاب سهم.
* اصلاح متن گزینه قرائت خارج از سامانه به «با قرآن چاپی خواندم/می‌خوانم».
* بازخورد پایدار و واضح روی دکمه «تا اینجا خواندم»، همراه با حالت خطا و برجسته‌سازی آیه.
* جلوگیری از عقب‌رفتن ناخواسته محل قرائت با کلیک روی آیات قبلی.
* بازطراحی واکنش‌گرای کارت‌های مشارکت، حداکثر سه ستون و نمایش فشرده سهم‌های تکمیل‌شده.
* مرتب‌سازی سهم‌ها بر اساس نیاز به اقدام کاربر.

= 3.4.1 =
* حذف کامل فیلدهای نام و موبایل از پنجره انتخاب سهم برای کاربر دارای نشست.
* شناسایی زنده و بدون کش نشست هنگام بازشدن پنجره انتخاب سهم.
* نمایش نام و شماره ماسک‌شده حساب به‌صورت غیرقابل‌ویرایش.
* نمایش فیلدهای نام و موبایل فقط برای کاربر بدون نشست.
* اتصال سمت سرور سهم به شناسه، نام و شماره حساب فعلی و مقاومت در برابر دستکاری درخواست.
* جلوگیری از درج اطلاعات شخصی نشست در HTML قابل کش صفحه ختم.

= 3.4.0 =
* افزودن صوت آیه‌به‌آیه استاد پرهیزکار از سایت مناهل با پخش پیوسته، تکرار، Wake Lock و ذخیره محل صوت.
* لینک پنج‌کاراکتری ختم مستقیماً از ریشه دامنه.
* حذف کامل مسیر خروج از قرآن داخلی و تنظیمات مرتبط.
* نمایش دکمه شناور ادامه قرائت برای نشست‌های دارای سهم ناتمام در تمام صفحات سایت.

= 3.3.0 =
* اتصال رسمی هم‌نور به افزونه Manahel Quran Core.
* افزودن قرآن داخلی برای سهم‌های صفحه، جزء و سوره.
* ذخیره آخرین آیه خوانده‌شده و ادامه خودکار از محل قبلی.
* نمایش نوار و درصد پیشرفت قرائت در داشبورد.
* افزودن دکمه «تا اینجا خواندم» برای هر آیه.
* فعال‌شدن ثبت پایان سهم پس از رسیدن به آخرین آیه.
* حفظ گزینه «با قرآن چاپی خواندم/می‌خوانم» برای کاربران خارج از قرآن داخلی.
* حذف پیشرفت ذخیره‌شده هنگام آزادشدن سهم برای جلوگیری از انتقال سابقه به مشارکت‌کننده بعدی.
* ایجاد جدول مستقل hamnoor_reading_progress بدون تغییر در جداول متن قرآن.

= 3.2.3 =
* قفل شماره موبایل کاربر دارای نشست در تمام مسیرهای رزرو سهم، شامل فرم، AJAX و REST API.
* استفاده اجباری از شناسه و شماره موجود در پنل فعال به‌عنوان منبع معتبر سمت سرور.
* جلوگیری از تغییر هویت پنل با دستکاری فیلدهای فرم یا درخواست‌های مستقیم.
* حذف دقیق‌تر برگه‌های قدیمی «ورود و ثبت‌نام» و شورت‌کدهای منسوخ هم‌نور.
* تبدیل شماره موبایل پروفایل به مقدار فقط‌خواندنی و افزودن فرایند مستقل تغییر شماره با OTP.
* تأیید شماره جدید پیش از تغییر، همگام‌سازی سوابق وابسته و بستن نشست‌های دیگر پس از تغییر شماره.

= 3.2.2 =
* رفع خطای قطعی Undefined constant WP_POST_REVISIONS هنگام ارتقای افزونه.
* انتقال مهاجرت دیتابیس و ساخت/به‌روزرسانی برگه‌ها از plugins_loaded به init.
* افزودن محافظ دوم برای تعویق همگام‌سازی برگه‌ها تا آماده‌شدن کامل هسته وردپرس.
* حفظ گزارش خطای کنترل‌شده برای مدیر بدون متوقف‌کردن کل سایت.

= 3.2.1 =
* رفع خطای کشنده احتمالی در هاست‌های فاقد افزونه PHP mbstring با جایگزین داخلی سازگار.
* افزودن محافظ راه‌اندازی و فعال‌سازی تا خطای ارتقا باعث از دسترس خارج شدن کل سایت نشود.
* سازگارتر کردن آماده‌سازی کوئری اعلان‌ها و محدود کردن خوانده‌شدن به اعلان‌های مهم.

= 3.2.0 =
* بازگرداندن برگه مستقل و بزرگ «ایجاد ختم جدید».
* حذف تب ایجاد ختم از داشبورد و اتصال دکمه بالای داشبورد به برگه مستقل.
* نمایش داشبورد بدون فرم ورود یا دریافت موبایل؛ پنل شخصی پس از مشارکت یا آغاز ایجاد ختم فعال می‌شود.
* تبدیل یادآوری تأیید موبایل به متن کوچک و پنجره AJAX.
* محدود کردن اعلان‌های کاربر به رویدادهای مهم و صفر شدن شمارنده پس از مشاهده.
* ثبت اعلان مهم هنگام تغییر شماره موبایل و لغو تأیید شماره قبلی.


= 3.1.0 =
* حذف برگه و مفهوم جداگانه ورود و ثبت‌نام از فرایند کاربر.
* افزودن کاربران و نشست‌های اختصاصی هم‌نور، مستقل از wp_users.
* رفع کامل مشکل دکمه مشاهده پنل با AJAX و مسیر POST پشتیبان.
* جلوگیری از نمایش شماره موبایل در URL و ایجاد کوکی نشست امن.
* ایجاد خودکار پنل فقط هنگام مشارکت یا آغاز ایجاد ختم.
* یکپارچه‌سازی داشبورد مشارکت‌کننده و سازنده ختم.
* حذف اطلاعات تماس غیرموبایلی از فرایندها، API، جداول نصب تازه و خروجی‌ها.
* ساده‌سازی فرم ایجاد ختم و دریافت مشخصات سازنده از پروفایل همان پنل.
* حذف خودکار برگه‌های قدیمی افزونه و نگهداری فقط فهرست ختم‌ها و داشبورد.
* حفظ الزام OTP برای ایجاد و مدیریت ختم، بدون الزام آن برای مشارکت.

= 3.0.0 =
* یکپارچه‌سازی اولیه داشبورد و جریان تأیید موبایل.

= 2.9.0 =
* افزودن پنل دسترسی ساده با شماره موبایل و تأیید اختیاری موبایل.

= 3.9.25 =
* Rubika: افزودن بازیابی خودکار getUpdates بدون offset در صورت خالی بودن پاسخ cursor-based، با اتکا به dedupe پیام‌ها.
* افزودن نمایش cursor فعلی، cursor استفاده‌شده، next_offset_id و وضعیت بازیابی برای عیب‌یابی قطعی polling.
* افزودن دکمه «پاک‌کردن Cursor getUpdates» برای شروع امن polling بدون offset.
