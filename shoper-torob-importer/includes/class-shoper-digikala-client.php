<?php
/**
 * سرویس‌گیرنده‌ی API دیجی‌کالا.
 *
 * منبع جایگزین وقتی ترب مسدود است. خروجی به همان قالب نرمال Shoper
 * نگاشت می‌شود تا ساخت ویژگی/تصویر/توضیح بدون تغییر بماند.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_Digikala_Client.
 */
class Shoper_Digikala_Client {

	const API_BASE    = 'https://api.digikala.com';
	const SEARCH_PATH = '/v1/search/';
	const DETAIL_PATH = '/v2/product/';

	/**
	 * منبع: direct | mock.
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
		$this->source     = get_option( 'shoper_data_source', 'auto' );
		$this->user_agent = get_option(
			'shoper_user_agent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
		);
		$this->timeout    = (int) get_option( 'shoper_request_timeout', 25 );
	}

	/**
	 * آیا این شناسه متعلق به دیجی‌کالاست؟
	 *
	 * @param string $prk شناسه.
	 * @return bool
	 */
	public static function is_dkp( $prk ) {
		$prk = (string) $prk;
		return (bool) preg_match( '/^(DKP-)?\d{4,}$/i', $prk );
	}

	/**
	 * استخراج شناسه عددی از لینک یا DKP-123.
	 *
	 * @param string $value لینک یا شناسه.
	 * @return int
	 */
	public static function extract_id( $value ) {
		$value = (string) $value;
		if ( preg_match( '/dkp-(\d+)/i', $value, $m ) ) {
			return (int) $m[1];
		}
		if ( preg_match( '/^(\d{4,})$/', $value, $m ) ) {
			return (int) $m[1];
		}
		if ( preg_match( '/^DKP-(\d+)$/i', $value, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * آدرس جستجو.
	 *
	 * @param string $query عبارت.
	 * @param int    $page  صفحه از ۱.
	 * @return string
	 */
	public static function build_search_url( $query, $page = 1 ) {
		return add_query_arg(
			array(
				'q'    => $query,
				'page' => max( 1, (int) $page ),
			),
			self::API_BASE . self::SEARCH_PATH
		);
	}

	/**
	 * آدرس جزئیات.
	 *
	 * @param int $id شناسه.
	 * @return string
	 */
	public static function build_details_url( $id ) {
		return self::API_BASE . self::DETAIL_PATH . absint( $id ) . '/';
	}

	/**
	 * جستجو.
	 *
	 * @param string $query عبارت.
	 * @param int    $page  صفحه.
	 * @param int    $size  حداکثر نتیجه.
	 * @return array|WP_Error
	 */
	public function search( $query, $page = 1, $size = 10 ) {
		if ( 'mock' === $this->source ) {
			return $this->load_mock( 'digikala-search-sample.json' );
		}

		$url  = self::build_search_url( $query, $page );
		$data = $this->request( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return $this->normalize_search( $data, $size );
	}

	/**
	 * پیشنهاد از روی جستجو.
	 *
	 * @param string $term  عبارت.
	 * @param int    $limit سقف.
	 * @return array|WP_Error
	 */
	public function suggest( $term, $limit = 8 ) {
		$search = $this->search( $term, 1, max( 8, (int) $limit ) );
		if ( is_wp_error( $search ) ) {
			return $search;
		}
		$out  = array();
		$seen = array();
		foreach ( (array) $search['results'] as $item ) {
			$name = isset( $item['name1'] ) ? trim( (string) $item['name1'] ) : '';
			if ( '' === $name || isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;
			$out[]         = array(
				'label'         => $name,
				'name2'         => isset( $item['name2'] ) ? $item['name2'] : '',
				'random_key'    => $item['random_key'],
				'search_id'     => '',
				'image_url'     => isset( $item['image_url'] ) ? $item['image_url'] : '',
				'price'         => isset( $item['price'] ) ? (int) $item['price'] : 0,
				'price_text'    => isset( $item['price_text'] ) ? $item['price_text'] : '',
				'shop_text'     => isset( $item['shop_text'] ) ? $item['shop_text'] : 'دیجی‌کالا',
				'more_info_url' => '',
				'gallery'       => isset( $item['gallery'] ) ? $item['gallery'] : array(),
				'page_url'      => isset( $item['page_url'] ) ? $item['page_url'] : '',
				'provider'      => 'digikala',
			);
			if ( count( $out ) >= (int) $limit ) {
				break;
			}
		}
		return array(
			'term'        => $term,
			'suggestions' => $out,
			'provider'    => 'digikala',
		);
	}

	/**
	 * جزئیات محصول.
	 *
	 * @param int|string $id شناسه یا DKP-…
	 * @return array|WP_Error
	 */
	public function details( $id ) {
		if ( 'mock' === $this->source ) {
			return $this->load_mock( 'digikala-details-sample.json' );
		}
		$num = self::extract_id( $id );
		if ( $num < 1 ) {
			return new WP_Error( 'invalid_prk', 'شناسه محصول دیجی‌کالا نامعتبر است.' );
		}
		$data = $this->request( self::build_details_url( $num ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return $this->normalize_details( $data );
	}

	/**
	 * نرمال‌سازی پاسخ خام جستجو (برای ingest مرورگر).
	 *
	 * @param mixed $raw داده.
	 * @param int   $size سقف.
	 * @return array|WP_Error
	 */
	public function ingest_search( $raw, $size = 10 ) {
		return $this->normalize_search( $raw, $size );
	}

	/**
	 * نرمال‌سازی پاسخ خام جزئیات.
	 *
	 * @param mixed $raw داده.
	 * @return array|WP_Error
	 */
	public function ingest_details( $raw ) {
		return $this->normalize_details( $raw );
	}

	/**
	 * استخراج لیست محصول از پاسخ جستجو.
	 *
	 * @param mixed $data پاسخ.
	 * @return array
	 */
	public static function extract_product_list( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( isset( $data['data']['products'] ) && is_array( $data['data']['products'] ) ) {
			$products = $data['data']['products'];
			if ( isset( $products['products'] ) && is_array( $products['products'] ) ) {
				return $products['products'];
			}
			// اگر آرایه عددی از محصولات باشد.
			if ( isset( $products[0] ) && is_array( $products[0] ) && isset( $products[0]['id'] ) ) {
				return $products;
			}
		}
		if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
			return $data['products'];
		}
		$out = array();
		if ( isset( $data['data']['widgets'] ) && is_array( $data['data']['widgets'] ) ) {
			foreach ( $data['data']['widgets'] as $widget ) {
				if ( empty( $widget['type'] ) ) {
					continue;
				}
				if ( 'vertical_product_listing' === $widget['type'] && ! empty( $widget['data']['widgets'] ) ) {
					foreach ( $widget['data']['widgets'] as $inner ) {
						if ( isset( $inner['type'], $inner['data'] ) && 'product' === $inner['type'] ) {
							$out[] = $inner['data'];
						}
					}
				}
				if ( isset( $widget['data']['products'] ) && is_array( $widget['data']['products'] ) ) {
					foreach ( $widget['data']['products'] as $p ) {
						$out[] = $p;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * نرمال‌سازی جستجو.
	 *
	 * @param mixed $data پاسخ.
	 * @param int   $size سقف.
	 * @return array|WP_Error
	 */
	public function normalize_search( $data, $size = 10 ) {
		$raw = self::extract_product_list( $data );
		if ( empty( $raw ) ) {
			return new WP_Error( 'no_results', 'نتیجه‌ای در دیجی‌کالا یافت نشد.' );
		}
		$results = array();
		foreach ( $raw as $item ) {
			$norm = $this->extract_search_item( $item );
			if ( empty( $norm['random_key'] ) || empty( $norm['name1'] ) ) {
				continue;
			}
			$results[] = $norm;
			if ( count( $results ) >= (int) $size ) {
				break;
			}
		}
		if ( empty( $results ) ) {
			return new WP_Error( 'no_results', 'نتیجه‌ای در دیجی‌کالا یافت نشد.' );
		}
		return array(
			'count'    => count( $results ),
			'results'  => $results,
			'next'     => '',
			'provider' => 'digikala',
		);
	}

	/**
	 * آیتم جستجو.
	 *
	 * @param array $item خام.
	 * @return array
	 */
	private function extract_search_item( $item ) {
		if ( ! is_array( $item ) ) {
			return array();
		}
		$id    = isset( $item['id'] ) ? (int) $item['id'] : 0;
		$price = $this->extract_price_toman( $item );
		$img   = $this->first_image( isset( $item['images']['main'] ) ? $item['images']['main'] : array() );
		$uri   = '';
		if ( ! empty( $item['url']['uri'] ) ) {
			$uri = (string) $item['url']['uri'];
		}
		return array(
			'random_key'    => $id ? ( 'DKP-' . $id ) : '',
			'search_id'     => '',
			'name1'         => isset( $item['title_fa'] ) ? (string) $item['title_fa'] : '',
			'name2'         => isset( $item['title_en'] ) ? (string) $item['title_en'] : '',
			'price'         => $price,
			'price_text'    => $price ? ( number_format_i18n( $price ) . ' تومان' ) : '',
			'shop_text'     => 'دیجی‌کالا',
			'image_url'     => $img,
			'gallery'       => $img ? array( $img ) : array(),
			'page_url'      => $uri ? ( 'https://www.digikala.com' . $uri ) : '',
			'more_info_url' => '',
			'is_adv'        => false,
			'provider'      => 'digikala',
		);
	}

	/**
	 * نرمال‌سازی جزئیات.
	 *
	 * @param mixed $data پاسخ.
	 * @return array|WP_Error
	 */
	public function normalize_details( $data ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_response', 'پاسخ دیجی‌کالا قابل پردازش نیست.' );
		}
		$product = array();
		if ( isset( $data['data']['product'] ) && is_array( $data['data']['product'] ) ) {
			$product = $data['data']['product'];
		} elseif ( isset( $data['product'] ) && is_array( $data['product'] ) ) {
			$product = $data['product'];
		} elseif ( isset( $data['id'], $data['title_fa'] ) ) {
			$product = $data;
		}
		if ( empty( $product['id'] ) && empty( $product['title_fa'] ) ) {
			return new WP_Error( 'invalid_response', 'ساختار جزئیات دیجی‌کالا قابل قبول نیست.' );
		}

		$id      = isset( $product['id'] ) ? (int) $product['id'] : 0;
		$gallery = $this->extract_gallery( $product );
		$specs   = array();
		$groups  = array();
		$keys    = array();

		if ( ! empty( $product['specifications'] ) && is_array( $product['specifications'] ) ) {
			foreach ( $product['specifications'] as $group ) {
				$header = isset( $group['title'] ) ? trim( (string) $group['title'] ) : 'مشخصات';
				$pairs  = array();
				if ( empty( $group['attributes'] ) || ! is_array( $group['attributes'] ) ) {
					continue;
				}
				foreach ( $group['attributes'] as $attr ) {
					$title = isset( $attr['title'] ) ? trim( (string) $attr['title'] ) : '';
					$vals  = isset( $attr['values'] ) ? $attr['values'] : array();
					if ( ! is_array( $vals ) ) {
						$vals = array( $vals );
					}
					$clean = array();
					foreach ( $vals as $v ) {
						$v = trim( html_entity_decode( wp_strip_all_tags( (string) $v ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
						$v = trim( preg_replace( '/\s+/u', ' ', $v ) );
						if ( '' !== $v ) {
							$clean[] = $v;
						}
					}
					if ( '' === $title || empty( $clean ) ) {
						continue;
					}
					$value           = implode( '، ', $clean );
					$specs[ $title ] = $value;
					$pairs[ $title ] = $value;
					if ( count( $keys ) < 8 ) {
						$keys[ $title ] = $value;
					}
				}
				if ( $pairs ) {
					$groups[] = array(
						'header' => $header,
						'specs'  => $pairs,
					);
				}
			}
		}

		if ( empty( $specs['برند'] ) ) {
			$brand = '';
			if ( ! empty( $product['brand']['title_fa'] ) ) {
				$brand = (string) $product['brand']['title_fa'];
			} elseif ( ! empty( $product['data_layer']['brand'] ) ) {
				$brand = (string) $product['data_layer']['brand'];
			}
			if ( $brand ) {
				$specs['برند'] = $brand;
				$keys['برند']  = $brand;
			}
		}

		$desc = '';
		if ( ! empty( $product['review']['description'] ) ) {
			$desc = (string) $product['review']['description'];
		} elseif ( ! empty( $product['expert_reviews']['description'] ) ) {
			$desc = (string) $product['expert_reviews']['description'];
		}
		$desc = html_entity_decode( $desc, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$desc = preg_replace( '/<\s*br\s*\/?>/iu', "\n", $desc );
		$desc = preg_replace( '/<\/p>/iu', "\n\n", $desc );
		$desc = trim( wp_strip_all_tags( $desc ) );

		$price = $this->extract_price_toman( $product );
		$uri   = ! empty( $product['url']['uri'] ) ? (string) $product['url']['uri'] : ( $id ? ( '/product/dkp-' . $id . '/' ) : '' );

		return array(
			'random_key'    => $id ? ( 'DKP-' . $id ) : '',
			'search_id'     => '',
			'name1'         => isset( $product['title_fa'] ) ? (string) $product['title_fa'] : '',
			'name2'         => isset( $product['title_en'] ) ? (string) $product['title_en'] : '',
			'description'   => $desc,
			'price'         => $price,
			'price_text'    => $price ? ( number_format_i18n( $price ) . ' تومان' ) : '',
			'min_price'     => $price,
			'max_price'     => 0,
			'image_url'     => ! empty( $gallery[0] ) ? $gallery[0] : '',
			'gallery'       => $gallery,
			'specs'         => $specs,
			'key_specs'     => $keys,
			'spec_groups'   => $groups,
			'sellers'       => array(),
			'sellers_count' => 0,
			'page_url'      => $uri ? ( 'https://www.digikala.com' . $uri ) : '',
			'variants'      => array(),
			'provider'      => 'digikala',
		);
	}

	/**
	 * گالری تصاویر.
	 *
	 * @param array $product محصول.
	 * @return array
	 */
	private function extract_gallery( $product ) {
		$out = array();
		if ( ! empty( $product['images']['main'] ) ) {
			$u = $this->first_image( $product['images']['main'] );
			if ( $u ) {
				$out[] = $u;
			}
		}
		if ( ! empty( $product['images']['list'] ) && is_array( $product['images']['list'] ) ) {
			foreach ( $product['images']['list'] as $img ) {
				$u = $this->first_image( $img );
				if ( $u && ! in_array( $u, $out, true ) ) {
					$out[] = $u;
				}
			}
		}
		return $out;
	}

	/**
	 * اولین URL تصویر از ساختار دیجی‌کالا.
	 *
	 * @param mixed $node گره تصویر.
	 * @return string
	 */
	private function first_image( $node ) {
		if ( is_string( $node ) && 0 === strpos( $node, 'http' ) ) {
			return $node;
		}
		if ( ! is_array( $node ) ) {
			return '';
		}
		foreach ( array( 'url', 'webp_url' ) as $key ) {
			if ( empty( $node[ $key ] ) ) {
				continue;
			}
			if ( is_array( $node[ $key ] ) ) {
				foreach ( $node[ $key ] as $u ) {
					if ( is_string( $u ) && 0 === strpos( $u, 'http' ) ) {
						return $u;
					}
				}
			} elseif ( is_string( $node[ $key ] ) && 0 === strpos( $node[ $key ], 'http' ) ) {
				return $node[ $key ];
			}
		}
		return '';
	}

	/**
	 * قیمت به تومان.
	 *
	 * @param array $item آیتم.
	 * @return int
	 */
	private function extract_price_toman( $item ) {
		$rial = 0;
		if ( isset( $item['default_variant']['price']['selling_price'] ) ) {
			$rial = (int) $item['default_variant']['price']['selling_price'];
		} elseif ( isset( $item['price']['selling_price'] ) ) {
			$rial = (int) $item['price']['selling_price'];
		} elseif ( isset( $item['selling_price'] ) ) {
			$rial = (int) $item['selling_price'];
		}
		if ( $rial <= 0 ) {
			return 0;
		}
		// قیمت دیجی‌کالا ریال است؛ فروشگاه‌های ایرانی معمولاً تومان می‌خواهند.
		if ( $rial >= 1000 && 0 === ( $rial % 10 ) ) {
			return (int) ( $rial / 10 );
		}
		return $rial;
	}

	/**
	 * درخواست HTTP ساده.
	 *
	 * @param string $url آدرس.
	 * @return array|WP_Error
	 */
	private function request( $url ) {
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_MAXREDIRS      => 3,
					CURLOPT_TIMEOUT        => $this->timeout,
					CURLOPT_CONNECTTIMEOUT => 10,
					CURLOPT_USERAGENT      => $this->user_agent,
					CURLOPT_ENCODING       => 'gzip, deflate',
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_HTTPHEADER     => array(
						'Accept: application/json, text/plain, */*',
						'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
						'Referer: https://www.digikala.com/',
						'Origin: https://www.digikala.com',
					),
				)
			);
			$body  = curl_exec( $ch );
			$errno = (int) curl_errno( $ch );
			$error = curl_error( $ch );
			$code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			curl_close( $ch );
			if ( false === $body || 0 !== $errno ) {
				return new WP_Error( 'curl_failed', sprintf( 'cURL #%d: %s', $errno, $error ) );
			}
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'http_error', sprintf( 'پاسخ غیرمنتظره از دیجی‌کالا (کد %d).', $code ), array( 'status' => $code ) );
			}
			$json = json_decode( (string) $body, true );
			if ( ! is_array( $json ) ) {
				return new WP_Error( 'invalid_json', 'پاسخ دیجی‌کالا JSON نیست.' );
			}
			return $json;
		}

		if ( ! function_exists( 'wp_remote_get' ) ) {
			return new WP_Error( 'connection_failed', 'هیچ روش HTTP در دسترس نیست.' );
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
					'Referer'         => 'https://www.digikala.com/',
					'Origin'          => 'https://www.digikala.com',
				),
				'sslverify'   => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'connection_failed', 'اتصال به دیجی‌کالا برقرار نشد: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'http_error', sprintf( 'پاسخ غیرمنتظره از دیجی‌کالا (کد %d).', $code ), array( 'status' => $code ) );
		}
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'invalid_json', 'پاسخ دیجی‌کالا JSON نیست.' );
		}
		return $json;
	}

	/**
	 * بارگذاری mock.
	 *
	 * @param string $filename فایل.
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
		if ( false !== strpos( $filename, 'search' ) ) {
			return $this->normalize_search( $data );
		}
		return $this->normalize_details( $data );
	}

	/**
	 * تست اتصال.
	 *
	 * @return array
	 */
	public function test_connection() {
		$result = $this->search( 's25', 1, 1 );
		if ( is_wp_error( $result ) ) {
			return array(
				'ok'      => false,
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'ok'      => true,
			'message' => 'اتصال به دیجی‌کالا برقرار است.',
			'count'   => isset( $result['count'] ) ? (int) $result['count'] : 0,
		);
	}
}
