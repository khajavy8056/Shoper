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
 * نکات تأییدشده با تست زنده روی api.torob.com:
 *   - پارامتر جستجو «q» است (نه «query»). ارسال «query» باعث می‌شود
 *     ترب عبارت را نادیده بگیرد و نتایج نامرتبط برگرداند.
 *   - اندپوینت details بدون search_id هم کار می‌کند.
 *   - مشخصات فنی در structural_specs.headers[*].specs (نگاشت key=>value).
 *   - فروشندگان آنلاین در products_info.result و حضوری‌ها در
 *     products_in_store_info.result قرار دارند.
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
		$this->user_agent = get_option(
			'shoper_user_agent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
		);
		$this->timeout    = (int) get_option( 'shoper_request_timeout', 25 );
	}

	/* --------------------------------------------------------------------- */
	/* جستجو و پیشنهاد نام                                                    */
	/* --------------------------------------------------------------------- */

	/**
	 * پیشنهاد نام محصول برای نوار کشویی (autocomplete).
	 *
	 * کاربر لازم نیست نام کامل محصول را بداند؛ با نوشتن بخشی از نام،
	 * این متد نام‌های کاملِ پیشنهادی را از ترب برمی‌گرداند.
	 *
	 * برای سبک ماندن، پاسخ ۵ دقیقه کش می‌شود.
	 *
	 * @param string $term  بخشی از نام محصول.
	 * @param int    $limit حداکثر تعداد پیشنهاد.
	 * @return array|WP_Error
	 */
	public function suggest( $term, $limit = 8 ) {
		$term = trim( (string) $term );
		if ( mb_strlen( $term, 'UTF-8' ) < 2 ) {
			return array( 'suggestions' => array() );
		}

		$cache_key = 'shoper_sug_' . md5( $term . '|' . $limit . '|' . $this->source );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$search = $this->search( $term, 0, max( $limit, 8 ) );
		if ( is_wp_error( $search ) ) {
			return $search;
		}

		$suggestions = array();
		$seen        = array();
		foreach ( (array) $search['results'] as $item ) {
			$name = isset( $item['name1'] ) ? trim( $item['name1'] ) : '';
			if ( '' === $name || isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;

			$suggestions[] = array(
				'label'      => $name,
				'name2'      => isset( $item['name2'] ) ? $item['name2'] : '',
				'random_key' => isset( $item['random_key'] ) ? $item['random_key'] : '',
				'search_id'  => isset( $item['search_id'] ) ? $item['search_id'] : '',
				'image_url'  => isset( $item['image_url'] ) ? $item['image_url'] : '',
				'price'      => isset( $item['price'] ) ? (int) $item['price'] : 0,
				'price_text' => isset( $item['price_text'] ) ? $item['price_text'] : '',
				'shop_text'  => isset( $item['shop_text'] ) ? $item['shop_text'] : '',
			);

			if ( count( $suggestions ) >= $limit ) {
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
	 * @param string $query  نام/عبارت جستجو.
	 * @param int    $page   شماره صفحه (0-based).
	 * @param int    $size   تعداد نتایج.
	 * @return array|WP_Error
	 */
	public function search( $query, $page = 0, $size = 10 ) {
		if ( 'mock' === $this->source ) {
			return $this->load_mock( 'torob-search-sample.json' );
		}

		// مهم: پارامتر درست «q» است. «query» توسط ترب نادیده گرفته می‌شود.
		// add_query_arg خودش مقدار را urlencode می‌کند، پس عبارت خام را می‌دهیم.
		$url = add_query_arg(
			array(
				'page'   => (int) $page,
				'size'   => (int) $size,
				'q'      => $query,
				'source' => 'next_desktop',
			),
			self::API_BASE . self::SEARCH_URL
		);

		$data = $this->request( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return new WP_Error( 'no_results', 'نتیجه‌ای برای این عبارت یافت نشد.' );
		}
		return $this->normalize_search_results( $data );
	}

	/* --------------------------------------------------------------------- */
	/* جزئیات محصول                                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * دریافت جزئیات کامل یک محصول.
	 *
	 * تست زنده نشان داد search_id اجباری نیست؛ اگر موجود بود ارسال می‌شود.
	 *
	 * @param string $prk       شناسه‌ی محصول (random_key).
	 * @param string $search_id شناسه‌ی جستجو (اختیاری).
	 * @return array|WP_Error
	 */
	public function details( $prk, $search_id = '' ) {
		if ( 'mock' === $this->source ) {
			return $this->load_mock( 'torob-details-sample.json' );
		}

		$args = array(
			'prk'    => $prk,
			'source' => 'next_desktop',
		);
		if ( ! empty( $search_id ) ) {
			$args['search_id'] = $search_id;
		}

		$url  = add_query_arg( $args, self::API_BASE . self::DETAIL_URL );
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

	/* --------------------------------------------------------------------- */
	/* لایه‌ی HTTP                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * ارسال درخواست HTTP به API.
	 *
	 * @param string $url آدرس کامل.
	 * @return array|WP_Error
	 */
	private function request( $url ) {
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
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'connection_failed',
				'اتصال به ترب برقرار نشد: ' . $response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 429 === $code ) {
			return new WP_Error( 'rate_limited', 'ترب تعداد درخواست‌ها را محدود کرده است. کمی بعد دوباره تلاش کنید.' );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'http_error', "پاسخ غیرمنتظره از ترب (کد $code)." );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_json', 'پاسخ ترب قابل پردازش نیست.' );
		}
		if ( isset( $data['error'] ) ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'خطای ترب.';
			return new WP_Error( 'torob_error', $msg );
		}
		return $data;
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
		$gallery = $this->extract_gallery( $item );

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
		);
	}

	/**
	 * استخراج گالری تصاویر از media_urls / image_urls / image_url.
	 *
	 * @param array $data داده‌ی خام.
	 * @return array
	 */
	private function extract_gallery( $data ) {
		$gallery = array();

		if ( ! empty( $data['media_urls'] ) && is_array( $data['media_urls'] ) ) {
			foreach ( $data['media_urls'] as $media ) {
				$url = is_array( $media ) ? ( isset( $media['url'] ) ? $media['url'] : '' ) : (string) $media;
				if ( $url && $this->is_supported_image( $url ) ) {
					$gallery[] = $url;
				}
			}
		}

		// image_urls یک ساختار جایگزین است: [{source, urls:[...]}].
		if ( ! empty( $data['image_urls'] ) && is_array( $data['image_urls'] ) ) {
			foreach ( $data['image_urls'] as $group ) {
				if ( empty( $group['urls'] ) || ! is_array( $group['urls'] ) ) {
					continue;
				}
				foreach ( $group['urls'] as $url ) {
					if ( $url && $this->is_supported_image( $url ) ) {
						$gallery[] = $url;
					}
				}
			}
		}

		if ( ! empty( $data['image_url'] ) && $this->is_supported_image( $data['image_url'] ) ) {
			array_unshift( $gallery, $data['image_url'] );
		}

		return array_values( array_unique( $gallery ) );
	}

	/**
	 * نرمال‌سازی داده‌ی جزئیات محصول.
	 *
	 * @param array $data داده‌ی خام.
	 * @return array
	 */
	private function normalize_details( $data ) {
		$gallery = $this->extract_gallery( $data );

		// مشخصات فنی: structural_specs.headers[*].specs (نگاشت key=>value).
		$specs        = array();
		$spec_groups  = array();
		if ( ! empty( $data['structural_specs']['headers'] ) && is_array( $data['structural_specs']['headers'] ) ) {
			foreach ( $data['structural_specs']['headers'] as $header_group ) {
				$group_title = isset( $header_group['header'] ) ? (string) $header_group['header'] : 'مشخصات';
				if ( empty( $header_group['specs'] ) || ! is_array( $header_group['specs'] ) ) {
					continue;
				}
				$group_pairs = array();
				foreach ( $header_group['specs'] as $key => $value ) {
					$value = $this->stringify_spec_value( $value );
					$key   = trim( (string) $key );
					if ( '' === $key || '' === $value ) {
						continue;
					}
					$specs[ $key ]       = $value;
					$group_pairs[ $key ] = $value;
				}
				if ( $group_pairs ) {
					$spec_groups[] = array(
						'header' => $group_title,
						'specs'  => $group_pairs,
					);
				}
			}
		}

		// key_specs: [{header, items:[{key, value:[...]}]}].
		$key_specs = array();
		if ( ! empty( $data['key_specs'] ) && is_array( $data['key_specs'] ) ) {
			foreach ( $data['key_specs'] as $group ) {
				if ( empty( $group['items'] ) || ! is_array( $group['items'] ) ) {
					continue;
				}
				foreach ( $group['items'] as $item ) {
					if ( ! isset( $item['key'] ) ) {
						continue;
					}
					$k = trim( (string) $item['key'] );
					$v = $this->stringify_spec_value( isset( $item['value'] ) ? $item['value'] : '' );
					if ( '' !== $k && '' !== $v ) {
						$key_specs[ $k ] = $v;
					}
				}
			}
		}

		// فروشندگان آنلاین.
		$sellers = $this->extract_sellers(
			isset( $data['products_info']['result'] ) ? $data['products_info']['result'] : array(),
			'online'
		);

		// فروشندگان حضوری.
		$store_sellers = $this->extract_sellers(
			isset( $data['products_in_store_info']['result'] ) ? $data['products_in_store_info']['result'] : array(),
			'in_store'
		);

		$cheapest = 0;
		foreach ( $sellers as $s ) {
			if ( $s['price'] > 0 && ( 0 === $cheapest || $s['price'] < $cheapest ) ) {
				$cheapest = $s['price'];
			}
		}
		if ( ! $cheapest && ! empty( $data['price'] ) ) {
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

		// دسته‌بندی از breadcrumbs (آخرین مورد دقیق‌ترین است).
		$categories = array();
		if ( ! empty( $data['breadcrumbs'] ) && is_array( $data['breadcrumbs'] ) ) {
			foreach ( $data['breadcrumbs'] as $crumb ) {
				if ( ! empty( $crumb['title'] ) && ! empty( $crumb['cat_id'] ) ) {
					$categories[] = (string) $crumb['title'];
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
			'spec_groups'   => $spec_groups,
			'key_specs'     => $key_specs,
			'sellers'       => $sellers,
			'store_sellers' => $store_sellers,
			'sellers_count' => count( $sellers ),
			'shop_text'     => isset( $data['shop_text'] ) ? $data['shop_text'] : '',
			'categories'    => $categories,
			'page_url'      => ! empty( $data['web_client_absolute_url'] ) ? 'https://torob.com' . $data['web_client_absolute_url'] : '',
			'variants'      => isset( $data['variants'] ) ? $data['variants'] : array(),
		);
	}

	/**
	 * استخراج و نرمال‌سازی لیست فروشندگان.
	 *
	 * @param array  $raw  آرایه‌ی خام فروشندگان.
	 * @param string $type online | in_store.
	 * @return array
	 */
	private function extract_sellers( $raw, $type = 'online' ) {
		$sellers = array();
		if ( ! is_array( $raw ) ) {
			return $sellers;
		}

		foreach ( $raw as $seller ) {
			if ( ! is_array( $seller ) ) {
				continue;
			}

			$score = 0.0;
			if ( isset( $seller['score_info']['score'] ) ) {
				$score = (float) $seller['score_info']['score'];
			} elseif ( isset( $seller['shop_score'] ) ) {
				$score = (float) $seller['shop_score'];
			}

			$shipping = array();
			if ( ! empty( $seller['more_info']['shipping_types'] ) && is_array( $seller['more_info']['shipping_types'] ) ) {
				$shipping = array_values( array_filter( array_map( 'strval', $seller['more_info']['shipping_types'] ) ) );
			}

			$sellers[] = array(
				'type'          => $type,
				'shop_name'     => isset( $seller['shop_name'] ) ? (string) $seller['shop_name'] : '',
				'city'          => isset( $seller['shop_name2'] ) ? (string) $seller['shop_name2'] : '',
				'shop_id'       => isset( $seller['shop_id'] ) ? (int) $seller['shop_id'] : 0,
				'price'         => isset( $seller['price'] ) ? (int) $seller['price'] : 0,
				'price_text'    => isset( $seller['price_text'] ) ? (string) $seller['price_text'] : '',
				'availability'  => isset( $seller['availability'] ) ? (bool) $seller['availability'] : true,
				'score'         => $score,
				'score_text'    => isset( $seller['score_info']['score_text'] ) ? (string) $seller['score_info']['score_text'] : '',
				// name1 فروشنده معمولاً توصیف دقیق‌تری از کالا دارد (گارانتی، پک، رجیستر).
				'title'         => isset( $seller['name1'] ) ? (string) $seller['name1'] : '',
				'features'      => isset( $seller['name2'] ) ? (string) $seller['name2'] : '',
				'guarantee'     => isset( $seller['guarantee_info']['status'] ) ? (string) $seller['guarantee_info']['status'] : '',
				'delivery_info' => isset( $seller['more_info']['delivery_info'] ) ? (string) $seller['more_info']['delivery_info'] : '',
				'free_shipping' => isset( $seller['more_info']['free_shipping'] ) ? (string) $seller['more_info']['free_shipping'] : '',
				'same_day'      => isset( $seller['more_info']['same_day_delivery'] ) ? (string) $seller['more_info']['same_day_delivery'] : '',
				'postage_fee'   => isset( $seller['postage_fee'] ) ? (string) $seller['postage_fee'] : '',
				'shipping'      => $shipping,
				'is_adv'        => ! empty( $seller['is_adv'] ),
				'last_change'   => isset( $seller['last_price_change_date'] ) ? (string) $seller['last_price_change_date'] : '',
				'url'           => isset( $seller['page_url'] ) ? (string) $seller['page_url'] : '',
			);
		}

		return $sellers;
	}

	/**
	 * تبدیل مقدار یک مشخصه به رشته‌ی تمیز.
	 *
	 * مقادیر ترب گاهی رشته، گاهی آرایه (مثل key_specs) هستند.
	 *
	 * @param mixed $value مقدار.
	 * @return string
	 */
	private function stringify_spec_value( $value ) {
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $v ) {
				if ( is_scalar( $v ) ) {
					$v = trim( (string) $v );
					if ( '' !== $v ) {
						$parts[] = $v;
					}
				}
			}
			$value = implode( '، ', $parts );
		} elseif ( is_bool( $value ) ) {
			$value = $value ? 'دارد' : 'ندارد';
		} elseif ( is_scalar( $value ) ) {
			$value = (string) $value;
		} else {
			return '';
		}

		$value = trim( $value );
		if ( in_array( $value, array( '', '[]', 'null', 'نامشخص', '-' ), true ) ) {
			return '';
		}
		return $value;
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
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore
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
			return true;
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$ok  = array( 'jpg', 'jpeg', 'png', 'webp', 'gif' );
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
		$result = $this->search( 'گوشی سامسونگ', 0, 3 );
		if ( is_wp_error( $result ) ) {
			return array(
				'ok'      => false,
				'message' => $result->get_error_message(),
			);
		}

		$sample = ! empty( $result['results'][0]['name1'] ) ? $result['results'][0]['name1'] : '';

		return array(
			'ok'      => true,
			'message' => 'اتصال به API ترب برقرار است.',
			'count'   => $result['count'],
			'sample'  => $sample,
		);
	}
}
