<?php
/**
 * Shoper — تست خودکار منطق کلاینت ترب (بدون وردپرس و بدون شبکه).
 *
 * این فایل کلاس واقعی Shoper_Torob_Client را با یک لایه‌ی HTTP شبیه‌سازی‌شده
 * تست می‌کند تا رفتار fallback، retry، خطاها، نرمال‌سازی و کش را مستقل از
 * دسترسی به اینترنت تأیید کند.
 *
 * نکته درباره‌ی cURL:
 *   - اگر افزونه‌ی cURL روی PHP نصب نباشد، این اسکریپت یک cURL شبیه‌سازی‌شده
 *     تعریف می‌کند و همه‌ی تست‌ها (از جمله قرارداد آپشن‌های cURL و fallback)
 *     قطعی اجرا می‌شوند.
 *   - اگر cURL نصب باشد، تست‌های وابسته به کنترل cURL با برچسب SKIP رد می‌شوند
 *     (چون نمی‌توان توابع داخلی cURL را بازنویسی کرد).
 *
 * اجرا:
 *     php tools/shoper-self-test.php            # حالت پیش‌فرض
 *     php tools/shoper-self-test.php --no-curl  # شبیه‌سازی نبود cURL (فقط وقتی cURL واقعاً نصب نیست)
 *
 * خروجی: گزارش PASS/FAIL/SKIP و کد خروج (0 = موفق).
 */

// نگهبان دسترسی مستقیم کلاس‌ها.
define( 'ABSPATH', true );

$SHOPER_NO_CURL = ( isset( $argv ) && in_array( '--no-curl', $argv, true ) ) || '1' === getenv( 'SHOPER_NO_CURL' );

/* -------------------------------------------------------------------------- */
/* حداقل شبیه‌سازی توابع وردپرس                                                */
/* -------------------------------------------------------------------------- */

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

// شناسه‌ی محصول آزمایشی (UUID 36 نویسه‌ای معتبر).
define( 'TEST_PRK', 'aaaaaaaa-1111-2222-3333-444444444444' );

// مسیر پوشه‌ی افزونه؛ قابل بازنویسی از طریق متغیر محیطی (مثلاً برای اجرا در محیط‌های ایزوله).
$shoper_plugin_dir = getenv( 'SHOPER_PLUGIN_DIR' );
if ( ! $shoper_plugin_dir ) {
	$shoper_plugin_dir = dirname( __DIR__ ) . '/shoper-torob-importer/';
}
define( 'SHOPER_PLUGIN_DIR', rtrim( $shoper_plugin_dir, '/' ) . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }

function wp_parse_url( $url, $component = -1 ) {
	if ( -1 === $component ) {
		return parse_url( $url );
	}
	return parse_url( $url, $component );
}

function add_query_arg( $args, $url ) {
	$parts = wp_parse_url( $url );
	$query = array();
	if ( isset( $parts['query'] ) ) { parse_str( $parts['query'], $query ); }
	$query = array_merge( $query, $args );
	$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
	$host   = isset( $parts['host'] ) ? $parts['host'] : '';
	$path   = isset( $parts['path'] ) ? $parts['path'] : '';
	$qs     = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	return $scheme . $host . $path . ( '' !== $qs ? '?' . $qs : '' );
}

function sanitize_text_field( $s ) { return trim( (string) $s ); }

/* گزینه‌ها و ترنزینت‌ها در حافظه. */
$GLOBALS['shoper_options']    = array();
$GLOBALS['shoper_transients'] = array();
$GLOBALS['shoper_now']        = 1000000;

function get_option( $key, $default = false ) {
	return isset( $GLOBALS['shoper_options'][ $key ] ) ? $GLOBALS['shoper_options'][ $key ] : $default;
}
function get_transient( $key ) {
	if ( isset( $GLOBALS['shoper_transients'][ $key ] ) ) {
		$t = $GLOBALS['shoper_transients'][ $key ];
		if ( $t['expires'] > $GLOBALS['shoper_now'] ) { return $t['value']; }
	}
	return false;
}
function set_transient( $key, $value, $expiration ) {
	$GLOBALS['shoper_transients'][ $key ] = array(
		'value'   => $value,
		'expires' => $GLOBALS['shoper_now'] + $expiration,
	);
	return true;
}

