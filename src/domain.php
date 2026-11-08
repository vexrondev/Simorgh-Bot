<?php
/**
 * منطق کسب‌وکار مشترک بین ربات، پنل مدیریت، گزارش‌ها و API مینی‌اپ.
 * قوانین مالی (کسر کیف پول، تأیید شارژ، تکمیل و رد سفارش) فقط یک‌بار و اتمیک نوشته شده‌اند.
 */
declare(strict_types=1);

const REPORT_TOPICS = [
    'users' => 'سایر گزارشات',
    'finance' => 'گزارش مالی',
    'errors' => 'گزارش خطا',
    'purchases' => 'گزارش خرید',
    'backup' => 'پشتیبان‌گیری',
];

/* ------------------------------------------------------------------ کاربران و مدیران */

/** پاداش هر دعوت موفق (تومان). از پنل مدیریت قابل تنظیم است. */
function referral_reward(): int
{
    return max(0, (int) setting_get('referral_reward', '10000'));
}

function is_blocked(int $uid): bool
{
    $r = one('SELECT blocked FROM users WHERE user_id = ?', [$uid]);
    return $r !== null && (int) $r['blocked'] === 1;
}

/** نقش مدیر: full (همه‌چیز)، finance (مالی و سفارش‌ها)، support (پشتیبانی و کاربران). */
function admin_role(int $uid): string
{
    if ($uid === (int) cfg('admin_id')) {
        return 'full';
    }
    $r = one('SELECT role FROM admins WHERE user_id = ?', [$uid]);
    return $r === null ? 'full' : (string) $r['role'];
}

function admin_allowed(int $uid, string $data): bool
{
    $role = admin_role($uid);
    if ($role === 'full' || $data === 'admin_panel' || $data === 'admin_home' || $data === 'admin_cancel' || $data === 'back_main') {
        return true;
    }
    $finance = ['admin_finance', 'fin_', 'zarin_', 'admin_pending', 'admin_stats', 'wallet_approve_',
        'wallet_reject_', 'order_reply_', 'order_reject_', 'order_view_', 'admin_orders'];
    $support = ['admin_support', 'admin_tickets', 'ticket_', 'admin_users', 'users_', 'user_', 'admin_test',
        'set_test_limit', 'test_reply_', 'test_reject_', 'umsg_'];
    $list = $role === 'finance' ? $finance : $support;
    foreach ($list as $prefix) {
        if (str_starts_with($data, $prefix)) {
            return true;
        }
    }
    return false;
}

function admin_ids(): array
{
    $ids = [(int) cfg('admin_id')];
    foreach (rows('SELECT user_id FROM admins') as $r) {
        $ids[] = (int) $r['user_id'];
    }
    return array_values(array_unique($ids));
}

function is_admin(int $uid): bool
{
    return in_array($uid, admin_ids(), true);
}

function get_user(int $uid): ?array
{
    return one('SELECT * FROM users WHERE user_id = ?', [$uid]);
}

/** کاربر را می‌سازد یا نام و یوزرنیمش را به‌روز می‌کند. */
function upsert_user(int $uid, string $username, string $firstName): void
{
    q('INSERT INTO users (user_id, username, first_name, balance, test_count, joined_at)
       VALUES (?, ?, ?, 0, 0, ?)
       ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name)',
        [$uid, $username, $firstName, now()]);
}

/** اعمال افزایش/کاهش موجودی توسط مدیر (موجودی منفی نمی‌شود). */
function adjust_balance(int $uid, int $delta): bool
{
    if (get_user($uid) === null) {
        return false;
    }
    q('UPDATE users SET balance = GREATEST(0, balance + ?) WHERE user_id = ?', [$delta, $uid]);
    return true;
}

function bot_username(): string
{
    // نام کاربری ربات همیشه با توکن فعلی تطبیق داده می‌شود؛ اگر توکن عوض شده باشد، دوباره از تلگرام گرفته می‌شود
    $tag = substr(hash('sha256', (string) cfg('bot_token')), 0, 16);
    if (setting_get('bot_username_tag', '') === $tag) {
        $cached = (string) setting_get('bot_username', '');
        if ($cached !== '') {
            return $cached;
        }
    }
    $r = tg('getMe');
    $name = (string) ($r['result']['username'] ?? '');
    if ($name !== '') {
        setting_set('bot_username', $name);
        setting_set('bot_username_tag', $tag);
    }
    return $name;
}

function referral_link(int $uid): string
{
    return 'https://t.me/' . bot_username() . '?start=ref_' . $uid;
}

/** پاداش زیرمجموعه‌گیری؛ فقط برای کاربر جدید و فقط یک‌بار. */
function apply_referral(int $newUid, int $referrerId): void
{
    if ($referrerId === $newUid || get_user($referrerId) === null) {
        return;
    }
    try {
        q('INSERT INTO referrals (referrer_id, referred_user_id, reward, created_at) VALUES (?, ?, ?, ?)',
            [$referrerId, $newUid, referral_reward(), now()]);
    } catch (PDOException $e) {
        return; // قبلاً ثبت شده است
    }
    q('UPDATE users SET balance = balance + ? WHERE user_id = ?', [referral_reward(), $referrerId]);
    send_text($referrerId, ' <b>دعوت موفق</b>' . "\n\n" .
        '' . money(referral_reward()) . ' تومان به کیف پول شما اضافه شد.' . "\n" .
        'موجودی جدید: <b>' . money(get_user($referrerId)['balance']) . ' تومان</b>');
    send_report('users', ' <b>دعوت موفق</b>' . "\nدعوت‌شده: <code>" . $newUid . '</code>' .
        "\n دعوت‌کننده: <code>" . $referrerId . '</code>');
}

/* ------------------------------------------------------------------ گزارش‌ها (سوپرگروه با تاپیک) */

function report_chat(): string
{
    return setting_get('report_chat_id', '');
}

/** شناسه‌ی تاپیک یک دسته‌ی گزارش؛ در صورت نبود، می‌سازد. */
function ensure_topic(string $category): ?int
{
    $chat = report_chat();
    if ($chat === '' || !isset(REPORT_TOPICS[$category])) {
        return null;
    }
    $existing = setting_get('report_topic2_' . $category, '');
    if ($existing !== '') {
        return (int) $existing;
    }
    $r = tg('createForumTopic', ['chat_id' => $chat, 'name' => REPORT_TOPICS[$category]]);
    if (empty($r['ok'])) {
        return null;
    }
    $tid = (int) $r['result']['message_thread_id'];
    setting_set('report_topic2_' . $category, (string) $tid);
    return $tid;
}

/** ارسال گزارش به تاپیک مربوط. اگر تاپیک حذف شده باشد، دوباره ساخته می‌شود. */
function send_report(string $category, string $text, ?string $photoId = null, ?string $markup = null): void
{
    $chat = report_chat();
    if ($chat === '') {
        return;
    }
    $token = (string) cfg('bot_token');
    $last = ['ok' => false, 'description' => 'unknown'];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $tid = ensure_topic($category);
        $params = ['chat_id' => $chat, 'message_thread_id' => $tid, 'parse_mode' => 'HTML',
                   'disable_web_page_preview' => 'true'];
        if ($markup !== null) {
            $params['reply_markup'] = $markup;
        }
        if ($photoId !== null) {
            $params += ['photo' => $photoId, 'caption' => $text];
            $last = tg_raw($token, 'sendPhoto', $params);
        } else {
            $params += ['text' => $text];
            $last = tg_raw($token, 'sendMessage', $params);
        }
        if (!empty($last['ok'])) {
            return;
        }
        // تاپیک حذف شده است؛ دفعه‌ی بعد ساخته می‌شود و خطای موقت به مدیر گزارش نمی‌شود
        setting_set('report_topic2_' . $category, '');
    }
    report_telegram_failure('sendMessage', (string) ($last['description'] ?? 'unknown'));
}

/** بررسی و ثبت سوپرگروه گزارش (باید Topics فعال باشد). */
function set_report_chat(string $chatId): array
{
    $r = tg('getChat', ['chat_id' => $chatId]);
    if (empty($r['ok'])) {
        return ['ok' => false, 'error' => 'گروه پیدا نشد. ربات را به‌عنوان مدیر گروه اضافه کنید.'];
    }
    $chat = $r['result'];
    if (($chat['type'] ?? '') !== 'supergroup' || empty($chat['is_forum'])) {
        return ['ok' => false, 'error' => 'باید یک سوپرگروه با قابلیت Topics (تاپیک) باشد.'];
    }
    setting_set('report_chat_id', $chatId);
    foreach (array_keys(REPORT_TOPICS) as $cat) {
        setting_set('report_topic2_' . $cat, '');
    }
    foreach (array_keys(REPORT_TOPICS) as $cat) {
        ensure_topic($cat);
    }
    return ['ok' => true, 'title' => (string) ($chat['title'] ?? $chatId)];
}

