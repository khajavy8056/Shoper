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
		$pros       = self::analysis_pros( $cat, $specs, $keys );
		$cons       = self::analysis_cons( $cat, $specs, $keys );
		$analysis   = self::analysis_text( $cat, $name, $specs, $keys, $pros, $cons );
		$verdict    = self::verdict_text( $cat, $name, $brand, $specs, $keys );
		$short      = self::short_html( $name, $name2, $keys, $highlights );
		$html       = self::assemble_html( $data, $article, $highlights, $analysis, $summary, '', $verdict, $faq, $pros, $cons );
		$seo        = self::seo( $name, $name2, $brand, $cat, $keys, $specs );

		return array(
			'title'             => $name,
			'short_description' => $short,
			'description_html'  => $html,
			'analysis'          => $analysis ? $analysis : $article,
			'review'            => $summary,
			'highlights'        => $highlights,
			'pros'              => $pros,
			'cons'              => $cons,
			'audience'          => '',
			'verdict'           => $verdict,
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
	 * عمق محتوا بر اساس دسته و تعداد مشخصات.
	 *
	 * @param string $cat   دسته.
	 * @param array  $specs مشخصات.
	 * @return string full|medium|light
	 */
	public static function content_depth( $cat, $specs ) {
		$count = is_array( $specs ) ? count( $specs ) : 0;
		$rich  = array( 'phone', 'laptop', 'tablet', 'console', 'tv', 'watch' );
		if ( in_array( $cat, $rich, true ) && $count >= 8 ) {
			return 'full';
		}
		if ( $count >= 5 ) {
			return 'medium';
		}
		return 'light';
	}

	/**
	 * مزایای واقعی از مشخصات.
	 *
	 * @param string $cat   دسته.
	 * @param array  $specs مشخصات.
	 * @param array  $keys  کلیدها.
	 * @return array
	 */
	public static function analysis_pros( $cat, $specs, $keys ) {
		$bag  = array_merge( (array) $keys, (array) $specs );
		$out  = array();
		$ram  = self::spec( $bag, array( 'مقدار رم', 'حافظه RAM', 'رم', 'مقدار RAM' ) );
		$stor = self::spec( $bag, array( 'حافظه داخلی', 'ظرفیت حافظه' ) );
		$cpu  = self::spec( $bag, array( 'پردازنده', 'پردازنده مرکزی', 'تراشه' ) );
		$scr  = self::spec( $bag, array( 'اندازه صفحه نمایش' ) );
		$type = self::spec( $bag, array( 'نوع صفحه نمایش' ) );
		$hz   = self::spec( $bag, array( 'نرخ نوسازی تصویر' ) );
		$cam  = self::spec( $bag, array( 'دوربین اصلی', 'کیفیت دوربین اصلی' ) );
		$bat  = self::spec( $bag, array( 'گنجایش باتری', 'ظرفیت باتری' ) );
		$ip   = self::spec( $bag, array( 'گواهی ضدآب' ) );
		$gpu  = self::spec( $bag, array( 'کارت گرافیک' ) );
		if ( $cpu ) {
			$out[] = 'پردازنده ' . $cpu . ' عملکرد روزمره را روان نگه می‌دارد';
		}
		if ( $ram ) {
			$out[] = 'رم ' . $ram . ' برای چندکارگی هم‌زمان مناسب است';
		}
		if ( $stor ) {
			$out[] = 'حافظه داخلی ' . $stor . ' فضای نصب برنامه و فایل را پوشش می‌دهد';
		}
		if ( $scr ) {
			$line = 'نمایشگر ' . $scr;
			if ( $type ) {
				$line .= ' از نوع ' . $type;
			}
			if ( $hz ) {
				$line .= ' با نرخ نوسازی ' . $hz;
			}
			$out[] = $line;
		} elseif ( $hz ) {
			$out[] = 'نرخ نوسازی ' . $hz . ' تصویر روان‌تری می‌سازد';
		}
		if ( $cam ) {
			$out[] = 'دوربین اصلی ' . $cam . ' ثبت تصویر روزمره را پوشش می‌دهد';
		}
		if ( $bat ) {
			$out[] = 'باتری ' . $bat . ' برای استفاده روزانه در نظر گرفته شده است';
		}
		if ( $ip ) {
			$out[] = 'گواهی ' . $ip . ' مقاومت در برابر آب و گردوغبار را نشان می‌دهد';
		}
		if ( $gpu ) {
			$out[] = 'کارت گرافیک ' . $gpu;
		}
		if ( count( $out ) < 3 ) {
			foreach ( array_slice( $bag, 0, 8 ) as $k => $v ) {
				$line = self::s( $k ) . ': ' . self::s( $v );
				if ( $line && ! in_array( $line, $out, true ) ) {
					$out[] = $line;
				}
				if ( count( $out ) >= 4 ) {
					break;
				}
			}
		}
		return array_slice( $out, 0, 6 );
	}

	/**
	 * محدودیت محتمل فقط اگر از مشخصات واقعی خوانده شود.
	 *
	 * @param string $cat   دسته.
	 * @param array  $specs مشخصات.
	 * @param array  $keys  کلیدها.
	 * @return array
	 */
	public static function analysis_cons( $cat, $specs, $keys ) {
		$bag  = array_merge( (array) $keys, (array) $specs );
		$out  = array();
		$w    = self::spec( $bag, array( 'وزن' ) );
		$stor = self::spec( $bag, array( 'حافظه داخلی', 'ظرفیت حافظه' ) );
		if ( $w && preg_match( '/(\d{2,4})/', $w, $m ) ) {
			$grams = (int) $m[1];
			if ( in_array( $cat, array( 'phone', 'tablet' ), true ) && $grams >= 210 ) {
				$out[] = 'وزن ' . $w . ' ممکن است برای استفاده طولانی کمی سنگین حس شود';
			}
		}
		if ( $stor && preg_match( '/\b(16|32|64)\b/', $stor ) ) {
			$out[] = 'حافظه داخلی ' . $stor . ' برای آرشیو سنگین ممکن است محدود باشد';
		}
		foreach ( $bag as $k => $v ) {
			$v = self::s( $v );
			if ( in_array( $v, array( 'ندارد', 'خیر', 'پشتیبانی نمی‌شود' ), true ) ) {
				$out[] = self::s( $k ) . ' در مشخصات این مدل «' . $v . '» ثبت شده است';
			}
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		return array_values( array_unique( array_slice( $out, 0, 3 ) ) );
	}

	/**
	 * متن تحلیل فنی.
	 *
	 * @param string $cat   دسته.
	 * @param string $name  نام.
	 * @param array  $specs مشخصات.
	 * @param array  $keys  کلیدها.
	 * @param array  $pros  مزایا.
	 * @param array  $cons  معایب.
	 * @return string
	 */
	public static function analysis_text( $cat, $name, $specs, $keys, $pros, $cons ) {
		$depth = self::content_depth( $cat, $specs );
		if ( 'light' === $depth ) {
			return '';
		}
		$who  = $name ? ( '«' . $name . '»' ) : 'این محصول';
		$text = 'تحلیل ' . $who . ' فقط از روی مشخصات ثبت‌شده همین کالا انجام شده است. ';
		if ( $pros ) {
			$text .= 'نقاط قوت از عددها و امکانات واقعی خوانده می‌شود، نه از شعار تبلیغاتی. ';
		}
		if ( $cons ) {
			$text .= 'محدودیت‌های احتمالی هم فقط جایی آمده که در جدول مشخصات نشانه دارد.';
		} else {
			$text .= 'در جدول مشخصات محدودیت واضحی دیده نشد.';
		}
		return trim( $text );
	}

	/**
	 * نتیجه‌گیری نامحسوس برای تصمیم خرید.
	 *
	 * @param string $cat   دسته.
	 * @param string $name  نام.
	 * @param string $brand برند.
	 * @param array  $specs مشخصات.
	 * @param array  $keys  کلیدها.
	 * @return string
	 */
	public static function verdict_text( $cat, $name, $brand, $specs, $keys ) {
		$depth = self::content_depth( $cat, $specs );
		$who   = $name ? $name : 'این محصول';
		$label = self::category_label( $cat );
		if ( 'light' === $depth ) {
			return $who . ' با مشخصات ثبت‌شده در همین صفحه معرفی شده است. اگر این مشخصات با نیازتان جور است، خرید آن می‌تواند انتخاب ساده‌ای باشد.';
		}
		$bits = array();
		$ram  = self::spec( array_merge( (array) $keys, (array) $specs ), array( 'مقدار رم', 'حافظه RAM', 'رم', 'مقدار RAM' ) );
		$cam  = self::spec( array_merge( (array) $keys, (array) $specs ), array( 'دوربین اصلی', 'کیفیت دوربین اصلی' ) );
		$bat  = self::spec( array_merge( (array) $keys, (array) $specs ), array( 'گنجایش باتری', 'ظرفیت باتری' ) );
		if ( $ram ) {
			$bits[] = 'رم ' . $ram;
		}
		if ( $cam ) {
			$bits[] = 'دوربین ' . $cam;
		}
		if ( $bat ) {
			$bits[] = 'باتری ' . $bat;
		}
		$text = $who . ' یک ' . $label;
		if ( $brand ) {
			$text .= ' از برند ' . $brand;
		}
		$text .= ' است.';
		if ( $bits ) {
			$text .= ' ترکیب ' . implode( '، ', $bits ) . ' روی کاغذ برای کار روزمره منطقی به نظر می‌رسد.';
		}
		$text .= ' اگر همین مشخصات با نیازتان هم‌خوان است، همین صفحه برای تصمیم خرید کافی است.';
		return $text;
	}

	/**
	 * HTML ثابت صفحه محصول به قالب نقد و بررسی تخصصی.
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
	public static function assemble_html( $data, $intro, $highlights, $analysis, $review, $audience, $verdict, $faq = array(), $pros = array(), $cons = array() ) {
		$name  = self::s( isset( $data['name1'] ) ? $data['name1'] : '' );
		$name2 = self::s( isset( $data['name2'] ) ? $data['name2'] : '' );
		$brand = self::spec( isset( $data['specs'] ) ? $data['specs'] : array(), array( 'برند', 'سازنده', 'Brand' ) );
		$title = $name2 ? $name2 : $name;
		$body  = $intro ? $intro : $analysis;
		if ( empty( $pros ) || ! is_array( $pros ) ) {
			$pros = $highlights;
		}

		$html  = '<article class="product-description-wrapper" dir="rtl" lang="fa" style="direction:rtl;text-align:right;font-family:Tahoma,Arial,sans-serif;color:#263238;line-height:2;max-width:100%;">';
		$html .= '<header class="product-description-header" style="border-bottom:1px solid #e5e7eb;margin-bottom:22px;padding-bottom:14px;">';
		if ( $brand ) {
			$html .= '<p class="product-description-brand" style="color:#64748b;font-size:13px;margin:0;">برند: ' . esc_html( $brand ) . '</p>';
		}
		$html .= '<h2 style="color:#111827;font-size:24px;line-height:1.7;margin:4px 0 0;">نقد و بررسی تخصصی ' . esc_html( $title ? $title : $name ) . '</h2>';
		$html .= '</header>';

		$html .= '<section class="product-description-section product-overview" style="background:#f8fafc;border:1px solid #e5e7eb;border-right:5px solid #2563eb;border-radius:12px;margin:0 0 20px;padding:20px;" aria-labelledby="overview-title">';
		$html .= '<h3 id="overview-title" style="color:#111827;font-size:19px;line-height:1.8;margin:0 0 12px;">معرفی و بررسی محصول</h3>';
		$html .= self::paragraphs_html( $body );
		$html .= '</section>';

		if ( $highlights ) {
			$html .= '<section class="product-description-section product-highlights" style="background:#f0fdf4;border:1px solid #e5e7eb;border-right:5px solid #16a34a;border-radius:12px;margin:0 0 20px;padding:20px;" aria-labelledby="highlights-title">';
			$html .= '<h3 id="highlights-title" style="color:#111827;font-size:19px;line-height:1.8;margin:0 0 12px;">ویژگی‌های برجسته</h3>';
			$html .= '<ul style="margin:0;padding:0 22px 0 0;">';
			foreach ( $highlights as $h ) {
				$html .= '<li>' . esc_html( $h ) . '</li>';
			}
			$html .= '</ul></section>';
		}

		$pairs = array();
		if ( ! empty( $data['key_specs'] ) && is_array( $data['key_specs'] ) ) {
			$pairs = $data['key_specs'];
		}
		if ( ! empty( $data['specs'] ) && is_array( $data['specs'] ) ) {
			foreach ( $data['specs'] as $k => $v ) {
				if ( ! isset( $pairs[ $k ] ) ) {
					$pairs[ $k ] = $v;
				}
			}
		}
		if ( $pairs ) {
			$html .= '<section class="product-description-section product-specifications" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin:0 0 20px;overflow:hidden;padding:20px;" aria-labelledby="specifications-title">';
			$html .= '<h3 id="specifications-title" style="color:#111827;font-size:19px;line-height:1.8;margin:0 0 12px;">مشخصات فنی کامل</h3>';
			$html .= self::table( $pairs, $title ? $title : $name );
			$html .= '</section>';
		}

		if ( $analysis || $pros || $cons ) {
			$html .= '<section class="product-description-section product-analysis" style="background:#fffbeb;border:1px solid #e5e7eb;border-right:5px solid #d97706;border-radius:12px;margin:0 0 20px;padding:20px;" aria-labelledby="analysis-title">';
			$html .= '<h3 id="analysis-title" style="color:#111827;font-size:19px;line-height:1.8;margin:0 0 12px;">تحلیل و آنالیز فنی</h3>';
			if ( $analysis ) {
				$html .= self::paragraphs_html( $analysis );
			}
			$html .= '<div class="product-analysis-columns" style="display:block;">';
			if ( $pros ) {
				$html .= '<div class="product-analysis-column product-pros" style="background:#ecfdf5;border-radius:9px;color:#166534;padding:14px;margin-bottom:14px;">';
				$html .= '<h4 style="font-size:16px;margin:0 0 8px;">مزایا</h4><ul style="margin:0;padding:0 22px 0 0;">';
				foreach ( $pros as $p ) {
					$html .= '<li>' . esc_html( $p ) . '</li>';
				}
				$html .= '</ul></div>';
			}
			if ( $cons ) {
				$html .= '<div class="product-analysis-column product-cons" style="background:#fef2f2;border-radius:9px;color:#991b1b;padding:14px;">';
				$html .= '<h4 style="font-size:16px;margin:0 0 8px;">معایب احتمالی</h4><ul style="margin:0;padding:0 22px 0 0;">';
				foreach ( $cons as $c ) {
					$html .= '<li>' . esc_html( $c ) . '</li>';
				}
				$html .= '</ul></div>';
			}
			$html .= '</div></section>';
		}

		if ( $verdict ) {
			$html .= '<section class="product-description-section product-verdict" style="background:#eff6ff;border:1px solid #e5e7eb;border-right:5px solid #4f46e5;border-radius:12px;margin:0 0 20px;padding:20px;" aria-labelledby="verdict-title">';
			$html .= '<h3 id="verdict-title" style="color:#111827;font-size:19px;line-height:1.8;margin:0 0 12px;">نتیجه‌گیری و پیشنهاد خرید</h3>';
			$html .= self::paragraphs_html( $verdict );
			$html .= '</section>';
		}

		$html .= '</article>';
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
	private static function table( $pairs, $caption = '' ) {
		if ( empty( $pairs ) || ! is_array( $pairs ) ) {
			return '';
		}
		$html  = '<div class="product-table-wrap" style="overflow-x:auto;">';
		$html .= '<table class="product-specs-table" style="border-collapse:collapse;min-width:620px;width:100%;">';
		if ( $caption ) {
			$html .= '<caption style="color:#64748b;font-size:13px;padding:0 0 10px;text-align:right;">جدول مشخصات فنی ' . esc_html( $caption ) . '</caption>';
		}
		$html .= '<thead><tr>';
		$html .= '<th style="background:#e8f1ff;border:1px solid #dbe3ea;color:#173b73;padding:11px 13px;text-align:right;" scope="col">مشخصه</th>';
		$html .= '<th style="background:#e8f1ff;border:1px solid #dbe3ea;color:#173b73;padding:11px 13px;text-align:right;" scope="col">مقدار</th>';
		$html .= '</tr></thead><tbody>';
		foreach ( $pairs as $k => $v ) {
			$html .= '<tr>';
			$html .= '<th style="background:#f8fafc;border:1px solid #dbe3ea;color:#334155;font-weight:bold;padding:11px 13px;text-align:right;vertical-align:top;width:31%;" scope="row">' . esc_html( $k ) . '</th>';
			$html .= '<td style="border:1px solid #dbe3ea;padding:11px 13px;text-align:right;vertical-align:top;">' . esc_html( $v ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div>';
		return $html;
	}
}
