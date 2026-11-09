<?php
/**
 * به‌روزرسانی ربات از داخل خود ربات.
 * فقط از مخزن رسمی GitHub دانلود می‌کند، پیش از هر تغییری پشتیبان کد می‌سازد،
 * فایل‌های تنظیمات و داده را دست نمی‌زند، و در صورت خطا همه‌ی تغییرات را برمی‌گرداند.
 */
declare(strict_types=1);

const UPD_REPO = 'vexrondev/Simorgh-Bot';
const UPD_MAX_BYTES = 30 * 1024 * 1024;
const UPD_KEEP_BACKUPS = 3;

function upd_dir(): string
{
    return SB_ROOT . '/storage/update';
}

function upd_fail(string $message): array
{
    return ['ok' => false, 'message' => $message];
}

/** فقط مسیرهای امن و داخل پروژه پذیرفته می‌شوند. */
function upd_safe_rel(string $name): ?string
{
    $name = str_replace('\\', '/', $name);
    if ($name === '' || $name[0] === '/' || str_contains($name, '../') || str_contains($name, "\0")) {
        return null;
    }
    return $name;
}

/** فایل‌هایی که هرگز بازنویسی یا حذف نمی‌شوند: تنظیمات، قفل نصب و هر چیزی در storage. */
function upd_is_protected(string $rel): bool
{
    return $rel === 'config.php' || $rel === 'installed.lock' || str_starts_with($rel, 'storage/');
}

/** درخواست HTTPS؛ فقط به میزبان‌های رسمی GitHub اجازه‌ی دنبال کردن تغییر مسیر داده می‌شود. */
function upd_http(string $url, bool $json, ?string $saveTo = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'افزونه‌ی curl روی هاست فعال نیست.'];
    }
    $fh = null;
    if ($saveTo !== null) {
        $fh = @fopen($saveTo, 'wb');
        if ($fh === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'فایل موقت ساخته نشد.'];
        }
    }
    $headers = ['User-Agent: SimorghBot-Updater'];
    if ($json) {
        $headers[] = 'Accept: application/vnd.github+json';
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => $saveTo === null,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ];
    if ($fh !== null) {
        $opts[CURLOPT_FILE] = $fh;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $finalHost = (string) parse_url((string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), PHP_URL_HOST);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fh !== null) {
        fclose($fh);
    }
    $allowed = $finalHost === 'github.com' || $finalHost === 'api.github.com' || str_ends_with($finalHost, 'githubusercontent.com');
    if (!$allowed) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'error' => 'مسیر دانلود از مخزن رسمی نیست و متوقف شد.'];
    }
    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err !== '' ? $err : 'اتصال برقرار نشد.'];
    }
    $ok = $status >= 200 && $status < 300;
    return [
        'ok' => $ok,
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'error' => $ok ? '' : 'پاسخ GitHub نامعتبر است (HTTP ' . $status . ').',
    ];
}

/** اگر در توضیحات Release خطی مثل «sha256: ...» باشد، همان مقدار برای کنترل فایل استفاده می‌شود. */
function upd_sha_from_text(string $text): ?string
{
    if (preg_match('/sha256\W{0,5}([a-f0-9]{64})/i', $text, $m) === 1) {
        return strtolower($m[1]);
    }
    return null;
}

