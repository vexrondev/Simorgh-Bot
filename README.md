<div align="center">

<img src="assets/logo.png" alt="لوگوی سیمرغ" width="150">

# 🔮 سیمرغ

ربات فروشگاه پنل‌های تلگرام
PHP و MySQL

[

![Telegram Channel](https://img.shields.io/badge/Telegram-Channel_@SimorghFree-2CA5E0?style=flat-square&logo=telegram&logoColor=white)

](https://t.me/SimorghFree)
[

![Telegram Group](https://img.shields.io/badge/Telegram-Group_@SimorghBotGroup-229ED9?style=flat-square&logo=telegram&logoColor=white)

](https://t.me/SimorghBotGroup)


![Version](https://img.shields.io/badge/version-3.4.0-blue?style=flat-square)




![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?style=flat-square&logo=php&logoColor=white)




![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)



**فروش خودکار پنل، کیف پول، تأیید رسید و مدیریت کامل از داخل تلگرام**

</div>

---

## ✨ معرفی

سیمرغ یک ربات فروشگاهی است که با **PHP** و **MySQL** نوشته شده است. ربات با **Webhook** کار می‌کند، یعنی تلگرام هر پیام را مستقیم به ربات می‌فرستد و همه‌ی کارها داخل خود تلگرام انجام می‌شود.

ربات روی دو نوع محیط اجرا می‌شود:
- **هاست اشتراکی با cPanel** (ساده‌ترین روش، بدون نیاز به سرور)
- **سرور مجازی VPS** با Nginx یا Apache (کنترل کامل)

---

## 🧭 امکانات

### برای مشتری
- 🛒 خرید پنل در مدت ۱، ۲ و ۳ ماهه
- 💰 کیف پول با افزایش موجودی و تأیید رسید توسط مدیر
- 💳 پرداخت کارت به کارت و درگاه پرداخت (در صورت فعال بودن)
- 🎁 تست رایگان با سهمیه‌ی قابل تنظیم
- 👥 زیرمجموعه‌گیری و پاداش دعوت
- 🎧 مرکز پشتیبانی، تیکت و سوالات متداول
- 📱 مینی‌اپ فارسی با حالت روشن و تاریک
- 🔐 ورود امن به مینی‌اپ با امضای تلگرام

### برای مدیر
- 📊 آمار کلی فروش، کاربران و کیف پول
- 📦 مدیریت پنل‌ها، محصولات، مدت‌های خرید و کدهای تخفیف
- 🧾 تأیید یا رد رسیدها و سفارش‌ها، با گزارش کامل در گروه
- 👤 مدیریت کاربران: موجودی، مسدودسازی، پیام مستقیم و سابقه‌ی سفارش
- 📢 عضویت اجباری در کانال‌ها و قوانین قابل ویرایش
- 📣 ارسال همگانی با نمایش پیشرفت و امکان لغو
- ✏️ ویرایش متن‌های ربات، رنگ دکمه‌ها و چیدمان منو، بدون نیاز به کد
- 🩺 وضعیت سیستم و تست اتصال
- 💾 پشتیبان‌گیری از پایگاه‌داده و کد
- 🔄 بروزرسانی ربات از داخل خود ربات، با پشتیبان خودکار

---

## 🛰 یکپارچه‌سازی با پاسارگاد

ربات می‌تواند به پنل **PasarGuard** وصل شود و برای هر سفارش نمایندگی، یک نقش محدود و یک ادمین جدید بسازد:

1. آدرس پنل را با **https** وارد کنید. ربات فقط دامنه و پورت را نگه می‌دارد.
2. کلید API را ثبت کنید و با دکمه‌ی **تست اتصال** آن را بررسی کنید.
3. پس از پرداخت و تأیید سفارش، ربات اطلاعات ورود نماینده را برای خریدار می‌فرستد.

> ⚠️ این بخش هنوز روی پنل واقعی کامل آزموده نشده است. پیش از فروش، آن را روی یک پنل آزمایشی تست کنید.

---

## 📋 پیش‌نیازها

| مورد | هاست cPanel | سرور VPS |
|---|---|---|
| PHP | 8.0 یا بالاتر | 8.0 یا بالاتر |
| افزونه‌ها | `pdo_mysql`، `curl`، `json`، `mbstring`، `openssl` | همان موارد |
| پایگاه‌داده | MySQL از بخش MySQL Databases | MySQL یا MariaDB |
| SSL | فعال (گواهی رایگان cPanel) | گواهی Let's Encrypt |
| دامنه | لازم است | لازم است |

برای هر دو روش، لازم است:
- یک **ربات تلگرام** از @BotFather
- شناسه‌ی عددی ادمین از @userinfobot

---

## 🚀 نصب روی هاست اشتراکی (cPanel)

### ۱) ساخت دیتابیس
1. در cPanel وارد **MySQL® Databases** شوید.
2. یک دیتابیس بسازید؛ نام کامل آن معمولاً به شکل `cpuser_simorgh` است.
3. یک کاربر با رمز قوی بسازید و آن را با دسترسی **ALL PRIVILEGES** به دیتابیس اضافه کنید.

### ۲) آپلود فایل‌ها
1. آخرین فایل Release را با نام `SimorghBot-X.Y.Z.zip` دانلود کنید.
2. در **File Manager** به `public_html` بروید، فایل را آپلود و **Extract** کنید.
3. پوشه‌ی `public_html/SimorghBot/` باید ساخته شود.

### ۳) اجرای نصب‌کننده
آدرس زیر را باز کنید (دامنه را با دامنه‌ی خودتان عوض کنید):

```
https://yourdomain.com/SimorghBot/install.php
```

فرم را کامل کنید و نصب را شروع کنید. نصب‌کننده این کارها را انجام می‌دهد:
- توکن را با تلگرام بررسی می‌کند
- جدول‌های دیتابیس را می‌سازد
- فایل `config.php` را با دسترسی خصوصی می‌سازد
- آدرس Webhook را در تلگرام ثبت می‌کند

### ۴) بررسی نهایی
1. در تلگرام به ربات `/start` بفرستید.
2. لینک `selftest.php` را که در پایان نصب نمایش داده می‌شود باز کنید؛ همه‌ی ردیف‌ها باید ✅ باشند.

---

## 🖥 نصب روی سرور مجازی (VPS)

این روش برای کسانی است که سرور اوبونتو یا دبیان با دسترسی SSH دارند.

### ۱) نصب نرم‌افزارهای لازم
```bash
sudo apt update
sudo apt install -y nginx mysql-server php8.2-fpm php8.2-mysql php8.2-curl php8.2-mbstring php8.2-zip certbot python3-certbot-nginx unzip
```

> اگر نسخه‌ی PHP شما 8.2 نیست، عدد را با نسخه‌ی نصب‌شده عوض کنید.

### ۲) ساخت دیتابیس
```bash
sudo mysql
```
و در محیط MySQL:
```sql
CREATE DATABASE simorgh CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'simorgh'@'localhost' IDENTIFIED BY 'رمز-قوی-خود';
GRANT ALL PRIVILEGES ON simorgh.* TO 'simorgh'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### ۳) قرار دادن فایل‌ها
```bash
sudo mkdir -p /var/www/simorgh
sudo unzip SimorghBot-X.Y.Z.zip -d /var/www/simorgh
sudo chown -R www-data:www-data /var/www/simorgh
```
فایل‌ها داخل `/var/www/simorgh/SimorghBot` قرار می‌گیرند.

### ۴) تنظیم Nginx
یک فایل جدید بسازید:
```bash
sudo nano /etc/nginx/sites-available/simorgh
```
و این محتوا را قرار دهید (`yourdomain.com` را عوض کنید):

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/simorgh/SimorghBot;
    index index.php;

    # نشانی‌های API
    location ~ ^/api/(.*)$ {
        rewrite ^/api/(.*)$ /api.php?r=$1&$args last;
    }

    # پوشه‌های داخلی و فایل‌های حساس از اینترنت بسته‌اند
    location ~ ^/(src|storage|\.git)(/|$) { deny all; }
    location ~ ^/(config\.php|installed\.lock)$ { deny all; }
    location ~ \.(sql|gz|md|log|bak|tmp|ini|env|zip|tar)$ { deny all; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location / {
        try_files $uri $uri/ =404;
    }
}
```

فعال‌سازی و بررسی:
```bash
sudo ln -s /etc/nginx/sites-available/simorgh /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### ۵) گرفتن گواهی SSL
```bash
sudo certbot --nginx -d yourdomain.com
```
تلگرام فقط آدرس HTTPS را می‌پذیرد، پس این مرحله ضروری است.

### ۶) اجرای نصب‌کننده
```
https://yourdomain.com/install.php
```
فرم را کامل کنید. مشخصات دیتابیس همان است که در مرحله‌ی ۲ ساختید (میزبان: `localhost`).

### ۷) مجوز پوشه‌ی storage
پوشه‌ی داده باید برای PHP قابل نوشتن باشد:
```bash
sudo chown -R www-data:www-data /var/www/simorgh/SimorghBot/storage
```

---

## 🔄 بروزرسانی

### از داخل ربات (از نسخه‌ی 3.4.0 به بعد)
1. در پنل مدیریت، **تنظیمات ← 🔄 بروزرسانی ربات** را باز کنید.
2. اگر نسخه‌ی جدیدی باشد، دکمه‌ی **نصب** را بزنید و تأیید کنید.
3. ربات پیش از نصب از کد فعلی پشتیبان می‌گیرد. تنظیمات، دیتابیس و پوشه‌ی `storage` تغییر نمی‌کنند.

### یک بار دستی (نسخه‌های قبل از بروزرسانی داخلی)
1. فایل ZIP نسخه‌ی جدید را از بخش **Releases** دانلود کنید.
2. فایل‌ها را داخل پوشه‌ی ربات جایگزین کنید.
3. فایل‌های `config.php` و `installed.lock` را نگه دارید.

---

## 🔐 امنیت

- تنظیمات و کلیدها فقط در `config.php` نگهداری می‌شوند و دسترسی به آن محدود است.
- پوشه‌های `src` و `storage` از اینترنت قابل دسترسی نیستند.
- پشتیبان‌های دیتابیس و کد فقط در `storage` ذخیره می‌شوند.
- تصاویر رسید فقط برای مدیران قابل مشاهده است.
- اگر فکر می‌کنید توکن یا کلیدی لو رفته، فوراً آن را در @BotFather یا پنل مربوطه باطل کنید.
- پس از نصب، فایل‌های `install.php` و `selftest.php` را حذف کنید.

**گزارش آسیب‌پذیری:** مشکل امنیتی را در issue عمومی مطرح نکنید؛ مستقیماً در [گروه پشتیبانی](https://t.me/SimorghBotGroup) به مدیر خبر دهید.

---

## 🩺 عیب‌یابی

| مشکل | راه‌حل |
|---|---|
| صفحه‌ی نصب خطای ۵۰۰ می‌دهد | نسخه‌ی PHP را 8.0 یا بالاتر کنید و `pdo_mysql` را فعال کنید |
| اتصال به دیتابیس ناموفق است | نام کامل دیتابیس (با پیشوند cPanel) و نام کاربری را بررسی کنید |
| ربات جواب نمی‌دهد | در `selftest.php` بخش «خطای آخر Webhook» را بخوانید |
| دکمه‌ی مینی‌اپ نمایش داده نمی‌شود | آدرس باید HTTPS باشد و گواهی SSL معتبر باشد |
| در VPS صفحه‌ی 502 می‌دهد | سرویس PHP-FPM را بررسی کنید: `sudo systemctl status php8.2-fpm` |
| گزارش‌ها در گروه ثبت نمی‌شوند | گروه باید سوپرگروه با تاپیک باشد و ربات ادمین با دسترسی مدیریت تاپیک‌ها باشد |
| آپلود رسید خطا می‌دهد | حجم عکس باید کمتر از ۲ مگابایت باشد |

---

## 🤝 جامعه و پشتیبانی

- 📢 **کانال سیمرغ:** [@SimorghFree](https://t.me/SimorghFree)
- 👥 **گروه پشتیبانی:** [@SimorghBotGroup](https://t.me/SimorghBotGroup)

سؤال، پیشنهاد و گزارش خطا را در گروه مطرح کنید. برای گزارش خطا، نسخه‌ی ربات، زمان دقیق و متن خطا را بنویسید.

---

## 📜 مجوز

این پروژه تحت مجوز **MIT** منتشر شده است. جزئیات در فایل [LICENSE](LICENSE) آمده است.