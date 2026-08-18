<?php
/**
 * عیب‌یابی خودکار اتصال به ترب.
 *
 * یک گزارش کامل و قابل کپی از وضعیت اتصال به endpoint جستجوی ترب می‌سازد:
 *   - محیط PHP/وردپرس/ووکامرس/cURL
 *   - DNS
 *   - cURL با gzip/deflate
 *   - cURL بدون فشرده‌سازی (identity)
 *   - cURL با Brotli (برای مقایسه)
 *   - WordPress HTTP API
 *   - User-Agent متفاوت
 *   - پروکسی (در صورت تنظیم)
 *
 * برای هر درخواست این موارد ثبت می‌شود:
 *   URL (بدون اطلاعات حساس)، روش، HTTP status، Content-Type، زمان پاسخ،
 *   curl errno/error، WP_Error code/message، طول پاسخ و ۴۰۰ نویسه‌ی اول،
 *   معتبر بودن JSON و وجود کلید results.
 *
 * گزارش فقط در پاسخ AJAX به کاربرِ دارای دسترسی edit_products برگردانده
 * می‌شود و هیچ اطلاعات حساسی (توکن/کوکی) ندارد.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_Diagnostics.
 */
class Shoper_Diagnostics {

	/**
	 * اجرای کامل عیب‌یابی و برگرداندن گزارش.
	 *
	 * @return array
	 */
	public static function run() {
		$checks    = array();
		$env       = self::environment();
		$search_url = self::search_url();

		$checks[] = self::dns_check();
		$checks[] = self::http_check( 'curl_gzip', 'cURL با gzip/deflate (روش اصلی افزونه)', 'curl', $search_url, array( 'encoding' => 'gzip, deflate', 'ua' => self::ua( 0 ) ) );
		$checks[] = self::http_check( 'curl_identity', 'cURL بدون فشرده‌سازی (identity)', 'curl', $search_url, array( 'encoding' => 'identity', 'ua' => self::ua( 0 ) ) );
		$checks[] = self::http_check( 'curl_brotli', 'cURL با Brotli (مقایسه)', 'curl', $search_url, array( 'encoding' => 'br, gzip, deflate', 'ua' => self::ua( 0 ) ) );
		$checks[] = self::http_check( 'wp_http', 'WordPress HTTP API', 'wp', $search_url, array( 'ua' => self::ua( 0 ) ) );
		$checks[] = self::http_check( 'curl_ua_mobile', 'cURL با User-Agent موبایل', 'curl', $search_url, array( 'encoding' => 'gzip, deflate', 'ua' => self::ua( 1 ) ) );
		$checks[] = self::proxy_check( $search_url );
		$checks[] = self::relay_check();
		foreach ( self::gateway_checks( $search_url ) as $gw_check ) {
			$checks[] = $gw_check;
		}

		$summary = self::summarize( $checks );

		return array(
			'generated_at' => current_time( 'mysql' ),
			'summary'      => $summary,
			'environment'  => $env,
			'checks'       => $checks,
			'text'         => self::render_text( $summary, $env, $checks, $search_url ),
		);
	}

