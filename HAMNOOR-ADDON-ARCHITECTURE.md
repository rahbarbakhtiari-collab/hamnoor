# معماری ادان‌های هم‌نور ۲.۶.۰

## اصل طراحی

هسته فقط موجودیت‌ها و workflow پایه ختم را مالک است. هر قابلیت provider-specific یا تجاری—پیامک، درگاه، QR پیشرفته، reminder، analytics و template pack—باید افزونه وردپرس جدا باشد. ادان می‌تواند به API عمومی هم‌نور وصل شود، اما نباید فایل هسته را include/patch یا جدول هسته را تغییر دهد.

## bootstrap ادان

هسته پس از راه‌اندازی کلاس‌ها این action را اجرا می‌کند:

```php
add_action('hamnoor_addons_loaded', 'my_hamnoor_addon_boot');
```

ادان باید وجود کلاس/نسخه را بررسی کند:

```php
function my_hamnoor_addon_boot() {
    if (!defined('HAMNOOR_VERSION') || version_compare(HAMNOOR_VERSION, '2.6.0', '<')) {
        return;
    }
    // Register providers and hooks here.
}
```

هیچ hard dependency از هسته به ادان وجود ندارد.

## قرارداد اعلان و پیامک

فایل `includes/interface-hamnoor-notification-provider.php` قرارداد زیر را تعریف می‌کند:

```php
interface Hamnoor_Notification_Provider_Interface {
    public function supports($type);
    public function send($type, $recipient, $message, $context = array());
}
```

- `supports()` باید برای channelهایی مانند `sms`, `email`, `in_app` مقدار bool بدهد.
- `send()` باید در موفقیت `true` و در شکست `WP_Error` برگرداند.
- provider نباید exception کنترل‌نشده به هسته نشت دهد.
- credential و log تحویل متعلق به ادان است و نباید در option عمومی هم‌نور ذخیره شود.

### ثبت provider

```php
final class My_Hamnoor_SMS implements Hamnoor_Notification_Provider_Interface {
    public function supports($type) {
        return 'sms' === $type;
    }

    public function send($type, $recipient, $message, $context = array()) {
        // Call provider API, redact secrets from logs, return true or WP_Error.
        return true;
    }
}

add_action('hamnoor_addons_loaded', function () {
    Hamnoor_Addons::register_notification_provider('my_sms', new My_Hamnoor_SMS());
});
```

### dispatch

```php
$result = Hamnoor_Notification_Service::send(
    'sms',
    '09123456789',
    'پیام نمونه',
    array('event' => 'claim', 'khatm_id' => 42, 'part_id' => 501)
);
```

هسته به‌ترتیب این مسیرها را پشتیبانی می‌کند:

1. action مشاهده‌ای `hamnoor_notification_requested`؛
2. filter کوتاه‌کننده `hamnoor_send_notification`؛ اگر خروجی آن `null` نباشد همان نتیجه برگردانده می‌شود؛
3. registry فیلترشده `hamnoor_notification_providers`؛
4. سازگاری با filter قدیمی `hamnoor_sms_provider`؛
5. `WP_Error` استاندارد وقتی provider وجود ندارد.

هسته در این نسخه خودکار پیامک نمی‌فرستد؛ ادان reminder/invitation باید زمان dispatch را از hookهای lifecycle انتخاب کند.

## hookهای جدید چرخه ختم

| hook | نوع | آرگومان‌ها | زمان اجرا |
|---|---|---|---|
| `hamnoor_before_create_khatm` | action | `$data` | پس از validation و قبل از transaction |
| `hamnoor_after_create_khatm` | action | `$khatm_id, $khatm, $data` | پس از commit |
| `hamnoor_before_update_khatm` | action | `$khatm_id, $fields, $old_khatm` | قبل از update |
| `hamnoor_after_update_khatm` | action | `$khatm_id, $new_khatm, $old_khatm, $fields` | پس از update موفق |
| `hamnoor_after_khatm_completed` | action | `$khatm_id, $khatm` | transition واقعی به completed |
| `hamnoor_after_khatm_archived` | action | `$khatm_id, $khatm` | transition واقعی به archived |
| `hamnoor_after_invitation_created` | action | `$khatm_id, $invite_url, $khatm` | پس از ایجاد موفق ختم و URL دعوت |

