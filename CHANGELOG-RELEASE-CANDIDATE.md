# تغییرات واقعی نسخه ۲.۶.۰ Release Candidate

- اصلاح اعطای ناخواسته نقش `hamnoor_manager` هنگام ثبت‌نام؛ نقش فقط با فعال بودن تنظیم مربوط اعطا می‌شود.
- افزایش طول توکن ختم‌های جدید از ۶ به ۱۲ کاراکتر، با حفظ پشتیبانی routeهای قدیمی.
- افزودن rate limit به ثبت‌نام، رزرو عمومی AJAX/REST، پیگیری تماس‌محور، ثبت قرائت و آزادسازی عمومی.
- جلوگیری از ایمیل/موبایل تکراری در ویرایش حساب، مدیریت خطای `wp_update_user()` و جلوگیری از موفقیت کاذب.
- اصلاح query آزادسازی خودکار برای انتخاب مستقیم سهم‌های واقعاً موعددار و جلوگیری از عقب‌ماندن رکوردها خارج از batch.
- افزودن indexهای ضروری برای فهرست عمومی، داشبورد مالک، lookup participant، سهم‌ها، cron، لاگ و cleanup OTP.
- idempotent شدن transition خودکار ختم به وضعیت تکمیل.
- اصلاح تشخیص insert واقعی participant و افزودن رویدادهای lifecycle پس از commit.
- تکمیل پاک‌سازی کنترل‌شده uninstall برای cron، برگه‌های خودکار، role/capability، option، user meta و transientهای هم‌نور.
- افزودن interface و registry سبک provider اعلان، با حفظ سازگاری `hamnoor_sms_provider`.
- افزودن hookهای چرخه ایجاد/ویرایش ختم، claim/complete/release سهم، participant، invitation، report/export، template، card data و visual mode.
- حذف برگه‌های ورود و ثبت‌نام هم‌نور از sitemap برگه‌های وردپرس، همسو با noindex موجود.
- بروزرسانی نسخه افزونه و دیتابیس به `2.6.0` و Stable tag فایل readme.

هیچ فایل CSS یا JavaScript تغییر نکرده است.