/* -------------------------------------------------------------------------- */
/* لایه‌ی HTTP شبیه‌سازی‌شده                                                   */
/* -------------------------------------------------------------------------- */

$GLOBALS['http_requests'] = array();   // لیست درخواست‌ها (transport, url).
$GLOBALS['http_responder'] = null;     // callable($transport, $url) => response
$GLOBALS['curl_last'] = array( 'errno' => 0, 'error' => '', 'code' => 0, 'ctype' => '', 'options' => array(), 'url' => '' );

function wp_response( $code, $body, $ctype = 'application/json' ) {
	return array(
		'response' => array( 'code' => (int) $code, 'message' => '' ),
		'headers'  => array( 'content-type' => $ctype ),
		'body'     => (string) $body,
	);
}
function wp_error( $code, $message ) { return new WP_Error( $code, $message ); }

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['http_requests'][] = array( 'transport' => 'wp', 'url' => $url, 'args' => $args );
	$r = $GLOBALS['http_responder'] ? call_user_func( $GLOBALS['http_responder'], 'wp', $url ) : null;
	if ( isset( $r['wp_error'] ) ) {
		return new WP_Error( $r['wp_error']['code'], $r['wp_error']['message'] );
	}
	return wp_response( isset( $r['code'] ) ? $r['code'] : 200, isset( $r['body'] ) ? $r['body'] : '', isset( $r['ctype'] ) ? $r['ctype'] : 'application/json' );
}
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}
function wp_remote_retrieve_header( $response, $name ) {
	if ( isset( $response['headers'] ) && is_array( $response['headers'] ) ) {
		foreach ( $response['headers'] as $k => $v ) {
			if ( strtolower( $k ) === strtolower( $name ) ) { return $v; }
		}
	}
	return '';
}

/* ثابت‌های cURL (فقط اگر از قبل تعریف نشده باشند). */
if ( ! defined( 'CURLOPT_RETURNTRANSFER' ) ) { define( 'CURLOPT_RETURNTRANSFER', 1 ); }
if ( ! defined( 'CURLOPT_FOLLOWLOCATION' ) ) { define( 'CURLOPT_FOLLOWLOCATION', 2 ); }
if ( ! defined( 'CURLOPT_MAXREDIRS' ) ) { define( 'CURLOPT_MAXREDIRS', 3 ); }
if ( ! defined( 'CURLOPT_TIMEOUT' ) ) { define( 'CURLOPT_TIMEOUT', 4 ); }
if ( ! defined( 'CURLOPT_CONNECTTIMEOUT' ) ) { define( 'CURLOPT_CONNECTTIMEOUT', 5 ); }
if ( ! defined( 'CURLOPT_HTTPHEADER' ) ) { define( 'CURLOPT_HTTPHEADER', 6 ); }
if ( ! defined( 'CURLOPT_USERAGENT' ) ) { define( 'CURLOPT_USERAGENT', 7 ); }
if ( ! defined( 'CURLOPT_ENCODING' ) ) { define( 'CURLOPT_ENCODING', 8 ); }
if ( ! defined( 'CURLOPT_SSL_VERIFYPEER' ) ) { define( 'CURLOPT_SSL_VERIFYPEER', 9 ); }
if ( ! defined( 'CURLOPT_SSL_VERIFYHOST' ) ) { define( 'CURLOPT_SSL_VERIFYHOST', 10 ); }
if ( ! defined( 'CURLOPT_HTTP_VERSION' ) ) { define( 'CURLOPT_HTTP_VERSION', 11 ); }
if ( ! defined( 'CURLOPT_PROXY' ) ) { define( 'CURLOPT_PROXY', 12 ); }
if ( ! defined( 'CURL_HTTP_VERSION_1_1' ) ) { define( 'CURL_HTTP_VERSION_1_1', 2 ); }
if ( ! defined( 'CURLINFO_RESPONSE_CODE' ) ) { define( 'CURLINFO_RESPONSE_CODE', 100 ); }
if ( ! defined( 'CURLINFO_CONTENT_TYPE' ) ) { define( 'CURLINFO_CONTENT_TYPE', 101 ); }

