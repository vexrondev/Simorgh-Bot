<?php
/**
 * اجرای صف ارسال همگانی. هر دقیقه از cPanel فراخوانی می‌شود.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
if (!is_installed()) {
    http_response_code(503);
    echo 'not installed';
    exit;
}
$key = (string) ($_GET['key'] ?? '');
$real = (string) setting_get('cron_key', '');
if ($real === '' || !hash_equals($real, $key)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

// فقط درخواست دارای کلید معتبر اجازه‌ی بارگذاری ماژول‌ها و اجرای مهاجرت‌ها را دارد.
require_once __DIR__ . '/src/loader.php';

// تست سلامت: فقط ثبت می‌کند که سایت می‌تواند به خودش درخواست بفرستد
if (isset($_GET['ping'])) {
    setting_set('ping_last', now());
    echo 'pong';
    exit;
}

// یک نمونه در هر لحظه؛ اجرای همزمان دو کار صف را دو بار ارسال نمی‌کند
$lock = fopen(SB_ROOT . '/storage/cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo 'busy';
    exit;
}

// بررسی پس‌زمینه‌ی تأیید خودکار: هر ~۸ ثانیه تا زمان رسیدها، بدون وابستگی به پیام کاربر یا cron
if (isset($_GET['auto'])) {
    ignore_user_abort(true);
    @set_time_limit(90);
    for ($i = 0; $i < 6; $i++) {
        auto_confirm_pass(true);
        setting_set('auto_run_last', now());
        if (!auto_confirm_waiting()) {
            break;
        }
        sleep(8);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    if (auto_confirm_waiting()) {
        auto_confirm_kick();
    }
    echo 'auto ok';
    exit;
}

@set_time_limit(90);
$start = time();
$batches = 0;
// تأیید خودکار رسیدهای شارژ هر دقیقه بررسی می‌شود، مستقل از اینکه کاربری پیامی بفرستد
auto_confirm_pass(true);
try { pg_expire_tick(); } catch (Throwable $e) { report_error('pasarguard_expiry', $e->getMessage()); }
setting_set('auto_run_last', now());
while (time() - $start < 50 && broadcast_tick(30)) {
    $batches++;
}
try {
    backup_maybe_run();
} catch (Throwable $e) {
    report_error('backup', $e->getMessage());
}
setting_set('cron_last', now());
echo 'ok ' . $batches;

flock($lock, LOCK_UN);
fclose($lock);

// ارسال همگانی هنوز تمام نشده؛ اجرای بعدی از همین نقطه ادامه می‌دهد
if (broadcast_pending()) {
    broadcast_chain();
}
