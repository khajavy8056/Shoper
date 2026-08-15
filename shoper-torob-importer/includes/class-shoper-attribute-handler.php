<?php
/**
 * مدیریت ویژگی‌های محصول (Attributes).
 *
 * مشخصات فنی ترب را به ویژگی‌های سراسری ووکامرس (pa_...) تبدیل می‌کند.
 * اگر ویژگی از قبل وجود نداشته باشد، خودکار ساخته می‌شود.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_Attribute_Handler.
 */
class Shoper_Attribute_Handler {

	/**
	 * نگاشت نام فارسی ویژگی‌های رایج به slug لاتین.
	 *
	 * @var array
	 */
	private static $slug_map = array(
		'برند'                  => 'brand',
		'مدل'                   => 'model',
		'سازنده'                => 'manufacturer',
		'وزن'                   => 'weight',
		'ابعاد'                 => 'dimensions',
		'جنس بدنه'              => 'body-material',
		'جنس'                   => 'material',
		'رنگ'                   => 'color',
		'رنگ‌بندی'              => 'color-variation',
		'پردازنده'              => 'processor',
		'مدل پردازنده'          => 'processor-model',
		'نوع شنا'               => 'chipset-architecture',
		'نوع پردازنده'          => 'processor-type',
		'تعداد هسته'            => 'cpu-cores',
		'فرکانس پردازنده'       => 'cpu-frequency',
		'پردازنده گرافیکی'      => 'gpu',
		'حافظه داخلی'           => 'storage',
		'ظرفیت حافظه داخلی'    => 'storage-capacity',
		'حافظه RAM'             => 'ram',
		'رم'                    => 'ram',
		'مقدار RAM'             => 'ram-size',
		'پشتیبانی از کارت حافظه' => 'memory-card',
		'اندازه صفحه نمایش'    => 'screen-size',
		'قطر صفحه نمایش'        => 'screen-size',
		'نوع صفحه نمایش'        => 'screen-type',
		'فناوری صفحه نمایش'     => 'display-technology',
		'رزولوشن'               => 'resolution',
		'دقت صفحه نمایش'        => 'resolution',
		'تراکم پیکسل'           => 'pixel-density',
		'نرخ نوسازی تصویر'      => 'refresh-rate',
		'محافظ صفحه'            => 'screen-protection',
		'دوربین اصلی'           => 'main-camera',
		'دوربین اولتراواید'     => 'ultrawide-camera',
		'دوربین تله‌فوتو'       => 'telephoto-camera',
		'فیلم‌برداری'           => 'video-recording',
		'دوربین سلفی'           => 'front-camera',
		'ظرفیت باتری'           => 'battery-capacity',
		'شارژ سریع'             => 'fast-charging',
		'شارژ بی‌سیم'           => 'wireless-charging',
		'سیستم‌عامل'            => 'os',
		'نسخه سیستم عامل'       => 'os-version',
		'شبکه‌های ارتباطی'      => 'network',
		'شبکه'                  => 'network',
		'Wi-Fi'                 => 'wifi',
		'وای‌فای'               => 'wifi',
		'بلوتوث'                => 'bluetooth',
		'NFC'                   => 'nfc',
		'درگاه ارتباطی'         => 'port',
		'پورت'                  => 'port',
		'حسگر اثر انگشت'        => 'fingerprint',
		'تشخیص چهره'            => 'face-id',
		'گواهی ضدآب'            => 'water-resistance',
		'گارانتی'               => 'warranty',
		'مدت گارانتی'           => 'warranty-duration',
		'توان'                  => 'power',
		'ولتاژ ورودی'           => 'input-voltage',
		'ولتاژ'                 => 'voltage',
		'جریان'                 => 'current',
		'نوع اتصال'             => 'connectivity',
		'طول کابل'              => 'cable-length',
		'نوع کابل'              => 'cable-type',
		'سایر امکانات'          => 'other-features',
		'امکانات'               => 'features',
		'حسگرها'                => 'sensors',
		'صدا'                   => 'audio',
		'بلندگو'                => 'speaker',
	);

