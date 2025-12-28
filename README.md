# 📋 PasteLink

> اسکریپت تحت وب اشتراک‌گذاری متن با رمزگذاری پیشرفته و ویژگی‌های چندزبانه v3

> ⚠️ **سلب مسئولیت:** این مخزن یک محیط آزمایشی (**Experimental Sandbox**) برای ارزیابی قابلیت‌های AI است و به عنوان یک ابزار کاربردی یا محصول نهایی در نظر گرفته نمی‌شود.

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

## ✨ ویژگی‌ها

### v3.0 - نسخه پیشرفته
- 🌍 **پشتیبانی دوزبانه** (فارسی/انگلیسی) با Language Class
- ⏰ **لینک‌های با زمان انقضا** (expires_at) - تنظیم زمان محدود برای لینک‌ها
- 👁️ **محدودیت بازدید** (view_limit) - محدود کردن تعداد بازدیدها
- 🔥 **لینک‌های یک‌بار مصرف** - حذف خودکار پس از اولین بازدید
- 🛡️ **امنیت پیشرفته** با Security Class (CSRF, Security Headers, Sanitization)
- ⚡ **کش در حافظه** با Cache Class برای افزایش سرعت
- 🗃️ **TextHandler Class** برای مدیریت کامل متن‌ها با بررسی انقضا و محدودیت
- 🔐 **رمزگذاری سمت کاربر** با AES-256
- 🎨 **طراحی Glassmorphism** مدرن و زیبا
- 🌓 **حالت تاریک/روشن** خودکار
- 📱 **Responsive** کامل
- 🗑️ **پاکسازی خودکار** متن‌های قدیمی و منقضی

## 🚀 نصب سریع

### 1. دانلود

**دانلود ZIP:**