$SHOPER_CURL_STUBBED = false;
if ( ! $SHOPER_NO_CURL && ! function_exists( 'curl_init' ) ) {
	$SHOPER_CURL_STUBBED = true;
	/**
	 * شبیه‌ساز cURL (فقط وقتی cURL واقعی نصب نیست).
	 */
	function curl_init( $url = '' ) {
		$GLOBALS['curl_last']['url'] = $url;
		$GLOBALS['curl_last']['options'] = array();
		return (object) array( 'url' => $url );
	}
	function curl_setopt( $ch, $opt, $value ) {
		$GLOBALS['curl_last']['options'][ $opt ] = $value;
		return true;
	}
	function curl_setopt_array( $ch, $options ) {
		foreach ( $options as $k => $v ) { $GLOBALS['curl_last']['options'][ $k ] = $v; }
		return true;
	}
	function curl_exec( $ch ) {
		$GLOBALS['http_requests'][] = array( 'transport' => 'curl', 'url' => $ch->url );
		$GLOBALS['curl_last']['errno'] = 0;
		$GLOBALS['curl_last']['error'] = '';
		$GLOBALS['curl_last']['code'] = 0;
		$GLOBALS['curl_last']['ctype'] = '';
		$r = $GLOBALS['http_responder'] ? call_user_func( $GLOBALS['http_responder'], 'curl', $ch->url ) : null;
		if ( isset( $r['wp_error'] ) ) {
			$GLOBALS['curl_last']['errno'] = 7;
			$GLOBALS['curl_last']['error'] = $r['wp_error']['message'];
			return false;
		}
		$GLOBALS['curl_last']['code'] = isset( $r['code'] ) ? (int) $r['code'] : 200;
		$GLOBALS['curl_last']['ctype'] = isset( $r['ctype'] ) ? $r['ctype'] : 'application/json';
		return isset( $r['body'] ) ? $r['body'] : '';
	}
	function curl_errno( $ch ) { return $GLOBALS['curl_last']['errno']; }
	function curl_error( $ch ) { return $GLOBALS['curl_last']['error']; }
	function curl_getinfo( $ch, $opt ) {
		if ( CURLINFO_RESPONSE_CODE === $opt ) { return $GLOBALS['curl_last']['code']; }
		if ( CURLINFO_CONTENT_TYPE === $opt ) { return $GLOBALS['curl_last']['ctype']; }
		return null;
	}
	function curl_close( $ch ) {}
}

/* بارگذاری کلاس‌های واقعی. */
require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-debug.php';
require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-torob-client.php';

/* -------------------------------------------------------------------------- */
/* چارچوب تست                                                                  */
/* -------------------------------------------------------------------------- */

$GLOBALS['test_pass'] = 0;
$GLOBALS['test_fail'] = 0;
$GLOBALS['test_skip'] = 0;
$GLOBALS['test_results'] = array();

function reset_state() {
	$GLOBALS['shoper_transients'] = array();
	$GLOBALS['shoper_options']    = array();
	$GLOBALS['http_requests']     = array();
	$GLOBALS['http_responder']    = null;
	$GLOBALS['curl_last'] = array( 'errno' => 0, 'error' => '', 'code' => 0, 'ctype' => '', 'options' => array(), 'url' => '' );
}
function check( $label, $cond, $extra = '' ) {
	if ( $cond ) { $GLOBALS['test_pass']++; $GLOBALS['test_results'][] = "  [PASS] $label" . ( '' !== $extra ? " — $extra" : '' ); }
	else { $GLOBALS['test_fail']++; $GLOBALS['test_results'][] = "  [FAIL] $label" . ( '' !== $extra ? " — $extra" : '' ); }
}
function skip( $label, $why = '' ) {
	$GLOBALS['test_skip']++;
	$GLOBALS['test_results'][] = "  [SKIP] $label" . ( '' !== $why ? " — $why" : '' );
}
function request_count() { return count( $GLOBALS['http_requests'] ); }
function wp_request_count() {
	$n = 0;
	foreach ( $GLOBALS['http_requests'] as $r ) { if ( 'wp' === $r['transport'] ) { $n++; } }
	return $n;
}
function last_request() { $n = count( $GLOBALS['http_requests'] ); return $n ? $GLOBALS['http_requests'][ $n - 1 ] : null; }

