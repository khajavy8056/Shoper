<?php
/**
 * Shoper - اسکریپت تشخیص اتصال به API ترب
 * ----------------------------------------------------
 * این فایل یک اسکریپت مستقل است (نیازی به وردپرس ندارد).
 * آن را روی هاست/سروری که افزونه قرار است روی آن نصب شود آپلود کنید:
 *
 *     php torob-connection-test.php "s25 ultra"
 *
 * یا در مرورگر (پس از تنظیم $ACCESS_KEY):
 *     https://yoursite.com/torob-connection-test.php?key=shoper2026
 *
 * این اسکریپت endpoint جستجوی ترب را با چند روش تست می‌کند:
 *   1. cURL با فشرده‌سازی gzip/deflate (رفتار جدید افزونه)
 *   2. cURL بدون فشرده‌سازی (identity)
 *   3. cURL با Brotli (برای مقایسه)
 *   4. PHP Streams (file_get_contents) — شبیه مسیرِ «بدون cURL» افزونه
 *   5. چند User-Agent مختلف
 *
 * نتیجه‌ی هر روش (کد HTTP، Content-Type، طول پاسخ، ۵۰۰ نویسه‌ی اول)
 * جداگانه چاپ می‌شود تا مشخص شود مشکل در کدام لایه است.
 *
 * پس از تست، این فایل را از سرور حذف کنید.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

// ---------- محافظت دسترسی ----------
$ACCESS_KEY = 'shoper2026'; // ← این مقدار را تغییر دهید
$is_cli = (PHP_SAPI === 'cli');
if (!$is_cli) {
    if (!isset($_GET['key']) || $_GET['key'] !== $ACCESS_KEY) {
        http_response_code(403);
        echo "دسترسی غیرمجاز. پارامتر key را در آدرس وارد کنید.\n";
        exit(1);
    }
}

$config = [
    'query'     => $argv[1] ?? ($_GET['q'] ?? 's25 ultra'),
    'api_base'  => 'https://api.torob.com',
    'timeout'   => 20,
    'connect'   => 10,
    'user_agent'=> 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                   .'(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
];

function line(string $msg = ''): void { echo $msg . PHP_EOL; }
function ok(string $msg): void   { line('[ ✅ ] ' . $msg); }
function bad(string $msg): void  { line('[ ❌ ] ' . $msg); }
function info(string $msg): void { line('[ ℹ️  ] ' . $msg); }

line('==============================================');
line(' Shoper - تست اتصال به API ترب');
line(' زمان: ' . date('Y-m-d H:i:s'));
line('==============================================');
line();

// ---------- 1. محیط PHP ----------
info('نسخه‌ی PHP: ' . PHP_VERSION);
info('تابع cURL: ' . (function_exists('curl_init') ? 'موجود' : 'موجود نیست!'));
info('allow_url_fopen: ' . (ini_get('allow_url_fopen') ? 'فعال' : 'غیرفعال'));
info('OpenSSL: ' . (defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'نامشخص'));
line();

$search_url = $config['api_base'] . '/v4/base-product/search/?' . http_build_query([
    'page'   => 0,
    'size'   => 3,
    'q'      => $config['query'],
    'source' => 'next_desktop',
]);
info('URL تست: ' . $search_url);
line();

/**
 * چاپ خلاصه‌ی یک پاسخ.
 */
function report(string $label, $body, int $code, string $ctype, float $seconds): void {
    $len = is_string($body) ? strlen($body) : 0;
    line('--- ' . $label . ' ---');
    info('کد HTTP: ' . $code . ' | Content-Type: ' . ($ctype ?: '-') . ' | طول: ' . $len . ' بایت | زمان: ' . round($seconds, 2) . 's');
    if (is_string($body) && $body !== '') {
        info('نمونه‌ی پاسخ (۵۰۰ نویسه):');
        line(substr($body, 0, 500));
    }
    line();
}

// ---------- 2. تست cURL با gzip/deflate (رفتار جدید افزونه) ----------
if (function_exists('curl_init')) {
    $ch = curl_init($search_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => $config['timeout'],
        CURLOPT_CONNECTTIMEOUT => $config['connect'],
        CURLOPT_USERAGENT      => $config['user_agent'],
        CURLOPT_ENCODING       => 'gzip, deflate',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
            'Referer: https://torob.com/',
            'Origin: https://torob.com',
        ],
    ]);
    $start = microtime(true);
    $body  = curl_exec($ch);
    $sec   = microtime(true) - $start;
    $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno || $code === 0) {
        bad('cURL (gzip/deflate) شکست خورد: #' . $errno . ' ' . $error);
    } else {
        report('cURL با gzip/deflate', $body, $code, $ctype, $sec);
        if ($code === 200) ok('cURL با gzip/deflate پاسخ 200 گرفت.');
    }
    line();
} else {
    bad('cURL موجود نیست؛ این روش رد می‌شود.');
    line();
}

