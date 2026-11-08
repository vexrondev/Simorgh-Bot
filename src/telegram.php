<?php
/**
 * ارتباط با API تلگرام (ارسال، ویرایش، حذف، کپی و پاسخ به دکمه‌ها).
 */
declare(strict_types=1);

/** فراخوانی یک متد API تلگرام با توکن دلخواه. */
function tg_raw(string $token, string $method, array $params = []): array
{
    // هر درخواست پیام، پیش از رسیدن به تلگرام، متن خالی را به یک نویسه‌ی نامرئی تبدیل می‌کند
    if (in_array($method, ['sendMessage', 'editMessageText'], true) && isset($params['text'])
        && is_blank_text((string) $params['text'])) {
        return skip_blank_send($method);
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('افزونه‌ی curl روی هاست فعال نیست.');
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => array_filter($params, static fn($v) => $v !== null),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : ['ok' => false, 'description' => 'پاسخ نامعتبر از تلگرام'];
}

function tg(string $method, array $params = []): array
{
    // تلگرام متن خالی را نمی‌پذیرد؛ هر متن خالی به یک نویسه‌ی نامرئی تبدیل می‌شود
    if (in_array($method, ['sendMessage', 'editMessageText'], true) && isset($params['text'])) {
        if (is_blank_text((string) $params['text'])) {
            return skip_blank_send($method);
        }
    }
    $r = tg_raw((string) cfg('bot_token'), $method, $params);
    if (empty($r['ok']) && isset($params['reply_markup']) && str_contains((string) ($r['description'] ?? ''), 'BUTTON')) {
        error_log('[SimorghBot] ' . $method . ' button rejected, sent without keyboard: ' . ($r['description'] ?? ''));
        unset($params['reply_markup']);
        $r = tg_raw((string) cfg('bot_token'), $method, $params);
    }
    if (empty($r['ok']) && $method !== 'getChatMember') {
        report_telegram_failure($method, (string) ($r['description'] ?? 'unknown'));
    }
    return $r;
}

/** خطای تلگرام را برای مدیر می‌فرستد؛ با یک حفاظ تا حلقه‌ی بی‌پایان ساخته نشود. */
/**
 * خطاهایی که نشانه‌ی مشکل ربات نیستند؛ مثلاً کاربری که گفتگو را حذف کرده یا ربات را مسدود کرده است.
 * این خطاها فقط در لاگ ثبت می‌شوند و به گروه گزارش‌ها یا مدیر فرستاده نمی‌شوند.
 */
function is_benign_telegram_error(string $description): bool
{
    foreach ([
        'chat not found', 'bot was blocked by the user', 'user is deactivated', 'chat_id is empty',
        'PEER_ID_INVALID', 'message is not modified', 'message to edit not found',
        'message to delete not found', "message can't be deleted", 'query is too old',
        'skipped: empty text', 'text must be non-empty',
    ] as $phrase) {
        if (stripos($description, $phrase) !== false) {
            return true;
        }
    }
    return false;
}

function report_telegram_failure(string $method, string $description): void
{
    static $sending = false;
    error_log('[SimorghBot] ' . $method . ': ' . $description);
    if ($sending || is_benign_telegram_error($description)) {
        return;
    }
    if (report_chat() !== '') {
        $sending = true;
        try {
            report_error('telegram', $method . ': ' . $description);
        } finally {
            $sending = false;
        }
        return;
    }
    $sending = true;
    try {
        $admin = (int) cfg('admin_id');
        if ($admin > 0) {
            tg_raw((string) cfg('bot_token'), 'sendMessage', [
                'chat_id' => $admin,
                'text' => 'خطای تلگرام' . "\n" . $method . ': ' . mb_substr($description, 0, 300),
            ]);
        }
    } finally {
        $sending = false;
    }
}

function send_text(int $chatId, string $text, ?string $markup = null, ?int $threadId = null): array
{
    // تلگرام متن خالی را نمی‌پذیرد؛ هر متن خالی با یک نویسه‌ی نامرئی جایگزین می‌شود.
    if (is_blank_text($text)) {
        return ['ok' => false, 'description' => 'skipped: empty text'];
    }
    // پیام‌های دکمه‌دار همیشه عرض ثابت دارند تا دکمه‌ها کوچک نشوند.
    if ($markup !== null && strpos($text, RULE) === false) {
        $text .= "\n" . RULE;
    }
    return tg('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
        'reply_markup' => $markup,
        'message_thread_id' => $threadId,
    ]);
}

