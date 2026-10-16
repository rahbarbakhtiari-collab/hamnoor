# Mafatih Reference Implementation Evidence

## مرجع بررسی‌شده

- نسخه واقعی موجود در Library: Manahel Mafatih Online `3.3.1`
- مسیر بسته: `C:\Users\ASUS\Downloads\Compressed\manahel-mafatih-online\manahel-mafatih-online`
- فایل نسخه: `manahel-mafatih-online.php`

## فایل‌های مرجع

- `includes/class-mmo-audio-source.php`: کشف فهرست رسمی erfan.ir، allowlist، probe و انتقال
- `includes/class-mmo-id3.php`: متادیتای MP3 و حذف artwork
- `includes/class-mmo-db.php`: مدل remote/local، قاری، scope، segment و layout_order
- `includes/class-mmo-frontend.php`, `templates/article.php`, `templates/single-mmo-prayer.php`: جریان چیدمان و پخش
- `assets/js/audio-source-admin.js`, `assets/js/frontend.js`: مدیریت منبع و player مشترک

## رفتارهای اقتباس‌شده

- خواندن فقط select رسمی `#js_TogglePlayList` با DOM و fallback محدود regex
- ساخت URL رسمی `file.erfan.ir/files/sound/mafatih/...mp3?seed=10`
- HTTPS و allowlist میزبان‌های `erfan.ir`، `www.erfan.ir` و `file.erfan.ir`
- probe با HEAD و GET محدود Range، کنترل status، MIME، اندازه و امضای باینری
- سقف پیش‌فرض قابل‌فیلتر ۲۰۰ MiB مطابق تنظیم پیش‌فرض 3.3.1
- نگه‌داری هم‌زمان remote provenance و attachment محلی با اولویت playback محلی
- جلوگیری از duplication با `source_hash` و استفاده مجدد از attachment معتبر
- انتقال انتخابی با `download_url()` و `media_handle_sideload()`؛ بدون mass import
- مدل قاری، full/segment، segment_key و layout_order در payload فعلی Content Registry
- player واحد با انتخاب چند قاری و توقف player قبلی

## تفاوت‌های آگاهانه

- هیچ وابستگی runtime به افزونه مفاتیح ساخته نشد.
- جدول جدید ایجاد نشد؛ metadata صوت داخل payload همان Content Registry نگه‌داری می‌شود.
- sitemap، جست‌وجو، SEO، تگ، accordion، Quran-link scanner و ویرایشگر عمومی مفاتیح کپی نشدند.
- URL و جزئیات ذخیره‌سازی برای شرکت‌کننده نمایش داده نمی‌شود؛ provenance فقط در مدیریت موجود است.