/* ------------------------------------------------------------------ خرید و سفارش */

function product_by_id(int $id): ?array
{
    return one('SELECT * FROM products WHERE id = ?', [$id]);
}

/* ------------------------------------------------------------------ کد تخفیف */

function discount_row(string $code): ?array
{
    $code = strtoupper(trim($code));
    return $code === '' ? null : one('SELECT * FROM discount_codes WHERE code = ?', [$code]);
}

/** وضعیت کد: active | disabled | expired | exhausted */
function discount_state(array $d): string
{
    if ((int) $d['active'] !== 1) {
        return 'disabled';
    }
    if ($d['expires_at'] !== null && strtotime((string) $d['expires_at']) <= time()) {
        return 'expired';
    }
    if ($d['max_uses'] !== null && (int) $d['used_count'] >= (int) $d['max_uses']) {
        return 'exhausted';
    }
    return 'active';
}

/**
 * اعتبارسنجی کد برای یک مشتری. برمی‌گرداند: ['discount' => ردیف] یا ['error' => پیام].
 */
function discount_validate(string $code, int $uid): array
{
    $d = discount_row($code);
    if ($d === null) {
        return ['error' => 'کد تخفیف وارد شده معتبر نیست.'];
    }
    switch (discount_state($d)) {
        case 'disabled':
            return ['error' => 'این کد تخفیف غیرفعال شده است.'];
        case 'expired':
            return ['error' => 'مهلت استفاده از این کد تخفیف به پایان رسیده است.'];
        case 'exhausted':
            return ['error' => 'ظرفیت استفاده از این کد تخفیف تکمیل شده است.'];
    }
    $scope = (string) ($d['scope'] ?? 'all_once');
    if ($scope === 'first_order' && customer_has_orders($uid)) {
        return ['error' => 'این کد تخفیف فقط برای اولین خرید شما معتبر است.'];
    }
    if ($scope !== 'all' && one('SELECT id FROM discount_uses WHERE code_id = ? AND user_id = ?', [(int) $d['id'], $uid]) !== null) {
        return ['error' => 'شما قبلاً از این کد تخفیف استفاده کرده‌اید.'];
    }
    return ['discount' => $d];
}

/** آیا این مشتری تا حالا سفارش معتبری داشته است؟ */
function customer_has_orders(int $uid): bool
{
    return one('SELECT id FROM orders WHERE user_id = ? AND status NOT IN (?, ?) LIMIT 1',
        [$uid, 'rejected', 'cancelled']) !== null;
}

/**
 * رزرو یک استفاده از کد. باید داخل تراکنش فراخوانی شود.
 * به‌روزرسانی شرطی باعث می‌شود دو مشتری همزمان نتوانند آخرین استفاده را بگیرند.
 */
function claim_discount(array $d, int $uid, string $orderId): bool
{
    $st = q('UPDATE discount_codes SET used_count = used_count + 1
             WHERE id = ? AND active = 1 AND (max_uses IS NULL OR used_count < max_uses)
             AND (expires_at IS NULL OR expires_at > ?)', [(int) $d['id'], now()]);
    if ($st->rowCount() !== 1) {
        return false;
    }
    try {
        q('INSERT INTO discount_uses (code_id, user_id, order_id, created_at) VALUES (?, ?, ?, ?)',
            [(int) $d['id'], $uid, $orderId, now()]);
    } catch (PDOException $e) {
        q('UPDATE discount_codes SET used_count = used_count - 1 WHERE id = ?', [(int) $d['id']]);
        return false;
    }
    return true;
}

/** آزاد کردن استفاده‌ی کد وقتی سفارش رد یا لغو می‌شود. */
function release_discount(string $orderId): void
{
    $use = one('SELECT * FROM discount_uses WHERE order_id = ?', [$orderId]);
    if ($use === null) {
        return;
    }
    q('DELETE FROM discount_uses WHERE id = ?', [(int) $use['id']]);
    q('UPDATE discount_codes SET used_count = GREATEST(0, used_count - 1) WHERE id = ?', [(int) $use['code_id']]);
}

/** قیمت پس از اعمال تخفیف. قیمت نهایی هم برای کسر کیف پول و هم برای سفارش استفاده می‌شود. */
function price_with_discount(int $price, ?array $discount): array
{
    if ($discount === null) {
        return ['price' => $price, 'discount' => 0, 'code' => null, 'percent' => 0];
    }
    $off = (int) round($price * (int) $discount['percent'] / 100);
    return ['price' => max(0, $price - $off), 'discount' => $off,
        'code' => (string) $discount['code'], 'percent' => (int) $discount['percent']];
}

/** کد تخفیفی که کاربر برای خرید بعدی وارد کرده است (در صورت وجود). */
function current_discount(int $uid): ?string
{
    $code = state_get($uid, 'discount');
    return $code === '' ? null : $code;
}

/** خرید از کیف پول با قیمت نهایی (پس از تخفیف). */
function buy_with_wallet(int $uid, int $productId, ?string $code = null): array
{
    $product = product_by_id($productId);
    if ($product === null) {
        return ['ok' => false, 'error' => 'این پلن در حال حاضر موجود نیست.'];
    }
    $discount = null;
    if ($code !== null) {
        $checked = discount_validate($code, $uid);
        if (isset($checked['error'])) {
            return ['ok' => false, 'error' => $checked['error']];
        }
        $discount = $checked['discount'];
    }
    $pricing = price_with_discount((int) $product['price'], $discount);
    $price = $pricing['price'];
    $orderId = new_id('ORD');

    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare('UPDATE users SET balance = balance - ? WHERE user_id = ? AND balance >= ?');
    $st->execute([$price, $uid, $price]);
    if ($st->rowCount() !== 1) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'موجودی کیف پول برای این خرید کافی نیست.'];
    }
    if ($discount !== null && !claim_discount($discount, $uid, $orderId)) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'این کد تخفیف دیگر قابل استفاده نیست.'];
    }
    $ins = $pdo->prepare('INSERT INTO orders (id, user_id, product_id, amount, payment_method, status, created_at,
                          discount_code, discount_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$orderId, $uid, $productId, $price, 'wallet', 'waiting_panel_details', now(),
        $pricing['code'], $pricing['discount']]);
    $pdo->commit();

    $product['price'] = $price;
    $stock = reserve_stock((int) $product['id'], $uid, $orderId);
    if ($stock !== null && deliver_stock_order($orderId, $stock)) {
        return ['ok' => true, 'order_id' => $orderId, 'product' => $product, 'amount' => $price, 'delivered' => true];
    }
    notify_order_to_admins($orderId, $uid, $product, 'خرید از کیف پول');
    return ['ok' => true, 'order_id' => $orderId, 'product' => $product, 'amount' => $price, 'delivered' => false];
}

/** ثبت سفارش کارت‌به‌کارت با قیمت نهایی. کد تخفیف باید قبلاً معتبر بودنش بررسی شده باشد. */
function create_card_order(int $uid, int $productId, ?string $code = null): ?string
{
    $product = product_by_id($productId);
    if ($product === null) {
        return null;
    }
    $discount = null;
    if ($code !== null) {
        $checked = discount_validate($code, $uid);
        if (isset($checked['error'])) {
            return null;
        }
        $discount = $checked['discount'];
    }
    $pricing = price_with_discount((int) $product['price'], $discount);
    $orderId = new_id('ORD');
    $pdo = db();
    $pdo->beginTransaction();
    if ($discount !== null && !claim_discount($discount, $uid, $orderId)) {
        $pdo->rollBack();
        return null;
    }
    $pdo->prepare('INSERT INTO orders (id, user_id, product_id, amount, payment_method, status, created_at,
                   discount_code, discount_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$orderId, $uid, $productId, $pricing['price'], 'card', 'waiting_receipt', now(),
            $pricing['code'], $pricing['discount']]);
    $pdo->commit();
    return $orderId;
}

