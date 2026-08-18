<?php
/**
 * استودیوی متن محصول — معرفی و بررسی + جدول مشخصات.
 *
 * متن منبع (دیجی‌کالا/ترب) را تمیز می‌کند.
 * اگر ناقص یا خالی باشد، از مشخصات واقعی همان کالا یک معرفی کوتاه می‌نویسد
 * تا صفحه محصول خالی نماند. مشخصه تازه اختراع نمی‌شود.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_Copywriter.
 */
class Shoper_Copywriter {

	/**
	 * آماده‌سازی محصول برای فروشگاه.
	 *
	 * @param array $data دادهٔ نرمال محصول.
	 * @return array
	 */
	public static function enhance( $data ) {
		$data   = is_array( $data ) ? $data : array();
		$name   = self::s( isset( $data['name1'] ) ? $data['name1'] : '' );
		$name2  = self::s( isset( $data['name2'] ) ? $data['name2'] : '' );
		$specs  = ( isset( $data['specs'] ) && is_array( $data['specs'] ) ) ? $data['specs'] : array();
		$keys   = ( isset( $data['key_specs'] ) && is_array( $data['key_specs'] ) ) ? $data['key_specs'] : array();
		$source = isset( $data['description'] ) ? (string) $data['description'] : '';
		$cat    = self::detect_category( $name . ' ' . $name2, $specs );
		$brand  = self::spec( $specs, array( 'برند', 'سازنده', 'Brand' ) );

		$article    = self::compose_article( $source, $name, $name2, $brand, $specs, $keys, $cat );
		$highlights = self::highlights( $cat, $specs, $keys );
		$summary    = self::spec_summary( $specs, $keys );
		$faq        = self::faq( $cat, $name, $specs, $keys );
		$short      = self::short_html( $name, $name2, $keys, $highlights );
		$html       = self::assemble_html( $data, $article, $highlights, $article, $summary, '', '', $faq );
		$seo        = self::seo( $name, $name2, $brand, $cat, $keys, $specs );

		return array(
			'title'             => $name,
			'short_description' => $short,
			'description_html'  => $html,
			'analysis'          => $article,
			'review'            => $summary,
			'highlights'        => $highlights,
			'audience'          => '',
			'verdict'           => '',
			'faq'               => $faq,
			'seo_title'         => $seo['title'],
			'seo_desc'          => $seo['description'],
			'focus_keyword'     => $seo['keyword'],
			'tags'              => $seo['tags'],
			'provider'          => 'studio',
			'provider_label'    => 'معرفی و بررسی — استودیو خواجوی',
			'category'          => $cat,
		);
	}

