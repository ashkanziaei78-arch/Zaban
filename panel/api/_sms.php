<?php
/**
 * کلاینت sms.ir
 *
 * از سرویس «ارسال وریفای» استفاده می‌کند:
 *   POST https://api.sms.ir/v1/send/verify
 *   هدرها: x-api-key, Content-Type: application/json, Accept: text/plain
 *   بدنه:  {"mobile":"09...","templateId":123,"parameters":[{"name":"CODE","value":"12345"}]}
 *   پاسخ:  {"status":1,"message":"موفق","data":{"messageId":...,"cost":...}}
 *
 * چرا سرویس وریفای و نه ارسال معمولی (بند P.4):
 * پیامک وریفای از خط خدماتی می‌رود، پس به دست کسی که «لغو تبلیغات» را
 * فعال کرده هم می‌رسد. اگر کد ورود را با خط تبلیغاتی بفرستید، برای بخشی
 * از کاربران هرگز نمی‌رسد و شما هم متوجه نمی‌شوید.
 */

declare(strict_types=1);

/*
 * محافظ دسترسی مستقیم.
 *
 * .htaccess فقط روی آپاچی کار می‌کند؛ اگر پلسک روی حالت «nginx only»
 * باشد نادیده گرفته می‌شود. این بررسی مستقل از وب‌سرور است.
 */
if (realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(404);
    exit;
}


/**
 * تنظیمات پیامک: اول از جدول site_setting، بعد از config.php.
 *
 * چرا دو جا؟ چون کلید sms.ir معمولاً چند روز بعد از راه‌اندازی سایت
 * به دست صاحب آموزشگاه می‌رسد. اگر تنها جایش config.php بود، برای
 * گذاشتنش باید دوباره سراغ FTP می‌رفت. حالا از پنل سوپرادمین وارد می‌شود.
 * config.php هنوز کار می‌کند و اولویتش پایین‌تر است، تا نصب‌های قدیمی
 * نشکنند.
 *
 * @return array{mode:string, key:string, template:int, param:string}
 */
function sms_conf(): array
{
    static $s = null;
    if ($s !== null) return $s;

    $c   = cfg();
    $row = [];
    try {
        $st = db()->query(
            "SELECT skey, svalue FROM site_setting
              WHERE skey IN ('sms_mode','smsir_api_key','smsir_template_id','smsir_param_name','otp_ttl')"
        );
        foreach ($st->fetchAll() as $r) $row[(string)$r['skey']] = (string)$r['svalue'];
    } catch (Throwable $e) {
        error_log('sms settings read failed: ' . $e->getMessage());
    }

    $key      = $row['smsir_api_key']     ?? '';
    $template = (int)($row['smsir_template_id'] ?? 0);
    if ($key === '')      $key      = (string)($c['smsir_api_key'] ?? '');
    if ($template === 0)  $template = (int)($c['smsir_template_id'] ?? 0);

    $mode = $row['sms_mode'] ?? '';
    if ($mode === '') $mode = ($key !== '' && $template > 0) ? 'smsir' : 'bridge';

    /*
     * نام پارامتر بدون «#» ذخیره می‌شود، ولی کاربر تقریباً همیشه همان
     * چیزی را کپی می‌کند که در قالب sms.ir نوشته — یعنی «#otp#». اگر
     * همان را بفرستیم، sms.ir هیچ پارامتری با آن نام پیدا نمی‌کند و
     * متن قالب دست‌نخورده می‌رود: کاربر پیامکی می‌گیرد که در آن به‌جای
     * کد، عبارت #otp# نوشته شده. پس اینجا پاکش می‌کنیم.
     */
    $param = trim($row['smsir_param_name'] ?? (string)($c['smsir_param_name'] ?? 'CODE'));
    $param = trim(str_replace('#', '', $param));
    if ($param === '') $param = 'CODE';

    $s = [
        'mode'     => $mode,
        'key'      => $key,
        'template' => $template,
        'param'    => $param,
        'ttl'      => otp_ttl_from($row, $c),
    ];
    return $s;
}

/**
 * عمر کد به ثانیه.
 *
 * عمداً به config.php نگاه نمی‌کند. نسخه‌های اول نصب‌کننده مقدار ۱۲۰
 * را آنجا می‌نوشتند، و اگر آن را محترم بشماریم، هر نصب موجود روی همان
 * دو دقیقه می‌ماند — یعنی دقیقاً همان چیزی که می‌خواهیم اصلاح کنیم:
 * پیامک با دو دقیقه تأخیر می‌رسد و کد از قبل مرده است. تنها منبع
 * معتبر، تنظیمات پنل سوپرادمین است.
 */
function otp_ttl_from(array $row, array $c): int
{
    $ttl = (int)($row['otp_ttl'] ?? 0);
    if ($ttl <= 0) $ttl = 300;
    return max(60, min(900, $ttl));
}

function otp_ttl(): int { return sms_conf()['ttl']; }

/** آیا الان کد ورود از راه پنل سوپرادمین به دست کاربر می‌رسد؟ */
function sms_is_bridge(): bool { return sms_conf()['mode'] === 'bridge'; }

/**
 * @return array{sent:bool, messageId:?int, cost:?float, error:?string, bridge?:bool}
 */