function attach_order_receipt(string $orderId, int $uid, string $fileId): bool
{
    $st = q("UPDATE orders SET receipt_file_id = ?, status = 'waiting_admin'
             WHERE id = ? AND user_id = ? AND status = 'waiting_receipt'", [$fileId, $orderId, $uid]);
    if ($st->rowCount() !== 1) {
        return false;
    }
    $order = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    $product = product_by_id((int) $order['product_id']) ?? ['name' => 'محصول'];
    $product['price'] = (int) $order['amount'];
    notify_order_to_admins($orderId, $uid, $product, 'رسید کارت‌به‌کارت', $fileId);
    return true;
}

function notify_order_to_admins(string $orderId, int $uid, array $product, string $source, ?string $photoId = null): void
{
    $text = '<b>سفارش جدید (' . h($source) . ')</b>' . "\n\n" .
        'سفارش: <code>' . h($orderId) . '</code>' . "\n" .
        'کاربر: <code>' . $uid . '</code>' . "\n" .
        'محصول: ' . h($product['name'] ?? '') . "\n" .
        'مبلغ: ' . money($product['price'] ?? 0) . ' تومان';
    $markup = kb([[
        btn('ارسال اطلاعات پنل', 'order_reply_' . $orderId, 'success'),
        btn('رد', 'order_reject_' . $orderId, 'danger'),
    ], [admin_user_button($uid)]]);
    foreach (admin_ids() as $aid) {
        if ($photoId !== null) {
            send_photo((int) $aid, $photoId, $text, $markup);
        } else {
            send_text((int) $aid, $text, $markup);
        }
    }
    send_report($photoId !== null ? 'finance' : 'purchases', $text, $photoId);
}

/** تکمیل سفارش با اطلاعات پنل (توسط مدیر). */
function complete_order(string $orderId, int $adminChat, int $adminMsgId, string $details, ?string $receipt = null): bool
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $order = one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null) {
            $pdo->rollBack();
            return false;
        }

        $st = q(
            "UPDATE orders SET status = 'completed', panel_details = ?
             WHERE id = ? AND status NOT IN ('completed', 'rejected', 'cancelled')",
            [$details, $orderId]
        );
        if ($st->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        $product = product_by_id((int) $order['product_id']);
        q('INSERT INTO panels (user_id, title, details, status, order_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [(int) $order['user_id'], $product['name'] ?? 'پنل', $details, 'active', $orderId, now()]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[SimorghBot complete_order] ' . $e->getMessage());
        return false;
    }

    if ($receipt !== null) {
        send_text((int) $order['user_id'], $receipt);
    } elseif ($adminMsgId > 0) {
        copy_message((int) $order['user_id'], $adminChat, $adminMsgId);
        send_text((int) $order['user_id'], '<b>اطلاعات سرویس شما ارسال شد.</b>' . "\n" .
            'برای مشاهده، به بخش «پنل‌های من» مراجعه کنید.');
    } else {
        send_text((int) $order['user_id'], '<code>' . h($details) . '</code>');
        send_text((int) $order['user_id'], '<b>اطلاعات سرویس شما ارسال شد.</b>' . "\n" .
            'برای مشاهده، به بخش «پنل‌های من» مراجعه کنید.');
    }
    send_purchase_report($orderId, '✅ سفارش تکمیل شد', '✅');
    return true;
}

/** رد سفارش؛ اگر با کیف پول بوده، مبلغ برمی‌گردد. */
function reject_order(string $orderId): bool
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $order = one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null) {
            $pdo->rollBack();
            return false;
        }

        $st = q(
            "UPDATE orders SET status = 'rejected' WHERE id = ?
             AND status NOT IN ('rejected', 'completed', 'cancelled')",
            [$orderId]
        );
        if ($st->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        if ($order['payment_method'] === 'wallet') {
            q('UPDATE users SET balance = balance + ? WHERE user_id = ?',
                [(int) $order['amount'], (int) $order['user_id']]);
        }
        release_discount($orderId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[SimorghBot reject_order] ' . $e->getMessage());
        return false;
    }

    send_text((int) $order['user_id'], '<b>❌ درخواست خرید شما تأیید نشد</b>' . "\n" . RULE . "\n" .
        'سفارش: <code>' . h($orderId) . '</code>' .
        ($order['payment_method'] === 'wallet' ? "\nمبلغ به کیف پول شما برگشت داده شد." : ''));
    send_purchase_report($orderId, '❌ سفارش رد شد', '❌');
    return true;
}