	/**
	 * اطلاعات محیط.
	 *
	 * @return array
	 */
	private static function environment() {
		global $wp_version;

		$proxy = get_option( 'shoper_proxy_url', '' );

		return array(
			'plugin_version'    => defined( 'SHOPER_VERSION' ) ? SHOPER_VERSION : '?',
			'php_version'       => PHP_VERSION,
			'wp_version'        => isset( $wp_version ) ? $wp_version : '?',
			'wc_version'        => defined( 'WC_VERSION' ) ? WC_VERSION : '—',
			'curl'              => function_exists( 'curl_init' ),
			'curl_version'      => function_exists( 'curl_version' ) ? self::curl_version_string() : '—',
			'allow_url_fopen'   => (bool) ini_get( 'allow_url_fopen' ),
			'openssl'           => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : '—',
			'data_source'       => get_option( 'shoper_data_source', 'direct' ),
			'timeout'           => (int) get_option( 'shoper_request_timeout', 25 ),
			'connect_timeout'   => (int) get_option( 'shoper_connect_timeout', 10 ),
			'proxy'             => self::mask_proxy( $proxy ),
			'proxy_configured'  => ( '' !== trim( (string) $proxy ) ),
			'relay'             => self::mask_proxy( get_option( 'shoper_relay_url', '' ) ),
			'relay_configured'  => ( '' !== trim( (string) get_option( 'shoper_relay_url', '' ) ) ),
			'fetch_mode'        => get_option( 'shoper_fetch_mode', 'auto' ),
			'default_gateways'  => ( 'no' !== get_option( 'shoper_use_default_gateways', 'yes' ) ),
			'debug_enabled'     => Shoper_Debug::enabled(),
			'home_url'          => function_exists( 'home_url' ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : '—',
		);
	}

	/**
	 * نسخه‌ی cURL به‌صورت رشته.
	 *
	 * @return string
	 */
	private static function curl_version_string() {
		$v = curl_version();
		if ( is_array( $v ) && isset( $v['version'] ) ) {
			return $v['version'] . ( isset( $v['ssl_version'] ) ? ' (' . $v['ssl_version'] . ')' : '' );
		}
		return '—';
	}

	/**
	 * آدرس endpoint جستجو با یک عبارت خنثی.
	 *
	 * @return string
	 */
	private static function search_url() {
		return add_query_arg(
			array(
				'page'   => 0,
				'size'   => 3,
				'q'      => 's25',
				'source' => 'next_desktop',
			),
			Shoper_Torob_Client::API_BASE . Shoper_Torob_Client::SEARCH_URL
		);
	}

	/**
	 * لیست User-Agentهای مختلف.
	 *
	 * @param int $i اندیس.
	 * @return string
	 */
	private static function ua( $i ) {
		$list = array(
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
			'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
		);
		return isset( $list[ $i ] ) ? $list[ $i ] : $list[0];
	}

	/**
	 * بررسی DNS.
	 *
	 * @return array
	 */
	private static function dns_check() {
		$host  = 'api.torob.com';
		$ips   = array();
		$ok    = false;
		$note  = '';

		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A ); // phpcs:ignore
			if ( is_array( $records ) ) {
				foreach ( $records as $r ) {
					if ( ! empty( $r['ip'] ) ) {
						$ips[] = $r['ip'];
					}
				}
			}
		}
		if ( empty( $ips ) && function_exists( 'gethostbynamel' ) ) {
			$res = @gethostbynamel( $host ); // phpcs:ignore
			if ( is_array( $res ) ) {
				$ips = array_values( array_filter( $res ) );
			}
		}

		if ( ! empty( $ips ) ) {
			$ok   = true;
			$note = 'دامنه resolve شد. اگر هنوز اتصال برقرار نمی‌شود، مشکل در فایروال/مسیر شبکه است نه DNS.';
		} else {
			$note = 'دامنه resolve نشد؛ DNS سرور مشکل دارد یا نام دامنه در دسترس نیست.';
		}

