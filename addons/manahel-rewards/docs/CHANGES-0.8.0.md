# Manahel Rewards 0.8.0 — Redemption Foundation

## هدف
کاربر بتواند امتیاز کسب‌شده را به جایزه تبدیل کند، بدون تغییر منبع حقیقت مالی (Ledger).

## قابلیت‌ها
- کاتالوگ جوایز قابل مدیریت: عنوان، توضیح، هزینه امتیازی، موجودی، سقف هر کاربر، روش تحویل، ترتیب، فعال/غیرفعال.
- شرط تأیید هویت اختیاری و جداگانه برای هر جایزه؛ پیش‌فرض بدون OTP.
- فیلد اختیاری اطلاعات کاربر برای مواردی مثل شماره موبایل شارژ یا نشانی پستی.
- ثبت Redemption اتمیک: Lock حساب + Lock جایزه + کسر Ledger + کاهش balance + رزرو موجودی در یک تراکنش InnoDB.
- جلوگیری از دوباره‌خرج‌کردن با idempotency key.
- تحویل دستی/کد/محصول فیزیکی.
- لغو/بازپرداخت با Ledger مثبت مستقل؛ Debit تاریخی هرگز حذف یا بازنویسی نمی‌شود.
- نمایش جوایز و درخواست‌های کاربر در صفحه «امتیازهای من».
- Hooks برای اتصال آینده WooCommerce / فروشگاه مناهل بدون coupling در هسته.

## Schema
DB version: 1.2.0
Tables added:
- `wp_manahel_reward_catalog`
- `wp_manahel_reward_redemptions`

## قرارداد مالی
Earn: `+N`
Redeem: `-N` (`entry_type=redeem`)
Refund: `+N` (`entry_type=refund`, linked to debit)
`account.balance == SUM(ledger.delta)` remains invariant.
