<?php
/** PasarGuard reseller-admin integration. Uses official OAuth2 bearer auth and REST endpoints. */
declare(strict_types=1);

function pg_url(): string { return rtrim(trim(setting_get('pg_url', '')), '/'); }
function pg_configured(): bool { return pg_url() !== '' && pg_secret_get('pg_username') !== '' && pg_secret_get('pg_password') !== ''; }
function pg_crypto_key(): string { $master=(string)cfg('app_secret',''); if($master==='') $master=(string)cfg('bot_token'); return hash('sha256', $master . '|SimorghBot|PasarGuard|credential-v1', true); }
function pg_secret_set(string $key, string $plain): void {
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('افزونه OpenSSL فعال نیست.');
    $iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt($plain,'aes-256-gcm',pg_crypto_key(),OPENSSL_RAW_DATA,$iv,$tag,'pg-v1');
    if ($cipher===false) throw new RuntimeException('رمزنگاری اطلاعات اتصال ناموفق بود.');
    setting_set($key, base64_encode($iv.$tag.$cipher));
}
function pg_secret_get(string $key): string {
    $raw=base64_decode(setting_get($key,''),true); if ($raw===false || strlen($raw)<28) return '';
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',pg_crypto_key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'pg-v1');
    return is_string($plain)?$plain:'';
}
function pg_http(string $method,string $path,?array $body=null,?string $token=null,bool $form=false): array {
    if (!function_exists('curl_init')) return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'افزونه cURL روی هاست فعال نیست.'];
    $url=pg_url().$path; $headers=['Accept: application/json'];
    if ($token!==null) $headers[]='Authorization: Bearer '.$token;
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS];
    if ($body!==null) {
        if ($form) { $headers[]='Content-Type: application/x-www-form-urlencoded'; $opts[CURLOPT_POSTFIELDS]=http_build_query($body); }
        else { $headers[]='Content-Type: application/json'; $opts[CURLOPT_POSTFIELDS]=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    }
    $opts[CURLOPT_HTTPHEADER]=$headers; $ch=curl_init($url); curl_setopt_array($ch,$opts); $raw=curl_exec($ch); $errno=curl_errno($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($raw===false) return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'خطای ارتباط cURL: '.$errno];
    $data=json_decode((string)$raw,true); $ok=$status>=200&&$status<300;
    $err=''; if(!$ok){$detail=is_array($data)?($data['detail']??$data['message']??'') : ''; $err='HTTP '.$status.($detail!==''?' — '.mb_substr((string)$detail,0,300):'');}
    return ['ok'=>$ok,'status'=>$status,'data'=>is_array($data)?$data:null,'error'=>$err];
}
function pg_token(): array {
    if (!pg_configured()) return ['ok'=>false,'error'=>'اطلاعات اتصال پاسارگارد کامل نیست.'];
    $r=pg_http('POST','/api/admin/token',['username'=>pg_secret_get('pg_username'),'password'=>pg_secret_get('pg_password')],null,true);
    $token=(string)($r['data']['access_token']??'');
    return $r['ok']&&$token!==''?['ok'=>true,'token'=>$token]:['ok'=>false,'error'=>$r['error']?:'توکن از پاسارگارد دریافت نشد.'];
}
function pg_api(string $method,string $path,?array $body=null): array {
    $a=pg_token(); if(!$a['ok']) return $a;
    return pg_http($method,$path,$body,$a['token']);
}
function pg_test(): array {
    $a=pg_token(); if(!$a['ok']) return $a;
    $r=pg_http('GET','/api/admin',null,$a['token']);
    return $r['ok']?['ok'=>true,'detail'=>'احراز هویت موفق؛ API ادمین در دسترس است.']:['ok'=>false,'error'=>$r['error']];
}
function pg_bytes_from_gb(int $gb): int { if($gb<1||$gb>100000) throw new InvalidArgumentException('حجم باید بین ۱ تا ۱۰۰۰۰۰ گیگابایت باشد.'); return $gb*1024*1024*1024; }
function pg_random_credentials(string $orderId): array {
    $suffix=strtolower(substr(preg_replace('/[^a-f0-9]/','',hash('sha256',$orderId)),0,8));
    return ['username'=>'ps_'.$suffix.'_'.strtolower(bin2hex(random_bytes(2))),'password'=>rtrim(strtr(base64_encode(random_bytes(18)),'+/','-_'),'=')];
}
function pg_role_payload(string $name,int $maxUsers): array {
    $own=['scope'=>1];
    return ['name'=>$name,'permissions'=>[
        'users'=>['create'=>$own,'read'=>$own,'read_simple'=>$own,'update'=>$own,'delete'=>$own,'reset_usage'=>$own,'revoke_sub'=>$own],
        'admins'=>['read_simple'=>$own],
    ],'limits'=>['max_users'=>$maxUsers,'data_limit_min'=>0,'expire_min'=>0],
        'features'=>['can_use_reset_strategy'=>false,'can_use_next_plan'=>false],
        'access'=>['require_template'=>false,'allowed_template_ids'=>null,'allowed_group_ids'=>null],
        'disabled_when_limited'=>true,'disconnect_users_when_limited'=>true,'disconnect_users_when_disabled'=>true];
}
function pg_find_role_id(string $name,string $token): ?int {
    $r=pg_http('GET','/api/admin-roles?search='.rawurlencode($name).'&limit=100',null,$token);
    if(!$r['ok']||!is_array($r['data'])) return -1;
    foreach(($r['data']['roles']??[]) as $role) if(($role['name']??'')===$name) return (int)$role['id'];
    return null;
}
function pg_create_role(string $name,int $maxUsers,string $token): array {
    $existing=pg_find_role_id($name,$token); if($existing===-1) return ['ok'=>false,'error'=>'خواندن نقش‌های موجود از پاسارگارد ناموفق بود؛ برای جلوگیری از نقش تکراری، عملیات متوقف شد.']; if($existing!==null) return ['ok'=>true,'id'=>$existing];
    $r=pg_http('POST','/api/admin-role',pg_role_payload($name,$maxUsers),$token);
    if(!$r['ok']) return ['ok'=>false,'error'=>'ساخت نقش نمایندگی ناموفق بود: '.$r['error']];
    $id=(int)($r['data']['id']??0); return $id>0?['ok'=>true,'id'=>$id]:['ok'=>false,'error'=>'پاسارگارد شناسه نقش را برنگرداند.'];
}
function pg_create_reseller_for_order(string $orderId): array {
    $order=one('SELECT o.*,p.name AS product_name,p.pg_enabled,p.pg_data_gb,p.pg_days,p.pg_max_users FROM orders o LEFT JOIN products p ON p.id=o.product_id WHERE o.id=?',[$orderId]);
    if(!$order) return ['ok'=>false,'error'=>'سفارش پیدا نشد.'];
    if((int)$order['pg_enabled']!==1) return ['ok'=>false,'error'=>'اتصال خودکار پاسارگارد برای این محصول فعال نشده است.'];
    if(!in_array($order['status'],['waiting_admin','waiting_panel_details','pg_error','pg_creating'],true)) return ['ok'=>false,'error'=>'وضعیت سفارش اجازه ساخت نمایندگی نمی‌دهد.'];
    $existing=one('SELECT * FROM pg_resellers WHERE order_id=?',[$orderId]);
    if($existing && $existing['status']==='delivered') return ['ok'=>true,'already'=>true,'message'=>'این نمایندگی قبلاً تحویل شده است.'];
    if($existing && $existing['status']==='creating') return ['ok'=>false,'error'=>'این سفارش هم‌اکنون در حال پردازش است.'];
    $gb=(int)$order['pg_data_gb']; $days=(int)$order['pg_days']; $max=(int)$order['pg_max_users'];
    if($gb<1||$days<1||$max<1) return ['ok'=>false,'error'=>'تنظیمات سهمیه محصول ناقص است.'];
    $pdo=db(); $pdo->beginTransaction();
    try {
        if(!$existing){$creds=pg_random_credentials($orderId); q("INSERT INTO pg_resellers(order_id,user_id,username,password_enc,status,expires_at,created_at) VALUES(?,?,?,?, 'creating',DATE_ADD(NOW(),INTERVAL ? DAY),?)",[$orderId,(int)$order['user_id'],$creds['username'],pg_encrypt_value($creds['password']),$days,now()]);}
        else {$creds=['username'=>$existing['username'],'password'=>pg_decrypt_value($existing['password_enc'])]; if($creds['password']===''){ $pdo->rollBack(); return ['ok'=>false,'error'=>'بازیابی امن رمز این سفارش ممکن نیست؛ از ساخت مجدد خودداری شد.']; } q("UPDATE pg_resellers SET status='creating',last_error=NULL WHERE order_id=?",[$orderId]);}
        q("UPDATE orders SET status='pg_creating' WHERE id=?",[$orderId]); $pdo->commit();
    } catch(Throwable $e){$pdo->rollBack();return ['ok'=>false,'error'=>'ثبت وضعیت سفارش ناموفق بود.'];}
    $auth=pg_token(); if(!$auth['ok']) { pg_mark_failed($orderId,$auth['error']); return $auth; }
    $token=$auth['token']; $roleName='PS-'.substr(hash('sha256',$orderId),0,18);
    $role=pg_create_role($roleName,$max,$token);
    if(!$role['ok']) { pg_mark_failed($orderId,$role['error']); return $role; }
    $payload=['username'=>$creds['username'],'password'=>$creds['password'],'role_id'=>$role['id'],'data_limit'=>pg_bytes_from_gb($gb),'telegram_id'=>(int)$order['user_id'],'note'=>'Panel Store order '.$orderId];
    // Reconcile by username before POST to avoid duplicate admins after a timeout/retry.
    $check=pg_http('GET','/api/admins?username='.rawurlencode($creds['username']).'&limit=10',null,$token);
    if(!$check['ok']) { pg_mark_failed($orderId,'بررسی وجود ادمین قبلی ممکن نشد: '.$check['error']); return ['ok'=>false,'error'=>'بررسی وجود ادمین قبلی ناموفق بود؛ برای جلوگیری از ساخت تکراری، عملیات متوقف شد.']; }
    $found=false; if($check['ok']) foreach(($check['data']['admins']??[]) as $a) if(($a['username']??'')===$creds['username']) {$found=true;break;}
    if(!$found){$created=pg_http('POST','/api/admin',$payload,$token); if(!$created['ok']) { pg_mark_failed($orderId,'ساخت ادمین ناموفق بود: '.$created['error']); return ['ok'=>false,'error'=>'ساخت ادمین ناموفق بود: '.$created['error']]; }}
    $adminUrl=pg_url().'/dashboard/';
    $detail="🎉 <b>نمایندگی پاسارگارد شما آماده شد</b>\n\n🌐 آدرس پنل: <code>".h($adminUrl)."</code>\n👤 نام کاربری: <code>".h($creds['username'])."</code>\n🔑 رمز عبور: <code>".h($creds['password'])."</code>\n📦 سهمیه نمایندگی: <b>".number_format($gb)." GB</b>\n⏳ اعتبار: <b>{$days} روز</b>\n👥 حداکثر اکانت قابل ساخت: <b>{$max}</b>\n\nاین حساب فقط برای مدیریت نمایندگی شما ساخته شده است.";
    // وضعیت تحویل سفارش و ثبت پنل باید یکپارچه باشند؛ از ثبت سفارش تکمیل‌شده
    // بدون رکورد پنل یا از ایجاد رکورد ناقص در اجرای مجدد جلوگیری می‌شود.
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $resellerUpdate = q(
            "UPDATE pg_resellers SET status='delivered',role_id=?,data_limit=?,max_users=?,days=?,delivered_at=?,last_error=NULL
             WHERE order_id=? AND status='creating'",
            [$role['id'], pg_bytes_from_gb($gb), $max, $days, now(), $orderId]
        );
        $orderUpdate = q(
            "UPDATE orders SET status='completed',panel_details=? WHERE id=? AND status='pg_creating'",
            [$detail, $orderId]
        );
        if ($resellerUpdate->rowCount() !== 1 || $orderUpdate->rowCount() !== 1) {
            throw new RuntimeException('وضعیت سفارش هنگام ثبت تحویل تغییر کرده است.');
        }
        q('INSERT INTO panels(user_id,title,details,status,order_id,created_at) VALUES(?,?,?,?,?,?)',
            [(int)$order['user_id'], $order['product_name'] ?: 'نمایندگی پاسارگارد', $detail, 'active', $orderId, now()]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        pg_mark_failed($orderId, 'ثبت نهایی تحویل در دیتابیس ناموفق بود: ' . $e->getMessage());
        return ['ok'=>false,'error'=>'نمایندگی در پاسارگارد ایجاد شد، اما ثبت نهایی در ربات ناموفق بود. دوباره تلاش کنید.'];
    }
    send_text((int)$order['user_id'],$detail);
    send_purchase_report($orderId,'✅ نمایندگی پاسارگارد ساخته و تحویل شد','✅');
    return ['ok'=>true,'username'=>$creds['username']];
}
function pg_encrypt_value(string $value): string { $iv=random_bytes(12);$tag='';$enc=openssl_encrypt($value,'aes-256-gcm',pg_crypto_key(),OPENSSL_RAW_DATA,$iv,$tag,'pg-reseller-v1');if($enc===false)throw new RuntimeException('encryption failed');return base64_encode($iv.$tag.$enc); }
function pg_decrypt_value(string $value): string { $raw=base64_decode($value,true);if($raw===false||strlen($raw)<28)return ''; $v=openssl_decrypt(substr($raw,28),'aes-256-gcm',pg_crypto_key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'pg-reseller-v1');return is_string($v)?$v:''; }
function pg_mark_failed(string $orderId,string $error): void { q("UPDATE pg_resellers SET status='error',last_error=? WHERE order_id=?",[mb_substr($error,0,500),$orderId]); q("UPDATE orders SET status='pg_error' WHERE id=? AND status='pg_creating'",[$orderId]); foreach(admin_ids() as $aid) send_text((int)$aid,'⚠️ خطای ساخت نمایندگی پاسارگارد'."\nسفارش: <code>".h($orderId)."</code>\n".h($error)); }
function pg_expire_tick(): int {
    $list=rows("SELECT * FROM pg_resellers WHERE status='delivered' AND expires_at<=NOW() LIMIT 50"); $count=0;
    foreach($list as $a){$r=pg_api('PUT','/api/admin/'.rawurlencode($a['username']),['status'=>'disabled']);if($r['ok']){q("UPDATE pg_resellers SET status='expired' WHERE order_id=?",[$a['order_id']]);$count++;foreach(admin_ids() as $aid)send_text((int)$aid,'⏰ نمایندگی '.$a['username'].' به پایان اعتبار رسید و غیرفعال شد.');}}
    return $count;
}

/**
 * آدرس پنل را از هر چیزی که ادمین می‌چسباند استخراج می‌کند و فقط «scheme://host[:port]» را نگه می‌دارد.
 * مسیرهایی مثل /dashboard/ حذف می‌شوند. اگر https نوشته نشده باشد، https فرض می‌شود.
 * خروجی خالی یعنی آدرس نامعتبر است.
 */
function pg_normalize_url(string $raw): string
{
    $raw = trim(str_replace([' ', "\t", "\n", "\r"], '', $raw));
    if ($raw === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $raw)) {
        $raw = 'https://' . $raw;
    }
    $parts = parse_url($raw);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
        return '';
    }
    $scheme = strtolower((string) $parts['scheme']);
    $host = (string) $parts['host'];
    // ارتباط API فقط از HTTPS مجاز است؛ آدرس HTTP با تنظیم cURL سازگار نیست
    // و نباید به‌عنوان اتصال معتبر ذخیره شود.
    if ($scheme !== 'https' || preg_match('/^[A-Za-z0-9.\-]+$/', $host) !== 1) {
        return '';
    }
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    return $scheme . '://' . $host . $port;
}