/** کیبورد پایین صفحه را پنهان می‌کند (با یک پیام کوتاه که بلافاصله حذف می‌شود). */
function hide_reply_keyboard(int $chatId): void
{
    $r = tg('sendMessage', [
        'chat_id' => $chatId,
        'text' => ".",
        'reply_markup' => json_encode(['remove_keyboard' => true]),
    ]);
    if (!empty($r['ok'])) {
        delete_message($chatId, (int) $r['result']['message_id']);
    }
}

function send_photo(int $chatId, string $fileId, string $caption, ?string $markup = null, ?int $threadId = null): array
{
    return tg('sendPhoto', [
        'chat_id' => $chatId,
        'photo' => $fileId,
        'caption' => $caption,
        'parse_mode' => 'HTML',
        'reply_markup' => $markup,
        'message_thread_id' => $threadId,
    ]);
}

function copy_message(int $toChat, int $fromChat, int $messageId, ?string $markup = null): array
{
    return tg('copyMessage', [
        'chat_id' => $toChat,
        'from_chat_id' => $fromChat,
        'message_id' => $messageId,
        'reply_markup' => $markup,
    ]);
}

function delete_message(int $chatId, int $messageId): void
{
    // پیامی که قبلاً حذف شده یا ارسال نشده، خطا نیست؛ بی‌صدا نادیده گرفته می‌شود.
    if ($messageId > 0) {
        tg_raw((string) cfg('bot_token'), 'deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }
}

function answer_cb(string $callbackId, string $text = ''): void
{
    tg('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text]);
}

/** آیا کاربر در همه‌ی کانال‌های عضویت اجباری است؟ */
function is_member(int $uid): bool
{
    foreach (rows('SELECT channel FROM force_join ORDER BY id') as $row) {
        $chat = trim((string) $row['channel']);
        if (strpos($chat, 'https://t.me/') === 0) {
            $chat = '@' . trim(substr($chat, strlen('https://t.me/')), '/');
        }
        if ($chat !== '' && $chat[0] !== '@' && !ctype_digit(ltrim($chat, '-'))) {
            $chat = '@' . $chat;
        }
        $r = tg('getChatMember', ['chat_id' => $chat, 'user_id' => $uid]);
        $status = $r['result']['status'] ?? '';
        if (empty($r['ok']) || in_array($status, ['left', 'kicked'], true)) {
            return false;
        }
    }
    return true;
}

/** دانلود یک فایل باینری (مثلاً عکس پروفایل). در صورت خطا null برمی‌گرداند. */
function tg_download(string $url): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return is_string($raw) && $code === 200 && $raw !== '' ? $raw : null;
}

/** متنی که تلگرام خالی می‌داند: فقط فاصله، فاصله‌ی ناشکسته، نویسه‌های نامرئی یا تگ‌های بی‌متن. */
function is_blank_text(string $text): bool
{
    $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = (string) preg_replace('/[\s\x{00A0}\x{200B}-\x{200F}\x{2028}-\x{202F}\x{2060}\x{FEFF}]+/u', '', $plain);
    return $plain === '';
}

/**
 * پیام خالی هرگز فرستاده نمی‌شود (تلگرام نویسه‌های نامرئی را هم خالی می‌داند).
 * منبع آن در لاگ ثبت می‌شود تا اگر جایی متن خالی ساخته شد، پیدا شود.
 */
function skip_blank_send(string $method): array
{
    $frames = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), 1, 3);
    $from = implode(' < ', array_map(static fn(array $f): string => (string) ($f['function'] ?? '?'), $frames));
    error_log('[SimorghBot] empty ' . $method . ' skipped; called from: ' . $from);
    return ['ok' => false, 'description' => 'skipped: empty text'];
}