[📥 دانلود PasteLink v3.0](https://github.com/Ho3einK84/PasteLink/archive/refs/tags/v3.0.zip)

### 2. تنظیمات دیتابیس

ویرایش `config.php`:

```php
const DB_CONFIG = [
    'host' => 'localhost',
    'name' => 'pastelink',
    'user' => 'your_username',
    'pass' => 'your_password',
];
```

### 3. ایجاد جدول

```sql
CREATE TABLE IF NOT EXISTS `texts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(10) UNIQUE NOT NULL,
    `content` LONGTEXT NOT NULL,
    `views` INT UNSIGNED DEFAULT 0,
    `view_limit` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME DEFAULT NULL,
    `ip_address` VARCHAR(45),
    `is_encrypted` TINYINT(1) DEFAULT 0,
    INDEX `idx_code` (`code`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_expires_at` (`expires_at`),
    INDEX `idx_view_limit` (`view_limit`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4. تنظیم رمز ادمین

```php
<?php echo password_hash("your_password", PASSWORD_DEFAULT); ?>
```

در `config.php`:

```php
const ADMIN_HASH = '$2y$10$your_generated_hash';
```

## 📖 استفاده

| صفحه | آدرس |
|------|------|
| ایجاد متن | `https://domain.com/` |
| مشاهده متن | `https://domain.com/CodE12` |
| پنل ادمین | `https://domain.com/admin.php` |

## 🔐 رمزگذاری

1. متن را وارد کنید
2. "فعال‌سازی رمز عبور" را بزنید
3. رمز تعیین کنید
4. لینک را به اشتراک بگذارید

> ⚠️ رمز عبور قابل بازیابی نیست!

## 🔧 ویژگی‌های جدید v3

### 1. پشتیبانی چندزبانه 🌍
- **زبان فارسی (پیش‌فرض)** و **انگلیسی**
- **تشخیص خودکار زبان** از مرورگر کاربر
- **سوییچر زبان** در هدر با پرچم کشورها
- **RTL/LTR خودکار** بر اساس زبان

### 2. لینک‌های پیشرفته 🔗

#### زمان انقضا
- تنظیم زمان انقضا از **1 ساعت تا 7 روز** (168 ساعت)
- حذف خودکار پس از انقضا
- نمایش زمان باقیمانده به کاربر

#### محدودیت بازدید
- محدود کردن تعداد بازدیدها (حداکثر 1,000,000)
- حذف خودکار پس از رسیدن به محدودیت
- نمایش بازدیدهای باقیمانده

#### لینک یک‌بار مصرف
- گزینه **"لینک یک‌بار مصرف"**
- حذف خودکار پس از اولین بازدید
- مناسب برای اطلاعات حساس

### 3. امنیت پیشرفته 🛡️

#### Security Class
- **CSRF Tokens** برای جلوگیری از حملات CSRF
- **Security Headers** شامل HSTS, CSP, X-Frame-Options
- **Input Sanitization** برای جلوگیری از XSS و SQL Injection
- **Hardened Session** با تنظیمات امنیتی بالا

#### Password Security
- استفاده از **Argon2ID** برای هش رمز عبور
- **Password Rate Limiting** برای جلوگیری از Brute Force

### 4. عملکرد بالا ⚡

#### Cache Class
- **In-Memory Cache** برای کاهش کوئری‌های دیتابیس
- **TTL (Time To Live)** برای مدیریت خودکار کش
- **Cleanup Function** برای پاکسازی کش قدیمی

#### Database Optimization
- **ایندکس‌های بهینه** برای ستون‌های جدید
- **کوئری‌های بهینه** برای جستجوی سریع
- **Persistent Connection** با PDO

### 5. استفاده از API 📡

```javascript
// ایجاد لینک با زمان انقضا 24 ساعت و محدودیت 10 بازدید
fetch('/api-create', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
    },
    body: JSON.stringify({
        content: 'Your text here',
        expiry_hours: 24,
        view_limit: 10,
        is_encrypted: false
    })
})
.then(response => response.json())
.then(data => {
    if (data.status === 'success') {
        console.log('Link:', data.url);
        console.log('Expires:', data.expires_at);
        console.log('View Limit:', data.view_limit);
    }
});
```

## 🔧 پاکسازی خودکار

**Cron Job (توصیه شده هر ساعت):**
```bash
0 * * * * /usr/bin/php /path/to/clean-db.php
```

**cPanel:** Cron Jobs → `php /home/user/public_html/clean-db.php`

**خروجی نمونه:**
```
[2025-12-28 10:00:00] === PasteLink v3 Cleanup ===
Deleted expired/exhausted texts: 25
Total texts: 1542
Total views: 8976
Expiring texts: 123
Limited texts: 89
Cleanup completed successfully.
```

## ⚙️ نیازمندی‌ها

- PHP 8.0+ (توصیه PHP 8.1+)
- MySQL 5.7+ / MariaDB 10.2+ (برای عملکرد بهینه MySQL 8.0+ توصیه می‌شود)
- PDO Extension
- JSON Extension
- mbstring Extension
- mod_rewrite (اختیاری)
- JavaScript فعال در مرورگر کاربر

## 🛡️ امنیت

✅ Client-Side Encryption  
✅ Rate Limiting  
✅ SQL Injection Prevention  
✅ XSS Protection  
✅ Session Security  

## 🐛 رفع مشکلات

| مشکل | راه حل |
|------|--------|
| خطای دیتابیس | بررسی `config.php` |
| صفحه سفید | PHP 8.0+ |
| URL کار نمی‌کند | `.htaccess` + mod_rewrite |
| ورود ادمین | Hash رمز جدید |

## 📁 ساختار

```
pastelink/
├── index.php              # صفحه اصلی با تمام ویژگی‌ها
├── admin.php             # پنل مدیریت (نسخه قبل)
├── config.php             # تنظیمات اصلی برنامه
├── clean-db.php           # پاکسازی پایگاه داده
├── .htaccess             # تنظیمات Apache
├── includes/             # کلاس‌های اصلی
│   ├── language.php       # کلاس زبان (فارسی/انگلیسی)
│   ├── security.php       # کلاس امنیت (CSRF, Headers)
│   ├── cache.php          # کلاس کش در حافظه
│   └── texthandler.php   # کلاس مدیریت متن‌ها
└── i18n/                 # فایل‌های ترجمه
    ├── en.php            # ترجمه انگلیسی
    └── fa.php            # ترجمه فارسی
```

## 🔄 مقایسه نسخه‌ها

| ویژگی | v1.0 | v2.0 | v3.0 |
|-------|------|------|------|
| رمزگذاری | ❌ | ✅ | ✅ |
| Glassmorphism | ❌ | ✅ | ✅ |
| Rate Limiting | ❌ | ✅ | ✅ |
| پاکسازی خودکار | ❌ | ✅ | ✅ |
| چندزبانه | ❌ | ❌ | ✅ |
| لینک با زمان انقضا | ❌ | ❌ | ✅ |
| محدودیت بازدید | ❌ | ❌ | ✅ |
| لینک یک‌بار مصرف | ❌ | ❌ | ✅ |
| Security Headers | ❌ | ❌ | ✅ |
| Cache Performance | ❌ | ❌ | ✅ |
| CSRF Protection | ❌ | ❌ | ✅ |

## 🤝 مشارکت

1. Fork کنید
2. Branch بسازید (`git checkout -b feature/NewFeature`)
3. Commit کنید (`git commit -m 'Add NewFeature'`)
4. Push کنید (`git push origin feature/NewFeature`)
5. Pull Request باز کنید

## 📝 لایسنس

MIT License - فایل [LICENSE](LICENSE) را ببینید.

---

<div align="center">

⭐ اگر مفید بود، یک ستاره بدهید!

**نسخه 3.0** | دسامبر 2025

</div>