	/**
	 * ساخت آرایه‌ی WC_Product_Attribute از مشخصات فنی.
	 *
	 * برای هر مشخصه:
	 *   1. یک slug لاتین می‌سازد.
	 *   2. اگر attribute سراسری وجود نداشت، آن را می‌سازد.
	 *   3. مقدار را به‌صورت term در آن taxonomy ثبت می‌کند.
	 *   4. یک WC_Product_Attribute آماده برمی‌گرداند.
	 *
	 * @param array $specs آرایه‌ی key => value.
	 * @return array { attrs: WC_Product_Attribute[], errors: string[] }
	 */
	public function build_attributes( $specs ) {
		$result = array(
			'attrs'  => array(),
			'errors' => array(),
		);

		if ( ! is_array( $specs ) || empty( $specs ) ) {
			return $result;
		}

		$position = 0;

		foreach ( $specs as $name => $value ) {
			$name  = $this->normalize_key( $name );
			$value = $this->normalize_value( $value );
			if ( '' === $name || '' === $value ) {
				continue;
			}

			$attribute = $this->make_attribute( $name, $value, $position );
			if ( is_wp_error( $attribute ) ) {
				$result['errors'][] = $name . ': ' . $attribute->get_error_message();
				continue;
			}
			$result['attrs'][] = $attribute;
			$position++;
		}

		return $result;
	}

