# 📋 PasteLink v2.0

> اسکریپت اشتراک‌گذاری متن با رمزگذاری پیشرفته AES-256

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

## ✨ ویژگی‌ها

- 🔐 **رمزگذاری سمت کاربر** با AES-256
- 🎨 **طراحی Glassmorphism** مدرن و زیبا
- 🌓 **حالت تاریک/روشن** خودکار
- 🛡️ **امنیت بالا** با Rate Limiting
- 📱 **Responsive** کامل
- 🗑️ **پاکسازی خودکار** متن‌های قدیمی

## 🚀 نصب سریع

### 1. دانلود

**دانلود ZIP:**

[📥 دانلود PasteLink v2.0](https://github.com/Ho3einK84/PasteLink/archive/refs/tags/v2.0.zip)

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
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45),
    `is_encrypted` TINYINT(1) DEFAULT 0,
    INDEX `idx_code` (`code`),
    INDEX `idx_created_at` (`created_at`)
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

## 🔧 پاکسازی خودکار (24 ساعت)

**Cron Job:**
```bash
0 2 * * * /usr/bin/php /path/to/clean-db.php
```

**cPanel:** Cron Jobs → `php /home/user/public_html/clean-db.php`

## ⚙️ نیازمندی‌ها

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.2+
- PDO Extension
- mod_rewrite (اختیاری)

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
├── index.php
├── admin.php
├── config.php
├── clean-db.php
└── .htaccess
```

## 🔄 مقایسه نسخه‌ها

| ویژگی | v1.0 | v2.0 |
|-------|------|------|
| رمزگذاری | ❌ | ✅ |
| Glassmorphism | ❌ | ✅ |
| Rate Limiting | ❌ | ✅ |
| پاکسازی خودکار | ❌ | ✅ |

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

**نسخه 2.0** | دسامبر 2025

</div>