function search_body( $count = 1, $name = 'گوشی تست' ) {
	$results = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$results[] = array(
			'random_key' => TEST_PRK,
			'name1'      => $name,
			'name2'      => 'Test Phone',
			'price'      => 1000000,
			'price_text' => 'از ۱٬۰۰۰٬۰۰۰ تومان',
			'shop_text'  => 'در ۱۰ فروشگاه',
			'image_url'  => 'https://image.torob.com/x.webp',
			'media_urls' => array( array( 'type' => 'image', 'url' => 'https://image.torob.com/x.webp' ) ),
			'more_info_url' => 'https://api.torob.com/v4/base-product/details/?search_id=sid1&prk=' . TEST_PRK,
			'web_client_absolute_url' => '/p/' . TEST_PRK . '/',
			'is_adv' => false,
		);
	}
	return json_encode( array( 'count' => $count, 'results' => $results ) );
}

function details_body() {
	return json_encode( array(
		'random_key' => TEST_PRK,
		'name1'      => 'گوشی تست',
		'name2'      => 'Test Phone',
		'price'      => 1000000,
		'price_text' => 'از ۱٬۰۰۰٬۰۰۰ تومان',
		'image_url'  => 'https://image.torob.com/x.webp',
		'media_urls' => array( array( 'type' => 'image', 'url' => 'https://image.torob.com/x.webp' ) ),
		'structural_specs' => array( 'headers' => array( array( 'header' => 'کلی', 'specs' => array( 'برند' => 'سامسونگ' ) ) ) ),
		'key_specs' => array(),
		'products_info' => array( 'result' => array( array( 'shop_name' => 'فروشگاه', 'shop_name2' => 'تهران', 'price' => 1000000, 'price_text' => '۱٬۰۰۰٬۰۰۰', 'shop_score' => 4.5, 'page_url' => 'https://x.example' ) ) ),
		'web_client_absolute_url' => '/p/' . TEST_PRK . '/',
	) );
}

$client = new Shoper_Torob_Client();

echo "Shoper self-test — cURL: " . ( $SHOPER_NO_CURL ? 'no-curl (شبیه‌سازی)' : ( $SHOPER_CURL_STUBBED ? 'stubbed (شبیه‌سازی)' : ( function_exists( 'curl_init' ) ? 'present (واقعی)' : 'absent' ) ) ) . "\n\n";

/* 1) mock ---------------------------------------------------------------- */
reset_state();
$GLOBALS['shoper_options']['shoper_data_source'] = 'mock';
$mock_client = new Shoper_Torob_Client();
$res = $mock_client->search( 'x', 0, 10 );
check( '1. mock: جستجو از فایل نمونه برمی‌گردد', ! is_wp_error( $res ) && ! empty( $res['results'] ), 'نتایج=' . ( is_array( $res ) ? count( $res['results'] ) : 0 ) );
reset_state();

/* 2) search 200 + results ------------------------------------------------- */
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => search_body( 2 ) ); };
$res = $client->search( 'گوشی', 0, 10 );
check( '2. search: پاسخ 200 معتبر نرمال می‌شود', ! is_wp_error( $res ) && 2 === count( $res['results'] ) );
$lr = last_request();
check( '2b. search: پارامتر q استفاده شده (نه query)', false !== strpos( $lr['url'], 'q=' ) && false === strpos( $lr['url'], 'query=' ), $lr['url'] );

/* 3) search 200 + empty results ------------------------------------------ */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => '{"count":0,"results":[]}' ); };
$res = $client->search( 'هیچ', 0, 10 );
check( '3. search: نتیجه‌ی خالی موفق است نه خطا', ! is_wp_error( $res ) && array() === $res['results'] );

/* 4) search 200 + missing results key ------------------------------------ */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => '{"foo":1}' ); };
$res = $client->search( 'x', 0, 10 );
check( '4. search: نبود کلید results → invalid_response', is_wp_error( $res ) && 'invalid_response' === $res->get_error_code() );

/* 5) search 200 + invalid JSON -------------------------------------------- */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => '<html>not json</html>', 'ctype' => 'text/html' ); };
$res = $client->search( 'x', 0, 10 );
check( '5. search: JSON نامعتبر → invalid_json', is_wp_error( $res ) && 'invalid_json' === $res->get_error_code() );