		return array(
			'id'      => 'dns',
			'label'   => 'DNS — api.torob.com',
			'method'  => 'dns',
			'status'  => $ok ? 'ok' : 'fail',
			'detail'  => $ok ? implode( ', ', $ips ) : 'no A record',
			'note'    => $note,
		);
	}

	/**
	 * یک درخواست HTTP مشخص و ثبت نتیجه.
	 *
	 * @param string $id      شناسه.
	 * @param string $label   برچسب.
	 * @param string $method  curl | wp.
	 * @param string $url     آدرس.
	 * @param array  $opts    گزینه‌ها.
	 * @return array
	 */
	private static function http_check( $id, $label, $method, $url, $opts = array() ) {
		$result = ( 'curl' === $method ) ? self::curl_request( $url, $opts ) : self::wp_request( $url, $opts );
		return self::analyze( $id, $label, $method, $result );
	}

	/**
	 * بررسی پروکسی.
	 *
	 * @param string $url آدرس.
	 * @return array
	 */
	private static function proxy_check( $url ) {
		$proxy = trim( (string) get_option( 'shoper_proxy_url', '' ) );
		if ( '' === $proxy ) {
			return array(
				'id'      => 'proxy',
				'label'   => 'پروکسی',
				'method'  => 'proxy',
				'status'  => 'skip',
				'note'    => 'پروکسی تنظیم نشده است؛ این بررسی رد شد.',
			);
		}
		$result = self::curl_request( $url, array( 'encoding' => 'gzip, deflate', 'ua' => self::ua( 0 ), 'proxy' => $proxy ) );
		return self::analyze( 'proxy', 'cURL از طریق پروکسی (' . self::mask_proxy( $proxy ) . ')', 'proxy', $result );
	}

	/**
	 * بررسی درگاه‌های پیش‌فرض تست‌شده.
	 *
	 * @param string $url آدرس تست.
	 * @return array
	 */
	private static function gateway_checks( $url ) {
		if ( 'no' === get_option( 'shoper_use_default_gateways', 'yes' ) ) {
			return array(
				array(
					'id'     => 'gateway',
					'label'  => 'درگاه پیش‌فرض',
					'method' => 'gateway',
					'status' => 'skip',
					'note'   => 'درگاه‌های پیش‌فرض در تنظیمات خاموش شده‌اند.',
				),
			);
		}

		$out = array();
		foreach ( Shoper_Torob_Client::default_gateways() as $g ) {
			$target = Shoper_Torob_Client::wrap_gateway( $g, $url );
			$result = self::curl_request(
				$target,
				array(
					'encoding' => 'gzip, deflate',
					'ua'       => self::ua( 0 ),
				)
			);
			$item = self::analyze(
				'gateway_' . $g['id'],
				'درگاه پیش‌فرض ' . $g['label'],
				'gateway',
				$result
			);
			if ( 'ok' === $item['status'] ) {
				$item['note'] = 'این درگاه JSON معتبر ترب برگرداند. افزونه باید بدون رله ایران کار کند.';
			} elseif ( 'fail' === $item['status'] && empty( $item['note'] ) ) {
				$item['note'] = 'این درگاه از این هاست به ترب نرسید.';
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * بررسی رلهٔ ایران.
	 *
	 * @return array
	 */
	private static function relay_check() {
		$relay = trim( (string) get_option( 'shoper_relay_url', '' ) );
		if ( '' === $relay ) {
			return array(
				'id'     => 'relay',
				'label'  => 'رله ایران',
				'method' => 'relay',
				'status' => 'skip',
				'note'   => 'رله تنظیم نشده است. برای هاست خارج، فایل tools/shoper-relay.php را روی هاست ایران بگذارید.',
			);
		}
		$client = new Shoper_Torob_Client();
		$target = $client->wrap_relay_url( self::search_url() );
		$result = self::curl_request( $target, array( 'encoding' => 'gzip, deflate', 'ua' => self::ua( 0 ) ) );
		return self::analyze( 'relay', 'رله ایران (' . self::mask_proxy( $relay ) . ')', 'relay', $result );
	}

	/**
	 * درخواست cURL خام.
	 *
	 * @param string $url  آدرس.
	 * @param array  $opts گزینه‌ها.
	 * @return array
	 */
	private static function curl_request( $url, $opts ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return array(
				'wp_error_code'    => 'curl_unavailable',
				'wp_error_message' => 'افزونه‌ی cURL روی این PHP فعال نیست.',
			);
		}

		$timeout = 8;
		$connect = 5;

		$ch = curl_init( $url );
		$options = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 3,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => $connect,
			CURLOPT_USERAGENT      => isset( $opts['ua'] ) ? $opts['ua'] : self::ua( 0 ),
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER     => array(
				'Accept: application/json, text/plain, */*',
				'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
				'Referer: https://torob.com/',
				'Origin: https://torob.com',
			),
		);
		if ( isset( $opts['encoding'] ) ) {
			$options[ CURLOPT_ENCODING ] = $opts['encoding'];
		}
		if ( ! empty( $opts['proxy'] ) ) {
			$options[ CURLOPT_PROXY ] = $opts['proxy'];
		}
		curl_setopt_array( $ch, $options );

		$body    = curl_exec( $ch );
		$errno   = (int) curl_errno( $ch );
		$error   = curl_error( $ch );
		$code    = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$ctype   = curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
		$elapsed = curl_getinfo( $ch, CURLINFO_TOTAL_TIME );
		curl_close( $ch );

		if ( false === $body || 0 !== $errno ) {
			return array(
				'curl_errno'  => $errno,
				'curl_error'  => $error,
				'duration'    => round( (float) $elapsed, 3 ),
			);
		}

		return array(
			'code'         => $code,
			'content_type' => is_string( $ctype ) ? $ctype : '',
			'body'         => (string) $body,
			'duration'     => round( (float) $elapsed, 3 ),
		);
	}

	/**
	 * درخواست با WordPress HTTP API.
	 *
	 * @param string $url  آدرس.
	 * @param array  $opts گزینه‌ها.
	 * @return array
	 */
	private static function wp_request( $url, $opts ) {
		$start = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 3,
				'user-agent'  => isset( $opts['ua'] ) ? $opts['ua'] : self::ua( 0 ),
				'headers'     => array(
					'Accept'          => 'application/json, text/plain, */*',
					'Accept-Language' => 'fa-IR,fa;q=0.9,en;q=0.8',
					'Referer'         => 'https://torob.com/',
					'Origin'          => 'https://torob.com',
				),
				'sslverify'   => true,
				'compress'    => true,
			)
		);
		$duration = round( microtime( true ) - $start, 3 );

		if ( is_wp_error( $response ) ) {
			return array(
				'wp_error_code'    => $response->get_error_code(),
				'wp_error_message' => $response->get_error_message(),
				'duration'         => $duration,
			);
		}

		return array(
			'code'         => (int) wp_remote_retrieve_response_code( $response ),
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			'body'         => (string) wp_remote_retrieve_body( $response ),
			'duration'     => $duration,
		);
	}

	/**
	 * تحلیل نتیجه‌ی یک درخواست و ساخت آیتم گزارش.
	 *
	 * @param string $id     شناسه.
	 * @param string $label  برچسب.
	 * @param string $method روش.
	 * @param array  $result نتیجه‌ی خام.
	 * @return array
	 */
	private static function analyze( $id, $label, $method, $result ) {
		$item = array(
			'id'     => $id,
			'label'  => $label,
			'method' => $method,
		);

		if ( isset( $result['curl_errno'] ) || isset( $result['wp_error_code'] ) ) {
			$item['status'] = 'fail';
			$item['note']   = self::transport_note( $result );
			if ( isset( $result['curl_errno'] ) ) {
				$item['curl_errno'] = $result['curl_errno'];
				$item['curl_error'] = isset( $result['curl_error'] ) ? $result['curl_error'] : '';
			}
			if ( isset( $result['wp_error_code'] ) ) {
				$item['wp_error_code']    = $result['wp_error_code'];
				$item['wp_error_message'] = isset( $result['wp_error_message'] ) ? $result['wp_error_message'] : '';
			}
			if ( isset( $result['duration'] ) ) {
				$item['duration'] = $result['duration'];
			}
			return $item;
		}

		$code = (int) $result['code'];
		$body = isset( $result['body'] ) ? $result['body'] : '';

		$item['code']         = $code;
		$item['content_type'] = isset( $result['content_type'] ) ? $result['content_type'] : '';
		$item['duration']     = isset( $result['duration'] ) ? $result['duration'] : 0;
		$item['body_length']  = strlen( $body );
		$item['body_sample']  = substr( $body, 0, 400 );

		$json = json_decode( $body, true );
		if ( $code >= 200 && $code < 300 && is_array( $json ) ) {
			$item['json_valid'] = true;
			if ( array_key_exists( 'results', $json ) && is_array( $json['results'] ) ) {
				$item['has_results']   = true;
				$item['results_count'] = count( $json['results'] );
				$item['status']        = 'ok';
				$item['note']          = 'پاسخ معتبر با کلید results (' . count( $json['results'] ) . ' نتیجه) دریافت شد.';
			} else {
				$item['has_results'] = false;
				$item['status']      = 'warn';
				$item['note']        = 'پاسخ JSON معتبر است اما کلید results وجود ندارد؛ ساختار پاسخ ترب تغییر کرده است.';
			}
		} elseif ( $code >= 200 && $code < 300 ) {
			$item['json_valid'] = false;
			$item['status']     = 'warn';
			$item['note']       = 'پاسخ 2xx است اما JSON نیست؛ احتمالاً صفحه‌ی HTML خطا یا پاسخ فشرده‌ی بازنشده (Brotli).';
		} elseif ( 403 === $code || 490 === $code ) {
			$item['status'] = 'fail';
			$item['blocked'] = true;
			$item['note']   = 'ترب IP این هاست را مسدود کرده (کد ' . $code . '). این روی سرور انتظار می‌رود. افزونه باید از مرورگر مدیر یا رله ایران کار کند — این دکمه فقط مسیر سرور را می‌سنجد.';
		} elseif ( 429 === $code ) {
			$item['status'] = 'warn';
			$item['note']   = 'ترب تعداد درخواست‌ها را محدود کرده (429)؛ کمی بعد دوباره تلاش کنید.';
		} elseif ( 502 === $code || 503 === $code ) {
			$item['status'] = 'warn';
			$item['note']   = 'پاسخ موقتی سرور ترب (' . $code . ')؛ افزونه با backoff تلاش مجدد می‌کند.';
		} else {
			$item['status'] = 'fail';
			$item['note']   = 'پاسخ غیرمنتظره با کد ' . $code . '.';
		}

		return $item;
	}

	/**
	 * توضیح خطای سطح انتقال.
	 *
	 * @param array $result نتیجه.
	 * @return string
	 */
	private static function transport_note( $result ) {
		if ( isset( $result['curl_errno'] ) ) {
			switch ( (int) $result['curl_errno'] ) {
				case 6:  return 'DNS قابل حل نیست (errno 6).';
				case 7:  return 'اتصال به سرور برقرار نشد (errno 7) — فایروال/مسیر/پورت 443.';
				case 28: return 'زمان اتصال به پایان رسید (errno 28).';
				case 35: return 'خطای SSL handshake (errno 35) — ریست اتصال؛ معمولاً مسیر شبکه/فایروال.';
				case 60: return 'مشکل گواهی SSL (errno 60) — CA/زمان سرور.';
				case 77: return 'ناتوانی در خواندن گواهی CA (errno 77) — باندل CA روی هاست ناقص است.';
			}
			return 'خطای cURL #' . (int) $result['curl_errno'] . ': ' . ( isset( $result['curl_error'] ) ? $result['curl_error'] : '' );
		}
		if ( isset( $result['wp_error_code'] ) ) {
			return 'WP_Error [' . $result['wp_error_code'] . ']: ' . ( isset( $result['wp_error_message'] ) ? $result['wp_error_message'] : '' );
		}
		return 'خطای ناشناخته‌ی انتقال.';
	}

	/**
	 * خلاصه‌ی کلی.
	 *
	 * @param array $checks بررسی‌ها.
	 * @return array
	 */
	private static function summarize( $checks ) {
		$ok      = 0;
		$warn    = 0;
		$fail    = 0;
		$success = null;
		$failure = null;

		foreach ( $checks as $c ) {
			// DNS فقط اطلاعاتی است؛ در نتیجه‌ی کلیِ «اتصال» شمرده نمی‌شود.
			if ( isset( $c['method'] ) && 'dns' === $c['method'] ) {
				continue;
			}
			if ( 'ok' === $c['status'] ) {
				$ok++;
				if ( null === $success ) {
					$success = $c;
				}
			} elseif ( 'fail' === $c['status'] ) {
				$fail++;
				if ( null === $failure ) {
					$failure = $c;
				}
			} elseif ( 'warn' === $c['status'] ) {
				$warn++;
			}
		}

		$blocked = 0;
		foreach ( $checks as $c ) {
			if ( ! empty( $c['blocked'] ) || ( isset( $c['code'] ) && in_array( (int) $c['code'], array( 403, 490 ), true ) ) ) {
				$blocked++;
			}
		}

		if ( $ok > 0 ) {
			$verdict = 'ok';
			$message = 'حداقل یک روش سرور به ترب متصل شد (' . ( $success ? $success['label'] : '' ) . ').';
		} elseif ( $blocked > 0 && $blocked === $fail ) {
			$verdict = 'blocked';
			$message = 'مسیر مستقیم سرور مسدود است (کد 490). اگر درگاه پیش‌فرض در همین گزارش موفق باشد افزونه کار می‌کند؛ وگرنه نام را در کادر جستجو بنویسید.';
		} elseif ( $fail > 0 && $warn === 0 ) {
			$verdict = 'fail';
			$message = 'هیچ روش سروری به ترب متصل نشد. جزئیات هر روش در گزارش آمده است.';
		} elseif ( $warn > 0 ) {
			$verdict = 'warn';
			$message = 'پاسخ‌هایی دریافت شد اما معتبر نبود (JSON/ساختار/کد). جزئیات را در گزارش ببینید.';
		} else {
			$verdict = 'unknown';
			$message = 'نتیجه‌ی قطعی به دست نیامد.';
		}

		return array(
			'verdict' => $verdict,
			'message' => $message,
			'counts'  => array( 'ok' => $ok, 'warn' => $warn, 'fail' => $fail ),
			'first_success' => $success ? $success['id'] : '',
			'first_failure' => $failure ? $failure['id'] : '',
		);
	}

	/**
	 * پنهان‌کردن اطلاعات احراز هویت پروکسی.
	 *
	 * @param string $proxy آدرس پروکسی.
	 * @return string
	 */
	private static function mask_proxy( $proxy ) {
		$proxy = trim( (string) $proxy );
		if ( '' === $proxy ) {
			return '';
		}
		$parts = wp_parse_url( $proxy );
		if ( false === $parts ) {
			return $proxy;
		}
		$out = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' );
		if ( isset( $parts['host'] ) ) {
			$out .= $parts['host'];
		}
		if ( ! empty( $parts['port'] ) ) {
			$out .= ':' . $parts['port'];
		}
		return $out;
	}

	/**
	 * ساخت گزارش متنی کامل (قابل کپی).
	 *
	 * @param array  $summary    خلاصه.
	 * @param array  $env        محیط.
	 * @param array  $checks     بررسی‌ها.
	 * @param string $search_url آدرس تست.
	 * @return string
	 */
	private static function render_text( $summary, $env, $checks, $search_url ) {
		$lines   = array();
		$lines[] = '==============================================================';
		$lines[] = ' Shoper — گزارش عیب‌یابی اتصال به ترب';
		$lines[] = ' زمان: ' . current_time( 'mysql' );
		$lines[] = ' نسخه افزونه: ' . $env['plugin_version'];
		$lines[] = '==============================================================';
		$lines[] = '';
		$lines[] = '[نتیجه‌ی کلی] ' . strtoupper( $summary['verdict'] ) . ' — ' . $summary['message'];
		$lines[] = '  موفق: ' . $summary['counts']['ok'] . ' | هشدار: ' . $summary['counts']['warn'] . ' | ناموفق: ' . $summary['counts']['fail'];
		$lines[] = '';
		$lines[] = '[محیط]';
		$lines[] = '  PHP: ' . $env['php_version'];
		$lines[] = '  WordPress: ' . $env['wp_version'];
		$lines[] = '  WooCommerce: ' . $env['wc_version'];
		$lines[] = '  cURL: ' . ( $env['curl'] ? $env['curl_version'] : 'موجود نیست' );
		$lines[] = '  allow_url_fopen: ' . ( $env['allow_url_fopen'] ? 'فعال' : 'غیرفعال' );
		$lines[] = '  OpenSSL: ' . $env['openssl'];
		$lines[] = '  منبع داده: ' . $env['data_source'];
		$lines[] = '  timeout / connect-timeout: ' . $env['timeout'] . ' / ' . $env['connect_timeout'];
		$lines[] = '  پروکسی: ' . ( $env['proxy_configured'] ? $env['proxy'] : 'تنظیم نشده' );
		$lines[] = '  رله ایران: ' . ( ! empty( $env['relay_configured'] ) ? $env['relay'] : 'تنظیم نشده' );
		$lines[] = '  روش دریافت: ' . ( isset( $env['fetch_mode'] ) ? $env['fetch_mode'] : 'auto' );
		$lines[] = '  درگاه پیش‌فرض: ' . ( ! empty( $env['default_gateways'] ) ? 'فعال' : 'خاموش' );
		$lines[] = '  لاگ اشکال‌زدایی: ' . ( $env['debug_enabled'] ? 'فعال' : 'غیرفعال' );
		$lines[] = '  دامنه سایت: ' . $env['home_url'];
		$lines[] = '';
		$lines[] = '[URL تست] ' . $search_url;
		$lines[] = '';

		foreach ( $checks as $c ) {
			$lines[] = '--------------------------------------------------------------';
			$lines[] = '[' . strtoupper( $c['status'] ) . '] ' . $c['label'];
			if ( isset( $c['code'] ) ) {
				$lines[] = '  HTTP status: ' . $c['code'];
			}
			if ( isset( $c['content_type'] ) && '' !== $c['content_type'] ) {
				$lines[] = '  Content-Type: ' . $c['content_type'];
			}
			if ( isset( $c['duration'] ) ) {
				$lines[] = '  زمان پاسخ: ' . $c['duration'] . 's';
			}
			if ( isset( $c['curl_errno'] ) ) {
				$lines[] = '  curl errno: ' . $c['curl_errno'];
				$lines[] = '  curl error: ' . $c['curl_error'];
			}
			if ( isset( $c['wp_error_code'] ) ) {
				$lines[] = '  WP_Error code: ' . $c['wp_error_code'];
				$lines[] = '  WP_Error message: ' . $c['wp_error_message'];
			}
			if ( isset( $c['body_length'] ) ) {
				$lines[] = '  طول پاسخ: ' . $c['body_length'] . ' بایت';
			}
			if ( isset( $c['json_valid'] ) ) {
				$lines[] = '  JSON معتبر: ' . ( $c['json_valid'] ? 'بله' : 'خیر' );
			}
			if ( isset( $c['has_results'] ) ) {
				$lines[] = '  کلید results: ' . ( $c['has_results'] ? 'دارد (' . ( isset( $c['results_count'] ) ? $c['results_count'] : 0 ) . ' نتیجه)' : 'ندارد' );
			}
			if ( ! empty( $c['note'] ) ) {
				$lines[] = '  توضیح: ' . $c['note'];
			}
			if ( ! empty( $c['detail'] ) ) {
				$lines[] = '  جزئیات: ' . $c['detail'];
			}
			if ( ! empty( $c['body_sample'] ) ) {
				$lines[] = '  نمونه‌ی پاسخ (۴۰۰ نویسه): ' . preg_replace( '/\s+/', ' ', $c['body_sample'] );
			}
			$lines[] = '';
		}

		$lines[] = '==============================================================';
		$lines[] = ' پایان گزارش';
		$lines[] = '==============================================================';

		return implode( "\n", $lines );
	}
}