/** آخرین Release رسمی را می‌گیرد. */
function upd_latest(): array
{
    $r = upd_http('https://api.github.com/repos/' . UPD_REPO . '/releases/latest', true);
    if ($r['status'] === 404) {
        return ['ok' => false, 'error' => 'هنوز هیچ Release ای در مخزن رسمی منتشر نشده است.'];
    }
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error']];
    }
    $data = json_decode($r['body'], true);
    if (!is_array($data) || empty($data['tag_name'])) {
        return ['ok' => false, 'error' => 'اطلاعات Release قابل خواندن نیست.'];
    }
    $zipUrl = '';
    foreach ((array) ($data['assets'] ?? []) as $asset) {
        $name = (string) ($asset['name'] ?? '');
        if (preg_match('/^SimorghBot-.*\.zip$/', $name) === 1) {
            $zipUrl = (string) ($asset['browser_download_url'] ?? '');
            break;
        }
    }
    if ($zipUrl === '') {
        return ['ok' => false, 'error' => 'فایل ZIP نسخه‌ی جدید در Release پیدا نشد.'];
    }
    $notes = (string) ($data['body'] ?? '');
    return [
        'ok' => true,
        'version' => ltrim((string) $data['tag_name'], 'vV'),
        'notes' => $notes,
        'url' => $zipUrl,
        'sha256' => upd_sha_from_text($notes),
    ];
}

/** بررسی اینکه آیا نسخه‌ی جدیدی هست؛ این بررسی چیزی را نصب نمی‌کند. */
function upd_check(): array
{
    $latest = upd_latest();
    if (!$latest['ok']) {
        return ['ok' => false, 'error' => $latest['error']];
    }
    return $latest + ['available' => version_compare($latest['version'], SB_VERSION, '>')];
}

/** بازگرداندن فایل‌های قبلی از پشتیبان، و حذف فایل‌هایی که نسخه‌ی جدید اضافه کرده بود. */
function upd_rollback(string $backup, array $created, string $reason): array
{
    foreach ($created as $rel) {
        @unlink(SB_ROOT . '/' . $rel);
    }
    $b = new ZipArchive();
    if ($b->open($backup) === true) {
        for ($i = 0; $i < $b->numFiles; $i++) {
            $name = (string) $b->getNameIndex($i);
            $target = SB_ROOT . '/' . $name;
            if (str_ends_with($name, '/') || upd_is_protected($name)) {
                continue;
            }
            $stream = $b->getStream($name);
            if ($stream === false) {
                continue;
            }
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0755, true);
            }
            $out = @fopen($target . '.restore', 'wb');
            if ($out !== false) {
                stream_copy_to_stream($stream, $out);
                fclose($out);
                @rename($target . '.restore', $target);
            }
            fclose($stream);
        }
        $b->close();
    }
    return upd_fail($reason . ' تغییرات به نسخه‌ی قبلی برگردانده شد.');
}