/* 6) search 403 ------------------------------------------------------------ */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 403, 'body' => '{}' ); };
$res = $client->search( 'x', 0, 10 );
check( '6. search: 403 → blocked', is_wp_error( $res ) && 'blocked' === $res->get_error_code(), $res->get_error_code() );

/* 7) search 490 ------------------------------------------------------------ */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 490, 'body' => '{"message":"Error"}' ); };
$res = $client->search( 'x', 0, 10 );
check( '7. search: 490 → blocked (نه JSON موفق)', is_wp_error( $res ) && 'blocked' === $res->get_error_code() && 490 === $res->get_error_data()['status'] );

/* 8) search 429 (تلاش مجدد ناموفق) ---------------------------------------- */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 429, 'body' => '' ); };
$start = microtime( true );
$res = $client->search( 'x', 0, 10 );
$elapsed = microtime( true ) - $start;
check( '8. search: 429 → rate_limited پس از تلاش مجدد', is_wp_error( $res ) && 'rate_limited' === $res->get_error_code() );
check( '8b. search: 429 چند بار تلاش کرده', request_count() >= 3, 'درخواست‌ها=' . request_count() );
check( '8c. search: backoff واقعی رعایت شده', $elapsed >= 1.0, 'زمان=' . round( $elapsed, 2 ) . 's' );

/* 9) search 502 سپس 503 سپس 200 (retry موفق) ------------------------------ */
reset_state();
$seq = array( 502, 503, 200 );
$GLOBALS['http_responder'] = function ( $t, $url ) use ( &$seq ) { $c = array_shift( $seq ); return array( 'code' => $c, 'body' => ( 200 === $c ? search_body( 1 ) : '' ) ); };
$res = $client->search( 'x', 0, 10 );
check( '9. search: 502→503→200 بعد از retry موفق می‌شود', ! is_wp_error( $res ) && 1 === count( $res['results'] ) );

/* 10) search 200 با کلید error --------------------------------------------- */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => '{"error":{"message":"oops"}}' ); };
$res = $client->search( 'x', 0, 10 );
check( '10. search: کلید error در بدنه → torob_error', is_wp_error( $res ) && 'torob_error' === $res->get_error_code() );

/* 11) کش: موفق کش می‌شود، خطا کش نمی‌شود ---------------------------------- */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => search_body( 1 ) ); };
$client->search( 'کش', 0, 10 );
$n1 = request_count();
$client->search( 'کش', 0, 10 );
check( '11. کش: درخواست موفق از کش خوانده می‌شود', request_count() === $n1, 'درخواست‌ها=' . request_count() );

reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 490, 'body' => '' ); };
$client->search( 'خطا', 0, 10 );
$n1 = request_count();
$client->search( 'خطا', 0, 10 );
check( '11b. کش: خطا کش نمی‌شود (درخواست مجدد)', request_count() > $n1, 'درخواست‌ها=' . request_count() );

/* 12) جزئیات: درخواست اصلی فقط prk + source ---------------------------------- */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => details_body() ); };
$res = $client->details( TEST_PRK, 'IGNORED_SID', '' );
$first_url = $GLOBALS['http_requests'][0]['url'];
check( '12. details: درخواست اصلی بدون search_id است', false === strpos( $first_url, 'search_id' ), $first_url );
check( '12b. details: prk و source در درخواست اصلی هستند', false !== strpos( $first_url, 'prk=' ) && false !== strpos( $first_url, 'source=next_desktop' ) );
check( '12c. details: نرمال‌سازی موفق', ! is_wp_error( $res ) && 'گوشی تست' === $res['name1'] && 1 === count( $res['sellers'] ) );

/* 13) جزئیات: main 490 → fallback more_info_url 200 -------------------------- */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) {
	if ( false !== strpos( $url, 'details-log-click' ) ) { return array( 'code' => 490, 'body' => '' ); }
	if ( false !== strpos( $url, 'search_id=sid1' ) ) { return array( 'code' => 200, 'body' => details_body() ); }
	return array( 'code' => 490, 'body' => '' ); // درخواست اصلی prk+source
};
$res = $client->details( TEST_PRK, '', 'https://api.torob.com/v4/base-product/details/?search_id=sid1&prk=' . TEST_PRK );
check( '13. details: fallback از more_info_url استفاده می‌کند', ! is_wp_error( $res ) && 'گوشی تست' === $res['name1'] );
check( '13b. details: درخواست اصلی اول اجرا شده', false === strpos( $GLOBALS['http_requests'][0]['url'], 'search_id' ) );

