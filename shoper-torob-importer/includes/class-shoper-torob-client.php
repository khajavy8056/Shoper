<?php
/**
 * سرویس‌گیرنده‌ی API ترب.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_Torob_Client
 *
 * مسئول برقراری ارتباط با API ترب یا خواندن داده‌ی نمونه (mock).
 *
 * لایه‌ی HTTP این کلاس:
 *   - اگر cURL موجود باشد ابتدا با آن درخواست می‌دهد؛
 *   - اگر cURL در سطح انتقال شکست بخورد، به WordPress HTTP API برمی‌گردد؛
 *   - SSL verification همیشه فعال است؛
 *   - Accept-Encoding فقط gzip/deflate است (بدون Brotli)؛
 *   - timeout و connect-timeout جداگانه‌اند؛
 *   - پاسخ غیر 2xx هرگز به‌عنوان JSON موفق پردازش نمی‌شود؛
 *   - برای 429/502/503 با backoff واقعی تلاش مجدد می‌شود.
 */
class Shoper_Torob_Client {

	const API_BASE          = 'https://api.torob.com';
	const SEARCH_URL        = '/v4/base-product/search/';
	const DETAIL_URL        = '/v4/base-product/details/';
	const DETAIL_CLICK_URL  = '/v4/base-product/details-log-click/';
	const SUGGEST_URL       = '/suggestion2/';

	/**
	 * منبع داده: direct | mock.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * User-Agent.
	 *
	 * @var string
	 */
	private $user_agent;

	/**
	 * Timeout کل درخواست (ثانیه).
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Timeout اتصال (ثانیه).
	 *
	 * @var int
	 */
	private $connect_timeout;

	/**
	 * آدرس پروکسی اختیاری.
	 *
	 * @var string
	 */
	private $proxy;

	/**
	 * آدرس رلهٔ اختیاری (برای هاست خارج از ایران / کد 490).
	 *
	 * @var string
	 */
	private $relay;

	/**
	 * سازنده.
	 */
	public function __construct() {
		$this->source          = get_option( 'shoper_data_source', 'direct' );
		$this->user_agent      = get_option(
			'shoper_user_agent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
		);
		$this->timeout         = (int) get_option( 'shoper_request_timeout', 25 );
		$this->connect_timeout = (int) get_option( 'shoper_connect_timeout', 10 );
		$this->proxy           = trim( (string) get_option( 'shoper_proxy_url', '' ) );
		$this->relay           = trim( (string) get_option( 'shoper_relay_url', '' ) );
	}

	/**
	 * ساخت آدرس جستجوی ترب.
	 *
	 * @param string $query عبارت.
	 * @param int    $page  صفحه.
	 * @param int    $size  تعداد.
	 * @return string
	 */
	public static function build_search_url( $query, $page = 0, $size = 10 ) {
		return add_query_arg(
			array(
				'page'   => (int) $page,
				'size'   => (int) $size,
				'q'      => $query,
				'source' => 'next_desktop',
			),
			self::API_BASE . self::SEARCH_URL
		);
	}

	/**
	 * ساخت آدرس جزئیات ترب.
	 *
	 * @param string $prk شناسه.
	 * @return string
	 */
	public static function build_details_url( $prk ) {
		return add_query_arg(
			array(
				'prk'    => $prk,
				'source' => 'next_desktop',
			),
			self::API_BASE . self::DETAIL_URL
		);
	}

	/**
	 * پیچیدن آدرس ترب داخل رلهٔ تنظیم‌شده.
	 *
	 * @param string $url آدرس ترب.
	 * @return string
	 */
	public function wrap_relay_url( $url ) {
		$relay = $this->relay;
		if ( '' === $relay ) {
			return '';
		}
		$sep = ( false === strpos( $relay, '?' ) ) ? '?' : '&';
		return $relay . $sep . 'url=' . rawurlencode( $url );
	}

	/**
	 * درگاه‌های پیش‌فرض تست‌شده (نه پروکسی باز تصادفی).
	 *
	 * فقط درگاه‌هایی اینجا هستند که در تست زنده JSON معتبر ترب برگرداندند.
	 * پروکسی‌های CONNECT عمومی به‌خاطر ناامنی و عدم تأیید اضافه نمی‌شوند.
	 *
	 * @return array
	 */
	public static function default_gateways() {
		return array(
			array(
				'id'    => 'cors_sh',
				'label' => 'CORS.SH',
				'style' => 'prefix',
				'base'  => 'https://proxy.cors.sh/',
			),
		);
	}

	/**
	 * پیچیدن آدرس ترب داخل یک درگاه.
	 *
	 * @param array  $gateway درگاه.
	 * @param string $url     آدرس ترب.
	 * @return string
	 */
	public static function wrap_gateway( $gateway, $url ) {
		if ( ! is_array( $gateway ) ) {
			return '';
		}
		$style = isset( $gateway['style'] ) ? $gateway['style'] : 'prefix';
		if ( 'template' === $style && ! empty( $gateway['template'] ) ) {
			return str_replace( '{url}', rawurlencode( $url ), (string) $gateway['template'] );
		}
		if ( empty( $gateway['base'] ) ) {
			return '';
		}
		$base = rtrim( (string) $gateway['base'], '/' );
		if ( 'query' === $style ) {
			$param = ! empty( $gateway['param'] ) ? $gateway['param'] : 'url';
			$sep   = ( false === strpos( $base, '?' ) ) ? '?' : '&';
			return $base . $sep . $param . '=' . rawurlencode( $url );
		}
		return $base . '/' . $url;
	}

