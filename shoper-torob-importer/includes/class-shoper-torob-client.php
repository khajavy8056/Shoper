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
 */
class Shoper_Torob_Client {

	const API_BASE   = 'https://api.torob.com';
	const SEARCH_URL = '/v4/base-product/search/';
	const DETAIL_URL = '/v4/base-product/details/';

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
	 * Timeout.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * سازنده.
	 */
	public function __construct() {
		$this->source     = get_option( 'shoper_data_source', 'direct' );
		$this->user_agent = get_option( 'shoper_user_agent', 'Mozilla/5.0' );
		$this->timeout    = (int) get_option( 'shoper_request_timeout', 25 );
	}

	/**
	 * جستجوی محصول با نام.
	 *
	 * @param string $query  نام/عبارت جستجو.
	 * @param int    $page   شماره صفحه (0-based).
	 * @param int    $size   تعداد نتایج.
	 * @return array|WP_Error
	 */
	public function search( $query, $page = 0, $size = 10 ) {
		if ( 'mock' === $this->source ) {
			return $this->load_mock( 'torob-search-sample.json' );
		}

		$url = add_query_arg(
			array(
				'page'   => (int) $page,
				'size'   => (int) $size,
				'sort'   => 'popularity',
				'query'  => $query,
				'q'      => $query,
				'source' => 'next_desktop',
			),
			self::API_BASE . self::SEARCH_URL
		);

		$cache_key = 'shoper_search_' . md5( $query . '|' . (int) $page . '|' . (int) $size );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) { return $cached; }

