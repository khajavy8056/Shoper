<?php
/**
 * Shoper - اسکریپت تست اتصال به API ترب
 * ----------------------------------------------------
 * این فایل یک اسکریپت مستقل است (نیازی به وردپرس ندارد).
 * آن را روی هاست/سروری که قرار است افزونه در آن نصب شود آپلود کنید
 * و از طریق مرورگر یا CLI اجرا کنید:
 *
 *     php torob-connection-test.php "s25 ultra"
 *
 * یا در مرورگر:
 *     https://yoursite.com/torob-connection-test.php?key=shoper2026
 *
 * برای تغییر کلمه‌ی عبور، مقدار $ACCESS_KEY را در پایین ویرایش کنید.
 *
 * این اسکریپت بررسی می‌کند:
 *   1. آیا سرور به api.torob.com دسترسی دارد؟
 *   2. آیا تصاویر image.torob.com قابل دانلود هستند؟
 *   3. آیا مشخصات فنی (structural_specs) به‌درستی برمی‌گردد؟
 *   4. نسخه‌ی PHP و افزونه‌های لازم.
 *
 * پس از تست، این فایل را از سرور حذف کنید.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

// ---------- محافظت دسترسی ----------
// برای جلوگیری از اجرای عمومی، یک عبارت دلخواه به‌عنوان توکن انتخاب کنید
// و هنگام باز شدن آدرس، آن را به‌صورت ?key=... وارد کنید.
// مثال: https://yoursite.com/torob-connection-test.php?key=shoper2026
$ACCESS_KEY = 'shoper2026';   // ← این مقدار را می‌توانید تغییر دهید
if (!isset($_GET['key']) || $_GET['key'] !== $ACCESS_KEY) {
    http_response_code(403);
    echo "دسترسی غیرمجاز. پارامتر key را در آدرس وارد کنید.\n";
    echo "مثال: torob-connection-test.php?key=" . $ACCESS_KEY . "\n";
    exit(1);
}

$config = [
    'query'     => $argv[1] ?? ($_GET['q'] ?? 's25 ultra'),
    'api_base'  => 'https://api.torob.com',
    'timeout'   => 20,
    'user_agent'=> 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                   .'(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
];

function line(string $msg = ''): void {
    echo $msg . PHP_EOL;
}
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

if (!function_exists('curl_init')) {
    bad('افزونه‌ی cURL در PHP فعال نیست. برای اجرای افزونه باید cURL فعال باشد.');
    exit(1);
}

// ---------- 2. جستجو ----------
$search_url = $config['api_base'] . '/v4/base-product/search/?' . http_build_query([
    'page'   => 0,
    'size'   => 5,
    'sort'   => 'popularity',
    'query'  => $config['query'],
    'q'      => $config['query'],
    'source' => 'next_desktop',
]);

info('در حال جستجوی: "' . $config['query'] . '"');
info('URL: ' . $search_url);

$ch = curl_init($search_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => $config['timeout'],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT      => $config['user_agent'],
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json, text/plain, */*',
        'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
        'Referer: https://torob.com/',
        'Origin: https://torob.com',
    ],
    CURLOPT_HEADER         => true,
]);

$response   = curl_exec($ch);
$http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$header_len = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers    = substr((string)$response, 0, $header_len);
$body       = substr((string)$response, $header_len);
$errno      = curl_errno($ch);
$error      = curl_error($ch);
curl_close($ch);

line();
info('کد پاسخ HTTP: ' . $http_code);

if ($errno || $http_code === 0) {
    bad('اتصال به API ترب برقرار نشد.');
    bad('خطای cURL #' . $errno . ': ' . $error);
    line();
    info('علل احتمالی:');
    info('  • سرور/هاست شما به آی‌پی‌های ایران یا torob.com دسترسی ندارد.');
    info('  • فایروال خروجی سرور اتصال به پورت 443 به torob.com را بسته است.');
    info('  • DNS سرور نمی‌تواند api.torob.com را resolve کند.');
    line();
    info('راه‌حل‌ها:');
    info('  • افزونه را روی یک هاست/سرور داخل ایران امتحان کنید.');
    info('  • در تنظیمات افزونه، آدرس پروکسی یا سرویس واسط را وارد کنید.');
    exit(1);
}