function sms_send_verify(string $phone, string $code): array
{
    $c = cfg();

    // حالت توسعه: کد در لاگ می‌رود و پیامکی ارسال نمی‌شود
    if (!empty($c['sms_dry_run'])) {
        error_log("SMS DRY-RUN → {$phone} : {$code}");
        return ['sent' => true, 'messageId' => null, 'cost' => 0.0, 'error' => null];
    }

    $s = sms_conf();

    /*
     * حالت پل: پیامکی نمی‌رود. کد در پنل سوپرادمین دیده می‌شود و مدیر
     * آموزشگاه آن را به کاربر می‌گوید.
     *
     * این برای روزهای بین «سفارش قالب sms.ir» و «تأیید قالب» است که
     * چند روز طول می‌کشد. بدون آن، سامانه در آن روزها کاملاً غیرقابل
     * استفاده است — نه مدیر می‌تواند وارد شود نه استادی. با آن، آموزشگاه
     * از همان روز اول کار می‌کند، فقط ورود دستی‌تر است.
     */
    if ($s['mode'] === 'bridge') {
        return ['sent' => true, 'messageId' => null, 'cost' => 0.0, 'error' => null, 'bridge' => true];
    }

    if ($s['key'] === '' || $s['template'] <= 0) {
        return ['sent' => false, 'messageId' => null, 'cost' => null,
                'error' => 'کلید یا شناسهٔ قالب sms.ir تنظیم نشده'];
    }

    /*
     * ═══ چرا کد را با چند نام می‌فرستیم ═══
     *
     * sms.ir متغیر «#otp#» را فقط وقتی با کد جایگزین می‌کند که پارامتری
     * *دقیقاً* به نام otp برایش آمده باشد. اگر نام نخواند، خطا نمی‌دهد:
     * پیامک را می‌فرستد و «موفق» برمی‌گرداند، ولی متن قالب دست‌نخورده
     * می‌ماند — کاربر پیامکی می‌گیرد که در آن نوشته «#otp#».
     *
     * این خرابی هیچ‌جا ثبت نمی‌شود و از سمت ما اصلاً دیده نمی‌شود. تنها
     * کسی که می‌فهمد، کاربری است که نمی‌تواند وارد شود.
     *
     * پس به‌جای اینکه درست‌بودن یک تنظیم را شرط کارکردن سامانه کنیم،
     * همان کد را با چند نام رایج می‌فرستیم. sms.ir هرکدام را که در قالب
     * باشد جایگزین می‌کند و بقیه را نادیده می‌گیرد.
     *
     * اگر روزی این را نپذیرفت، پایین‌تر با همان یک نام تنظیم‌شده دوباره
     * تلاش می‌کنیم — پس این «هوشمندی» نمی‌تواند چیزی را بشکند.
     */
    $names = [];
    foreach ([$s['param'], 'otp', 'OTP', 'code', 'CODE', 'Code', 'token'] as $n) {
        $n = trim((string)$n);
        if ($n !== '' && !in_array($n, $names, true)) $names[] = $n;
    }
    $params = array_map(fn($n) => ['name' => $n, 'value' => $code], $names);

    $res = sms_post($s, $phone, $params);

    /*
     * اگر با فهرست کامل رد شد، شاید sms.ir پارامتر اضافی را نمی‌پسندد.
     * همان یک نامی که ادمین تنظیم کرده را تنها می‌فرستیم.
     */
    if (!$res['sent'] && count($params) > 1) {
        error_log('sms.ir چند-نامی رد شد، تلاش دوباره با ' . $s['param']);
        $res = sms_post($s, $phone, [['name' => $s['param'], 'value' => $code]]);
    }
    return $res;
}

/**
 * یک درخواست به sms.ir.
 *
 * @param array $params فهرست ['name'=>..., 'value'=>...]
 * @return array{sent:bool, messageId:?int, cost:?float, error:?string}
 */
function sms_post(array $s, string $phone, array $params): array
{
    $payload = json_encode([
        'mobile'     => $phone,
        'templateId' => $s['template'],
        'parameters' => $params,
    ], JSON_UNESCAPED_UNICODE);

    /*
     * آدرس از پیکربندی خوانده می‌شود تا بتوان مسیر ارسال را بدون
     * تماس با sms.ir آزمود. اگر تنظیم نشده باشد — یعنی همیشه، روی
     * سرور واقعی — همان نقطهٔ پایانی خود sms.ir است.
     */
    $base = (string)(cfg()['smsir_base'] ?? 'https://api.sms.ir');
    $ch = curl_init(rtrim($base, '/') . '/v1/send/verify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: text/plain',
            'x-api-key: ' . $s['key'],
        ],
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        error_log("sms.ir curl error: {$err}");
        return ['sent' => false, 'messageId' => null, 'cost' => null, 'error' => 'اتصال به sms.ir برقرار نشد'];
    }

    $res = json_decode((string)$raw, true);
    if (!is_array($res)) {
        error_log("sms.ir bad response ({$http}): {$raw}");
        return ['sent' => false, 'messageId' => null, 'cost' => null, 'error' => 'پاسخ نامعتبر از sms.ir'];
    }

    // status = 1 یعنی موفق
    if ((int)($res['status'] ?? 0) === 1) {
        return [
            'sent'      => true,
            'messageId' => isset($res['data']['messageId']) ? (int)$res['data']['messageId'] : null,
            'cost'      => isset($res['data']['cost']) ? (float)$res['data']['cost'] : null,
            'error'     => null,
        ];
    }

    $msg = (string)($res['message'] ?? 'خطای نامشخص');
    error_log("sms.ir failed ({$http}) status={$res['status']}: {$msg}");
    return ['sent' => false, 'messageId' => null, 'cost' => null, 'error' => $msg];
}
