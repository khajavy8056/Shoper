<?php
/**
 * Shoper — رلهٔ سبک برای هاست ایران
 * ================================================================
 * این فایل را روی یک هاست داخل ایران آپلود کنید (حتی یک زیردامنهٔ رایگان).
 * سپس آدرس کامل آن را در تنظیمات افزونه، فیلد «آدرس رله ایران» بگذارید:
 *
 *     https://your-iran-host.com/shoper-relay.php?token=یک-رمز-قوی
 *
 * فقط درخواست GET به دامنه‌های ترب را پروکسی می‌کند.
 * پس از نصب، مقدار $TOKEN را عوض کنید.
 */

declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

$TOKEN = 'change-this-token'; // ← این مقدار را عوض کنید.

$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Accept, Content-Type');
header('Vary: Origin');

if ('OPTIONS' === strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
	http_response_code(204);
	exit;
}

if ('GET' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) {
	http_response_code(405);
	header('Content-Type: application/json; charset=utf-8');
	echo '{"error":"method not allowed"}';
	exit;
}

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ('' === $TOKEN || ! hash_equals($TOKEN, $token)) {
	http_response_code(403);
	header('Content-Type: application/json; charset=utf-8');
	echo '{"error":"forbidden"}';
	exit;
}

$target = isset($_GET['url']) ? (string) $_GET['url'] : '';
if ('' === $target || ! preg_match('#^https://#i', $target)) {
	http_response_code(400);
	header('Content-Type: application/json; charset=utf-8');
	echo '{"error":"invalid url"}';
	exit;
}

$host = parse_url($target, PHP_URL_HOST);
if (! is_string($host) || ! preg_match('#(^|\.)torob\.(com|ir)$#i', $host)) {
	http_response_code(400);
	header('Content-Type: application/json; charset=utf-8');
	echo '{"error":"host not allowed"}';
	exit;
}

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

if (function_exists('curl_init')) {
	$ch = curl_init($target);
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 3,
			CURLOPT_TIMEOUT        => 25,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_USERAGENT      => $ua,
			CURLOPT_ENCODING       => 'gzip, deflate',
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER     => array(
				'Accept: application/json, text/plain, */*',
				'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
				'Referer: https://torob.com/',
				'Origin: https://torob.com',
			),
		)
	);
	$body = curl_exec($ch);
	$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	$errno = (int) curl_errno($ch);
	curl_close($ch);
	if (false === $body || 0 !== $errno) {
		http_response_code(502);
		header('Content-Type: application/json; charset=utf-8');
		echo '{"error":"upstream failed"}';
		exit;
	}
} else {
	$ctx  = stream_context_create(
		array(
			'http' => array(
				'method'  => 'GET',
				'timeout' => 25,
				'header'  => "User-Agent: {$ua}\r\nAccept: application/json\r\nReferer: https://torob.com/\r\n",
			),
		)
	);
	$body = @file_get_contents($target, false, $ctx);
	$code = 200;
	$ctype = 'application/json; charset=utf-8';
	if (false === $body) {
		http_response_code(502);
		header('Content-Type: application/json; charset=utf-8');
		echo '{"error":"upstream failed"}';
		exit;
	}
}

http_response_code($code > 0 ? $code : 200);
header('Content-Type: ' . ($ctype ? $ctype : 'application/json; charset=utf-8'));
echo $body;