if ($http_code !== 200) {
    bad('سرور ترب پاسخ غیرمنتظره داد (کد ' . $http_code . ').');
    line('هدرهای پاسخ:');
    line($headers);
    line('بدنه‌ی پاسخ (اول ۵۰۰ بایت):');
    line(substr($body, 0, 500));
    exit(1);
}

$data = json_decode($body, true);
if (!is_array($data) || empty($data['results'])) {
    bad('پاسخ JSON معتبر دریافت نشد یا نتیجه‌ای یافت نشد.');
    line('بدنه‌ی پاسخ (اول ۱۰۰۰ بایت):');
    line(substr($body, 0, 1000));
    exit(1);
}

ok('اتصال به API جستجوی ترب برقرار است.');
line();

$results = $data['results'];
line('تعداد نتایج در این صفحه: ' . count($results));
line('تعداد کل نتایج: ' . ($data['count'] ?? 'نامشخص'));
line();

// نمایش خلاصه‌ی نتایج
foreach ($results as $i => $item) {
    line(($i + 1) . '. ' . ($item['name1'] ?? 'بدون نام'));
    line('    انگلیسی: ' . ($item['name2'] ?? '-'));
    line('    قیمت: ' . (isset($item['price']) ? number_format((int)$item['price']) . ' تومان' : '-'));
    line('    تصویر: ' . ($item['image_url'] ?? '-'));
    if (!empty($item['more_info_url'])) {
        line('    more_info_url: ' . $item['more_info_url']);
    }
    line();
}

// ---------- 3. دریافت جزئیات اولین محصول ----------
$first = $results[0];
$more_info_url = $first['more_info_url'] ?? '';

if ($more_info_url) {
    // استخراج prk و search_id از more_info_url
    $parts = parse_url($more_info_url);
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $prk       = $query['prk'] ?? '';
    $search_id = $query['search_id'] ?? '';

    if ($prk && $search_id) {
        info('در حال دریافت جزئیات کامل محصول اول...');
        info("prk={$prk}, search_id={$search_id}");

        $detail_url = $config['api_base'] . '/v4/base-product/details/?' . http_build_query([
            'prk'        => $prk,
            'search_id'  => $search_id,
            'source'     => 'next_desktop',
        ]);

        $ch = curl_init($detail_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $config['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => $config['user_agent'],
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/plain, */*',
                'Referer: https://torob.com/',
            ],
        ]);
        $detail_body = (string)curl_exec($ch);
        $detail_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($detail_code === 200) {
            $detail = json_decode($detail_body, true);
            ok('جزئیات محصول دریافت شد.');
            line();

            // مشخصات فنی
            $specs = $detail['structural_specs']['headers'][0]['specs'] ?? [];
            if (!empty($specs)) {
                ok('تعداد مشخصات فنی (structural_specs): ' . count($specs));
                line('نمونه‌ی مشخصات:');
                $shown = 0;
                foreach ($specs as $key => $value) {
                    line('   - ' . $key . ': ' . $value);
                    if (++$shown >= 15) { line('   - ... و موارد دیگر'); break; }
                }
            } else {
                bad('structural_specs خالی است یا ساختار تغییر کرده.');
            }
            line();

            // بررسی تصویر
            $image = $detail['image_url'] ?? '';
            if ($image) {
                $ch = curl_init($image);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_NOBODY         => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_USERAGENT      => $config['user_agent'],
                    CURLOPT_REFERER        => 'https://torob.com/',
                ]);
                curl_exec($ch);
                $img_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($img_code === 200) {
                    ok('تصویر محصول از image.torob.com قابل دانلود است.');
                } else {
                    bad('تصویر محصول با کد ' . $img_code . ' پاسخ داد.');
                }
            }

            line();
            line('--- خلاصه‌ی فیلدهای موجود در پاسخ جزئیات ---');
            line(implode(', ', array_keys($detail)));
        } else {
            bad('دریافت جزئیات با کد ' . $detail_code . ' ناموفق بود.');
        }
    } else {
        bad('prk یا search_id در more_info_url یافت نشد.');
        info('مقدار more_info_url: ' . $more_info_url);
    }
}

line();
line('==============================================');
ok('تست به پایان رسید.');
line('اگر همه‌ی مراحل ✅ بودند، افزونه روی این هاست بدون مشکل کار خواهد کرد.');
line('این فایل را پس از تست حذف کنید.');
