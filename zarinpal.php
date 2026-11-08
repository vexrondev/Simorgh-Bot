<?php
/**
 * بازگشت از درگاه زرین‌پال. پرداخت فقط یک بار تأیید و کیف پول فقط یک بار شارژ می‌شود.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

$authority = trim((string) ($_GET['Authority'] ?? ''));
$status = (string) ($_GET['Status'] ?? '');
$row = is_installed() && $authority !== ''
    ? one('SELECT * FROM gateway_payments WHERE authority = ?', [$authority])
    : null;

// درخواست‌های فاقد شناسه‌ی پرداخت معتبر نباید مهاجرت دیتابیس اجرا کنند.
if ($row !== null) {
    require_once __DIR__ . '/src/loader.php';
}

$ok = false;
$text = 'اطلاعات پرداخت معتبر نیست.';

if ($row !== null) {
    if ($row['status'] === 'paid') {
        $ok = true;
        $text = 'این پرداخت قبلاً ثبت شده و موجودی شما شارژ شده است.';
    } elseif (in_array($row['status'], ['pending', 'verifying'], true) && $status === 'OK') {
        // درخواست اول وضعیت را به verifying می‌برد؛ callback تکراری می‌تواند
        // تسویه‌ی نیمه‌تمام را بدون افزایش موجودیِ تکراری دوباره امتحان کند.
        $claim = q("UPDATE gateway_payments SET status = 'verifying' WHERE authority = ? AND status = 'pending'", [$authority]);
        if ($claim->rowCount() === 1 || $row['status'] === 'verifying') {
            $ref = zarinpal_verify($authority, (int) $row['amount']);
            if ($ref !== null) {
                if (approve_online_charge((string) $row['charge_id'], $authority, $ref)) {
                    $ok = true;
                    $text = 'پرداخت با موفقیت تأیید شد و موجودی کیف پول شما شارژ شد.';
                } else {
                    // در صورت خطای دیتابیس، وضعیت verifying باقی می‌ماند تا بررسی دستی انجام شود؛
                    // هرگز موفقیت پرداخت را بدون افزایش موجودی اعلام نمی‌کنیم.
                    error_log('[SimorghBot Zarinpal] verified payment could not be settled: ' . $authority);
                    $text = 'پرداخت تأیید شده، اما ثبت موجودی با مشکل موقت روبه‌رو شد. لطفاً شناسه پرداخت را برای پشتیبانی ارسال کنید.';
                }
            } else {
                q("UPDATE gateway_payments SET status = 'failed' WHERE authority = ?", [$authority]);
                $text = 'تأیید پرداخت انجام نشد. اگر مبلغی کسر شده، با پشتیبانی تماس بگیرید.';
            }
        }
    } elseif ($row['status'] === 'pending' && $status !== 'OK') {
        q("UPDATE gateway_payments SET status = 'cancelled' WHERE authority = ? AND status = 'pending'", [$authority]);
        q("UPDATE wallet_charges SET status = 'cancelled' WHERE id = ? AND status = 'waiting'", [(string) $row['charge_id']]);
        $text = 'پرداخت لغو شد.';
    } elseif ($row['status'] === 'verifying') {
        $text = 'وضعیت پرداخت در حال بررسی نهایی است. در صورت کسر وجه، لطفاً این صفحه را نبندید و با پشتیبانی تماس بگیرید.';
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>نتیجه‌ی پرداخت</title>'
    . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0f1115;color:#eceef3;'
    . 'font-family:Tahoma,system-ui,sans-serif;padding:24px}.box{max-width:420px;text-align:center;background:#171a21;'
    . 'border:1px solid #262b36;border-radius:16px;padding:28px}.ok{color:#3ccf8e}.bad{color:#ef5b5b}'
    . 'p{line-height:2;margin:10px 0}</style></head><body><div class="box">'
    . '<h2 class="' . ($ok ? 'ok' : 'bad') . '">' . ($ok ? 'پرداخت موفق' : 'پرداخت ناموفق') . '</h2>'
    . '<p>' . h($text) . '</p><p>می‌توانید به تلگرام برگردید.</p></div></body></html>';