/* 14) جزئیات: ساختار نامعتبر ----------------------------------------------- */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => '{"foo":1}' ); };
$res = $client->details( TEST_PRK );
check( '14. details: ساختار نامعتبر → invalid_response', is_wp_error( $res ) && 'invalid_response' === $res->get_error_code() );

/* 15) suggest -------------------------------------------------------------- */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => search_body( 3, 'گوشی تست' ) ); };
$res = $client->suggest( 'گوشی', 8 );
check( '15. suggest: پیشنهادها ساخته می‌شوند', ! is_wp_error( $res ) && 1 === count( $res['suggestions'] ), 'پیشنهاد=' . ( isset( $res['suggestions'][0]['label'] ) ? $res['suggestions'][0]['label'] : '-' ) );
check( '15b. suggest: نام تکراری حذف می‌شود', 1 === count( $res['suggestions'] ) );

/* 16) details_from_url ------------------------------------------------------ */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => details_body() ); };
$res = $client->details_from_url( 'https://torob.com/p/' . TEST_PRK . '/x/' );
check( '16. details_from_url: prk از لینک استخراج می‌شود', ! is_wp_error( $res ) && TEST_PRK === $res['random_key'] );
$res2 = $client->details_from_url( 'https://torob.com/foo' );
check( '16b. details_from_url: لینک نامعتبر → invalid_url', is_wp_error( $res2 ) && 'invalid_url' === $res2->get_error_code() );

/* 17) cURL آپشن‌ها (قرارداد امنیتی) — فقط در حالت stubbed ------------------- */
if ( $SHOPER_CURL_STUBBED ) {
	reset_state();
	$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => search_body( 1 ) ); };
	$client->search( 'x', 0, 10 );
	$opts = $GLOBALS['curl_last']['options'];
	check( '17. cURL: SSL_VERIFYPEER فعال است', isset( $opts[ CURLOPT_SSL_VERIFYPEER ] ) && true === $opts[ CURLOPT_SSL_VERIFYPEER ] );
	check( '17b. cURL: ENCODING فقط gzip, deflate (بدون brotli)', isset( $opts[ CURLOPT_ENCODING ] ) && 'gzip, deflate' === $opts[ CURLOPT_ENCODING ] );
	check( '17c. cURL: timeout و connect-timeout جداگانه', isset( $opts[ CURLOPT_TIMEOUT ] ) && isset( $opts[ CURLOPT_CONNECTTIMEOUT ] ) );
	$headers = isset( $opts[ CURLOPT_HTTPHEADER ] ) ? implode( "\n", $opts[ CURLOPT_HTTPHEADER ] ) : '';
	check( '17d. cURL: هیچ هدر Accept-Encoding دستی (brotli) نیست', false === stripos( $headers, 'Accept-Encoding' ) );

	/* 18) fallback واقعی: cURL می‌شکند، وردپرس موفق می‌شود ------------------- */
	reset_state();
	$GLOBALS['http_responder'] = function ( $t, $url ) {
		if ( 'curl' === $t ) { return array( 'wp_error' => array( 'code' => 'curl_failed', 'message' => 'could not connect' ) ); }
		return array( 'code' => 200, 'body' => search_body( 1 ) );
	};
	$res = $client->search( 'x', 0, 10 );
	$used_curl = false; $used_wp = false;
	foreach ( $GLOBALS['http_requests'] as $r ) { if ( 'curl' === $r['transport'] ) { $used_curl = true; } if ( 'wp' === $r['transport'] ) { $used_wp = true; } }
	check( '18. fallback: cURL شکست → وردپرس امتحان شد', $used_curl && $used_wp );
	check( '18b. fallback: نتیجه موفق از مسیر وردپرس', ! is_wp_error( $res ) && 1 === count( $res['results'] ) );
} else {
	skip( '17-18. cURL stub + fallback', 'cURL واقعی نصب است؛ این تست‌ها فقط در PHP بدون cURL اجرا می‌شوند' );
}

