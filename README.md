# 📋 PasteLink v3.0

> اسکریپت اشتراک‌گذاری متن با رمزگذاری پیشرفته و پشتیبانی چندزبانه

> ⚠️ **سلب مسئولیت:** این مخزن یک 
محیط آزمایشی (**Experimental 
Sandbox**) برای ارزیابی قابلیت‌های AI 
است و به عنوان یک ابزار کاربردی یا 
محصول نهایی در نظر گرفته نمی‌شود.

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## ✨ ویژگی‌های کلیدی

- 🌍 **چندزبانه** - فارسی و انگلیسی با RTL/LTR خودکار
- 🔐 **رمزگذاری AES-256** - رمزگذاری سمت کاربر
- ⏰ **زمان انقضا** - از 1 ساعت تا 7 روز
- 👁️ **محدودیت بازدید** - تا 1,000,000 بازدید
- 🔥 **لینک یک‌بار مصرف** - حذف خودکار پس از اولین بازدید
- 🛡️ **امنیت پیشرفته** - CSRF، Security Headers، Rate Limiting
- ⚡ **کش در حافظه** - افزایش سرعت با Cache Class
- 🎨 **طراحی مدرن** - Glassmorphism با حالت تاریک/روشن
- 📱 **Responsive** - سازگار با تمام دستگاه‌ها

---

## 🚀 نصب سریع

### 1. تنظیمات دیتابیس

ویرایش `config.php`:

```php
const DB_CONFIG = [
    'host' => 'localhost',
    'name' => 'pastelink',
    'user' => 'your_username',
    'pass' => 'your_password',
];
```

### 2. ایجاد جدول

جدول به صورت خودکار ایجاد می‌شود. در صورت نیاز می‌توانید دستی ایجاد کنید:

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
    INDEX `idx_expires_at` (`expires_at`),
    INDEX `idx_view_limit` (`view_limit`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3. تنظیم رمز ادمین

```php
<?php echo password_hash("your_password", PASSWORD_DEFAULT); ?>
```

مقدار تولید شده را در `config.php` قرار دهید:

```php
const ADMIN_HASH = '$2y$10$your_generated_hash';
```

---

## 📖 استفاده

| صفحه | آدرس |
|------|------|
| ایجاد متن | `https://domain.com/` |
| مشاهده متن | `https://domain.com/CodE12` |
| پنل ادمین | `https://domain.com/admin.php` |

### رمزگذاری متن

1. متن را وارد کنید
2. گزینه "فعال‌سازی رمز عبور" را انتخاب کنید
3. رمز را تعیین کنید
4. لینک را به اشتراک بگذارید

> ⚠️ **هشدار:** رمز عبور قابل بازیابی نیست!

---

## 🔧 پاکسازی خودکار

**Cron Job (هر ساعت):**

```bash
0 * * * * /usr/bin/php /path/to/clean-db.php
```

**cPanel:** Cron Jobs → `php /home/user/public_html/clean-db.php`

---

## 📡 API

```javascript
fetch('/api-create', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
    },
    body: JSON.stringify({
        content: 'متن شما',
        expiry_hours: 24,      // اختیاری
        view_limit: 10,         // اختیاری
        is_encrypted: false
    })
})
.then(response => response.json())
.then(data => {
    if (data.status === 'success') {
        console.log('Link:', data.url);
    }
});
```

---

## ⚙️ نیازمندی‌ها

- **PHP 8.0+** (توصیه: PHP 8.1+)
- **MySQL 5.7+** یا **MariaDB 10.2+**
- **PDO Extension**
- **JSON Extension**
- **mbstring Extension**
- **mod_rewrite** (اختیاری)

---

## 🛡️ امنیت

- ✅ رمزگذاری سمت کاربر (AES-256)
- ✅ محافظت در برابر SQL Injection (Prepared Statements)
- ✅ محافظت در برابر XSS (Sanitization & Escaping)
- ✅ CSRF Protection (Tokens)
- ✅ Security Headers (CSP, HSTS, X-Frame-Options)
- ✅ Rate Limiting
- ✅ Session Security (Hardened Sessions)

---

## 🐛 رفع مشکلات

| مشکل | راه حل |
|------|--------|
| خطای دیتابیس | بررسی تنظیمات در `config.php` |
| صفحه سفید | اطمینان از PHP 8.0+ |
| URL کار نمی‌کند | فعال‌سازی mod_rewrite و `.htaccess` |
| ورود ادمین | تولید Hash جدید برای رمز عبور |

---

## 📁 ساختار پروژه

```
pastelink/
├── index.php              # صفحه اصلی
├── admin.php             # پنل مدیریت
├── config.php             # تنظیمات
├── clean-db.php           # پاکسازی خودکار
├── includes/
│   ├── database.php       # کلاس اتصال دیتابیس
│   ├── language.php       # کلاس زبان
│   ├── security.php       # کلاس امنیت
│   ├── cache.php          # کلاس کش
│   └── texthandler.php   # کلاس مدیریت متن
└── i18n/
    ├── en.php            # ترجمه انگلیسی
    └── fa.php            # ترجمه فارسی
```

---

## 🔄 مقایسه نسخه‌ها

| ویژگی | v1.0 | v2.0 | v3.0 |
|-------|------|------|------|
| رمزگذاری | ❌ | ✅ | ✅ |
| چندزبانه | ❌ | ❌ | ✅ |
| زمان انقضا | ❌ | ❌ | ✅ |
| محدودیت بازدید | ❌ | ❌ | ✅ |
| لینک یک‌بار مصرف | ❌ | ❌ | ✅ |
| CSRF Protection | ❌ | ❌ | ✅ |
| Cache Performance | ❌ | ❌ | ✅ |

---

## 📝 لایسنس

MIT License - برای جزئیات بیشتر فایل [LICENSE](LICENSE) را ببینید.

---

<div align="center">

⭐ اگر مفید بود، یک ستاره بدهید!

**نسخه 3.0** | 2025

</div>