Actionهای `before_*` برای مشاهده/هماهنگی‌اند، نه تغییر مستقیم آرگومان. اگر ادان نیاز به داده سفارشی دارد باید آن را در جدول خودش ذخیره کند یا از API ورودی خودش استفاده کند.

## hookهای جدید چرخه سهم

| hook | آرگومان‌ها | توضیح |
|---|---|---|
| `hamnoor_before_claim_part` | `$part_id, $part, $data, $khatm` | قبل از transaction رزرو |
| `hamnoor_after_claim_part` | `$part_id, $participant_id, $khatm_id, $data` | پس از commit رزرو |
| `hamnoor_before_complete_part` | `$part_id, $part, $context` | پیش از ثبت دستی done |
| `hamnoor_after_complete_part` | `$part_id, $participant_id, $khatm_id, $context` | پس از ثبت done؛ در auto-done نیز با `source=auto_done_on_claim` اجرا می‌شود |
| `hamnoor_before_release_part` | `$part_id, $part, $reason, $user_id` | پس از permission check و قبل از update |
| `hamnoor_after_release_part` | `$part_id, $khatm_id, $old_participant_id, $reason, $user_id` | پس از آزادسازی موفق |

ادان reminder باید job قبلی را در `after_complete` و `after_release` لغو کند. اجرای عملیات شبکه‌ای سنگین داخل transaction یا hookهای `before_*` ممنوع است.

## hookهای participant

| hook | آرگومان‌ها | نکته |
|---|---|---|
| `hamnoor_after_participant_created` | `$participant_id, $khatm_id, $data` | فقط برای insert واقعی و پس از commit |
| `hamnoor_after_participant_linked_to_user` | `$participant_id, $user_id, $context` | برای اتصال مستقیم؛ در sync گروهی، participant id برابر صفر و `context['count']` تعداد رکوردها است |

## hookهای خروجی و گزارش

| filter/action | آرگومان‌ها | مسئولیت |
|---|---|---|
| `hamnoor_export_columns` | `$columns, $include_contacts, $user_id` | افزودن/تغییر ستون‌های CSV/XLSX پایه |
| `hamnoor_export_rows` | `$row, $part, $include_contacts, $user_id` | تغییر یک ردیف؛ نام برای سازگاری با درخواست عمومی حفظ شده است |
| `hamnoor_report_data` | `$rows, $khatm_id, $user_id` | داده کامل گزارش in-memory در `rows_for_khatm()` |
| `hamnoor_export_downloaded` | `$khatm_id, $user_id, $format` | audit بعد از درخواست دانلود |
| `hamnoor_can_export_khatm` | `$allowed, $khatm_id, $user_id` | توسعه policy خروجی |

filterهای قدیمی `hamnoor_participant_export_row` و `hamnoor_participant_export_fields` حفظ شده‌اند. ادان باید تعداد ستون و cellهای هر ردیف را هماهنگ نگه دارد. برای dataset بزرگ، از hook دانلود برای queue کردن گزارش ادان استفاده شود؛ `hamnoor_report_data` ذاتاً in-memory است و برای analytics حجیم توصیه نمی‌شود.

## hookهای ظاهر و template

| filter | آرگومان‌ها | کاربرد |
|---|---|---|
| `hamnoor_template_path` | `$located_path, $relative_template` | override مسیر template پس از اولویت قالب فعال |
| `hamnoor_khatm_card_data` | `$data, $khatm` | تغییر object/progress کارت ختم |
| `hamnoor_part_card_data` | `$data, $part, $khatm` | تغییر داده کارت سهم |
| `hamnoor_visual_mode` | `$mode, $settings` | انتخاب یکی از `theme_aware`, `hamnoor`, `minimal` |

مسیر برگشتی `hamnoor_template_path` باید فایل موجود باشد؛ در غیر این صورت renderer آن را رد می‌کند. CSS ادان باید زیر `.hamnoor-wrap` یا `.hamnoor-modal` scope شود.

## hookهای سازگاری موجود

این hookها از نسخه‌های قبل حفظ شده‌اند:

- `hamnoor_khatm_created`
- `hamnoor_khatm_completed`
- `hamnoor_part_claimed`
- `hamnoor_part_completed`
- `hamnoor_part_released`
- `hamnoor_part_completion_verified`
- `hamnoor_track_contact_verified`
- `hamnoor_otp_created`
- `hamnoor_otp_requested`
- `hamnoor_parts_generation_hard_limit`
- `hamnoor_parts_batch_size`
- `hamnoor_android_api_enabled`

برای کد جدید، hookهای `after_*` ترجیح دارند؛ hookهای قدیمی حذف نشده‌اند.

## قرارداد پرداخت

پرداخت در هسته وجود ندارد و هیچ hook پرداختی از هسته به‌صورت مصنوعی اجرا نمی‌شود، زیرا lifecycle واقعی برای آن تعریف نشده است. ادان پرداخت باید:

1. جدول transaction خودش را با `khatm_id`, `user_id`, amount, currency, gateway reference و status بسازد؛
2. permission و nonce/REST authentication خودش را کنترل کند؛
3. redirect/callback را در endpointهای خود مدیریت کند؛
4. بعد از موفقیت فقط از serviceهای عمومی هم‌نور یا status hookهای موجود استفاده کند؛
5. در API عمومی contact یا credential درگاه را برنگرداند.

نام‌های توصیه‌شده برای eventهای داخل خود ادان عبارت‌اند از:

- `hamnoor_payment_required`
- `hamnoor_before_payment_redirect`
- `hamnoor_after_payment_success`
- `hamnoor_after_payment_failed`

این نام‌ها در ۲.۶.۰ «قرارداد پیشنهادی ادان» هستند، نه eventهایی که هسته فعلی fire کند. افزودن payment columns یا gateway setting به هسته عمداً انجام نشد.

## قرارداد reminder

هسته scheduler یادآوری ندارد. ادان reminder باید:

- در `hamnoor_after_claim_part` موعد خود را بسازد؛
- در complete/release آن را لغو کند؛
- پیش از ارسال، وضعیت جاری سهم را دوباره از service بخواند؛
- event پیشنهادی `hamnoor_after_reminder_due` را در خود ادان هنگام due شدن fire کند؛
- ارسال را از `Hamnoor_Notification_Service::send()` عبور دهد.

## REST و integration

REST namespace هسته `hamnoor/v1` است. ادان نباید endpoint موجود را monkey patch کند؛ namespace پیشنهادی آن مانند `hamnoor-myaddon/v1` است. endpointهای مدیریتی باید `Hamnoor_DB::user_can_manage_khatm()` یا capability دقیق‌تر خود ادان را به‌عنوان permission callback استفاده کنند.

## مالکیت قابلیت‌های آینده

| قابلیت | محل صحیح |
|---|---|
| provider IPPanel و credential | ادان SMS |
| queue/retry/delivery log | ادان notification |
| OTP login مستقل | ادان authentication |
| payment/nazr/WooCommerce | ادان payment |
| QR styling/download | ادان QR |
| reminder schedule | ادان reminder |
| dashboard آماری و گزارش چند-sheet | ادان analytics/report |
| templateهای مناسبتی | ادان template pack |
| اتصال پیام‌رسان | ادان channel provider |

## موارد ممنوع برای ادان

- نوشتن مستقیم روی `status`, `participant_id`, `done_at` بدون service/repository هسته؛
- ذخیره API key در جدول/setting عمومی هم‌نور؛
- تغییر فایل‌های template/CSS هسته؛
- فرض ثابت بودن prefix دیتابیس؛
- خروجی تماس شرکت‌کننده بدون permission؛
- ارسال شبکه‌ای synchronous در transactionهای claim/create؛
- تعریف مجدد کلاس‌ها یا hookهای هسته.

## نتیجه

چارچوب ۲.۶.۰ برای ادان‌های اعلان، پیامک، reminder، گزارش و template بدون بازنویسی هسته کافی است. پرداخت و OTP login باید lifecycle و storage مستقل خود را داشته باشند. افزودن registry عمومی برای هر نوع ادان در این مرحله ضروری نیست؛ provider registry اعلان و lifecycle hooks فعلی سطح abstraction لازم نسخه پایه را فراهم می‌کنند.