/* 19) بدون cURL: مسیر وردپرس مستقیم ----------------------------------------- */
if ( ! function_exists( 'curl_init' ) ) {
	reset_state();
	$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => search_body( 1 ) ); };
	$res = $client->search( 'x', 0, 10 );
	$only_wp = true;
	foreach ( $GLOBALS['http_requests'] as $r ) { if ( 'curl' === $r['transport'] ) { $only_wp = false; } }
	check( '19. no-curl: فقط مسیر وردپرس استفاده شد', $only_wp && ! is_wp_error( $res ) );
	check( '19b. no-curl: جستجو موفق', ! is_wp_error( $res ) && 1 === count( $res['results'] ) );
} else {
	skip( '19. no-curl', 'cURL موجود است؛ این تست فقط در PHP بدون cURL اجرا می‌شود' );
}

/* 21) ingest + relay + payload ----------------------------------------------- */
reset_state();
$raw_search = json_decode(search_body(2, 'گوشی تست'), true);
$ing = $client->ingest_search($raw_search);
check('21. ingest_search: نتایج نرمال می‌شوند', ! is_wp_error($ing) && 2 === count($ing['results']));
$ing_bad = $client->ingest_search(array('foo' => 1));
check('21b. ingest_search: ساختار نامعتبر خطا است', is_wp_error($ing_bad) && 'invalid_response' === $ing_bad->get_error_code());

$raw_details = json_decode(details_body(), true);
$ingd = $client->ingest_details($raw_details);
check('21c. ingest_details: جزئیات نرمال می‌شود', ! is_wp_error($ingd) && 'گوشی تست' === $ingd['name1']);
check('21d. ingest_details: فروشنده availability دارد', ! empty($ingd['sellers'][0]['availability']));

$item = $client->ingest_search_item(array(
	'name1' => 'گوشی تست',
	'random_key' => TEST_PRK,
	'price' => 1000,
	'image_url' => 'https://image.torob.com/x.webp',
	'more_info_url' => 'https://api.torob.com/v4/base-product/details/?prk=' . TEST_PRK,
));
check('21e. ingest_search_item: پیش‌نمایش جزئی ساخته می‌شود', ! is_wp_error($item) && ! empty($item['partial']) && 1 === count($item['gallery']));

$GLOBALS['shoper_options']['shoper_relay_url'] = 'https://relay.example/shoper-relay.php?token=abc';
$relay_client = new Shoper_Torob_Client();
$wrapped = $relay_client->wrap_relay_url('https://api.torob.com/v4/base-product/search/?q=s25');
check('21f. wrap_relay_url: توکن و url حفظ می‌شوند', false !== strpos($wrapped, 'token=abc') && false !== strpos($wrapped, 'url='));

$sug = $client->suggest('گوشی', 8);
// suggest will hit mock/http depending on state; reset and stub.
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => search_body( 1, 'گوشی تست' ) ); };
$sug = $client->suggest('گوشی', 8);
check('21g. suggest: more_info_url در پیشنهاد هست', ! is_wp_error($sug) && ! empty($sug['suggestions'][0]['more_info_url']));

/* 20) test_connection -------------------------------------------------------- */
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 200, 'body' => search_body( 1 ) ); };
$r = $client->test_connection();
check( '20. test_connection: موفق', ! empty( $r['ok'] ) && ! empty( $r['count'] ) );
reset_state();
$GLOBALS['http_responder'] = function ( $t, $url ) { return array( 'code' => 490, 'body' => '' ); };
$r = $client->test_connection();
check( '20b. test_connection: کد خطا برمی‌گردد', empty( $r['ok'] ) && 'blocked' === $r['code'] );

/* -------------------------------------------------------------------------- */
echo implode( "\n", $GLOBALS['test_results'] ) . "\n\n";
echo 'نتیجه: ' . $GLOBALS['test_pass'] . ' موفق، ' . $GLOBALS['test_fail'] . ' ناموفق، ' . $GLOBALS['test_skip'] . " رد شده\n";
exit( $GLOBALS['test_fail'] > 0 ? 1 : 0 );