function user_orders(int $uid, int $limit = 15): array
{
    return rows('SELECT o.*, p.name AS product_name FROM orders o
                 LEFT JOIN products p ON p.id = o.product_id
                 WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT ' . (int) $limit, [$uid]);
}

function active_panels(int $uid): array
{
    return rows("SELECT * FROM panels WHERE user_id = ? AND status = 'active' ORDER BY id DESC", [$uid]);
}

function payment_info(): array
{
    return ['card' => setting_get('payment_card', 'ثبت نشده'), 'owner' => setting_get('payment_owner', 'ثبت نشده')];
}

/* ------------------------------------------------------------------ کیف پول */

/** ثبت شارژ کیف پول در انتظار رسید. */
function create_charge(int $uid, int $amount): string
{
    $cid = new_id('CHG');
    q("INSERT INTO wallet_charges (id, user_id, amount, status, created_at) VALUES (?, ?, ?, 'waiting', ?)",
        [$cid, $uid, $amount, now()]);
    send_report('finance', report_card('💳', 'درخواست شارژ کیف پول', [report_user_line($uid), 'مبلغ: <b>' . money($amount) . '</b> تومان', 'شناسه‌ی شارژ: <code>' . h($cid) . '</code>']), null, report_markup($uid));
    return $cid;
}

function attach_charge_receipt(string $cid, int $uid, string $fileId): bool
{
    $st = q("UPDATE wallet_charges SET receipt_file_id = ?, receipt_at = ?, status = 'waiting_admin'
             WHERE id = ? AND user_id = ? AND status = 'waiting'", [$fileId, now(), $cid, $uid]);
    if ($st->rowCount() < 1) {
        return false;
    }
    $c = one('SELECT * FROM wallet_charges WHERE id = ?', [$cid]);
    $caption = '<b>رسید افزایش موجودی</b>' . "\n\n" .
        'کاربر: <code>' . $uid . '</code>' . "\n" .
        'مبلغ: <b>' . money($c['amount']) . ' تومان</b>' . "\n" .
        'شارژ: <code>' . h($cid) . '</code>';
    $markup = kb([[btn('تأیید', 'wallet_approve_' . $cid, 'success'), btn('❌ رد پرداخت', 'wallet_reject_' . $cid, 'danger')], [admin_action_button('➕ افزودن دستی موجودی', 'bp', $uid), admin_action_button('🚫 بلاک کردن کاربر', 'bk', $uid)], [admin_user_button($uid)]]);
    foreach (admin_ids() as $aid) {
        send_photo((int) $aid, $fileId, $caption, $markup);
    }
    // اگر تأیید خودکار روشن است، بررسی زمان‌بندی‌شده در پس‌زمینه شروع می‌شود (بدون نیاز به پیام کاربر یا cron)
    if (auto_confirm_waiting()) {
        auto_confirm_kick();
    }
    // گزارش مالی فقط مشخصات کامل کاربر و تراکنش را دارد؛ تصویر رسید در گزارش فرستاده نمی‌شود
    $u = get_user($uid);
    $name = ($u !== null && (string) $u['first_name'] !== '') ? h((string) $u['first_name']) : 'ثبت نشده';
    send_report('finance', report_card('🧾', 'درخواست شارژ کیف پول', [
        'نام: <b>' . $name . '</b>',
        report_user_line($uid),
        'مبلغ: <b>' . money($c['amount']) . ' تومان</b>',
        'شناسه‌ی شارژ: <code>' . h($cid) . '</code>',
        'وضعیت: در انتظار بررسی',
    ]));
    return true;
}

/** تأیید شارژ: فقط یک بار اعتبار می‌گیرد، حتی اگر دو مدیر همزمان بزنند. */
function approve_charge(string $cid): bool
{
    // تغییر وضعیت و افزایش موجودی باید اتمیک باشند؛ دو مدیر همزمان فقط یک‌بار اعتبار می‌دهند.
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = q("UPDATE wallet_charges SET status = 'approved'
                 WHERE id = ? AND status = 'waiting_admin' AND receipt_file_id IS NOT NULL", [$cid]);
        if ($st->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $c = one('SELECT * FROM wallet_charges WHERE id = ?', [$cid]);
        if ($c === null) {
            $pdo->rollBack();
            return false;
        }
        q('UPDATE users SET balance = balance + ? WHERE user_id = ?', [(int) $c['amount'], (int) $c['user_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[SimorghBot approve_charge] ' . $e->getMessage());
        return false;
    }
    charge_settle_customer($cid);
    $balance = (int) (get_user((int) $c['user_id'])['balance'] ?? 0);
    send_text((int) $c['user_id'], '<b>✅ شارژ کیف پول شما تأیید شد</b>' . "\n" . RULE . "\n" .
        'مبلغ افزوده‌شده: <b>' . money($c['amount']) . ' تومان</b>' . "\n" .
        'موجودی جدید: <b>' . money($balance) . ' تومان</b>');
    send_report('finance', '<b>شارژ تأیید شد</b>' . "\n<code>" . h($cid) . '</code>');
    return true;
}

/**
 * تأیید اتمیک شارژ آنلاین پس از تأیید موفق زرین‌پال.
 * وضعیت درگاه، شارژ کیف پول و افزایش موجودی در یک تراکنش ثبت می‌شوند.
 */
function approve_online_charge(string $cid, string $authority, string $refId): bool
{
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $payment = one(
            "SELECT * FROM gateway_payments WHERE authority = ? AND charge_id = ? FOR UPDATE",
            [$authority, $cid]
        );
        if ($payment === null || !in_array($payment['status'], ['verifying', 'paid'], true)) {
            $pdo->rollBack();
            return false;
        }

        // پرداختی که قبلاً نهایی شده نباید دوباره به موجودی اضافه شود.
        if ($payment['status'] === 'paid') {
            $pdo->rollBack();
            return one("SELECT id FROM wallet_charges WHERE id = ? AND status = 'approved'", [$cid]) !== null;
        }

        $charge = one("SELECT * FROM wallet_charges WHERE id = ? FOR UPDATE", [$cid]);
        if ($charge === null
            || (int) $charge['user_id'] !== (int) $payment['user_id']
            || (int) $charge['amount'] !== (int) $payment['amount']
            || $charge['status'] !== 'waiting') {
            $pdo->rollBack();
            return false;
        }

        $chargeUpdate = q(
            "UPDATE wallet_charges SET status = 'approved' WHERE id = ? AND status = 'waiting'",
            [$cid]
        );
        if ($chargeUpdate->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        $paymentUpdate = q(
            "UPDATE gateway_payments SET status = 'paid', ref_id = ? WHERE authority = ? AND status = 'verifying'",
            [$refId, $authority]
        );
        if ($paymentUpdate->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        q('UPDATE users SET balance = balance + ? WHERE user_id = ?', [
            (int) $charge['amount'],
            (int) $charge['user_id'],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[SimorghBot approve_online_charge] ' . $e->getMessage());
        return false;
    }

    charge_settle_customer($cid);
    $user = get_user((int) $charge['user_id']);
    $balance = (int) ($user['balance'] ?? 0);
    send_text((int) $charge['user_id'],
        '<b>✅ پرداخت آنلاین با موفقیت تأیید شد</b>' . "\n" . RULE . "\n"
        . 'مبلغ افزوده‌شده: <b>' . money($charge['amount']) . ' تومان</b>' . "\n"
        . 'موجودی جدید: <b>' . money($balance) . ' تومان</b>' . "\n"
        . 'کد پیگیری: <code>' . h($refId) . '</code>');
    send_report('finance', '<b>شارژ آنلاین تأیید شد</b>' . "\n"
        . 'شناسه شارژ: <code>' . h($cid) . '</code>' . "\n"
        . 'کد پیگیری: <code>' . h($refId) . '</code>');
    return true;
}

function reject_charge(string $cid): bool
{
    charge_settle_customer($cid);
    $st = q("UPDATE wallet_charges SET status = 'rejected'
             WHERE id = ? AND status IN ('waiting', 'waiting_admin')", [$cid]);
    if ($st->rowCount() !== 1) {
        return false;
    }
    $c = one('SELECT user_id FROM wallet_charges WHERE id = ?', [$cid]);
    send_text((int) $c['user_id'], '<b>❌ درخواست شارژ کیف پول تأیید نشد</b>' . "\n" . RULE . "\n" . 'در صورت اختلاف، از بخش پشتیبانی ربات پیام دهید.');
    send_report('finance', '<b>شارژ رد شد</b>' . "\n<code>" . h($cid) . '</code>');
    return true;
}

/* ------------------------------------------------------------------ تست رایگان */

function test_limit(): int
{
    return (int) setting_get('test_limit', '1');
}

/** درخواست تست رایگان. برمی‌گرداند: ['ok' => bool, 'error' => string, 'id' => string] */
function create_test_request(int $uid): array
{
    if (!test_enabled()) {
        return ['ok' => false, 'error' => 'تست رایگان در حال حاضر در دسترس نیست.'];
    }
    $user = get_user($uid);
    if ($user === null) {
        return ['ok' => false, 'error' => 'کاربر پیدا نشد.'];
    }
    if ((int) $user['test_count'] >= test_limit()) {
        return ['ok' => false, 'error' => 'سهمیه‌ی تست رایگان شما به پایان رسیده است.'];
    }
    if ((int) scalar("SELECT COUNT(*) FROM test_requests WHERE user_id = ? AND status = 'waiting'", [$uid]) > 0) {
        return ['ok' => false, 'error' => 'درخواست تست قبلی شما هنوز در حال بررسی است.'];
    }
    $tid = new_id('TST');
    q("INSERT INTO test_requests (id, user_id, status, created_at) VALUES (?, ?, 'waiting', ?)", [$tid, $uid, now()]);
    $text = '<b>درخواست تست رایگان</b>' . "\n\n" .
        report_user_line($uid) . "\n" .
        'درخواست: <code>' . h($tid) . '</code>';
    $markup = kb([[btn('ارسال تست', 'test_reply_' . $tid, 'success'), btn('رد', 'test_reject_' . $tid, 'danger')]]);
    foreach (admin_ids() as $aid) {
        send_text((int) $aid, $text, $markup);
    }
    send_report('users', $text);
    return ['ok' => true, 'id' => $tid];
}

/* ------------------------------------------------------------------ پشتیبانی */

function open_ticket(int $uid, string $message, ?string $photoId = null): int
{
    q("INSERT INTO tickets (user_id, message, status, created_at, updated_at) VALUES (?, ?, 'open', ?, ?)",
        [$uid, $message, now(), now()]);
    $tid = (int) db()->lastInsertId();
    $text = '<b>تیکت جدید #' . $tid . '</b>' . "\n" .
        'کاربر: <code>' . $uid . '</code>' . "\n\n" . h($message);
    $markup = kb([[btn('پاسخ', 'ticket_reply_' . $tid, 'success'), btn('بستن', 'ticket_close_' . $tid, 'danger')]]);
    foreach (admin_ids() as $aid) {
        if ($photoId !== null) {
            send_photo((int) $aid, $photoId, $text, $markup);
        } else {
            send_text((int) $aid, $text, $markup);
        }
    }
    send_report('users', $text, $photoId);
    return $tid;
}

/* ------------------------------------------------------------ موجودی پنل‌ها */

/**
 * یک پنل آماده‌ی همین پلن را برای سفارش رزرو می‌کند.
 * به‌روزرسانی شرطی باعث می‌شود دو خرید همزمان یک پنل را نگیرند.
 */
function reserve_stock(int $productId, int $uid, string $orderId): ?array
{
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $row = one("SELECT * FROM panel_stock WHERE product_id = ? AND status = 'available' ORDER BY id LIMIT 1", [$productId]);
        if ($row === null) {
            return null;
        }
        $st = q("UPDATE panel_stock SET status = 'sold', user_id = ?, order_id = ?, sold_at = ?
                 WHERE id = ? AND status = 'available'", [$uid, $orderId, now(), (int) $row['id']]);
        if ($st->rowCount() === 1) {
            $row['status'] = 'sold';
            $row['order_id'] = $orderId;
            return $row;
        }
    }
    return null;
}

/** آزاد کردن پنل رزروشده وقتی تحویل انجام نشد. */
function release_stock(int $stockId): void
{
    q("UPDATE panel_stock SET status = 'available', user_id = NULL, order_id = NULL, sold_at = NULL WHERE id = ?", [$stockId]);
}

/** تحویل خودکار: رسید و مشخصات پنل برای مشتری ارسال می‌شود. */
function deliver_stock_order(string $orderId, array $stock): bool
{
    $order = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if ($order === null) {
        release_stock((int) $stock['id']);
        return false;
    }
    $product = product_by_id((int) $order['product_id']);
    $productName = (string) ($product['name'] ?? 'پلن');
    $details = 'آدرس پنل: ' . $stock['address'] . "\n" .
        'نام کاربری: ' . $stock['name'] . "\n" .
        'رمز عبور: ' . $stock['password'];
    $receipt = '<b>رسید خرید</b>' . "\n" . RULE . "\n" .
        'پلن: <b>' . h($productName) . '</b>' . "\n" .
        'شماره سفارش: <code>' . h($orderId) . '</code>' . "\n" .
        'تاریخ: ' . h(now()) . "\n\n" .
        '<b>مشخصات پنل</b>' . "\n\n" .
        panel_details_html($details) . "\n\n" .
        'این اطلاعات در بخش «پنل‌های من» نیز ثبت شد.';
    if (!complete_order($orderId, 0, 0, $details, $receipt)) {
        release_stock((int) $stock['id']);
        return false;
    }
    return true;
}

/* ------------------------------------------------------------ ارسال همگانی */

/** شناسه‌ی عددی خود ربات (بخشی از توکن). به خود ربات هرگز پیام ارسال نمی‌شود. */
function bot_user_id(): int
{
    return (int) explode(':', (string) cfg('bot_token'))[0];
}

function broadcast_markup(string $type): ?string
{
    return match ($type) {
        'buy' => kb([[btn('خرید پنل', 'buy_panel', '')]]),
        'referral' => kb([[btn('دعوت دوستان', 'referral', '')]]),
        'wallet' => kb([[btn('کیف پول', 'wallet', '')]]),
        default => null,
    };
}

/** متن وضعیت عملیات ارسال همگانی (در حال انجام). */
function broadcast_progress(array $job, int $processed): void
{
    $total = (int) $job['total'];
    $text = '<b>📤 ارسال همگانی در حال انجام است</b>' . "\n" . RULE . "\n" .
        'پیشرفت: <b>' . fa((string) $processed) . '</b> از <b>' . fa((string) $total) . '</b>' . "\n" .
        'باقی‌مانده: <b>' . fa((string) max(0, $total - $processed)) . '</b>' . "\n" .
        'موفق: ' . fa((string) $job['ok_count']) . ' | ناموفق: ' . fa((string) $job['fail_count']);
    tg_raw((string) cfg('bot_token'), 'editMessageText', [
        'chat_id' => (int) $job['admin_id'], 'message_id' => (int) $job['progress_msg'],
        'text' => $text, 'parse_mode' => 'HTML',
        'reply_markup' => kb([[btn('لغو عملیات', 'bc_cancel_' . (int) $job['id'], '')]]),
    ]);
}

/** پایان عملیات: پیام پیشرفت حذف و نتیجه برای مدیر ارسال می‌شود. */
function broadcast_finish(array $job): void
{
    q("UPDATE broadcast_jobs SET status = 'done' WHERE id = ? AND status = 'running'", [(int) $job['id']]);
    if ((int) $job['progress_msg'] > 0) {
        delete_message((int) $job['admin_id'], (int) $job['progress_msg']);
    }
    send_text((int) $job['admin_id'], '<b>✅ ارسال همگانی با موفقیت انجام شد</b>' . "\n" . RULE . "\n" .
        'موفق: <b>' . fa((string) $job['ok_count']) . '</b>' . "\n" .
        'ناموفق: <b>' . fa((string) $job['fail_count']) . '</b>',
        kb([admin_back('admin_home')]));
}

/**
 * یک دسته‌ی ارسال. برمی‌گرداند true اگر کاری انجام شد (کار در صف بود).
 */
function broadcast_tick(int $batch): bool
{
    $job = one("SELECT * FROM broadcast_jobs WHERE status = 'running' ORDER BY id LIMIT 1");
    if ($job === null) {
        return false;
    }
    $last = (int) $job['last_user'];
    $users = rows('SELECT user_id FROM users WHERE blocked = 0 AND user_id > ? AND user_id <> ? ORDER BY user_id LIMIT '
        . (int) $batch, [$last, bot_user_id()]);
    if ($users === []) {
        broadcast_finish($job);
        return true;
    }
    $markup = broadcast_markup((string) $job['markup_type']);
    $ok = (int) $job['ok_count'];
    $fail = (int) $job['fail_count'];
    foreach ($users as $u) {
        $target = (int) $u['user_id'];
        $r = copy_message($target, (int) $job['from_chat'], (int) $job['msg_id'], $markup);
        if (!empty($r['ok'])) {
            $ok++;
        } else {
            $fail++;
        }
        $last = $target;
        usleep(35000);
    }
    q('UPDATE broadcast_jobs SET last_user = ?, ok_count = ?, fail_count = ? WHERE id = ?',
        [$last, $ok, $fail, (int) $job['id']]);
    $job['ok_count'] = $ok;
    $job['fail_count'] = $fail;
    broadcast_progress($job, $ok + $fail);
    return true;
}

/* ------------------------------------------------------------ مدت‌های خرید */

/** در نخستین اجرا سه مدت پیش‌فرض ثبت می‌شود. */
function plan_seed(): void
{
    if ((int) scalar('SELECT COUNT(*) FROM plan_durations') === 0) {
        foreach ([1 => 'یک ماهه', 2 => 'دو ماهه', 3 => 'سه ماهه'] as $months => $label) {
            q('INSERT IGNORE INTO plan_durations (months, label, active, created_at) VALUES (?, ?, 1, ?)',
                [$months, $label, now()]);
        }
    }
}

function plan_durations(bool $activeOnly = true): array
{
    plan_seed();
    return rows('SELECT * FROM plan_durations' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY months');
}

function plan_find(int $months): ?array
{
    plan_seed();
    return one('SELECT * FROM plan_durations WHERE months = ?', [$months]);
}

function plan_is_active(int $months): bool
{
    $p = plan_find($months);
    return $p !== null && (int) $p['active'] === 1;
}

/** نام نمایشی یک مدت: نام سفارشی مدیر، یا نام پیش‌فرض بر پایه‌ی تعداد ماه. */
function plan_title(int $months): string
{
    $p = plan_find($months);
    $label = trim((string) ($p['label'] ?? ''));
    return $label !== '' ? $label : fa((string) $months) . ' ماهه';
}

/** فهرست مدت‌های فعال به شکل متن، برای راهنمای ورودی مدیر. */
function plan_list_text(): string
{
    $nums = array_map(static fn(array $p): string => fa((string) (int) $p['months']), plan_durations(true));
    return $nums === [] ? '—' : implode('، ', $nums);
}

/* ------------------------------------------------------------ متن‌های قابل ویرایش ربات */

/** متن‌هایی که مدیر می‌تواند ویرایش کند. */
function bot_text_parts(): array
{
    return [
        'welcome' => ['title' => 'متن خوش‌آمد', 'hint' => 'می‌توانید از {name} برای نام کاربر و از {store} برای نام فروشگاه استفاده کنید.',
            'setting' => 'welcome_text', 'default' => ''],
        'buy_intro' => ['title' => 'متن صفحه‌ی خرید پنل', 'hint' => 'پیش از انتخاب مدت سرویس نمایش داده می‌شود.',
            'setting' => 'txt_buy_intro', 'default' => "<b>🛒 خرید پنل</b>\n━━━━━━━━━━━━━━━━━━━━\n\nمدت سرویس را انتخاب کنید:"],
        'rules' => ['title' => 'متن قوانین ربات', 'hint' => 'این متن در نخستین ورود کاربر نمایش داده می‌شود و کاربر باید آن را بپذیرد.',
            'setting' => 'txt_rules', 'default' => "<b>📜 قوانین استفاده از ربات</b>\n━━━━━━━━━━━━━━━━━━━━\n\n۱. استفاده از خدمات ربات فقط برای مصارف قانونی مجاز است.\n۲. خرید و پرداخت فقط از مسیرهای رسمی همین ربات انجام می‌شود.\n۳. مسئولیت حفظ اطلاعات ورود و رمز عبور با خود کاربر است.\n\nبرای ادامه، قوانین را بپذیرید."],
        'rules_done' => ['title' => 'متن پس از پذیرش قوانین', 'hint' => 'بعد از پذیرش قوانین نمایش داده می‌شود و کاربر را به منوی ربات راهنمایی می‌کند.',
            'setting' => 'txt_rules_done', 'default' => "<b>✅ قوانین تأیید شد</b>\n━━━━━━━━━━━━━━━━━━━━\n\nاکنون می‌توانید از خدمات ربات استفاده کنید. برای ورود به منو، دکمه‌ی زیر را بزنید."],
        'not_member' => ['title' => 'هشدار عدم عضویت', 'hint' => 'وقتی کاربر هنوز عضو کانال‌ها نشده و روی بررسی عضویت می‌زند، این هشدار نمایش داده می‌شود.',
            'setting' => 'txt_not_member', 'default' => "❌ <b>هنوز عضو کانال نشده‌اید.</b>\nلطفاً ابتدا در کانال‌های بالا عضو شوید و سپس دوباره بررسی کنید."],
        'force_intro' => ['title' => 'متن عضویت در کانال‌ها', 'hint' => 'پیش از استفاده از ربات، کاربر باید در کانال‌های زیر عضو شود.',
            'setting' => 'txt_force_intro', 'default' => "<b>📢 عضویت در کانال‌ها</b>\n━━━━━━━━━━━━━━━━━━━━\n\nبرای ادامه‌ی استفاده از ربات، لطفاً در کانال‌های زیر عضو شوید.\nپس از عضویت، دکمه‌ی «بررسی عضویت» را بزنید."],
        'support_intro' => ['title' => 'متن مرکز پشتیبانی', 'hint' => 'در بالای منوی پشتیبانی نمایش داده می‌شود.',
            'setting' => 'txt_support_intro', 'default' => "<b>مرکز پشتیبانی</b>\n━━━━━━━━━━━━━━━━━━━━\n\nپاسخ‌های سریع را در سوالات متداول ببینید.\nیا برای بررسی اختصاصی تیکت ثبت کنید."],
    ];
}

/** متن فعلی: نسخه‌ی ذخیره‌شده‌ی مدیر، یا متن پیش‌فرض. */
function bot_text(string $key): string
{
    $parts = bot_text_parts();
    if (!isset($parts[$key])) {
        return '';
    }
    $custom = trim((string) setting_get($parts[$key]['setting'], ''));
    return $custom !== '' ? $custom : $parts[$key]['default'];
}

/** فقط برچسب‌های مجاز تلگرام و برچسب‌های بسته‌شده پذیرفته می‌شوند. */
function bot_text_is_valid(string $text): bool
{
    foreach (['b', 'i', 'u', 'code', 'blockquote'] as $tag) {
        if (substr_count($text, '<' . $tag . '>') !== substr_count($text, '</' . $tag . '>')) {
            return false;
        }
    }
    return preg_match('/<(?!\/?(b|i|u|code|blockquote)>)/', $text) !== 1;
}

/* ------------------------------------------------------------ کارت‌های گزارش */

/** کارت استاندارد گزارش: آیکون، عنوان، خط جداکننده، و سطرهای اطلاعات. */
function report_card(string $icon, string $title, array $lines): string
{
    return '<b>' . $icon . ' ' . h($title) . '</b>' . "\n" . RULE . "\n" .
        implode("\n", $lines) . "\n\n" .
        '<i>' . h(now()) . '</i>';
}

/** مشخصات کاربر برای کارت‌های گزارش. */
function report_user_line(int $uid): string
{
    $u = get_user($uid);
    $name = ($u !== null && (string) $u['first_name'] !== '') ? h((string) $u['first_name']) : '';
    $username = ($u !== null && (string) $u['username'] !== '')
        ? '<a href="https://t.me/' . h((string) $u['username']) . '">@' . h((string) $u['username']) . '</a>'
        : 'ثبت نشده';
    return ($name !== '' ? 'نام: <b>' . $name . '</b>' . "\n" : '') .
        'نام کاربری: ' . $username . "\n" .
        'شناسه‌ی عددی: ' . $uid;
}

/** دکمه‌ی زیر کارت: باز کردن پروفایل کاربر داخل ربات برای مدیر. */
function report_markup(?int $uid): ?string
{
    $bot = bot_username();
    if ($uid === null || $uid <= 0 || $bot === '') {
        return null;
    }
    return kb([[btn_url('👤 مدیریت کاربر', 'https://t.me/' . $bot . '?start=u_' . $uid, 'primary')]]);
}

function admin_role_label(string $role): string
{
    return ['full' => 'کامل', 'finance' => 'مالی', 'support' => 'پشتیبانی'][$role] ?? $role;
}

/** گزارش یک سفارش در بخش گزارش خرید. */
function send_purchase_report(string $orderId, string $status, string $icon): void
{
    $o = one('SELECT o.*, p.name AS pname FROM orders o LEFT JOIN products p ON p.id = o.product_id WHERE o.id = ?', [$orderId]);
    if ($o === null) {
        return;
    }
    $uid = (int) $o['user_id'];
    send_report('purchases', report_card($icon, $status, [
        report_user_line($uid),
        'سفارش: <code>' . h($orderId) . '</code>',
        'پلن: <b>' . h((string) ($o['pname'] ?? '—')) . '</b>',
        'مبلغ: <b>' . money((int) $o['amount']) . '</b> تومان',
    ]), null, report_markup($uid));
}

/**
 * ثبت خطای رخ‌داده برای کاربر در بخش گزارش خطا. خطا هرگز به خود کاربر نشان داده نمی‌شود؛
 * این کارت فقط برای پیگیری مدیر است.
 */
function report_error(string $where, string $message, int $uid = 0, string $action = ''): void
{
    // پیام‌های خیلی کوتاه یا خالی اطلاعاتی ندارند؛ فقط در لاگ ثبت می‌شوند
    if (mb_strlen(trim(strip_tags($message))) < 6) {
        error_log('[SimorghBot] report skipped: ' . $message);
        return;
    }
    try {
        $lines = ['بخش: <b>' . h($where) . '</b>', 'پیام خطا: <code>' . h(mb_substr($message, 0, 800)) . '</code>'];
        if ($uid > 0) {
            $lines[] = report_user_line($uid);
        }
        if ($action !== '') {
            $lines[] = 'عملیات: <code>' . h(mb_substr($action, 0, 120)) . '</code>';
        }
        send_report('errors', report_card('⚠️', 'خطای سیستمی', $lines), null, report_markup($uid > 0 ? $uid : null));
    } catch (Throwable $e) {
        error_log('[SimorghBot] report_error: ' . $e->getMessage());
    }
}

/** کارت کاربر جدید: فقط بار اول که کاربر ربات را راه‌اندازی می‌کند. */
function new_user_card(int $uid): void
{
    send_report('users', report_card('👤', 'کاربر جدید ربات', [
        report_user_line($uid),
        'ورود: <b>' . h(now()) . '</b>',
    ]), null, report_markup($uid));
}

/* ------------------------------------------------------------ پشتیبان‌گیری */

function backup_interval(): int
{
    return max(0, (int) setting_get('backup_hours', '0'));
}

/** نام پایگاه‌داده برای نام فایل و کارت پشتیبان (فقط حروف و عدد و خط تیره). */
function backup_db_label(): string
{
    $name = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) cfg('db_name', 'database'));
    return ($name === null || $name === '') ? 'database' : $name;
}

/**
 * ساخت فایل پشتیبان فشرده از همه‌ی جدول‌ها و همه‌ی ردیف‌ها.
 * تعداد جدول‌ها و ردیف‌های نوشته‌شده برای کنترل برمی‌گردد.
 */
function backup_create(): array
{
    $dir = SB_ROOT . '/storage/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . backup_db_label() . '_' . date('Ymd_His') . '.sql.gz';
    $gz = gzopen($file, 'wb9');
    if ($gz === false) {
        throw new RuntimeException('ساخت فایل پشتیبان ممکن نشد.');
    }
    $pdo = db();
    $tables = 0;
    $rows = 0;
    gzwrite($gz, "-- SimorghBot backup " . now() . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $tables++;
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "\nDROP TABLE IF EXISTS `" . $table . "`;\n" . $create . ";\n");
        $stmt = $pdo->query('SELECT * FROM `' . $table . '`');
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows++;
            $values = array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row));
            gzwrite($gz, 'INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', $values) . ");\n");
        }
        $stmt->closeCursor();
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
    return ['file' => $file, 'tables' => $tables, 'rows' => $rows];
}

/** ارسال فایل پشتیبان به بخش پشتیبان‌گیری گروه گزارش‌ها. */
function backup_send(string $file, string $caption): bool
{
    $chat = report_chat();
    if ($chat === '') {
        return false;
    }
    $params = ['chat_id' => $chat, 'document' => new CURLFile($file, 'application/gzip', basename($file)),
        'caption' => $caption, 'parse_mode' => 'HTML'];
    $tid = ensure_topic('backup');
    if ($tid !== null) {
        $params['message_thread_id'] = $tid;
    }
    $r = tg_raw((string) cfg('bot_token'), 'sendDocument', $params);
    return !empty($r['ok']);
}

/** پشتیبان کامل: ساخت، ارسال با شمارش جدول‌ها و ردیف‌ها، و نگه داشتن ۱۰ فایل آخر. */
function backup_run(): bool
{
    $dump = backup_create();
    $file = $dump['file'];
    $size = (int) filesize($file);
    $sizeText = $size >= 1048576
        ? number_format($size / 1048576, 2, '.', '') . ' مگابایت'
        : number_format($size / 1024, 1, '.', '') . ' کیلوبایت';
    $caption = '<b>📦 پشتیبان پایگاه‌داده: ' . h(backup_db_label()) . '</b>' . "\n" . RULE . "\n" .
        'تاریخ: ' . h(now()) . "\n" .
        'جدول‌ها: ' . $dump['tables'] . "\n" .
        'ردیف‌ها: ' . $dump['rows'] . "\n" .
        'حجم فایل فشرده: ' . $sizeText;
    $ok = $size > 49 * 1048576 ? false : backup_send($file, $caption);
    setting_set('backup_last', now());
    $files = glob(SB_ROOT . '/storage/backups/*.sql.gz') ?: [];
    usort($files, static fn(string $a, string $b): int => filemtime($a) <=> filemtime($b));
    foreach (array_slice($files, 0, max(0, count($files) - 10)) as $old) {
        @unlink($old);
    }
    if (!$ok) {
        report_error('backup', $size > 49 * 1048576 ? 'حجم پشتیبان از حد مجاز تلگرام بیشتر است.' : 'ارسال فایل پشتیبان انجام نشد.');
    }
    return $ok;
}

/** اجرای خودکار پشتیبان بر اساس فاصله‌ی تنظیم‌شده توسط مدیر. */
function backup_maybe_run(): void
{
    $hours = backup_interval();
    if ($hours <= 0) {
        return;
    }
    $last = (string) setting_get('backup_last', '');
    if ($last !== '' && strtotime($last) > time() - $hours * 3600) {
        return;
    }
    backup_run();
}

/* ------------------------------------------------------------ پاک‌سازی تاپیک‌های قدیمی */

/** تاپیک‌های نسخه‌های قبل؛ فقط برای حذف، هرگز برای ساخت. */
const REPORT_TOPICS_LEGACY = ['orders', 'payments', 'wallet', 'tests', 'support', 'users'];

/** حذف تاپیک‌های قدیمی گروه. تاپیک‌های فعلی هرگز حذف نمی‌شوند. */
function delete_legacy_report_topics(): array
{
    $chat = report_chat();
    $result = ['deleted' => 0, 'failed' => 0];
    if ($chat === '') {
        return $result;
    }
    $current = [];
    foreach (array_keys(REPORT_TOPICS) as $cat) {
        $id = (string) setting_get('report_topic2_' . $cat, '');
        if ($id !== '') {
            $current[$id] = true;
        }
    }
    foreach (REPORT_TOPICS_LEGACY as $cat) {
        $id = (string) setting_get('report_topic_' . $cat, '');
        if ($id === '' || isset($current[$id])) {
            setting_set('report_topic_' . $cat, '');
            continue;
        }
        $r = tg('deleteForumTopic', ['chat_id' => $chat, 'message_thread_id' => (int) $id]);
        if (!empty($r['ok'])) {
            $result['deleted']++;
        } else {
            $result['failed']++;
        }
        setting_set('report_topic_' . $cat, '');
    }
    return $result;
}

/* ------------------------------------------------------------ ورود امن به مینی‌اپ */

/** امضای توکن با کلید مخفی ربات؛ بدون امضای معتبر هیچ توکنی پذیرفته نمی‌شود. */
function auth_sign(string $purpose, int $uid, int $exp): string
{
    $body = $purpose . '.' . $uid . '.' . $exp;
    return rtrim(strtr(base64_encode($body), '+/', '-_'), '=') . '.' . hash_hmac('sha256', $body, (string) cfg('bot_token'));
}

/** اعتبارسنجی توکن: امضا، نوع توکن و تاریخ انقضا. شناسه‌ی کاربر یا null برمی‌گرداند. */
function auth_verify(string $purpose, string $token): ?int
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }
    $body = base64_decode(strtr($parts[0], '-_', '+/'), true);
    if ($body === false) {
        return null;
    }
    if (!hash_equals(hash_hmac('sha256', $body, (string) cfg('bot_token')), $parts[1])) {
        return null;
    }
    $fields = explode('.', $body);
    if (count($fields) !== 3 || $fields[0] !== $purpose || (int) $fields[2] < time()) {
        return null;
    }
    return (int) $fields[1];
}

/**
 * دکمه‌ی مدیریت کاربر در کارت‌های مدیر. با لینک ربات در پیام جدید باز می‌شود،
 * تا کارت اصلی (رسید یا سفارش) حذف نشود.
 */
function admin_user_button(int $uid): array
{
    $bot = bot_username();
    return $bot !== ''
        ? btn_url('👤 مدیریت کاربر', 'https://t.me/' . $bot . '?start=u_' . $uid, 'primary')
        : btn('👤 مدیریت کاربر', 'user_' . $uid, '');
}

/** وضعیت کارت را روی همان پیام نشان می‌دهد؛ کارت حذف یا جابه‌جا نمی‌شود. */
function card_set_status(int $chat, int $msgId, string $status, int $uid): void
{
    if ($msgId <= 0) {
        return;
    }
    tg_raw((string) cfg('bot_token'), 'editMessageReplyMarkup', [
        'chat_id' => $chat,
        'message_id' => $msgId,
        'reply_markup' => kb([[btn($status, 'noop', '')], [admin_user_button($uid)]]),
    ]);
}

/** دکمه‌ی عملیات روی یک کاربر؛ با لینک ربات در پیام جدید باز می‌شود تا کارت حذف نشود. */
function admin_action_button(string $label, string $code, int $uid): array
{
    $bot = bot_username();
    return $bot !== ''
        ? btn_url($label, 'https://t.me/' . $bot . '?start=' . $code . '_' . $uid, 'primary')
        : btn($label, $code === 'bp' ? 'user_balplus_' . $uid : 'user_block_' . $uid, '');
}

/* ------------------------------------------------------------ ادامه‌ی خودکار ارسال همگانی */

/** کلید امنیتی اجرای صف؛ در نخستین نیاز ساخته می‌شود. */
function cron_key(): string
{
    $key = (string) setting_get('cron_key', '');
    if ($key === '') {
        $key = bin2hex(random_bytes(16));
        setting_set('cron_key', $key);
    }
    return $key;
}

/** اگر ارسال هنوز کامل نشده، یک اجرای تازه‌ی صف را فوراً شروع می‌کند (بدون انتظار برای پاسخ). */
function broadcast_chain(): void
{
    $base = rtrim((string) cfg('webapp_url', ''), '/');
    if ($base === '' || !function_exists('curl_init')) {
        return;
    }
    $ch = curl_init($base . '/cron.php?key=' . cron_key());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 1500,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_NOSIGNAL => 1,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

/** آیا هنوز ارسالی در صف مانده است؟ */
function broadcast_pending(): bool
{
    return one("SELECT id FROM broadcast_jobs WHERE status = 'running' LIMIT 1") !== null;
}

/** پیام پرداخت و مرحله‌ی ارسال رسید برای یک درخواست شارژ؛ همان متنی که در ربات دیده می‌شود. */
function charge_instructions(int $uid, int $amount, string $cid): void
{
    $p = payment_info();
    send_text($uid, '<b>💳 افزایش موجودی</b>' . "\n" . RULE . "\n" .
        'مبلغ: <b>' . money($amount) . ' تومان</b>' . "\n" .
        'شناسه‌ی شارژ: <code>' . h($cid) . '</code>' . "\n\n" .
        'شماره کارت:' . "\n" . '<code>' . h((string) $p['card']) . '</code>' . "\n" .
        'به نام: <b>' . h((string) $p['owner']) . '</b>' . "\n\n" .
        'پس از پرداخت، تصویر رسید را در همین گفتگو ارسال کنید.',
        kb([[btn('لغو درخواست', 'wallet_cancel_' . $cid, 'danger')]]));
    state_set($uid, 'wallet_receipt', $cid);
}

/* ------------------------------------------------------------ تأیید خودکار رسید شارژ */

function fin_auto_text(): string
{
    $min = (int) setting_get('auto_confirm_min', '0');
    $state = $min > 0
        ? 'فعال — هر رسید شارژ که <b>' . fa((string) $min) . ' دقیقه</b> بدون بررسی بماند، خودکار تأیید می‌شود.'
        : 'خاموش — رسیدها باید به‌صورت دستی تأیید شوند.';
    $url = rtrim((string) cfg('webapp_url', ''), '/') . '/cron.php?key=' . cron_key();
    $setup = $min > 0
        ? "\n\n<b>راه‌اندازی زمان‌بندی:</b> در cPanel بخش Cron Jobs یک کار با زمان‌بندی «هر دقیقه» بسازید و این دستور را در آن قرار دهید:\n" .
          '<code>curl -s "' . h($url) . '" &gt; /dev/null</code>' . "\n" .
          'این آدرس را محرمانه نگه دارید.'
        : '';
    return '<b>⏱ تأیید خودکار رسید شارژ</b>' . "\n" . RULE . "\n" . $state . "\n\n" .
        'یکی از مدت‌های زیر را انتخاب کنید، یا عدد دلخواه بدهید.' . $setup;
}

function fin_auto_markup(): string
{
    return kb([
        [btn('⛔ خاموش', 'fin_auto_set_0', ''), btn('۵ دقیقه', 'fin_auto_set_5', '')],
        [btn('۱۰ دقیقه', 'fin_auto_set_10', ''), btn('۲۰ دقیقه', 'fin_auto_set_20', '')],
        [btn('۳۰ دقیقه', 'fin_auto_set_30', ''), btn('۶۰ دقیقه', 'fin_auto_set_60', '')],
        [btn('✏️ عدد دلخواه', 'fin_auto_custom', '')],
        admin_back('admin_finance'),
    ]);
}

/**
 * رسیدهایی را که مدت تعیین‌شده از ارسالشان گذشته تأیید می‌کند.
 * فقط از مسیر cron اجرا می‌شود و به پیام‌های ورودی کاربران وابسته نیست.
 */
function auto_confirm_pass(bool $force = false): int
{
    $min = (int) setting_get('auto_confirm_min', '0');
    if ($min <= 0 || (!$force && (int) setting_get('autoconf_last', '0') > time() - 60)) {
        return 0;
    }
    setting_set('autoconf_last', (string) time());
    $done = 0;
    try {
        $cut = date('Y-m-d H:i:s', time() - $min * 60);
        foreach (rows("SELECT id FROM wallet_charges WHERE status = 'waiting_admin' AND receipt_at IS NOT NULL AND receipt_at <= ? LIMIT 50", [$cut]) as $r) {
            if (approve_charge((string) $r['id'])) {
                $done++;
            }
        }
    } catch (Throwable $e) {
        error_log('[SimorghBot auto_confirm] ' . $e->getMessage());
    }
    return $done;
}

/**
 * وقتی درخواست شارژ تأیید یا رد می‌شود، وضعیت «در انتظار رسید» کاربر پاک می‌شود
 * تا دکمه‌ی کیف پول یا لغو، درخواست بررسی‌شده را دوباره «لغو» نکند.
 */
function charge_settle_customer(string $cid): void
{
    $c = one('SELECT user_id FROM wallet_charges WHERE id = ?', [$cid]);
    if ($c !== null && state_get((int) $c['user_id'], 'wallet_receipt') === $cid) {
        state_set((int) $c['user_id'], 'wallet_receipt', '');
    }
}

/** آیا تأیید خودکار روشن است و رسیدی در انتظار تأیید خودکار مانده است؟ */
function auto_confirm_waiting(): bool
{
    if ((int) setting_get('auto_confirm_min', '0') <= 0) {
        return false;
    }
    return one("SELECT id FROM wallet_charges WHERE status = 'waiting_admin' AND receipt_at IS NOT NULL LIMIT 1") !== null;
}

/** یک اجرای پس‌زمینه‌ی بررسی تأیید خودکار را از همین سایت شروع می‌کند؛ پاسخ آن لازم نیست. */
function auto_confirm_kick(): void
{
    $base = rtrim((string) cfg('webapp_url', ''), '/');
    if ($base === '' || !function_exists('curl_init')) {
        return;
    }
    $ch = curl_init($base . '/cron.php?key=' . cron_key() . '&auto=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 1500,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_NOSIGNAL => 1,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

/* ------------------------------------------------------------ وضعیت سیستم و عیب‌یابی */

/** «چه مدت پیش» به فارسی؛ برای مقدار خالی «هرگز». */
function ago_text(string $datetime): string
{
    if ($datetime === '') {
        return 'هرگز';
    }
    $t = strtotime($datetime);
    if ($t === false) {
        return h($datetime);
    }
    $diff = max(0, time() - $t);
    if ($diff < 60) {
        return 'کمتر از یک دقیقه پیش';
    }
    if ($diff < 3600) {
        return fa((string) intdiv($diff, 60)) . ' دقیقه پیش';
    }
    if ($diff < 86400) {
        return fa((string) intdiv($diff, 3600)) . ' ساعت پیش';
    }
    return fa((string) intdiv($diff, 86400)) . ' روز پیش';
}

/** گزارش وضعیت سرور و تأیید خودکار؛ برای فهمیدن اینکه هاست چه چیزی را پشتیبانی می‌کند. */
function system_status_text(): string
{
    $min = (int) setting_get('auto_confirm_min', '0');
    $cronAt = (string) setting_get('cron_last', '');
    $cronTs = $cronAt !== '' ? strtotime($cronAt) : false;
    $cronFresh = $cronTs !== false && time() - $cronTs <= 180;
    $stat = one("SELECT COUNT(*) AS n, MIN(receipt_at) AS oldest FROM wallet_charges WHERE status = 'waiting_admin' AND receipt_at IS NOT NULL");
    $pending = (int) ($stat['n'] ?? 0);
    $oldest = (string) ($stat['oldest'] ?? '');
    $oldestTs = $oldest !== '' ? strtotime($oldest) : false;

    $lines = ['<b>🩺 وضعیت سیستم</b>', RULE];
    $lines[] = 'PHP: <code>' . h(PHP_VERSION) . '</code>';
    $lines[] = 'افزونه‌ی curl: ' . (function_exists('curl_init') ? '✅ فعال' : '❌ غیرفعال');
    $lines[] = 'آدرس سایت: <code>' . h((string) cfg('webapp_url', 'ثبت نشده')) . '</code>';
    $lines[] = 'زمان‌بندی (cron): ' . ($cronFresh ? '✅ فعال' : '❌ اجرا نمی‌شود') . ' — آخرین اجرا: ' . ago_text($cronAt);
    $lines[] = 'آخرین بررسی تأیید خودکار: ' . ago_text((string) setting_get('auto_run_last', ''));
    $lines[] = 'تأیید خودکار: ' . ($min > 0 ? 'روشن (' . fa((string) $min) . ' دقیقه)' : 'خاموش');
    $lines[] = 'رسیدهای در انتظار: <b>' . fa((string) $pending) . '</b>'
        . ($oldest !== '' ? ' — قدیمی‌ترین: ' . ago_text($oldest) : '');

    $overdue = $min > 0 && $oldestTs !== false && time() - $oldestTs > ($min + 2) * 60;
    if ($overdue) {
        $lines[] = '';
        $lines[] = '⚠️ رسیدی از موعد گذشته و هنوز تأیید نشده است. زمان‌بندی (cron) یا اجرای پس‌زمینه روی هاست کار نمی‌کند. '
            . 'با دکمه‌ی «بررسی همین حالا» می‌توانید فوراً تأیید کنید.';
    } elseif ($min > 0 && !$cronFresh) {
        $lines[] = '';
        $lines[] = 'ℹ️ برای تأیید دقیق در زمان تعیین‌شده، cron هر دقیقه را در cPanel تنظیم کنید (دستور آن در بخش امور مالی ← تأیید خودکار رسید است).';
    }
    return implode("\n", $lines);
}

/** تست اینکه سایت می‌تواند به خودش درخواست بفرستد (برای اجرای پس‌زمینه‌ی تأیید خودکار لازم است). */
function self_ping(): array
{
    $base = rtrim((string) cfg('webapp_url', ''), '/');
    if ($base === '') {
        return ['ok' => false, 'status' => 0, 'ms' => 0, 'error' => 'آدرس سایت در تنظیمات ربات ثبت نشده است.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'ms' => 0, 'error' => 'افزونه‌ی curl روی هاست فعال نیست.'];
    }
    $key = cron_key();
    $t0 = microtime(true);
    $ch = curl_init($base . '/cron.php?key=' . $key . '&ping=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = str_replace($key, '***', curl_error($ch));
    curl_close($ch);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    if ($body === false) {
        return ['ok' => false, 'status' => 0, 'ms' => $ms,
            'error' => 'اتصال سایت به خودش برقرار نشد' . ($err !== '' ? ': ' . $err : '.')];
    }
    $ok = $status === 200 && trim((string) $body) === 'pong';
    return ['ok' => $ok, 'status' => $status, 'ms' => $ms, 'error' => $ok ? '' : 'پاسخ غیرمنتظره (HTTP ' . $status . ').'];
}