		$data = $this->request( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return new WP_Error( 'no_results', 'نتیجه‌ای برای این عبارت یافت نشد.' );
		}
		$normalized = $this->normalize_search_results( $data );
		set_transient( $cache_key, $normalized, 5 * MINUTE_IN_SECONDS );
		return $normalized;
	}

	/**
	 * دریافت جزئیات کامل یک محصول.
	 *
	 * @param string $prk       شناسه‌ی محصول (random_key).
	 * @param string $search_id شناسه‌ی جستجو.
	 * @return array|WP_Error
	 */
	public function details( $prk, $search_id = '' ) {
		if ( 'mock' === $this->source ) {
			return $this->load_mock( 'torob-details-sample.json' );
		}

		$url = add_query_arg(
			array(
				'prk'       => $prk,
				'source'    => 'next_desktop',
			),
			self::API_BASE . self::DETAIL_URL
		);

		$data = $this->request( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return $this->normalize_details( $data );
	}

	/**
	 * دریافت جزئیات با استفاده از لینک صفحه‌ی محصول.
	 *
	 * @param string $page_url لینک محصول در torob.com.
	 * @return array|WP_Error
	 */
	public function details_from_url( $page_url ) {
		// استخراج random_key (prk) از مسیر: /p/<uuid>/...
		if ( preg_match( '#/p/([0-9a-f\-]{36})#i', $page_url, $m ) ) {
			$prk = $m[1];
			return $this->details( $prk );
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
	 * ارسال درخواست HTTP به API.
	 *
	 * @param string $url آدرس کامل.
	 * @return array|WP_Error
	 */
	private function request( $url ) {
		$agents = array(
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36',
			'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/123.0.0.0 Safari/537.36',
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.3 Safari/605.1.15',
		);
		$headers = array(
			'Accept: application/json, text/plain, */*', 'Accept-Language: fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7',
			'Cache-Control: no-cache', 'Pragma: no-cache', 'Referer: https://torob.com/', 'Origin: https://torob.com',
			'Sec-Fetch-Dest: empty', 'Sec-Fetch-Mode: cors', 'Sec-Fetch-Site: same-site',
			'Sec-Ch-Ua: "Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
			'Sec-Ch-Ua-Mobile: ?0', 'Sec-Ch-Ua-Platform: "Windows"',
			'Accept-Encoding: gzip, deflate, br',
		);
		$attempts = 3;
		for ( $attempt = 0; $attempt < $attempts; $attempt++ ) {
			$ch = curl_init( $url );
			curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
				CURLOPT_TIMEOUT => $this->timeout, CURLOPT_CONNECTTIMEOUT => min( 10, $this->timeout ), CURLOPT_HTTPHEADER => $headers,
				CURLOPT_USERAGENT => $agents[ $attempt % count( $agents ) ], CURLOPT_ENCODING => '', CURLOPT_SSL_VERIFYPEER => true,
			) );
			$proxy = trim( (string) get_option( 'shoper_proxy_url', '' ) );
			if ( $proxy ) { curl_setopt( $ch, CURLOPT_PROXY, $proxy ); }
			$body = curl_exec( $ch ); $error = curl_error( $ch ); $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE ); curl_close( $ch );
			if ( false === $body ) { if ( $attempt < 2 ) { sleep( 1 + $attempt ); continue; } return new WP_Error( 'connection_failed', 'اتصال به ترب برقرار نشد: ' . $error ); }
			if ( in_array( $code, array( 429, 502, 503 ), true ) && $attempt < 2 ) { sleep( 1 + $attempt ); continue; }
			if ( 403 === $code || 490 === $code ) return new WP_Error( 'torob_blocked', "ترب درخواست را مسدود کرد (کد $code). پروکسی خروجی معتبر تنظیم کنید یا هاست را تغییر دهید." );
			if ( 200 !== $code ) return new WP_Error( 'http_error', "پاسخ غیرمنتظره از ترب (کد $code)." );
			$data = json_decode( $body, true ); return is_array( $data ) ? $data : new WP_Error( 'invalid_json', 'پاسخ ترب قابل پردازش نیست.' );
		}
		return new WP_Error( 'http_error', 'تلاش برای اتصال به ترب ناموفق بود.' );
	}
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
		$prk        = ! empty( $item['random_key'] ) ? $item['random_key'] : '';
		$search_id  = '';
		$more_url   = isset( $item['more_info_url'] ) ? $item['more_info_url'] : '';
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
			'random_key' => $prk,
			'search_id'  => $search_id,
			'name1'      => isset( $item['name1'] ) ? $item['name1'] : '',
			'name2'      => isset( $item['name2'] ) ? $item['name2'] : '',
			'price'      => isset( $item['price'] ) ? (int) $item['price'] : 0,
			'price_text' => isset( $item['price_text'] ) ? $item['price_text'] : '',
			'shop_text'  => isset( $item['shop_text'] ) ? $item['shop_text'] : '',
			'image_url'  => isset( $item['image_url'] ) ? $item['image_url'] : '',
			'gallery'    => $gallery,
			'page_url'   => ! empty( $item['web_client_absolute_url'] ) ? 'https://torob.com' . $item['web_client_absolute_url'] : '',
			'more_info_url' => $more_url,
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
				$sellers[] = array(
					'shop_name'  => isset( $seller['shop_name'] ) ? $seller['shop_name'] : '',
					'city'       => isset( $seller['shop_name2'] ) ? $seller['shop_name2'] : '',
					'price'      => $price,
					'price_text' => isset( $seller['price_text'] ) ? $seller['price_text'] : '',
					'score'      => isset( $seller['shop_score'] ) ? $seller['shop_score'] : '',
					'url'        => isset( $seller['page_url'] ) ? $seller['page_url'] : '',
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
			'random_key'   => $prk,
			'search_id'    => $search_id,
			'name1'        => isset( $data['name1'] ) ? $data['name1'] : '',
			'name2'        => isset( $data['name2'] ) ? $data['name2'] : '',
			'description'  => isset( $data['description'] ) ? $data['description'] : '',
			'price'        => $cheapest,
			'price_text'   => ! empty( $data['price_text'] ) ? $data['price_text'] : '',
			'min_price'    => isset( $data['min_price'] ) ? (int) $data['min_price'] : $cheapest,
			'max_price'    => isset( $data['max_price'] ) ? (int) $data['max_price'] : 0,
			'image_url'    => isset( $data['image_url'] ) ? $data['image_url'] : '',
			'gallery'      => $gallery,
			'specs'        => $specs,
			'key_specs'    => $key_specs,
			'sellers'      => $sellers,
			'sellers_count'=> isset( $data['products_info']['result'] ) ? count( $data['products_info']['result'] ) : count( $sellers ),
			'page_url'     => ! empty( $data['web_client_absolute_url'] ) ? 'https://torob.com' . $data['web_client_absolute_url'] : '',
			'variants'     => isset( $data['variants'] ) ? $data['variants'] : array(),
		);
	}

	/**
	 * تلاش برای پیدا کردن search_id با یک جستجوی کوتاه.
	 *
	 * @param string $prk شناسه‌ی محصول.
	 * @return string|WP_Error
	 */
	private function resolve_search_id( $prk ) {
		// ابتدا از کش.
		$cached = get_transient( 'shoper_sid_' . md5( $prk ) );
		if ( $cached ) {
			return $cached;
		}

		// یک جستجوی کلی برای گرفتن search_id معتبر.
		$search = $this->search( 'محصول', 0, 1 );
		if ( is_wp_error( $search ) ) {
			return $search;
		}
		if ( ! empty( $search['results'][0]['search_id'] ) ) {
			$sid = $search['results'][0]['search_id'];
			set_transient( 'shoper_sid_' . md5( $prk ), $sid, HOUR_IN_SECONDS );
			return $sid;
		}
		return new WP_Error( 'no_search_id', 'شناسه‌ی جستجو یافت نشد. ابتدا یک جستجو انجام دهید.' );
	}

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
		$data = json_decode( (string) file_get_contents( $path ), true );
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
	 * وردپرس AVIF را فقط از نسخه 6.5 پشتیبانی می‌کند؛
	 * برای احتیاط فقط jpg/png/webp/gif را قبول می‌کنیم.
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
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'ok'      => true,
			'message' => 'اتصال به API ترب برقرار است.',
			'count'   => $result['count'],
		);
	}
}
