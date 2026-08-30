<?php
/**
 * بررسی سلامت. پنل از این استفاده می‌کند تا بفهمد بک‌اند نصب شده یا نه؛
 * اگر نبود، خودش را در حالت نمایشی بالا می‌آورد.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_sms.php';

$dbOk = false;
try { db()->query('SELECT 1'); $dbOk = true; } catch (Throwable $e) {}

$c = cfg();

/*
 * «bridge» یعنی سامانه سالم است ولی کد ورود از پنل سوپرادمین می‌آید نه
 * پیامک. این با «missing» فرق دارد: missing یعنی چیزی تنظیم نشده و
 * کسی نمی‌تواند وارد شود، bridge یعنی راه ورود هست و عمدی است.
 */
$sms = 'missing';
if (!empty($c['sms_dry_run'])) {
    $sms = 'dry_run';
} elseif ($dbOk) {
    $s = sms_conf();
    $sms = $s['mode'] === 'bridge' ? 'bridge'
         : (($s['key'] !== '' && $s['template'] > 0) ? 'configured' : 'missing');
}

json_out($dbOk ? 200 : 503, [
    'ok'       => $dbOk,
    'api'      => 'talkora',
    'database' => $dbOk,
    'sms'      => $sms,
    'time'     => gmdate('c'),
]);
