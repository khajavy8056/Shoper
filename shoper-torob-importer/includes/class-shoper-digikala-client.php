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

	const API_BASE = 'https://api.digikala.com';

	/*
	 * مهاجرت API دیجی‌کالا (2026-11-13 / آبان ۱۴۰۵):
	 *   - GET /v1/search/ و /v1/autocomplete/ حذف شدند و برای همه
	 *     {"status":404} برمی‌گردانند (حتی با IP ایران و هدر درست).
	 *   - GET /v2/product/{id}/ نیز دیگر پاسخ نمی‌دهد.
	 *   - جستجوی متنی زنده: /discovery/api/v1/search (لیست تخت data.products)
	 *     و /discovery/api/v2/search (لیست داخل ویجت vertical_product_listing).
	 *   - جزئیات زنده: /product/v1/products/{id}/ (همان شکل data.product قبلی:
	 *     specifications ،review ،images ،default_variant/variants).
	 * مسیرهای قدیمی فقط به‌عنوان آخرین fallback نگه داشته شده‌اند تا اگر
	 * دیجی‌کالا آن‌ها را برگرداند، افزونه بدون آپدیت دوباره کار کند.
	 */
	const SEARCH_PATH        = '/discovery/api/v1/search';
	const SEARCH_PATH_V2     = '/discovery/api/v2/search';
	const SEARCH_PATH_LEGACY = '/v1/search/';
	const DETAIL_PATH        = '/product/v1/products/';
	const DETAIL_PATH_LEGACY = '/v2/product/';

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
	 * آدرس جستجو (مسیر اصلی زنده).
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
	 * همه‌ی آدرس‌های کاندید جستجو، به ترتیب اولویت.
	 *
	 * مسیری که آخرین بار جواب داده (transient) اول می‌آید تا بعد از هر
	 * مهاجرت بعدیِ دیجی‌کالا، افزونه خودش مسیر زنده را پیدا و حفظ کند.
	 *
	 * @param string $query عبارت.
	 * @param int    $page  صفحه از ۱.
	 * @return string[]
	 */
	public static function build_search_urls( $query, $page = 1 ) {
		$paths = array( self::SEARCH_PATH, self::SEARCH_PATH_V2, self::SEARCH_PATH_LEGACY );
		$good  = function_exists( 'get_transient' ) ? get_transient( 'shoper_dk_search_path' ) : '';
		if ( $good && in_array( $good, $paths, true ) ) {
			$paths = array_merge( array( $good ), array_diff( $paths, array( $good ) ) );
		}
		$urls = array();
		foreach ( $paths as $path ) {
			$urls[ $path ] = add_query_arg(
				array(
					'q'    => $query,
					'page' => max( 1, (int) $page ),
				),
				self::API_BASE . $path
			);
		}
		return $urls;
	}

	/**
	 * آدرس جزئیات (مسیر اصلی زنده).
	 *
	 * @param int $id شناسه.
	 * @return string
	 */
	public static function build_details_url( $id ) {
		return self::API_BASE . self::DETAIL_PATH . absint( $id ) . '/';
	}

	/**
	 * همه‌ی آدرس‌های کاندید جزئیات، به ترتیب اولویت.
	 *
	 * @param int $id شناسه.
	 * @return string[]
	 */
	public static function build_details_urls( $id ) {
		$paths = array( self::DETAIL_PATH, self::DETAIL_PATH_LEGACY );
		$good  = function_exists( 'get_transient' ) ? get_transient( 'shoper_dk_detail_path' ) : '';
		if ( $good && in_array( $good, $paths, true ) ) {
			$paths = array_merge( array( $good ), array_diff( $paths, array( $good ) ) );
		}
		$urls = array();
		foreach ( $paths as $path ) {
			$urls[ $path ] = self::API_BASE . $path . absint( $id ) . '/';
		}
		return $urls;
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

		// هر مسیر کاندید را امتحان می‌کنیم؛ 404 یک مسیر (حذف‌شدن endpoint)
		// نباید کل جستجو را بکشد. اولین پاسخِ دارای محصول برنده است.
		$last_error = null;
		foreach ( self::build_search_urls( $query, $page ) as $path => $url ) {
			$data = $this->request( $url );
			if ( is_wp_error( $data ) ) {
				$last_error = $data;
				continue;
			}
			$normalized = $this->normalize_search( $data, $size );
			if ( is_wp_error( $normalized ) ) {
				$last_error = $normalized;
				continue;
			}
			if ( function_exists( 'set_transient' ) ) {
				set_transient( 'shoper_dk_search_path', $path, 6 * HOUR_IN_SECONDS );
			}
			return $normalized;
		}
		return $last_error ? $last_error : new WP_Error( 'no_results', 'نتیجه‌ای در دیجی‌کالا یافت نشد.' );
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
		$last_error = null;
		foreach ( self::build_details_urls( $num ) as $path => $url ) {
			$data = $this->request( $url );
			if ( is_wp_error( $data ) ) {
				$last_error = $data;
				continue;
			}
			$normalized = $this->normalize_details( $data );
			if ( is_wp_error( $normalized ) ) {
				$last_error = $normalized;
				continue;
			}
			if ( function_exists( 'set_transient' ) ) {
				set_transient( 'shoper_dk_detail_path', $path, 6 * HOUR_IN_SECONDS );
			}
			return $this->enrich_sibling_variants( $normalized );
		}
		return $last_error ? $last_error : new WP_Error( 'invalid_response', 'جزئیات دیجی‌کالا دریافت نشد.' );
	}

	/* --------------------------------------------------------------------- */
	/* کشف هوشمند متغیر از محصولات هم‌نام                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * تبدیل ارقام فارسی/عربی به لاتین برای پارس مطمئن عنوان‌ها.
	 *
	 * @param string $s رشته.
	 * @return string
	 */
	private static function latin_digits( $s ) {
		return strtr( (string) $s, array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		) );
	}

	/**
	 * استخراج بُعدهای متغیر از عنوان محصول: حافظه داخلی، رم، ریجن.
	 *
	 * مثلاً «... ظرفیت 512 گیگابایت و رم 12 گیگابایت - پک ویتنام» →
	 * ['حافظه داخلی' => '512 گیگابایت', 'رم' => '12 گیگابایت', 'ریجن' => 'ویتنام']
	 *
	 * @param string $title_fa عنوان فارسی.
	 * @param string $title_en عنوان انگلیسی.
	 * @return array
	 */
	public static function title_dimensions( $title_fa, $title_en = '' ) {
		$fa  = self::latin_digits( $title_fa );
		$en  = (string) $title_en;
		$out = array();

		// حافظه داخلی: «ظرفیت 512 گیگابایت» / «حافظه 1 ترابایت» / انگلیسی «512/12 GB».
		if ( preg_match( '/ظرفیت\s*(\d+(?:\.\d+)?)\s*(ترابایت|گیگابایت|tb|gb)/iu', $fa, $m )
			|| preg_match( '/حافظه(?:\s*داخلی)?\s*(\d+(?:\.\d+)?)\s*(ترابایت|گیگابایت|tb|gb)/iu', $fa, $m ) ) {
			$out['حافظه داخلی'] = self::capacity_label( $m[1], $m[2] );
		} elseif ( preg_match( '/(\d+(?:\.\d+)?)\s*(tb|gb)\s*\/\s*\d+\s*gb/iu', $en, $m ) ) {
			$out['حافظه داخلی'] = self::capacity_label( $m[1], $m[2] );
		}

		// رم: «رم 12 گیگابایت» / بخش دوم «512/12».
		if ( preg_match( '/رم\s*(\d+(?:\.\d+)?)\s*(گیگابایت|gb)/iu', $fa, $m ) ) {
			$out['رم'] = self::capacity_label( $m[1], 'gb' );
		} elseif ( ! isset( $out['رم'] ) && preg_match( '/\d+\s*(?:tb|gb)\s*\/\s*(\d+(?:\.\d+)?)\s*gb/iu', $en, $m ) ) {
			$out['رم'] = self::capacity_label( $m[1], 'gb' );
		}

		// ریجن: «پک ویتنام»، «(کره)»، یا نام کشور/منطقه در عنوان.
		$regions_fa = 'ویتنام|کره جنوبی|کره|هند|چین|گلوبال|امارات|سنگاپور|مالزی|اندونزی|تایوان';
		if ( preg_match( '/پک\s*(' . $regions_fa . ')/u', $fa, $m )
			|| preg_match( '/\(\s*(' . $regions_fa . ')\s*\)/u', $fa, $m )
			|| preg_match( '/(?:^|[\s\-\|])(' . $regions_fa . ')(?=$|[\s\-\|\)])/u', $fa, $m ) ) {
			$out['ریجن'] = $m[1];
		} elseif ( preg_match( '/\b(vietnam|korea|india|china|global|uae|singapore|malaysia|indonesia|taiwan)\b/i', $en, $m ) ) {
			$map = array(
				'vietnam' => 'ویتنام', 'korea' => 'کره', 'india' => 'هند', 'china' => 'چین',
				'global' => 'گلوبال', 'uae' => 'امارات', 'singapore' => 'سنگاپور',
				'malaysia' => 'مالزی', 'indonesia' => 'اندونزی', 'taiwan' => 'تایوان',
			);
			$key = strtolower( $m[1] );
			if ( isset( $map[ $key ] ) ) {
				$out['ریجن'] = $map[ $key ];
			}
		}

		return $out;
	}

	/**
	 * برچسب نمایشی ظرفیت: «512 گیگابایت» / «1 ترابایت».
	 *
	 * @param string $num عدد.
	 * @param string $unit واحد.
	 * @return string
	 */
	private static function capacity_label( $num, $unit ) {
		$num = rtrim( rtrim( (string) $num, '0' ), '.' );
		if ( '' === $num ) {
			$num = '0';
		}
		$u = strtolower( (string) $unit );
		return $num . ' ' . ( ( 'tb' === $u || 'ترابایت' === $unit ) ? 'ترابایت' : 'گیگابایت' );
	}

	/**
	 * نام پایه‌ی محصول بدون توکن‌های متغیر (برای تشخیص هم‌نام بودن).
	 *
	 * @param string $title_fa عنوان فارسی.
	 * @param string $title_en عنوان انگلیسی.
	 * @return string
	 */
	public static function base_title( $title_fa, $title_en = '' ) {
		$t = self::latin_digits( $title_fa );
		// حذف عبارت‌های بُعد متغیر.
		$t = preg_replace( '/ظرفیت\s*\d+(?:\.\d+)?\s*(ترابایت|گیگابایت|tb|gb)/iu', ' ', $t );
		$t = preg_replace( '/حافظه(?:\s*داخلی)?\s*\d+(?:\.\d+)?\s*(ترابایت|گیگابایت|tb|gb)/iu', ' ', $t );
		$t = preg_replace( '/و?\s*رم\s*\d+(?:\.\d+)?\s*(گیگابایت|gb)/iu', ' ', $t );
		$t = preg_replace( '/پک\s*(ویتنام|کره جنوبی|کره|هند|چین|گلوبال|امارات|سنگاپور|مالزی|اندونزی|تایوان)/u', ' ', $t );
		$t = preg_replace( '/\(\s*(ویتنام|کره جنوبی|کره|هند|چین|گلوبال|امارات|سنگاپور|مالزی|اندونزی|تایوان)\s*\)/u', ' ', $t );
		$t = preg_replace( '/(?:^|[\s\-\|])(ویتنام|کره جنوبی|کره|هند|چین|گلوبال|امارات|سنگاپور|مالزی|اندونزی|تایوان)(?=$|[\s\-\|])/u', ' ', $t );
		$t = preg_replace( '/[\|\-–—،,\(\)\/]/u', ' ', $t );
		$t = trim( preg_replace( '/\s+/u', ' ', $t ) );
		return $t;
	}

	/**
	 * ساخت گروه‌های متغیر از روی محصولات هم‌نام در نتایج جستجو (خالص و قابل تست).
	 *
	 * وقتی صفحه‌ی محصول دیجی‌کالا متغیرها را کامل نمی‌دهد (مثلاً حافظه و ریجن
	 * هر کدام محصول جدا هستند)، از روی هم‌نام‌ها بُعدهایی که واقعاً بین آن‌ها
	 * فرق می‌کند (حافظه داخلی/رم/ریجن) با ارزان‌ترین قیمت هر مقدار ساخته می‌شود.
	 *
	 * @param array $current  محصول نرمال فعلی (name1, name2, price, random_key).
	 * @param array $siblings نتایج نرمال جستجو.
	 * @return array گروه‌ها با detected=true.
	 */
	public static function detect_sibling_variants( $current, $siblings ) {
		if ( empty( $current['name1'] ) || ! is_array( $siblings ) ) {
			return array();
		}
		$base = self::base_title( $current['name1'], isset( $current['name2'] ) ? $current['name2'] : '' );
		if ( '' === $base ) {
			return array();
		}
		$pool   = array();
		$pool[] = $current;
		foreach ( $siblings as $s ) {
			if ( ! is_array( $s ) || empty( $s['name1'] ) ) {
				continue;
			}
			if ( ! empty( $current['random_key'] ) && ! empty( $s['random_key'] ) && $s['random_key'] === $current['random_key'] ) {
				continue;
			}
			$sb = self::base_title( $s['name1'], isset( $s['name2'] ) ? $s['name2'] : '' );
			if ( '' === $sb ) {
				continue;
			}
			$same = ( $sb === $base )
				|| ( mb_strlen( $base ) >= 8 && false !== mb_strpos( $sb, $base ) )
				|| ( mb_strlen( $sb ) >= 8 && false !== mb_strpos( $base, $sb ) );
			if ( $same ) {
				$pool[] = $s;
			}
		}
		if ( count( $pool ) < 2 ) {
			return array();
		}
		$groups = array();
		foreach ( array( 'حافظه داخلی', 'رم', 'ریجن' ) as $dim ) {
			$values = array(); // label => ['key'=>, 'title'=>, 'price'=>]
			foreach ( $pool as $item ) {
				$dims = self::title_dimensions( $item['name1'], isset( $item['name2'] ) ? $item['name2'] : '' );
				if ( empty( $dims[ $dim ] ) ) {
					continue;
				}
				$label = $dims[ $dim ];
				$price = isset( $item['price'] ) ? (int) $item['price'] : 0;
				if ( ! isset( $values[ $label ] )
					|| ( $price > 0 && ( 0 === $values[ $label ]['price'] || $price < $values[ $label ]['price'] ) ) ) {
					$values[ $label ] = array(
						'key'   => ! empty( $item['random_key'] ) ? (string) $item['random_key'] : substr( md5( $label ), 0, 8 ),
						'title' => $label,
						'price' => $price,
					);
				}
			}
			if ( count( $values ) < 2 ) {
				continue;
			}
			$items = array_values( $values );
			usort( $items, function ( $a, $b ) {
				$pa = $a['price'] > 0 ? $a['price'] : PHP_INT_MAX;
				$pb = $b['price'] > 0 ? $b['price'] : PHP_INT_MAX;
				return $pa <=> $pb;
			} );
			$groups[] = array(
				'title'    => $dim,
				'items'    => $items,
				'provider' => 'digikala',
				'detected' => true,
			);
		}
		return $groups;
	}

	/**
	 * اگر متغیرهای صفحه‌ی محصول کافی نبود، از هم‌نام‌های جستجو کاملشان کن.
	 *
	 * @param array $normalized داده‌ی نرمال جزئیات.
	 * @return array
	 */
	public function enrich_sibling_variants( $normalized ) {
		if ( ! is_array( $normalized ) || empty( $normalized['name1'] ) || 'mock' === $this->source ) {
			return $normalized;
		}
		$items = 0;
		$titles = array();
		foreach ( (array) ( isset( $normalized['variants'] ) ? $normalized['variants'] : array() ) as $g ) {
			$items += isset( $g['items'] ) && is_array( $g['items'] ) ? count( $g['items'] ) : 0;
			if ( ! empty( $g['title'] ) ) {
				$titles[] = $g['title'];
			}
		}
		if ( $items >= 2 ) {
			return $normalized;
		}
		// از خود مشخصات فنی هم بُعدهای فعلی را می‌خوانیم تا با همان برچسب‌ها مقایسه شود.
		$cache_key = 'shoper_dk_sib_' . md5( isset( $normalized['random_key'] ) ? $normalized['random_key'] : $normalized['name1'] );
		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['groups'] ) ) {
				return $this->apply_detected_groups( $normalized, $cached['groups'], $titles );
			}
		}
		$search = $this->search( $normalized['name1'], 1, 20 );
		if ( is_wp_error( $search ) || empty( $search['results'] ) ) {
			return $normalized;
		}
		$groups = self::detect_sibling_variants( $normalized, $search['results'] );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $cache_key, array( 'groups' => $groups ), 30 * MINUTE_IN_SECONDS );
		}
		return $this->apply_detected_groups( $normalized, $groups, $titles );
	}

	/**
	 * افزودن گروه‌های کشف‌شده به variants بدون تکرار عنوان گروه بومی.
	 *
	 * @param array $normalized داده.
	 * @param array $groups     گروه‌های کشف‌شده.
	 * @param array $titles     عنوان‌های موجود.
	 * @return array
	 */
	private function apply_detected_groups( $normalized, $groups, $titles ) {
		if ( empty( $groups ) ) {
			return $normalized;
		}
		if ( empty( $normalized['variants'] ) || ! is_array( $normalized['variants'] ) ) {
			$normalized['variants'] = array();
		}
		foreach ( $groups as $g ) {
			if ( ! in_array( $g['title'], $titles, true ) ) {
				$normalized['variants'][] = $g;
			}
		}
		return $normalized;
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
				// شکل discovery v2: ویجت لیست، داخلش ویجت‌های product.
				if ( 'vertical_product_listing' === $widget['type'] && ! empty( $widget['data']['widgets'] ) && is_array( $widget['data']['widgets'] ) ) {
					foreach ( $widget['data']['widgets'] as $inner ) {
						if ( isset( $inner['type'], $inner['data'] ) && 'product' === $inner['type'] && is_array( $inner['data'] ) ) {
							$out[] = $inner['data'];
						}
					}
				}
				// ویجت product در سطح بالا.
				if ( 'product' === $widget['type'] && ! empty( $widget['data'] ) && is_array( $widget['data'] ) && isset( $widget['data']['id'] ) ) {
					$out[] = $widget['data'];
				}
				if ( isset( $widget['data']['products'] ) && is_array( $widget['data']['products'] ) ) {
					foreach ( $widget['data']['products'] as $p ) {
						if ( is_array( $p ) ) {
							$out[] = $p;
						}
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
			'variants'      => $this->extract_variant_groups( $product ),
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
	 * استخراج گروه‌های متغیر (رنگ/سایز/…) با قیمت هر گزینه — به تومان.
	 *
	 * خروجی هم‌شکل خروجی ترب است: [{title, items: [{key, title, price}]}]
	 * تا رابط کاربری و سازنده‌ی محصول متغیر برای هر دو منبع یکسان کار کنند.
	 * قیمت هر گزینه ارزان‌ترین قیمت همان گزینه بین variantهای قابل‌فروش است.
	 *
	 * @param array $product محصول.
	 * @return array
	 */
	private function extract_variant_groups( $product ) {
		$groups = array(); // title => [ itemTitle => ['key'=>, 'title'=>, 'price'=>] ]
		if ( empty( $product['variants'] ) || ! is_array( $product['variants'] ) ) {
			return array();
		}
		foreach ( $product['variants'] as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}
			if ( isset( $variant['status'] ) && 'marketable' !== $variant['status'] ) {
				continue;
			}
			$price = 0;
			if ( isset( $variant['price']['selling_price'] ) ) {
				$rial  = (int) $variant['price']['selling_price'];
				$price = ( $rial >= 1000 && 0 === ( $rial % 10 ) ) ? (int) ( $rial / 10 ) : $rial;
			}
			$dimensions = array(
				'رنگ'  => array( 'color', 'رنگ' ),
				'سایز' => array( 'size', 'سایز', 'اندازه' ),
			);
			foreach ( $dimensions as $group_title => $keys ) {
				foreach ( $keys as $key ) {
					if ( empty( $variant[ $key ] ) ) {
						continue;
					}
					$node  = $variant[ $key ];
					$title = '';
					$id    = '';
					if ( is_array( $node ) ) {
						$title = isset( $node['title'] ) ? (string) $node['title'] : ( isset( $node['title_fa'] ) ? (string) $node['title_fa'] : '' );
						$id    = isset( $node['id'] ) ? (string) $node['id'] : '';
					} elseif ( is_scalar( $node ) ) {
						$title = (string) $node;
					}
					$title = trim( $title );
					if ( '' === $title ) {
						continue;
					}
					if ( ! isset( $groups[ $group_title ][ $title ] ) ) {
						$groups[ $group_title ][ $title ] = array(
							'key'   => '' !== $id ? ( 'v' . $id ) : ( 'v' . substr( md5( $group_title . '|' . $title ), 0, 10 ) ),
							'title' => $title,
							'price' => $price,
						);
					} elseif ( $price > 0 && ( 0 === $groups[ $group_title ][ $title ]['price'] || $price < $groups[ $group_title ][ $title ]['price'] ) ) {
						$groups[ $group_title ][ $title ]['price'] = $price;
					}
				}
			}
		}
		$out = array();
		foreach ( $groups as $title => $items ) {
			// گروه تک‌گزینه‌ای متغیر محسوب نمی‌شود.
			if ( count( $items ) < 2 ) {
				continue;
			}
			$out[] = array(
				'title'    => $title,
				'items'    => array_values( $items ),
				'provider' => 'digikala',
			);
		}
		return $out;
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
		// مسیر جزئیات جدید (/product/v1/products/): اگر default_variant قیمت
		// نداشت، ارزان‌ترین variant قابل‌فروش و بعد second_default_variant.
		if ( $rial <= 0 && ! empty( $item['variants'] ) && is_array( $item['variants'] ) ) {
			foreach ( $item['variants'] as $variant ) {
				if ( ! is_array( $variant ) || ! isset( $variant['price']['selling_price'] ) ) {
					continue;
				}
				if ( isset( $variant['status'] ) && 'marketable' !== $variant['status'] ) {
					continue;
				}
				$candidate = (int) $variant['price']['selling_price'];
				if ( $candidate > 0 && ( 0 === $rial || $candidate < $rial ) ) {
					$rial = $candidate;
				}
			}
		}
		if ( $rial <= 0 && isset( $item['second_default_variant']['price']['selling_price'] ) ) {
			$rial = (int) $item['second_default_variant']['price']['selling_price'];
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
	 * فایل کوکی موقت برای چالش کوکی CDN دیجی‌کالا (DigiCDN).
	 *
	 * لبه‌ی CDN دیجی‌کالا گاهی اول پاسخ 307 با Set-Cookie: digicdn_cookie
	 * به همان آدرس می‌دهد؛ بدون نگه‌داشتن کوکی، ریدایرکت لوپ می‌شود یا
	 * پاسخ چالش برمی‌گردد. cURL با COOKIEJAR/COOKIEFILE همان فایل، کوکی
	 * را روی ریدایرکت و درخواست‌های بعدی همین کلاینت نگه می‌دارد.
	 *
	 * @var string
	 */
	private $cookie_file = '';

	/**
	 * ساخت/گرفتن مسیر فایل کوکی موقت.
	 *
	 * @return string
	 */
	private function cookie_file() {
		if ( '' === $this->cookie_file ) {
			$dir  = function_exists( 'sys_get_temp_dir' ) ? sys_get_temp_dir() : '/tmp';
			$file = tempnam( $dir, 'shoper_dk_' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->cookie_file = $file ? $file : '';
		}
		return $this->cookie_file;
	}

	/**
	 * پاک‌کردن فایل کوکی موقت.
	 */
	public function __destruct() {
		if ( '' !== $this->cookie_file && file_exists( $this->cookie_file ) ) {
			@unlink( $this->cookie_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * هدرهای درخواست به API دیجی‌کالا (همان‌هایی که وب‌اپ خودش می‌فرستد).
	 *
	 * @return string[]
	 */
	private function api_headers() {
		return array(
			'Accept: application/json, text/plain, */*',
			'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
			'Referer: https://www.digikala.com/',
			'Origin: https://www.digikala.com',
			'x-web-client: desktop',
			'x-web-client-id: web',
			'Sec-Fetch-Dest: empty',
			'Sec-Fetch-Mode: cors',
			'Sec-Fetch-Site: same-site',
		);
	}

	/**
	 * اعتبارسنجی JSON دیجی‌کالا: فیلد status بدنه authoritative است.
	 *
	 * دیجی‌کالا برای endpoint حذف‌شده حتی ممکن است HTTP 200 با
	 * {"status":404} بدهد؛ پس فقط به کد HTTP تکیه نمی‌کنیم.
	 *
	 * @param mixed $json داده‌ی decode شده.
	 * @param int   $code کد HTTP.
	 * @return array|WP_Error
	 */
	private function validate_json( $json, $code ) {
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'invalid_json', 'پاسخ دیجی‌کالا JSON نیست.', array( 'status' => $code ) );
		}
		if ( isset( $json['status'] ) && is_numeric( $json['status'] ) && (int) $json['status'] >= 400 ) {
			$status = (int) $json['status'];
			return new WP_Error(
				'http_error',
				sprintf( 'پاسخ غیرمنتظره از دیجی‌کالا (کد %d).', $status ),
				array( 'status' => $status )
			);
		}
		return $json;
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
			$options = array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_TIMEOUT        => $this->timeout,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_USERAGENT      => $this->user_agent,
				CURLOPT_ENCODING       => 'gzip, deflate',
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_HTTPHEADER     => $this->api_headers(),
			);
			$cookie = $this->cookie_file();
			if ( '' !== $cookie ) {
				// چالش 307 کوکی DigiCDN: کوکی را بگیر و روی ریدایرکت بفرست.
				$options[ CURLOPT_COOKIEJAR ]  = $cookie;
				$options[ CURLOPT_COOKIEFILE ] = $cookie;
			}
			if ( defined( 'CURLOPT_HTTP_VERSION' ) && defined( 'CURL_HTTP_VERSION_1_1' ) ) {
				$options[ CURLOPT_HTTP_VERSION ] = CURL_HTTP_VERSION_1_1;
			}
			curl_setopt_array( $ch, $options );
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
			return $this->validate_json( json_decode( (string) $body, true ), $code );
		}

		if ( ! function_exists( 'wp_remote_get' ) ) {
			return new WP_Error( 'connection_failed', 'هیچ روش HTTP در دسترس نیست.' );
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $this->timeout,
				'redirection' => 5,
				'user-agent'  => $this->user_agent,
				'headers'     => array(
					'Accept'          => 'application/json, text/plain, */*',
					'Accept-Language' => 'fa-IR,fa;q=0.9,en;q=0.8',
					'Referer'         => 'https://www.digikala.com/',
					'Origin'          => 'https://www.digikala.com',
					'x-web-client'    => 'desktop',
					'x-web-client-id' => 'web',
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
		return $this->validate_json( json_decode( $body, true ), $code );
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