	/**
	 * برش امن یک خط.
	 *
	 * @param mixed $value مقدار.
	 * @return string
	 */
	public static function s( $value ) {
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * طول متن.
	 *
	 * @param string $value متن.
	 * @return int
	 */
	public static function len( $value ) {
		$value = (string) $value;
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * آیا متن شامل عبارت است؟
	 *
	 * @param string $hay    متن.
	 * @param string $needle عبارت.
	 * @return bool
	 */
	public static function has( $hay, $needle ) {
		$hay    = (string) $hay;
		$needle = (string) $needle;
		if ( '' === $hay || '' === $needle ) {
			return false;
		}
		if ( function_exists( 'mb_strtolower' ) ) {
			$hay    = mb_strtolower( $hay, 'UTF-8' );
			$needle = mb_strtolower( $needle, 'UTF-8' );
		} else {
			$hay    = strtolower( $hay );
			$needle = strtolower( $needle );
		}
		return false !== strpos( $hay, $needle );
	}

	/**
	 * گرفتن یک مشخصه با چند نام محتمل.
	 *
	 * @param array $specs مشخصات.
	 * @param array $names نام‌ها.
	 * @return string
	 */
	public static function spec( $specs, $names ) {
		foreach ( (array) $names as $n ) {
			if ( ! empty( $specs[ $n ] ) ) {
				return self::s( $specs[ $n ] );
			}
		}
		return '';
	}

	/**
	 * تشخیص دسته.
	 *
	 * @param string $hay   متن.
	 * @param array  $specs مشخصات.
	 * @return string
	 */
	public static function detect_category( $hay, $specs ) {
		$hay = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $hay, 'UTF-8' ) : strtolower( (string) $hay );
		$map = array(
			'phone'     => array( 'گوشی', 'موبایل', 'smartphone', 'galaxy', 'iphone', 'redmi', 'poco', 'xiaomi', 'سامسونگ', 'شیائومی' ),
			'laptop'    => array( 'لپ تاپ', 'لپ‌تاپ', 'لپتاپ', 'macbook', 'notebook' ),
			'tablet'    => array( 'تبلت', 'ipad', 'tablet' ),
			'headphone' => array( 'هدفون', 'هندزفری', 'ایرپاد', 'earbuds', 'headset' ),
			'watch'     => array( 'ساعت هوشمند', 'smartwatch', 'watch' ),
			'tv'        => array( 'تلویزیون', 'tv ', 'smart tv' ),
			'console'   => array( 'پلی‌استیشن', 'playstation', 'xbox', 'nintendo', 'ps5', 'ps4', 'کنسول بازی', 'کنسول' ),
		);
		foreach ( $map as $cat => $needles ) {
			foreach ( $needles as $n ) {
				if ( function_exists( 'mb_strpos' ) ) {
					if ( false !== mb_strpos( $hay, $n, 0, 'UTF-8' ) ) {
						return $cat;
					}
				} elseif ( false !== strpos( $hay, $n ) ) {
					return $cat;
				}
			}
		}
		if ( self::spec( $specs, array( 'گنجایش باتری', 'ظرفیت باتری', 'دوربین اصلی', 'حافظه RAM', 'مقدار رم' ) ) ) {
			return 'phone';
		}
		return 'generic';
	}

	/**
	 * برچسب فارسی دسته.
	 *
	 * @param string $cat دسته.
	 * @return string
	 */
	public static function category_label( $cat ) {
		$map = array(
			'phone'     => 'گوشی موبایل',
			'laptop'    => 'لپ‌تاپ',
			'tablet'    => 'تبلت',
			'headphone' => 'هدفون',
			'watch'     => 'ساعت هوشمند',
			'tv'        => 'تلویزیون',
			'console'   => 'کنسول بازی',
			'generic'   => 'محصول',
		);
		return isset( $map[ $cat ] ) ? $map[ $cat ] : 'محصول';
	}

	/**
	 * تمیزکاری فاصله، علائم و پاراگراف متن منبع.
	 *
	 * @param string $source متن خام.
	 * @return string
	 */
	public static function polish_source( $source ) {
		$source = (string) $source;
		$source = html_entity_decode( $source, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$source = preg_replace( '/<\s*br\s*\/?>/iu', "\n", $source );
		$source = preg_replace( '/<\/p>/iu', "\n\n", $source );
		$source = wp_strip_all_tags( $source );
		$source = str_replace( array( "\r\n", "\r" ), "\n", $source );
		$source = preg_replace( '/[ \t\x{00A0}]+/u', ' ', $source );
		$source = preg_replace( '/ *\n */u', "\n", $source );
		$source = preg_replace( '/\n{3,}/u', "\n\n", $source );
		$source = preg_replace( '/\s+([،,.;:!?])/u', '$1', $source );
		$source = preg_replace( '/([،,.;:!?])\1+/u', '$1', $source );
		$source = preg_replace( '/([،,.;:!?])(\S)/u', '$1 $2', $source );
		$source = trim( $source );
		if ( self::len( $source ) > 6000 ) {
			$source = function_exists( 'mb_substr' ) ? mb_substr( $source, 0, 5980, 'UTF-8' ) . '…' : ( substr( $source, 0, 6980 ) . '…' );
		}
		return $source;
	}

	/**
	 * معرفی و بررسی محصول به سبک صفحه دیجی‌کالا.
	 *
	 * اگر متن منبع کافی باشد همان را مرتب می‌کند.
	 * اگر کوتاه یا خالی باشد از مشخصات واقعی مقاله کوتاه می‌سازد.
	 *
	 * @param string $source متن منبع.
	 * @param string $name   نام.
	 * @param string $name2  انگلیسی.
	 * @param string $brand  برند.
	 * @param array  $specs  مشخصات.
	 * @param array  $keys   کلیدها.
	 * @param string $cat    دسته.
	 * @return string
	 */
	public static function compose_article( $source, $name, $name2, $brand, $specs, $keys, $cat ) {
		$polished = self::polish_source( $source );
		if ( self::len( $polished ) >= 240 ) {
			$extra = self::missing_facts_paragraph( $polished, $name, $specs, $keys );
			return $extra ? ( $polished . "\n\n" . $extra ) : $polished;
		}
		return self::draft_article( $name, $name2, $brand, $specs, $keys, $cat, $polished );
	}

	/**
	 * سازگاری با نام قبلی.
	 *
	 * @param string $source متن منبع.
	 * @param string $name   نام.
	 * @param string $name2  انگلیسی.
	 * @param string $brand  برند.
	 * @param array  $specs  مشخصات.
	 * @param array  $keys   کلیدها.
	 * @return string
	 */
	public static function organize( $source, $name, $name2, $brand, $specs, $keys ) {
		$cat = self::detect_category( $name . ' ' . $name2, $specs );
		return self::compose_article( $source, $name, $name2, $brand, $specs, $keys, $cat );
	}

	/**
	 * نوشتن معرفی کوتاه از روی مشخصات واقعی.
	 *
	 * @param string $name  نام.
	 * @param string $name2 انگلیسی.
	 * @param string $brand برند.
	 * @param array  $specs مشخصات.
	 * @param array  $keys  کلیدها.
	 * @param string $cat   دسته.
	 * @param string $seed  متن کوتاه منبع.
	 * @return string
	 */
	public static function draft_article( $name, $name2, $brand, $specs, $keys, $cat, $seed = '' ) {
		$label = self::category_label( $cat );
		$p1    = '';
		if ( $name ) {
			$p1 = $name;
			if ( $name2 && ! self::has( $name, $name2 ) ) {
				$p1 .= ' (' . $name2 . ')';
			}
			if ( $brand ) {
				$p1 .= ' محصول برند ' . $brand . ' است.';
			} else {
				$p1 .= ' یک ' . $label . ' است.';
			}
		} elseif ( $name2 ) {
			$p1 = $name2 . ' یک ' . $label . ' است.';
		} else {
			$p1 = 'این ' . $label . ' بر اساس مشخصات ثبت‌شده معرفی می‌شود.';
		}

		$seed = trim( (string) $seed );
		if ( $seed && ! self::has( $p1, $seed ) ) {
			$p1 .= ' ' . $seed;
		}

		$woven = self::weave_specs( $specs, $keys );
		$paras = array( $p1 );
		if ( $woven ) {
			$chunks = preg_split( '/(?<=[.؟])\s+/u', $woven );
			$chunks = array_values( array_filter( array_map( 'trim', (array) $chunks ) ) );
			if ( count( $chunks ) > 4 ) {
				$paras[] = implode( ' ', array_slice( $chunks, 0, 4 ) );
				$paras[] = implode( ' ', array_slice( $chunks, 4, 5 ) );
			} else {
				$paras[] = $woven;
			}
		}

		$last = end( $paras );
		if ( ! self::has( $last, 'جدول مشخصات' ) ) {
			$who    = $name ? ( '«' . $name . '»' ) : 'این محصول';
			$paras[] = 'جزئیات کامل ' . $who . ' در جدول مشخصات همین صفحه آمده است.';
		}

		$out = array();
		foreach ( $paras as $p ) {
			$p = trim( preg_replace( '/\s+/u', ' ', $p ) );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return implode( "\n\n", $out );
	}

	/**
	 * بافتن مشخصات واقعی به جملهٔ روان.
	 *
	 * @param array $specs مشخصات.
	 * @param array $keys  کلیدها.
	 * @return string
	 */
	public static function weave_specs( $specs, $keys ) {
		$bag  = array_merge( (array) $keys, (array) $specs );
		$map  = array(
			array( 'پردازنده', array( 'پردازنده', 'پردازنده مرکزی', 'تراشه' ) ),
			array( 'رم', array( 'مقدار رم', 'حافظه RAM', 'رم', 'مقدار RAM' ) ),
			array( 'حافظه داخلی', array( 'حافظه داخلی', 'ظرفیت حافظه' ) ),
			array( 'صفحه نمایش', array( 'اندازه صفحه نمایش' ) ),
			array( 'نوع نمایشگر', array( 'نوع صفحه نمایش' ) ),
			array( 'نرخ نوسازی', array( 'نرخ نوسازی تصویر' ) ),
			array( 'دوربین اصلی', array( 'دوربین اصلی', 'کیفیت دوربین اصلی' ) ),
			array( 'دوربین سلفی', array( 'دوربین سلفی', 'کیفیت دوربین جلو' ) ),
			array( 'باتری', array( 'گنجایش باتری', 'ظرفیت باتری' ) ),
			array( 'سیستم عامل', array( 'سیستم عامل' ) ),
			array( 'وزن', array( 'وزن' ) ),
			array( 'ابعاد', array( 'ابعاد' ) ),
			array( 'رنگ', array( 'رنگ' ) ),
			array( 'گواهی ضدآب', array( 'گواهی ضدآب' ) ),
			array( 'کارت گرافیک', array( 'کارت گرافیک' ) ),
			array( 'نوع اتصال', array( 'نوع اتصال' ) ),
		);
		$used = array();
		$bits = array();
		foreach ( $map as $row ) {
			$val = self::spec( $bag, $row[1] );
			if ( '' === $val ) {
				continue;
			}
			$bits[] = self::spec_sentence( $row[0], $val );
			foreach ( $row[1] as $k ) {
				$used[ $k ] = true;
			}
		}
		$extra = 0;
		foreach ( $bag as $k => $v ) {
			if ( isset( $used[ $k ] ) ) {
				continue;
			}
			$k = self::s( $k );
			$v = self::s( $v );
			if ( '' === $k || '' === $v ) {
				continue;
			}
			$bits[] = $k . ' این محصول ' . $v . ' است.';
			if ( ++$extra >= 3 ) {
				break;
			}
		}
		return trim( implode( ' ', $bits ) );
	}

	/**
	 * جملهٔ طبیعی برای یک مشخصه.
	 *
	 * @param string $label برچسب.
	 * @param string $val   مقدار.
	 * @return string
	 */
	public static function spec_sentence( $label, $val ) {
		$val = self::s( $val );
		$map = array(
			'پردازنده'    => 'پردازنده آن ' . $val . ' است.',
			'رم'          => 'این مدل با رم ' . $val . ' عرضه می‌شود.',
			'حافظه داخلی' => 'حافظه داخلی آن ' . $val . ' اعلام شده است.',
			'صفحه نمایش'  => 'اندازه صفحه نمایش ' . $val . ' است.',
			'نوع نمایشگر' => 'نوع نمایشگر ' . $val . ' ثبت شده است.',
			'نرخ نوسازی'  => 'نرخ نوسازی تصویر ' . $val . ' است.',
			'دوربین اصلی' => 'دوربین اصلی ' . $val . ' دارد.',
			'دوربین سلفی' => 'دوربین سلفی ' . $val . ' است.',
			'باتری'       => 'ظرفیت باتری ' . $val . ' است.',
			'سیستم عامل'  => 'سیستم‌عامل ' . $val . ' روی این محصول نصب شده است.',
			'وزن'         => 'وزن دستگاه ' . $val . ' است.',
			'ابعاد'       => 'ابعاد آن ' . $val . ' است.',
			'رنگ'         => 'رنگ ثبت‌شده ' . $val . ' است.',
			'گواهی ضدآب'  => 'گواهی ضدآب ' . $val . ' برای آن ثبت شده است.',
			'کارت گرافیک' => 'کارت گرافیک ' . $val . ' است.',
			'نوع اتصال'   => 'نوع اتصال ' . $val . ' است.',
		);
		return isset( $map[ $label ] ) ? $map[ $label ] : ( $label . ' این محصول ' . $val . ' است.' );
	}

	/**
	 * اگر مقالهٔ منبع چند مشخصهٔ مهم را جا انداخته، یک پاراگراف تکمیلی بساز.
	 *
	 * @param string $article متن.
	 * @param string $name    نام.
	 * @param array  $specs   مشخصات.
	 * @param array  $keys    کلیدها.
	 * @return string
	 */
	public static function missing_facts_paragraph( $article, $name, $specs, $keys ) {
		$bag   = array_merge( (array) $keys, (array) $specs );
		$pairs = array(
			array( 'رم', array( 'مقدار رم', 'حافظه RAM', 'رم', 'مقدار RAM' ) ),
			array( 'حافظه داخلی', array( 'حافظه داخلی', 'ظرفیت حافظه' ) ),
			array( 'پردازنده', array( 'پردازنده', 'پردازنده مرکزی', 'تراشه' ) ),
			array( 'صفحه نمایش', array( 'اندازه صفحه نمایش' ) ),
			array( 'دوربین اصلی', array( 'دوربین اصلی', 'کیفیت دوربین اصلی' ) ),
			array( 'باتری', array( 'گنجایش باتری', 'ظرفیت باتری' ) ),
		);
		$miss  = array();
		foreach ( $pairs as $row ) {
			$val = self::spec( $bag, $row[1] );
			if ( '' === $val ) {
				continue;
			}
			if ( self::has( $article, $val ) ) {
				continue;
			}
			$miss[] = self::spec_sentence( $row[0], $val );
		}
		if ( count( $miss ) < 2 ) {
			return '';
		}
		$who = $name ? ( 'در تکمیل معرفی «' . $name . '»، ' ) : 'در تکمیل معرفی این محصول، ';
		return $who . implode( ' ', array_slice( $miss, 0, 5 ) );
	}

	/**
	 * خلاصه مشخصات برای تب ناظر — فقط دادهٔ واقعی.
	 *
	 * @param array $specs مشخصات.
	 * @param array $keys  کلیدها.
	 * @return string
	 */
	public static function spec_summary( $specs, $keys ) {
		$bag = array_merge( (array) $keys, (array) $specs );
		$out = array();
		$i   = 0;
		foreach ( $bag as $k => $v ) {
			$line = self::s( $k ) . ': ' . self::s( $v );
			if ( '' === $line || in_array( $line, $out, true ) ) {
				continue;
			}
			$out[] = '• ' . $line;
			if ( ++$i >= 10 ) {
				break;
			}
		}
		return $out ? implode( "\n", $out ) : '';
	}

	/**
	 * نکات برجسته از مشخصات واقعی.
	 *
	 * @param string $cat   دسته.
	 * @param array  $specs مشخصات.
	 * @param array  $keys  کلیدها.
	 * @return array
	 */
	public static function highlights( $cat, $specs, $keys ) {
		$out   = array();
		$pairs = array_merge( (array) $keys, (array) $specs );
		$pref  = array(
			'phone'     => array( 'برند', 'مدل', 'مقدار رم', 'حافظه RAM', 'مقدار RAM', 'حافظه داخلی', 'گنجایش باتری', 'ظرفیت باتری', 'دوربین اصلی', 'اندازه صفحه نمایش', 'سیستم عامل' ),
			'laptop'    => array( 'برند', 'پردازنده', 'پردازنده مرکزی', 'مقدار رم', 'حافظه RAM', 'حافظه داخلی', 'کارت گرافیک', 'اندازه صفحه نمایش' ),
			'tablet'    => array( 'برند', 'اندازه صفحه نمایش', 'مقدار رم', 'حافظه داخلی', 'گنجایش باتری' ),
			'headphone' => array( 'برند', 'نوع اتصال', 'عمر باتری', 'حذف نویز', 'درایور' ),
			'watch'     => array( 'برند', 'سازگاری', 'گنجایش باتری', 'مقاومت در برابر آب' ),
			'tv'        => array( 'برند', 'اندازه صفحه نمایش', 'کیفیت تصویر', 'سیستم عامل', 'نرخ نوسازی تصویر' ),
			'console'   => array( 'برند', 'مدل', 'حافظه داخلی', 'پردازنده', 'رنگ' ),
			'generic'   => array( 'برند', 'مدل', 'رنگ', 'وزن', 'ابعاد' ),
		);
		$order = isset( $pref[ $cat ] ) ? $pref[ $cat ] : $pref['generic'];
		foreach ( $order as $k ) {
			if ( empty( $pairs[ $k ] ) ) {
				continue;
			}
			$out[] = $k . ': ' . self::s( $pairs[ $k ] );
			if ( count( $out ) >= 6 ) {
				break;
			}
		}
		if ( count( $out ) < 4 ) {
			foreach ( $pairs as $k => $v ) {
				$line = $k . ': ' . self::s( $v );
				if ( in_array( $line, $out, true ) ) {
					continue;
				}
				$out[] = $line;
				if ( count( $out ) >= 6 ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * توضیح کوتاه HTML.
	 *
	 * @param string $name       نام.
	 * @param string $name2      انگلیسی.
	 * @param array  $keys       کلیدها.
	 * @param array  $highlights نکات.
	 * @return string
	 */
	public static function short_html( $name, $name2, $keys, $highlights ) {
		$html = '';
		if ( $name2 ) {
			$html .= '<p>' . esc_html( $name2 ) . '</p>';
		}
		if ( $name ) {
			$html .= '<p>' . esc_html( $name ) . '</p>';
		}
		$items = array();
		$i     = 0;
		foreach ( $keys as $k => $v ) {
			$items[] = '<li><strong>' . esc_html( $k ) . ':</strong> ' . esc_html( $v ) . '</li>';
			if ( ++$i >= 5 ) {
				break;
			}
		}
		if ( ! $items ) {
			foreach ( array_slice( $highlights, 0, 5 ) as $h ) {
				$items[] = '<li>' . esc_html( $h ) . '</li>';
			}
		}
		if ( $items ) {
			$html .= '<ul>' . implode( '', $items ) . '</ul>';
		}
		return $html;
	}

	/**
	 * سئو از نام و مشخصات واقعی.
	 *
	 * @param string $name  نام.
	 * @param string $name2 انگلیسی.
	 * @param string $brand برند.
	 * @param string $cat   دسته.
	 * @param array  $keys  کلیدها.
	 * @param array  $specs مشخصات.
	 * @return array
	 */
	public static function seo( $name, $name2, $brand, $cat, $keys, $specs ) {
		$label = self::category_label( $cat );
		$title = 'خرید ' . $name;
		if ( self::len( $title ) > 60 ) {
			$short_name = $brand ? ( $brand . ' ' . $label ) : $name;
			$title      = 'خرید ' . $short_name;
			if ( self::len( $title ) > 60 ) {
				$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 57, 'UTF-8' ) . '…' : ( substr( $title, 0, 57 ) . '…' );
			}
		}
		$bits = array();
		if ( $name2 ) {
			$bits[] = $name2;
		}
		if ( $brand ) {
			$bits[] = $brand;
		}
		$i = 0;
		foreach ( $keys as $k => $v ) {
			$bits[] = $k . ' ' . $v;
			if ( ++$i >= 3 ) {
				break;
			}
		}
		$desc = 'خرید ' . $name . ' با مشخصات کامل و تصاویر واقعی. ' . implode( ' | ', $bits );
		$tags = array();
		$seen = array();
		foreach ( array( $name, $name2, $brand, $label, 'خرید ' . $label ) as $c ) {
			$c = self::s( $c );
			if ( '' === $c || isset( $seen[ $c ] ) ) {
				continue;
			}
			$seen[ $c ] = true;
			$tags[]     = $c;
		}
		foreach ( array( 'برند', 'مدل', 'رنگ' ) as $k ) {
			if ( empty( $specs[ $k ] ) ) {
				continue;
			}
			$t = self::s( $specs[ $k ] );
			if ( $t && ! isset( $seen[ $t ] ) ) {
				$seen[ $t ] = true;
				$tags[]     = $t;
			}
		}
		$keyword = $brand ? ( 'خرید ' . $brand . ' ' . $label ) : ( 'خرید ' . $label );
		$clamped = self::clamp_seo( $title, $desc, $keyword, $name, $keys );
		return array(
			'title'       => $clamped['title'],
			'description' => $clamped['description'],
			'keyword'     => $clamped['keyword'],
			'tags'        => array_slice( $tags, 0, 12 ),
		);
	}

	/**
	 * طول سئو را سخت اعمال می‌کند.
	 *
	 * @param string $title   عنوان.
	 * @param string $desc    توضیح.
	 * @param string $keyword کلمه.
	 * @param string $name    نام محصول.
	 * @param array  $keys    مشخصات کلیدی.
	 * @return array
	 */
	public static function clamp_seo( $title, $desc, $keyword, $name, $keys = array() ) {
		$title   = self::s( $title );
		$desc    = self::s( $desc );
		$keyword = self::s( $keyword );
		$name    = self::s( $name );
		if ( 0 !== strpos( $title, 'خرید' ) ) {
			$title = 'خرید ' . ( $title ? $title : $name );
		}
		$tlen = self::len( $title );
		if ( $tlen < 50 ) {
			$title .= ' | مشخصات کامل';
			$tlen    = self::len( $title );
		}
		if ( $tlen > 60 ) {
			$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 57, 'UTF-8' ) . '…' : ( substr( $title, 0, 57 ) . '…' );
		}
		$bits = array();
		$i    = 0;
		foreach ( (array) $keys as $k => $v ) {
			$bits[] = self::s( $k ) . ' ' . self::s( $v );
			if ( ++$i >= 2 ) {
				break;
			}
		}
		if ( '' === $desc ) {
			$desc = 'خرید ' . $name . ' با مشخصات کامل و تصاویر واقعی.';
			if ( $bits ) {
				$desc .= ' ' . implode( ' | ', $bits );
			}
		}
		$dlen = self::len( $desc );
		if ( $dlen < 140 ) {
			$extra = $bits ? ( ' ' . implode( ' | ', $bits ) ) : '';
			$desc .= $extra . ' مشخصات را ببینید.';
			$dlen   = self::len( $desc );
		}
		if ( $dlen > 155 ) {
			$desc = function_exists( 'mb_substr' ) ? mb_substr( $desc, 0, 152, 'UTF-8' ) . '…' : ( substr( $desc, 0, 152 ) . '…' );
		}
		if ( '' === $keyword ) {
			$keyword = 'خرید ' . $name;
		}
		if ( 0 !== strpos( $keyword, 'خرید' ) ) {
			$keyword = 'خرید ' . $keyword;
		}
		$klen = self::len( $keyword );
		if ( $klen > 40 ) {
			$keyword = function_exists( 'mb_substr' ) ? mb_substr( $keyword, 0, 40, 'UTF-8' ) : substr( $keyword, 0, 40 );
		}
		return array(
			'title'       => $title,
			'description' => $desc,
			'keyword'     => $keyword,
		);
	}

	/**
	 * پرسش از مشخصات واقعی.
	 *
	 * @param string $cat   دسته.
	 * @param string $name  نام.
	 * @param array  $specs مشخصات.
	 * @param array  $keys  کلیدها.
	 * @return array
	 */
	public static function faq( $cat, $name, $specs, $keys ) {
		$out  = array();
		$bag  = array_merge( (array) $keys, (array) $specs );
		$pref = array(
			'حافظه داخلی'       => 'حافظه داخلی این محصول چقدر است؟',
			'مقدار رم'          => 'رم این محصول چقدر است؟',
			'حافظه RAM'         => 'رم این محصول چقدر است؟',
			'مقدار RAM'         => 'رم این محصول چقدر است؟',
			'گنجایش باتری'      => 'ظرفیت باتری چقدر اعلام شده؟',
			'ظرفیت باتری'       => 'ظرفیت باتری چقدر اعلام شده؟',
			'دوربین اصلی'       => 'دوربین اصلی چه مشخصه‌ای دارد؟',
			'اندازه صفحه نمایش' => 'اندازه نمایشگر چقدر است؟',
			'سیستم عامل'        => 'سیستم‌عامل چیست؟',
			'پردازنده'          => 'پردازنده چه مدلی است؟',
			'برند'              => 'برند سازنده چیست؟',
		);
		foreach ( $pref as $key => $q ) {
			if ( empty( $bag[ $key ] ) ) {
				continue;
			}
			$out[] = array(
				'q' => $q,
				'a' => self::s( $bag[ $key ] ) . ' — طبق مشخصات ثبت‌شده برای «' . $name . '».',
			);
			if ( count( $out ) >= 4 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * HTML ثابت صفحه محصول: معرفی و بررسی + جدول مشخصات.
	 *
	 * @param array  $data       داده.
	 * @param string $intro      معرفی و بررسی.
	 * @param array  $highlights نکات.
	 * @param string $analysis   همان معرفی (سازگاری).
	 * @param string $review     خلاصه مشخصات.
	 * @param string $audience   بلااستفاده.
	 * @param string $verdict    بلااستفاده.
	 * @param array  $faq        پرسش‌ها.
	 * @return string
	 */
	public static function assemble_html( $data, $intro, $highlights, $analysis, $review, $audience, $verdict, $faq = array() ) {
		$body = $intro ? $intro : $analysis;
		$html = '<div class="shoper-page" dir="rtl" lang="fa" style="max-width:860px;margin:0 auto;color:#3f4064;font-size:15px;line-height:2;">';

		$html .= '<section class="shoper-sec shoper-sec-review" style="margin:0 0 28px;">';
		$html .= '<h2 class="shoper-sec-title" style="font-size:18px;font-weight:700;color:#23254e;margin:0 0 16px;padding:0 0 10px;border-bottom:2px solid #ef394e;">معرفی و بررسی محصول</h2>';
		$html .= '<div class="shoper-prose" style="text-align:justify;">';
		$html .= self::paragraphs_html( $body );
		$html .= '</div></section>';

		if ( $highlights ) {
			$html .= '<section class="shoper-sec shoper-sec-highlights" style="margin:0 0 28px;background:#f7f7f8;border-radius:12px;padding:16px 18px;">';
			$html .= '<h2 class="shoper-sec-title" style="font-size:16px;font-weight:700;color:#23254e;margin:0 0 10px;border:0;padding:0;">نکات برجسته</h2>';
			$html .= '<ul style="margin:0;padding:0 18px 0 0;">';
			foreach ( $highlights as $h ) {
				$html .= '<li style="margin:0 0 6px;">' . esc_html( $h ) . '</li>';
			}
			$html .= '</ul></section>';
		}

		$has_keys   = ! empty( $data['key_specs'] ) && is_array( $data['key_specs'] );
		$has_groups = ! empty( $data['spec_groups'] ) && is_array( $data['spec_groups'] );
		$has_specs  = ! empty( $data['specs'] ) && is_array( $data['specs'] );
		if ( $has_keys || $has_groups || $has_specs ) {
			$html .= '<section class="shoper-sec shoper-sec-specs" style="margin:0 0 28px;">';
			$html .= '<h2 class="shoper-sec-title" style="font-size:18px;font-weight:700;color:#23254e;margin:0 0 16px;padding:0 0 10px;border-bottom:2px solid #19bfd3;">مشخصات فنی</h2>';
			if ( $has_keys ) {
				$html .= '<h3 style="font-size:14px;color:#62666d;margin:0 0 8px;">مشخصات کلیدی</h3>';
				$html .= self::table( $data['key_specs'] );
			}
			if ( $has_groups ) {
				foreach ( $data['spec_groups'] as $group ) {
					if ( empty( $group['specs'] ) ) {
						continue;
					}
					if ( ! empty( $group['header'] ) ) {
						$html .= '<h3 style="font-size:14px;color:#62666d;margin:18px 0 8px;">' . esc_html( $group['header'] ) . '</h3>';
					}
					$html .= self::table( $group['specs'] );
				}
			} elseif ( $has_specs ) {
				$html .= self::table( $data['specs'] );
			}
			$html .= '</section>';
		}

		if ( $faq && is_array( $faq ) ) {
			$html .= '<section class="shoper-sec shoper-sec-faq" style="margin:0 0 20px;">';
			$html .= '<h2 class="shoper-sec-title" style="font-size:18px;font-weight:700;color:#23254e;margin:0 0 16px;padding:0 0 10px;border-bottom:2px solid #e0e0e2;">پرسش‌های پرتکرار</h2>';
			foreach ( $faq as $item ) {
				if ( empty( $item['q'] ) || empty( $item['a'] ) ) {
					continue;
				}
				$html .= '<div style="margin:0 0 12px;padding:10px 12px;background:#fafafa;border-radius:8px;">';
				$html .= '<h3 style="font-size:14px;margin:0 0 4px;color:#23254e;">' . esc_html( $item['q'] ) . '</h3>';
				$html .= '<p style="margin:0;">' . esc_html( $item['a'] ) . '</p>';
				$html .= '</div>';
			}
			$html .= '</section>';
		}

		$src = 'کاتالوگ';
		if ( ! empty( $data['provider'] ) && 'digikala' === $data['provider'] ) {
			$src = 'دیجی‌کالا';
		} elseif ( ! empty( $data['page_url'] ) && false !== strpos( (string) $data['page_url'], 'torob' ) ) {
			$src = 'ترب';
		}
		$html .= '<p class="shoper-source" style="font-size:12px;color:#888;margin-top:20px;">';
		$html .= 'معرفی و مشخصات توسط <strong>Shoper Studio</strong> خواجوی آماده شده است. منبع: ' . esc_html( $src ) . '.';
		if ( ! empty( $data['page_url'] ) ) {
			$html .= ' <a href="' . esc_url( $data['page_url'] ) . '" target="_blank" rel="nofollow">صفحه منبع</a>.';
		}
		$html .= '</p></div>';
		return $html;
	}

	/**
	 * پاراگراف‌بندی امن متن ساده.
	 *
	 * @param string $text متن.
	 * @return string
	 */
	public static function paragraphs_html( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		$parts = preg_split( "/\n\s*\n/u", $text );
		$html  = '';
		foreach ( (array) $parts as $p ) {
			$p = trim( preg_replace( '/\s+/u', ' ', $p ) );
			if ( '' === $p ) {
				continue;
			}
			$html .= '<p style="margin:0 0 14px;">' . esc_html( $p ) . '</p>';
		}
		return $html;
	}

	/**
	 * جدول مشخصات.
	 *
	 * @param array $pairs زوج‌ها.
	 * @return string
	 */
	private static function table( $pairs ) {
		if ( empty( $pairs ) || ! is_array( $pairs ) ) {
			return '';
		}
		$html = '<table class="shoper-specs-table" style="width:100%;border-collapse:collapse;margin:0 0 12px;"><tbody>';
		$i    = 0;
		foreach ( $pairs as $k => $v ) {
			$bg    = ( 0 === $i % 2 ) ? '#f7f7f8' : '#fff';
			$html .= '<tr style="background:' . esc_attr( $bg ) . ';">';
			$html .= '<th style="width:38%;text-align:right;padding:10px 14px;border:1px solid #f0f0f1;vertical-align:top;color:#62666d;font-weight:500;">' . esc_html( $k ) . '</th>';
			$html .= '<td style="padding:10px 14px;border:1px solid #f0f0f1;color:#23254e;">' . esc_html( $v ) . '</td>';
			$html .= '</tr>';
			$i++;
		}
		$html .= '</tbody></table>';
		return $html;
	}
}