	/**
	 * ساخت یک ویژگی سراسری برای یک مشخصه.
	 *
	 * @param string $name     نام فارسی ویژگی.
	 * @param string $value    مقدار.
	 * @param int    $position موقعیت.
	 * @return WC_Product_Attribute|WP_Error
	 */
	private function make_attribute( $name, $value, $position ) {
		$taxonomy_name = $this->ensure_taxonomy( $name );
		if ( is_wp_error( $taxonomy_name ) ) {
			return $taxonomy_name;
		}

		// ساخت term (مقدار) اگر وجود نداشت.
		$term = $this->ensure_term( $taxonomy_name, $value );
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy_name ) );
		$attribute->set_name( $taxonomy_name );
		$attribute->set_options( array( (int) $term['term_id'] ) );
		$attribute->set_position( $position );
		$attribute->set_visible( true );
		$attribute->set_variation( false );

		return $attribute;
	}

	/**
	 * اطمینان از وجود taxonomy سراسری برای یک نام ویژگی.
	 *
	 * @param string $name نام فارسی.
	 * @return string|WP_Error نام taxonomy مثل pa_brand
	 */
	private function ensure_taxonomy( $name ) {
		$slug = $this->latin_slug( $name );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}

		$taxonomy = wc_attribute_taxonomy_name( $slug ); // pa_xxx.

		// اگر taxonomy از قبل ثبت شده، همان را برمی‌گردانیم.
		if ( taxonomy_exists( $taxonomy ) ) {
			return $taxonomy;
		}

		// در غیر این صورت یک attribute سراسری جدید می‌سازیم.
		// بررسی اینکه آیا در جدول attribute_taxonomies ثبت شده.
		$existing_id = wc_attribute_taxonomy_id_by_name( $slug );
		if ( $existing_id ) {
			return $taxonomy;
		}

		// ساخت attribute جدید.
		$new_id = wc_create_attribute(
			array(
				'name'         => $name,  // نام نمایشی فارسی.
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// ثبت taxonomy برای استفاده در همین درخواست.
		register_taxonomy(
			$taxonomy,
			apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, array( 'product' ) ),
			array(
				'hierarchical' => false,
				'show_ui'      => false,
				'query_var'    => true,
				'rewrite'      => false,
			)
		);

		// پاک کردن کش ووکامرس.
		delete_transient( 'wc_attribute_taxonomies' );

		return $taxonomy;
	}

	/**
	 * اطمینان از وجود term مقدار در taxonomy.
	 *
	 * @param string $taxonomy نام taxonomy.
	 * @param string $value    مقدار.
	 * @return array|WP_Error
	 */
	private function ensure_term( $taxonomy, $value ) {
		// مقادیر ممکن است خیلی طولانی باشند؛ ووکامرس نام term را تا ۲۰۰ کاراکتر می‌پذیرد.
		$value = mb_substr( trim( $value ), 0, 190 );

		$term = term_exists( $value, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return array( 'term_id' => (int) $term['term_id'] );
		}

		$inserted = wp_insert_term( $value, $taxonomy );
		if ( is_wp_error( $inserted ) ) {
			// ممکن است term با نامی مشابه وجود داشته باشد.
			if ( isset( $inserted->error_data['term_exists'] ) ) {
				return array( 'term_id' => (int) $inserted->error_data['term_exists'] );
			}
			return $inserted;
		}
		return array( 'term_id' => (int) $inserted['term_id'] );
	}

	/**
	 * ادغام ویژگی‌های جدید با ویژگی‌های فعلی محصول.
	 *
	 * @param WC_Product_Attribute[] $current   فعلی.
	 * @param WC_Product_Attribute[] $new_attrs جدید.
	 * @return WC_Product_Attribute[]
	 */
	public function merge_attributes( $current, $new_attrs ) {
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		// جلوگیری از افزودن تکراری بر اساس نام taxonomy.
		$existing_names = array();
		foreach ( $current as $attr ) {
			$existing_names[ $attr->get_name() ] = true;
		}
		foreach ( $new_attrs as $attr ) {
			if ( ! isset( $existing_names[ $attr->get_name() ] ) ) {
				$current[] = $attr;
				$existing_names[ $attr->get_name() ] = true;
			}
		}
		return array_values( $current );
	}

	/**
	 * تولید slug لاتین برای یک نام فارسی.
	 *
	 * @param string $name نام.
	 * @return string|WP_Error
	 */
	public static function latin_slug( $name ) {
		$name = trim( $name );
		if ( isset( self::$slug_map[ $name ] ) ) {
			return self::$slug_map[ $name ];
		}

		// تلاش برای sanitize_title وردپرس (فقط لاتین).
		$slug = sanitize_title( $name );

		// اگر slug خالی بود یا فقط شامل علائم/فارسی بود، یک شناسه‌ی پایدار می‌سازیم.
		if ( empty( $slug ) || self::is_mostly_persian( $slug ) || ! self::is_valid_slug( $slug ) ) {
			// شناسه‌ی پایدار بر اساس md5 نام تا ویژگی‌های یکسان، یک slug ثابت داشته باشند.
			$slug = 'spec-' . substr( md5( $name ), 0, 10 );
		}

		// محدودیت طول.
		$slug = substr( $slug, 0, 28 );
		$slug = trim( $slug, '-' );

		if ( empty( $slug ) ) {
			return new WP_Error( 'invalid_slug', 'Slug نامعتبر برای ویژگی: ' . $name );
		}
		return $slug;
	}

	/**
	 * بررسی معتبر بودن slug (فقط حروف لاتین، عدد و خط تیره).
	 *
	 * @param string $slug slug.
	 * @return bool
	 */
	private static function is_valid_slug( $slug ) {
		return (bool) preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug );
	}

	/**
	 * بررسی فارسی بودن بخش عمده‌ی رشته.
	 *
	 * @param string $str رشته.
	 * @return bool
	 */
	private static function is_mostly_persian( $str ) {
		return (bool) preg_match( '/[\x{0600}-\x{06FF}]/u', $str );
	}

	/**
	 * نرمال‌سازی کلید (نام ویژگی).
	 *
	 * @param string $key کلید.
	 * @return string
	 */
	private function normalize_key( $key ) {
		$key = html_entity_decode( (string) $key, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$key = trim( preg_replace( '/\s+/u', ' ', $key ) );
		return $key;
	}

	/**
	 * نرمال‌سازی مقدار ویژگی.
	 *
	 * @param mixed $value مقدار.
	 * @return string
	 */
	private function normalize_value( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( '، ', array_map( array( $this, 'flatten_value' ), $value ) );
		}
		$value = $this->flatten_value( $value );
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) );
		return $value;
	}

	/**
	 * تخت‌سازی مقدار.
	 *
	 * @param mixed $v مقدار.
	 * @return string
	 */
	private function flatten_value( $v ) {
		if ( is_bool( $v ) ) {
			return $v ? 'دارد' : 'ندارد';
		}
		if ( is_null( $v ) ) {
			return '';
		}
		if ( is_scalar( $v ) ) {
			return (string) $v;
		}
		return wp_json_encode( $v, JSON_UNESCAPED_UNICODE );
	}
}