	/**
	 * درگاه‌های فعال (پیش‌فرض + سفارشی کاربر).
	 *
	 * @return array
	 */
	public static function active_gateways() {
		$list = array();
		if ( 'no' !== get_option( 'shoper_use_default_gateways', 'yes' ) ) {
			$list = self::default_gateways();
		}

		$extra = trim( (string) get_option( 'shoper_extra_gateways', '' ) );
		if ( '' !== $extra ) {
			$lines = preg_split( '/\r\n|\r|\n/', $extra );
			$i     = 0;
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line || '#' === $line[0] ) {
					continue;
				}
				++$i;
				if ( false !== strpos( $line, '{url}' ) ) {
					$list[] = array(
						'id'       => 'custom_' . $i,
						'label'    => 'سفارشی ' . $i,
						'style'    => 'template',
						'template' => $line,
					);
					continue;
				}
				$list[] = array(
					'id'    => 'custom_' . $i,
					'label' => 'سفارشی ' . $i,
					'style' => 'prefix',
					'base'  => $line,
				);
			}
		}

		return $list;
	}

	/**
	 * فهرست آدرس‌های کاندید برای یک درخواست ترب.
	 *
	 * @param string $url آدرس اصلی ترب.
	 * @return array
	 */
	public function build_request_candidates( $url ) {
		$out = array();

		if ( $this->relay ) {
			$wrapped = $this->wrap_relay_url( $url );
			if ( $wrapped ) {
				$out[] = array(
					'url'  => $wrapped,
					'kind' => 'relay',
				);
			}
		}

		$gateways  = self::active_gateways();
		$cached_id = function_exists( 'get_transient' ) ? get_transient( 'shoper_good_gateway' ) : '';
		$cached_gw = null;
		if ( $cached_id ) {
			foreach ( $gateways as $g ) {
				if ( isset( $g['id'] ) && $g['id'] === $cached_id ) {
					$cached_gw = $g;
					break;
				}
			}
		}
		if ( $cached_gw ) {
			$wrapped = self::wrap_gateway( $cached_gw, $url );
			if ( $wrapped ) {
				$out[] = array(
					'url'        => $wrapped,
					'kind'       => 'gateway',
					'gateway_id' => $cached_gw['id'],
				);
			}
		}

		$direct_blocked = function_exists( 'get_transient' ) ? get_transient( 'shoper_direct_blocked' ) : false;
		if ( ! $direct_blocked ) {
			$out[] = array(
				'url'  => $url,
				'kind' => 'direct',
			);
		}

		foreach ( $gateways as $g ) {
			if ( $cached_gw && isset( $g['id'] ) && $g['id'] === $cached_gw['id'] ) {
				continue;
			}
			$wrapped = self::wrap_gateway( $g, $url );
			if ( ! $wrapped ) {
				continue;
			}
			$out[] = array(
				'url'        => $wrapped,
				'kind'       => 'gateway',
				'gateway_id' => isset( $g['id'] ) ? $g['id'] : '',
			);
		}

		if ( $direct_blocked ) {
			$out[] = array(
				'url'  => $url,
				'kind' => 'direct',
			);
		}

		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* جستجو و پیشنهاد نام                                                     */
	/* --------------------------------------------------------------------- */

	/**
	 * پیشنهاد نام محصول برای نوار کشویی (autocomplete).
	 *
	 * پیشنهادها بر پایه‌ی همان جستجوی اصلی ساخته می‌شوند و مستقل از جزئیات
	 * محصول هستند. فقط پاسخ موفق ۵ دقیقه کش می‌شود.
	 *
	 * @param string $term  بخشی از نام محصول.
	 * @param int    $limit حداکثر تعداد پیشنهاد.
	 * @return array|WP_Error
	 */
	public function suggest( $term, $limit = 8 ) {
		$term = trim( (string) $term );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $term, 'UTF-8' ) : strlen( $term );
		if ( $len < 2 ) {
			return array( 'suggestions' => array() );
		}

		$cache_key = 'shoper_sug_' . md5( $term . '|' . (int) $limit . '|' . $this->source );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$search = $this->search( $term, 0, max( (int) $limit, 8 ) );
		if ( is_wp_error( $search ) ) {
			return $search;
		}

		$suggestions = array();
		$seen        = array();
		foreach ( (array) $search['results'] as $item ) {
			if ( ! empty( $item['is_adv'] ) ) {
				continue;
			}
			$name = isset( $item['name1'] ) ? trim( (string) $item['name1'] ) : '';
			if ( '' === $name || isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;

			$suggestions[] = array(
				'label'         => $name,
				'name2'         => isset( $item['name2'] ) ? $item['name2'] : '',
				'random_key'    => isset( $item['random_key'] ) ? $item['random_key'] : '',
				'search_id'     => isset( $item['search_id'] ) ? $item['search_id'] : '',
				'image_url'     => isset( $item['image_url'] ) ? $item['image_url'] : '',
				'price'         => isset( $item['price'] ) ? (int) $item['price'] : 0,
				'price_text'    => isset( $item['price_text'] ) ? $item['price_text'] : '',
				'shop_text'     => isset( $item['shop_text'] ) ? $item['shop_text'] : '',
				'more_info_url' => isset( $item['more_info_url'] ) ? $item['more_info_url'] : '',
				'gallery'       => isset( $item['gallery'] ) ? $item['gallery'] : array(),
				'page_url'      => isset( $item['page_url'] ) ? $item['page_url'] : '',
			);

			if ( count( $suggestions ) >= (int) $limit ) {
				break;
			}
		}

		$payload = array(
			'term'        => $term,
			'suggestions' => $suggestions,
		);

		set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );
		return $payload;
	}

	/**
	 * جستجوی محصول با نام.
	 *
	 * فقط وقتی «no result» برگردانده می‌شود که درخواست واقعاً موفق بوده و
	 * نتایج خالی باشد؛ خطای شبکه هرگز به نتایج خالی تبدیل نمی‌شود.
	 *
	 * @param string $query نام/عبارت جستجو.
	 * @param int    $page  شماره صفحه (0-based).
	 * @param int    $size  تعداد نتایج.
	 * @return array|WP_Error
	 */
	public function search( $query, $page = 0, $size = 10 ) {
		if ( 'mock' === $this->source ) {
			return $this->load_mock( 'torob-search-sample.json' );
		}

		// پارامتر جستجوی ترب «q» است؛ ارسال «query» باعث نتایج نامرتبط می‌شود.
		$url = self::build_search_url( $query, $page, $size );

		$cache_key = 'shoper_search_' . md5( $query . '|' . (int) $page . '|' . (int) $size );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = $this->request( $url, 'search' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// ساختار پاسخ باید کلید results را داشته باشد (حتی اگر خالی باشد).
		if ( ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return new WP_Error( 'invalid_response', 'ساختار پاسخ جستجوی ترب تغییر کرده است (کلید results یافت نشد).' );
		}

		$normalized = $this->normalize_search_results( $data );

		// فقط پاسخ موفق کش می‌شود؛ خطاها هرگز کش نمی‌شوند.
		set_transient( $cache_key, $normalized, 5 * MINUTE_IN_SECONDS );
		return $normalized;
	}

	/* --------------------------------------------------------------------- */
	/* جزئیات محصول                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * دریافت جزئیات کامل یک محصول.
	 *
	 * درخواست اصلی فقط با prk + source=next_desktop ارسال می‌شود (بدون search_id).
	 * لینک more_info_url و اندپوینت details-log-click فقط به‌عنوان fallback
	 * امتحان می‌شوند و جایگزین رفع مشکل endpoint اصلی نیستند.
	 *
	 * @param string $prk           شناسه‌ی محصول (random_key).
	 * @param string $search_id     شناسه‌ی جستجو (نگهداری برای سازگاری؛ در درخواست اصلی استفاده نمی‌شود).
	 * @param string $more_info_url لینک کامل جزئیات برگردانده‌شده در نتایج جستجو.
	 * @return array|WP_Error
	 */
	public function details( $prk, $search_id = '', $more_info_url = '' ) {
		if ( 'mock' === $this->source ) {
			return $this->load_mock( 'torob-details-sample.json' );
		}

		$candidates = array();

		// ۱) درخواست اصلی: فقط prk + source.
		$candidates[] = add_query_arg(
			array(
				'prk'    => $prk,
				'source' => 'next_desktop',
			),
			self::API_BASE . self::DETAIL_URL
		);

		// ۲) fallback: لینک کامل more_info_url که خود ترب در نتایج جستجو داده است.
		if ( $more_info_url && $this->is_details_url( $more_info_url ) ) {
			$normalized = $this->normalize_api_url( $more_info_url );
			if ( $normalized ) {
				$candidates[] = $normalized;
			}
		}

		// ۳) fallback: details-log-click (به search_id نیاز ندارد).
		$candidates[] = add_query_arg(
			array(
				'prk'             => $prk,
				'source'          => 'next_desktop',
				'discover_method' => 'browse',
			),
			self::API_BASE . self::DETAIL_CLICK_URL
		);

		$candidates = array_values( array_unique( $candidates ) );

		$last_error = null;
		foreach ( $candidates as $url ) {
			$data = $this->request( $url, 'details' );
			if ( is_wp_error( $data ) ) {
				$last_error = $data;
				continue;
			}
			if ( ! $this->is_valid_details( $data ) ) {
				$last_error = new WP_Error( 'invalid_response', 'ساختار پاسخ جزئیات ترب قابل قبول نیست.' );
				continue;
			}
			return $this->normalize_details( $data );
		}

		return $last_error ? $last_error : new WP_Error( 'invalid_response', 'جزئیات محصول دریافت نشد.' );
	}

	/**
	 * دریافت جزئیات با استفاده از لینک صفحه‌ی محصول.
	 *
	 * @param string $page_url لینک محصول در torob.com.
	 * @return array|WP_Error
	 */
	public function details_from_url( $page_url ) {
		if ( preg_match( '#/p/([0-9a-f\-]{36})#i', $page_url, $m ) ) {
			return $this->details( $m[1] );
		}

		// اگر خود prk در کوئری‌استرینگ بود.
		$parsed = wp_parse_url( $page_url );
		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $q );
			if ( ! empty( $q['prk'] ) ) {
				return $this->details( sanitize_text_field( $q['prk'] ) );
			}
		}

		return new WP_Error( 'invalid_url', 'لینک محصول ترب معتبر به‌نظر نمی‌رسد. شناسه‌ی محصول در URL یافت نشد.' );
	}

	/**
	 * متد سازگاری برای نسخه‌های قبلی.
	 *
	 * @param string $page_url لینک.
	 * @return array|WP_Error
	 */
	public function get_details_from_url( $page_url ) {
		return $this->details_from_url( $page_url );
	}

	/**
	 * بررسی اینکه URL متعلق به اندپوینت جزئیات ترب است.
	 *
	 * @param string $url لینک.
	 * @return bool
	 */
	private function is_details_url( $url ) {
		return false !== strpos( (string) $url, '/base-product/details' );
	}

	/**
	 * تبدیل لینک نسبی API به آدرس کامل (با جلوگیری از SSRF).
	 *
	 * @param string $url لینک مطلق یا نسبی.
	 * @return string
	 */
	private function normalize_api_url( $url ) {
		$url = trim( (string) $url );
		if ( preg_match( '#^https?://#i', $url ) ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( ! $host || ! preg_match( '#(^|\.)torob\.(com|ir)$#i', $host ) ) {
				return '';
			}
			return $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return self::API_BASE . $url;
		}
		return self::API_BASE . '/' . $url;
	}

	/**
	 * بررسی ساختار حداقلی پاسخ جزئیات.
	 *
	 * @param array $data داده‌ی خام.
	 * @return bool
	 */
	private function is_valid_details( $data ) {
		return is_array( $data ) && ( ! empty( $data['random_key'] ) || ! empty( $data['name1'] ) );
	}

	/* --------------------------------------------------------------------- */
	/* نرمال‌سازی دادهٔ خام از مرورگر / رله                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * نرمال‌سازی پاسخ خام جستجو (برای دادهٔ آمده از مرورگر/رله).
	 *
	 * @param mixed $raw دادهٔ خام.
	 * @return array|WP_Error
	 */
	public function ingest_search( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'invalid_json', 'پاسخ ترب قابل پردازش نیست.' );
		}
		if ( ! isset( $raw['results'] ) || ! is_array( $raw['results'] ) ) {
			return new WP_Error( 'invalid_response', 'ساختار پاسخ جستجوی ترب تغییر کرده است (کلید results یافت نشد).' );
		}
		return $this->normalize_search_results( $raw );
	}

	/**
	 * نرمال‌سازی پاسخ خام جزئیات.
	 *
	 * @param mixed $raw دادهٔ خام.
	 * @return array|WP_Error
	 */
	public function ingest_details( $raw ) {
		if ( ! is_array( $raw ) || ! $this->is_valid_details( $raw ) ) {
			return new WP_Error( 'invalid_response', 'ساختار پاسخ جزئیات ترب قابل قبول نیست.' );
		}
		return $this->normalize_details( $raw );
	}

	/**
	 * ساخت پیش‌نمایش جزئی از یک آیتم جستجو (وقتی جزئیات 490 می‌شود).
	 *
	 * @param mixed $item آیتم خام یا نرمال‌شده.
	 * @return array|WP_Error
	 */
	public function ingest_search_item( $item ) {
		if ( ! is_array( $item ) ) {
			return new WP_Error( 'invalid_response', 'آیتم محصول نامعتبر است.' );
		}

		if ( ! empty( $item['name1'] ) && ( ! empty( $item['random_key'] ) || ! empty( $item['label'] ) ) && ! isset( $item['media_urls'] ) ) {
			$normalized = array(
				'random_key'    => ! empty( $item['random_key'] ) ? $item['random_key'] : '',
				'search_id'     => isset( $item['search_id'] ) ? $item['search_id'] : '',
				'name1'         => isset( $item['name1'] ) ? $item['name1'] : ( isset( $item['label'] ) ? $item['label'] : '' ),
				'name2'         => isset( $item['name2'] ) ? $item['name2'] : '',
				'price'         => isset( $item['price'] ) ? (int) $item['price'] : 0,
				'price_text'    => isset( $item['price_text'] ) ? $item['price_text'] : '',
				'shop_text'     => isset( $item['shop_text'] ) ? $item['shop_text'] : '',
				'image_url'     => isset( $item['image_url'] ) ? $item['image_url'] : '',
				'gallery'       => isset( $item['gallery'] ) && is_array( $item['gallery'] ) ? $item['gallery'] : array(),
				'page_url'      => isset( $item['page_url'] ) ? $item['page_url'] : '',
				'more_info_url' => isset( $item['more_info_url'] ) ? $item['more_info_url'] : '',
			);
		} else {
			$normalized = $this->extract_search_item( $item );
		}

		if ( empty( $normalized['name1'] ) && empty( $normalized['random_key'] ) ) {
			return new WP_Error( 'invalid_response', 'آیتم محصول نامعتبر است.' );
		}

		if ( empty( $normalized['gallery'] ) && ! empty( $normalized['image_url'] ) ) {
			$normalized['gallery'] = array( $normalized['image_url'] );
		}

		$normalized['description']   = '';
		$normalized['specs']         = array();
		$normalized['key_specs']     = array();
		$normalized['spec_groups']   = array();
		$normalized['sellers']       = array();
		$normalized['sellers_count'] = 0;
		$normalized['partial']       = true;
		$normalized['min_price']     = $normalized['price'];
		$normalized['max_price']     = 0;
		$normalized['variants']      = array();

		return $normalized;
	}

	/* --------------------------------------------------------------------- */
	/* لایه‌ی HTTP                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * ارسال درخواست HTTP با انتخاب خودکار روش انتقال.
	 *
	 * @param string $url     آدرس کامل.
	 * @param string $context برچسب بخش (search/details) برای لاگ.
	 * @return array|WP_Error
	 */
	private function request( $url, $context = '' ) {
		$candidates = $this->build_request_candidates( $url );
		$last_error = null;

		foreach ( $candidates as $candidate ) {
			$target = isset( $candidate['url'] ) ? $candidate['url'] : '';
			if ( '' === $target ) {
				continue;
			}

			$transports = array();
			if ( $this->curl_available() ) {
				$transports[] = 'curl';
			}
			$transports[] = 'wp';

			foreach ( $transports as $transport ) {
				$result = $this->request_once( $target, $transport, $context );

				if ( ! is_wp_error( $result ) ) {
					if ( ! empty( $candidate['gateway_id'] ) && function_exists( 'set_transient' ) ) {
						set_transient( 'shoper_good_gateway', $candidate['gateway_id'], 30 * MINUTE_IN_SECONDS );
					}
					if ( 'direct' === $candidate['kind'] && function_exists( 'delete_transient' ) ) {
						delete_transient( 'shoper_direct_blocked' );
					}
					return $result;
				}

				$last_error = $result;

				if ( 'direct' === $candidate['kind'] && 'blocked' === $result->get_error_code() && function_exists( 'set_transient' ) ) {
					set_transient( 'shoper_direct_blocked', 1, 15 * MINUTE_IN_SECONDS );
				}

				// پاسخ قطعی سرور (۴۹۰ و مشابه) را با روش انتقال دوم تکرار نکن؛ کاندید بعدی را برو.
				if ( 'curl' === $transport && ! $this->is_transport_error( $result ) ) {
					break;
				}

				if ( 'curl' === $transport ) {
					Shoper_Debug::log(
						'fallback',
						array(
							'context' => $context,
							'reason'  => 'cURL failed, trying WordPress HTTP API',
							'error'   => $result->get_error_code(),
							'kind'    => isset( $candidate['kind'] ) ? $candidate['kind'] : '',
						)
					);
				}
			}
		}

		return $last_error;
	}

	/**
	 * یک درخواست با یک روش انتقال مشخص + تلاش مجدد برای 429/502/503.
	 *
	 * @param string $url       آدرس.
	 * @param string $transport curl | wp.
	 * @param string $context   برچسب بخش.
	 * @return array|WP_Error
	 */
	private function request_once( $url, $transport, $context ) {
		$max_attempts = 3;
		$attempt      = 0;
		$last_error   = null;

		while ( $attempt < $max_attempts ) {
			$started  = microtime( true );
			$response = ( 'curl' === $transport ) ? $this->curl_get( $url ) : $this->wp_get( $url );
			$duration = round( microtime( true ) - $started, 3 );

			$this->log_request( $context, $transport, $url, $response, $duration );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				break;
			}

			$attempt++;
			$code = (int) $response['code'];

			if ( in_array( $code, array( 429, 502, 503 ), true ) ) {
				if ( $attempt < $max_attempts ) {
					$backoff = $this->backoff_seconds( $attempt - 1 );
					Shoper_Debug::log(
						'retry',
						array(
							'context'   => $context,
							'transport' => $transport,
							'status'    => $code,
							'attempt'   => $attempt,
							'backoff'   => $backoff,
						)
					);
					usleep( (int) round( $backoff * 1000000 ) );
					continue;
				}
				return $this->status_error( $code );
			}

			if ( $code >= 200 && $code < 300 ) {
				$data = json_decode( (string) $response['body'], true );
				if ( ! is_array( $data ) ) {
					return new WP_Error( 'invalid_json', 'پاسخ ترب قابل پردازش نیست.', array( 'status' => $code ) );
				}
				if ( isset( $data['error'] ) ) {
					$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'خطای ترب.';
					return new WP_Error( 'torob_error', $msg, array( 'status' => $code ) );
				}
				return $data;
			}

			// پاسخ غیر 2xx هرگز به‌عنوان JSON موفق پردازش نمی‌شود.
			return $this->status_error( $code );
		}

		return $last_error;
	}

	/**
	 * درخواست با cURL.
	 *
	 * @param string $url آدرس.
	 * @return array|WP_Error
	 */
	private function curl_get( $url ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'curl_unavailable', 'cURL در PHP فعال نیست.' );
		}

		$ch = curl_init( $url );
		if ( false === $ch ) {
			return new WP_Error( 'curl_failed', 'cURL نتوانست مقداردهی اولیه شود.' );
		}

		$options = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 3,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
			CURLOPT_HTTPHEADER     => $this->curl_headers(),
			CURLOPT_USERAGENT      => $this->user_agent,
			// فقط gzip و deflate تبلیغ و باز می‌شوند؛ بدون Brotli.
			CURLOPT_ENCODING       => 'gzip, deflate',
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		);

		if ( defined( 'CURLOPT_HTTP_VERSION' ) && defined( 'CURL_HTTP_VERSION_1_1' ) ) {
			$options[ CURLOPT_HTTP_VERSION ] = CURL_HTTP_VERSION_1_1;
		}

		curl_setopt_array( $ch, $options );

		if ( $this->proxy ) {
			curl_setopt( $ch, CURLOPT_PROXY, $this->proxy );
		}

		$body  = curl_exec( $ch );
		$errno = (int) curl_errno( $ch );
		$error = curl_error( $ch );
		$code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$ctype = curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
		curl_close( $ch );

		if ( false === $body || 0 !== $errno ) {
			return new WP_Error(
				'curl_failed',
				sprintf( 'cURL #%d: %s', $errno, $error ),
				array(
					'errno'      => $errno,
					'curl_error' => $error,
				)
			);
		}

		return array(
			'code'         => $code,
			'body'         => (string) $body,
			'content_type' => is_string( $ctype ) ? $ctype : '',
		);
	}

	/**
	 * درخواست با WordPress HTTP API.
	 *
	 * @param string $url آدرس.
	 * @return array|WP_Error
	 */
	private function wp_get( $url ) {
		$proxy_cb = null;
		if ( $this->proxy ) {
			$proxy    = $this->proxy;
			$proxy_cb = function ( $handle ) use ( $proxy ) {
				if ( function_exists( 'curl_setopt' ) && ( is_resource( $handle ) || ( class_exists( 'CurlHandle' ) && $handle instanceof \CurlHandle ) ) ) {
					curl_setopt( $handle, CURLOPT_PROXY, $proxy );
				}
			};
			add_action( 'http_api_curl', $proxy_cb, 10, 1 );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $this->timeout,
				'redirection' => 3,
				'user-agent'  => $this->user_agent,
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

		if ( $proxy_cb ) {
			remove_action( 'http_api_curl', $proxy_cb, 10 );
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'connection_failed',
				'اتصال به ترب برقرار نشد: ' . $response->get_error_message(),
				array( 'wp_error_code' => $response->get_error_code() )
			);
		}

		return array(
			'code'         => (int) wp_remote_retrieve_response_code( $response ),
			'body'         => (string) wp_remote_retrieve_body( $response ),
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		);
	}

	/**
	 * هدرهای درخواست cURL (بدون Accept-Encoding؛ آن را خود cURL مدیریت می‌کند).
	 *
	 * @return array
	 */
	private function curl_headers() {
		return array(
			'Accept: application/json, text/plain, */*',
			'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
			'Cache-Control: no-cache',
			'Pragma: no-cache',
			'Referer: https://torob.com/',
			'Origin: https://torob.com',
			'Sec-Fetch-Dest: empty',
			'Sec-Fetch-Mode: cors',
			'Sec-Fetch-Site: same-site',
		);
	}

	/**
	 * بررسی موجود بودن cURL.
	 *
	 * @return bool
	 */
	private function curl_available() {
		return function_exists( 'curl_init' ) && function_exists( 'curl_exec' );
	}

	/**
	 * آیا این خطا در سطح انتقال است (نه پاسخ قطعی سرور)؟
	 *
	 * @param WP_Error $error خطا.
	 * @return bool
	 */
	private function is_transport_error( $error ) {
		return in_array( $error->get_error_code(), array( 'curl_failed', 'curl_unavailable', 'connection_failed' ), true );
	}

	/**
	 * تبدیل کد وضعیت HTTP به WP_Error با کد مشخص.
	 *
	 * @param int $code کد وضعیت.
	 * @return WP_Error
	 */
	private function status_error( $code ) {
		switch ( $code ) {
			case 429:
				return new WP_Error( 'rate_limited', 'ترب تعداد درخواست‌ها را محدود کرده است. کمی بعد دوباره تلاش کنید.', array( 'status' => 429 ) );
			case 401:
			case 403:
			case 490:
				return new WP_Error( 'blocked', sprintf( 'ترب این درخواست را مسدود کرده است (کد %d).', $code ), array( 'status' => $code ) );
			default:
				return new WP_Error( 'http_error', sprintf( 'پاسخ غیرمنتظره از ترب (کد %d).', $code ), array( 'status' => $code ) );
		}
	}

	/**
	 * زمان انتظار backoff برای تلاش مجدد.
	 *
	 * @param int $retry_index شماره‌ی تلاش مجدد (0-based).
	 * @return float
	 */
	private function backoff_seconds( $retry_index ) {
		$steps = array( 0.5, 1.0, 2.0 );
		return isset( $steps[ $retry_index ] ) ? $steps[ $retry_index ] : 2.0;
	}

	/**
	 * ثبت یک درخواست در لاگ اشکال‌زدایی (فقط در صورت فعال بودن).
	 *
	 * @param string          $context   برچسب بخش.
	 * @param string          $transport curl | wp.
	 * @param string          $url       آدرس.
	 * @param array|WP_Error  $response  پاسخ.
	 * @param float           $duration  زمان پاسخ (ثانیه).
	 * @return void
	 */
	private function log_request( $context, $transport, $url, $response, $duration ) {
		if ( ! Shoper_Debug::enabled() ) {
			return;
		}

		$entry = array(
			'context'   => $context ? $context : 'request',
			'transport' => $transport,
			'method'    => 'GET',
			'url'       => $url,
			'duration'  => $duration,
		);

		if ( is_wp_error( $response ) ) {
			$entry['error_code']    = $response->get_error_code();
			$entry['error_message'] = $response->get_error_message();
			$data                   = $response->get_error_data();
			if ( is_array( $data ) ) {
				if ( isset( $data['errno'] ) ) {
					$entry['curl_errno'] = (int) $data['errno'];
				}
				if ( isset( $data['curl_error'] ) ) {
					$entry['curl_error'] = (string) $data['curl_error'];
				}
				if ( isset( $data['wp_error_code'] ) ) {
					$entry['wp_error_code'] = (string) $data['wp_error_code'];
				}
			}
		} else {
			$entry['status']       = (int) $response['code'];
			$entry['content_type'] = (string) $response['content_type'];
			$entry['body_length']  = strlen( (string) $response['body'] );
			$entry['body_sample']  = substr( (string) $response['body'], 0, 500 );
		}

		Shoper_Debug::log( 'request', $entry );
	}

	/* --------------------------------------------------------------------- */
	/* نرمال‌سازی                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * نرمال‌سازی نتایج جستجو.
	 *
	 * @param array $data داده‌ی خام.
	 * @return array
	 */
	private function normalize_search_results( $data ) {
		$results = array();
		foreach ( (array) $data['results'] as $item ) {
			$results[] = $this->extract_search_item( $item );
		}
		return array(
			'count'      => isset( $data['count'] ) ? (int) $data['count'] : count( $results ),
			'results'    => $results,
			'next'       => isset( $data['next'] ) ? $data['next'] : '',
			'categories' => isset( $data['categories'] ) ? $data['categories'] : array(),
		);
	}

	/**
	 * استخراج فیلدهای یک آیتم جستجو.
	 *
	 * @param array $item آیتم خام.
	 * @return array
	 */
	private function extract_search_item( $item ) {
		$gallery = array();
		if ( ! empty( $item['media_urls'] ) && is_array( $item['media_urls'] ) ) {
			foreach ( $item['media_urls'] as $media ) {
				if ( ! empty( $media['url'] ) && $this->is_supported_image( $media['url'] ) ) {
					$gallery[] = $media['url'];
				}
			}
		}
		if ( empty( $gallery ) && ! empty( $item['image_url'] ) && $this->is_supported_image( $item['image_url'] ) ) {
			$gallery[] = $item['image_url'];
		}

		// استخراج prk و search_id از more_info_url.
		$prk       = ! empty( $item['random_key'] ) ? $item['random_key'] : '';
		$search_id = '';
		$more_url  = isset( $item['more_info_url'] ) ? $item['more_info_url'] : '';
		if ( $more_url ) {
			$parsed = wp_parse_url( $more_url );
			if ( ! empty( $parsed['query'] ) ) {
				parse_str( $parsed['query'], $q );
				if ( ! empty( $q['prk'] ) ) {
					$prk = $q['prk'];
				}
				if ( ! empty( $q['search_id'] ) ) {
					$search_id = $q['search_id'];
				}
			}
		}

		return array(
			'random_key'    => $prk,
			'search_id'     => $search_id,
			'name1'         => isset( $item['name1'] ) ? $item['name1'] : '',
			'name2'         => isset( $item['name2'] ) ? $item['name2'] : '',
			'price'         => isset( $item['price'] ) ? (int) $item['price'] : 0,
			'price_text'    => isset( $item['price_text'] ) ? $item['price_text'] : '',
			'shop_text'     => isset( $item['shop_text'] ) ? $item['shop_text'] : '',
			'image_url'     => isset( $item['image_url'] ) ? $item['image_url'] : '',
			'gallery'       => $gallery,
			'page_url'      => ! empty( $item['web_client_absolute_url'] ) ? 'https://torob.com' . $item['web_client_absolute_url'] : '',
			'more_info_url' => $more_url,
			'is_adv'        => ! empty( $item['is_adv'] ),
		);
	}

	/**
	 * نرمال‌سازی داده‌ی جزئیات محصول.
	 *
	 * @param array $data داده‌ی خام.
	 * @return array
	 */
	private function normalize_details( $data ) {
		$gallery = array();
		if ( ! empty( $data['media_urls'] ) && is_array( $data['media_urls'] ) ) {
			foreach ( $data['media_urls'] as $media ) {
				if ( ! empty( $media['url'] ) && $this->is_supported_image( $media['url'] ) ) {
					$gallery[] = $media['url'];
				}
			}
		}
		if ( empty( $gallery ) && ! empty( $data['image_url'] ) && $this->is_supported_image( $data['image_url'] ) ) {
			$gallery[] = $data['image_url'];
		}

		// مشخصات فنی: structural_specs.headers[*].specs
		$specs = array();
		if ( ! empty( $data['structural_specs']['headers'] ) && is_array( $data['structural_specs']['headers'] ) ) {
			foreach ( $data['structural_specs']['headers'] as $header_group ) {
				if ( ! empty( $header_group['specs'] ) && is_array( $header_group['specs'] ) ) {
					foreach ( $header_group['specs'] as $key => $value ) {
						// نرمال‌سازی مقدار.
						$value = is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : (string) $value;
						$value = trim( $value );
						if ( '' !== $value && '[]' !== $value && 'null' !== $value ) {
							$specs[ (string) $key ] = $value;
						}
					}
				}
			}
		}

		// key_specs (نسخه‌ی گروه‌بندی‌شده) — برای جدول توضیحات.
		$key_specs = array();
		if ( ! empty( $data['key_specs'] ) && is_array( $data['key_specs'] ) ) {
			$key_specs = $data['key_specs'];
		}

		// قیمت: ارزان‌ترین فروشنده.
		$cheapest = 0;
		$sellers  = array();
		if ( ! empty( $data['products_info']['result'] ) && is_array( $data['products_info']['result'] ) ) {
			foreach ( $data['products_info']['result'] as $seller ) {
				$price = isset( $seller['price'] ) ? (int) $seller['price'] : 0;
				if ( $price > 0 && ( 0 === $cheapest || $price < $cheapest ) ) {
					$cheapest = $price;
				}
				$score = 0;
				if ( isset( $seller['score_info']['score'] ) ) {
					$score = (float) $seller['score_info']['score'];
				} elseif ( isset( $seller['shop_score'] ) ) {
					$score = (float) $seller['shop_score'];
				}
				$more = isset( $seller['more_info'] ) && is_array( $seller['more_info'] ) ? $seller['more_info'] : array();
				$sellers[] = array(
					'shop_name'    => isset( $seller['shop_name'] ) ? $seller['shop_name'] : '',
					'city'         => isset( $seller['shop_name2'] ) ? $seller['shop_name2'] : '',
					'price'        => $price,
					'price_text'   => isset( $seller['price_text'] ) ? $seller['price_text'] : '',
					'score'        => $score,
					'score_text'   => isset( $seller['score_info']['score_text'] ) ? $seller['score_info']['score_text'] : '',
					'url'          => isset( $seller['page_url'] ) ? $seller['page_url'] : '',
					'availability' => array_key_exists( 'availability', $seller ) ? (bool) $seller['availability'] : ( $price > 0 ),
					'features'     => isset( $seller['name2'] ) ? $seller['name2'] : '',
					'guarantee'    => isset( $seller['guarantee_info']['status'] ) ? $seller['guarantee_info']['status'] : '',
					'shipping'     => isset( $more['shipping_types'] ) && is_array( $more['shipping_types'] ) ? $more['shipping_types'] : array(),
					'free_shipping'=> isset( $more['free_shipping'] ) ? $more['free_shipping'] : '',
					'same_day'     => isset( $more['same_day_delivery'] ) ? $more['same_day_delivery'] : '',
					'postage_fee'  => isset( $seller['postage_fee'] ) ? $seller['postage_fee'] : '',
					'is_adv'       => ! empty( $seller['is_adv'] ),
				);
			}
		}
		if ( empty( $cheapest ) && ! empty( $data['price'] ) ) {
			$cheapest = (int) $data['price'];
		}

		$prk       = ! empty( $data['random_key'] ) ? $data['random_key'] : '';
		$search_id = '';
		$more_url  = isset( $data['more_info_url'] ) ? $data['more_info_url'] : '';
		if ( $more_url ) {
			$parsed = wp_parse_url( $more_url );
			if ( ! empty( $parsed['query'] ) ) {
				parse_str( $parsed['query'], $q );
				if ( ! empty( $q['search_id'] ) ) {
					$search_id = $q['search_id'];
				}
			}
		}

		return array(
			'random_key'    => $prk,
			'search_id'     => $search_id,
			'name1'         => isset( $data['name1'] ) ? $data['name1'] : '',
			'name2'         => isset( $data['name2'] ) ? $data['name2'] : '',
			'description'   => isset( $data['description'] ) ? $data['description'] : '',
			'price'         => $cheapest,
			'price_text'    => ! empty( $data['price_text'] ) ? $data['price_text'] : '',
			'min_price'     => isset( $data['min_price'] ) ? (int) $data['min_price'] : $cheapest,
			'max_price'     => isset( $data['max_price'] ) ? (int) $data['max_price'] : 0,
			'image_url'     => isset( $data['image_url'] ) ? $data['image_url'] : '',
			'gallery'       => $gallery,
			'specs'         => $specs,
			'key_specs'     => $key_specs,
			'sellers'       => $sellers,
			'sellers_count' => isset( $data['products_info']['result'] ) ? count( $data['products_info']['result'] ) : count( $sellers ),
			'page_url'      => ! empty( $data['web_client_absolute_url'] ) ? 'https://torob.com' . $data['web_client_absolute_url'] : '',
			'variants'      => isset( $data['variants'] ) ? $data['variants'] : array(),
		);
	}

	/* --------------------------------------------------------------------- */
	/* کمکی                                                                    */
	/* --------------------------------------------------------------------- */

	/**
	 * بارگذاری فایل mock.
	 *
	 * @param string $filename نام فایل.
	 * @return array|WP_Error
	 */
	private function load_mock( $filename ) {
		$path = SHOPER_PLUGIN_DIR . 'assets/mock/' . $filename;
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'mock_missing', 'فایل نمونه یافت نشد: ' . $filename );
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'mock_invalid', 'فایل نمونه خراب است.' );
		}
		if ( 'torob-search-sample.json' === $filename ) {
			return $this->normalize_search_results( $data );
		}
		return $this->normalize_details( $data );
	}

	/**
	 * بررسی پشتیبانی فرمت تصویر توسط وردپرس.
	 *
	 * @param string $url آدرس تصویر.
	 * @return bool
	 */
	private function is_supported_image( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return true; // اگر path نبود، قبول می‌کنیم.
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$ok  = array( 'jpg', 'jpeg', 'png', 'webp', 'gif' );
		// AVIF را فقط در صورت پشتیبانی وردپرس قبول می‌کنیم.
		if ( 'avif' === $ext && function_exists( 'imageavif' ) ) {
			return true;
		}
		return in_array( $ext, $ok, true );
	}

	/**
	 * تست اتصال.
	 *
	 * @return array
	 */
	public function test_connection() {
		$result = $this->search( 's25', 0, 1 );
		if ( is_wp_error( $result ) ) {
			return array(
				'ok'      => false,
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'ok'      => true,
			'message' => 'اتصال به API ترب برقرار است.',
			'count'   => isset( $result['count'] ) ? (int) $result['count'] : 0,
		);
	}
}