// ---------- 3. تست cURL بدون فشرده‌سازی ----------
if (function_exists('curl_init')) {
    $ch = curl_init($search_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $config['timeout'],
        CURLOPT_CONNECTTIMEOUT => $config['connect'],
        CURLOPT_USERAGENT      => $config['user_agent'],
        CURLOPT_ENCODING       => 'identity',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json, text/plain, */*',
            'Referer: https://torob.com/',
        ],
    ]);
    $start = microtime(true);
    $body  = curl_exec($ch);
    $sec   = microtime(true) - $start;
    $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno || $code === 0) {
        bad('cURL (identity) شکست خورد: #' . $errno . ' ' . $error);
    } else {
        report('cURL بدون فشرده‌سازی (identity)', $body, $code, $ctype, $sec);
    }
    line();
}

// ---------- 4. تست cURL با Brotli (مقایسه) ----------
if (function_exists('curl_init')) {
    $ch = curl_init($search_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $config['timeout'],
        CURLOPT_CONNECTTIMEOUT => $config['connect'],
        CURLOPT_USERAGENT      => $config['user_agent'],
        CURLOPT_ENCODING       => 'br, gzip, deflate',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $start = microtime(true);
    $body  = curl_exec($ch);
    $sec   = microtime(true) - $start;
    $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno || $code === 0) {
        bad('cURL (brotli) شکست خورد: #' . $errno . ' ' . $error);
    } else {
        report('cURL با Brotli (مقایسه)', $body, $code, $ctype, $sec);
        if ($code === 200 && is_string($body) && strpos($body, '{') !== 0) {
            bad('بدنه‌ی پاسخ JSON نیست؛ احتمالاً Brotli باز نشده (علت خطای JSON در نسخه‌های قبلی).');
        }
    }
    line();
}

// ---------- 5. تست PHP Streams (مسیر «بدون cURL») ----------
if (ini_get('allow_url_fopen')) {
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => $config['timeout'],
            'header'        => "Accept: application/json, text/plain, */*\r\n"
                             . "Accept-Language: fa-IR,fa;q=0.9,en;q=0.8\r\n"
                             . "User-Agent: {$config['user_agent']}\r\n"
                             . "Referer: https://torob.com/\r\n"
                             . "Accept-Encoding: gzip, deflate\r\n",
            'follow_location' => 1,
            'ignore_errors'   => true,
        ],
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
        ],
    ]);
    $start = microtime(true);
    $body  = @file_get_contents($search_url, false, $ctx);
    $sec   = microtime(true) - $start;
    if ($body === false) {
        bad('PHP Streams (file_get_contents) شکست خورد.');
    } else {
        $code = 200;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int) $m[1]; break; }
        }
        $ctype = '';
        foreach ($http_response_header ?? [] as $h) {
            if (stripos($h, 'Content-Type:') === 0) { $ctype = trim(substr($h, 13)); break; }
        }
        // اگر پاسخ gzip بود و خودکار باز نشده، تلاش برای بازکردن.
        if (0 === strpos($body, "\x1f\x8b") && function_exists('gzdecode')) {
            $body = gzdecode($body);
        }
        report('PHP Streams (file_get_contents)', $body, $code, $ctype, $sec);
    }
    line();
} else {
    info('allow_url_fopen غیرفعال است؛ این روش رد می‌شود (در افزونه از WordPress HTTP API استفاده می‌شود).');
    line();
}

// ---------- 6. جمع‌بندی ----------
line('==============================================');
line('جمع‌بندی:');
line('  • اگر «cURL بدون فشرده‌سازی» موفق بود ولی «cURL با gzip/deflate» نه،');
line('    مشکل از رمزگشایی فشرده‌سازی است.');
line('  • اگر cURL شکست خورد ولی Streams موفق بود، مشکل در پیاده‌سازی cURL/تنظیمات آن است.');
line('  • اگر هر دو شکست خوردند، DNS / SSL / فایروال / مسیر شبکه یا IP هاست را بررسی کنید.');
line('  • اگر همه 200 دادند ولی بدنه JSON نبود، endpoint یا ساختار پاسخ تغییر کرده است.');
line('==============================================');