/** نصب نسخه‌ی جدید؛ فقط با پشتیبان و کنترل کامل فایل‌ها. */
function upd_apply(): array
{
    @set_time_limit(300);
    $dir = upd_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return upd_fail('پوشه‌ی موقت به‌روزرسانی ساخته نشد.');
    }
    $lock = @fopen($dir . '/lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return upd_fail('یک به‌روزرسانی دیگر در حال اجراست.');
    }
    try {
        return upd_run($dir);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function upd_run(string $dir): array
{
    $latest = upd_latest();
    if (!$latest['ok']) {
        return upd_fail($latest['error']);
    }
    if (version_compare($latest['version'], SB_VERSION, '<=')) {
        return upd_fail('این نسخه از قبل نصب است.');
    }

    // ۱) دانلود و کنترل فایل
    $zipPath = $dir . '/new.zip';
    $download = upd_http($latest['url'], false, $zipPath);
    if (!$download['ok']) {
        @unlink($zipPath);
        return upd_fail('دانلود فایل ناموفق بود: ' . $download['error']);
    }
    $size = is_file($zipPath) ? (int) filesize($zipPath) : 0;
    if ($size === 0 || $size > UPD_MAX_BYTES) {
        @unlink($zipPath);
        return upd_fail('فایل دانلودشده معتبر نیست.');
    }
    if ($latest['sha256'] !== null && !hash_equals($latest['sha256'], strtolower((string) hash_file('sha256', $zipPath)))) {
        @unlink($zipPath);
        return upd_fail('امضای SHA256 فایل با توضیحات Release یکی نیست. نصب متوقف شد.');
    }

    $z = new ZipArchive();
    if ($z->open($zipPath) !== true) {
        @unlink($zipPath);
        return upd_fail('فایل ZIP باز نشد.');
    }
    $prefix = null;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $name = (string) $z->getNameIndex($i);
        if (upd_safe_rel($name) === null) {
            $z->close();
            @unlink($zipPath);
            return upd_fail('فایل ZIP مسیر نامعتبر دارد و نصب متوقف شد.');
        }
        $norm = str_replace('\\', '/', $name);
        if (str_ends_with($norm, 'src/bootstrap.php')) {
            $prefix = substr($norm, 0, -strlen('src/bootstrap.php'));
        }
    }
    if ($prefix === null) {
        $z->close();
        @unlink($zipPath);
        return upd_fail('ساختار فایل ZIP شناخته نشد.');
    }
    $boot = (string) $z->getFromName($prefix . 'src/bootstrap.php');
    if (preg_match("/define\\('SB_VERSION', '([^']+)'\\)/", $boot, $m) !== 1 || $m[1] !== $latest['version']) {
        $z->close();
        @unlink($zipPath);
        return upd_fail('نسخه‌ی داخل فایل با نسخه‌ی Release یکی نیست.');
    }

    // ۲) پشتیبان کد فعلی (بدون تنظیمات و داده)
    $backup = $dir . '/backup-' . SB_VERSION . '-' . date('Ymd-His') . '.zip';
    $b = new ZipArchive();
    if ($b->open($backup, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $z->close();
        @unlink($zipPath);
        return upd_fail('پشتیبان ساخته نشد؛ نصب انجام نشد.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SB_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(SB_ROOT) + 1));
        if (upd_is_protected($rel)) {
            continue;
        }
        $b->addFile($file->getPathname(), $rel);
    }
    $b->close();

    // ۳) نوشتن فایل‌ها یکی‌یکی؛ اگر یکی شکست خورد، همه برمی‌گردند
    $created = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $name = (string) $z->getNameIndex($i);
        $norm = str_replace('\\', '/', $name);
        if (!str_starts_with($norm, $prefix) || str_ends_with($norm, '/')) {
            continue;
        }
        $rel = substr($norm, strlen($prefix));
        if ($rel === '' || upd_is_protected($rel)) {
            continue;
        }
        $target = SB_ROOT . '/' . $rel;
        $existed = is_file($target);
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) {
            $z->close();
            @unlink($zipPath);
            return upd_rollback($backup, $created, 'ساخت پوشه‌ی ' . $rel . ' ممکن نشد.');
        }
        $stream = $z->getStream($name);
        $tmp = $target . '.new';
        $out = $stream !== false ? @fopen($tmp, 'wb') : false;
        if ($stream === false || $out === false) {
            if ($stream !== false) {
                fclose($stream);
            }
            $z->close();
            @unlink($zipPath);
            return upd_rollback($backup, $created, 'نوشتن فایل ' . $rel . ' ممکن نشد.');
        }
        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            $z->close();
            @unlink($zipPath);
            return upd_rollback($backup, $created, 'جایگزینی فایل ' . $rel . ' ممکن نشد.');
        }
        if (!$existed) {
            $created[] = $rel;
        }
    }
    $z->close();
    @unlink($zipPath);

    // ۴) نگهداری فقط از چند پشتیبان آخر
    $backups = glob($dir . '/backup-*.zip') ?: [];
    usort($backups, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    foreach (array_slice($backups, UPD_KEEP_BACKUPS) as $old) {
        @unlink($old);
    }

    setting_set('update_last', now());
    setting_set('update_from', SB_VERSION);
    setting_set('update_to', $latest['version']);
    return [
        'ok' => true,
        'message' => 'نسخه‌ی ' . $latest['version'] . ' با موفقیت نصب شد. تغییرات پایگاه‌داده هنگام اولین درخواست بعدی اعمال می‌شوند.',
    ];
}
