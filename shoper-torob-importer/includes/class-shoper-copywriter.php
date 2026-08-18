<?php
/**
 * ویراستار متن محصول — مرتب‌سازی منبع، نه تولید محتوا.
 *
 * متن دیجی‌کالا/ترب را تمیز و پاراگراف‌بندی می‌کند.
 * اگر ناقص باشد فقط از مشخصات واقعی همان کالا کاملش می‌کند.
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
	 * مرتب‌سازی محصول برای فروشگاه.
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

		$body       = self::organize( $source, $name, $name2, $brand, $specs, $keys );
		$highlights = self::highlights( $cat, $specs, $keys );
		$summary    = self::spec_summary( $specs, $keys );
		$faq        = self::faq( $cat, $name, $specs, $keys );
		$short      = self::short_html( $name, $name2, $keys, $highlights );
		$html       = self::assemble_html( $data, $body, $highlights, $body, $summary, '', '', $faq );
		$seo        = self::seo( $name, $name2, $brand, $cat, $keys, $specs );

		return array(
			'title'             => $name,
			'short_description' => $short,
			'description_html'  => $html,
			'analysis'          => $body,
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
			'provider_label'    => 'ویرایش منبع — استودیو خواجوی',
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
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $source, 'UTF-8' ) > 6000 ) {
			$source = mb_substr( $source, 0, 5980, 'UTF-8' ) . '…';
		} elseif ( strlen( $source ) > 7000 ) {
			$source = substr( $source, 0, 6980 ) . '…';
		}
		return $source;
	}

	/**
	 * متن منبع را مرتب و در صورت نقص از مشخصات کامل می‌کند.
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
		$body = self::polish_source( $source );
		if ( '' === $body ) {
			$bits = array();
			if ( $name ) {
				$bits[] = $name;
			}
			if ( $name2 ) {
				$bits[] = $name2;
			}
			if ( $brand ) {
				$bits[] = 'برند ' . $brand;
			}
			$body = $bits ? implode( '. ', $bits ) . '.' : '';
		}

		$facts = array();
		$pairs = array(
			'رم'            => self::spec( $specs, array( 'مقدار رم', 'حافظه RAM', 'رم', 'مقدار RAM' ) ),
			'حافظه داخلی'   => self::spec( $specs, array( 'حافظه داخلی', 'ظرفیت حافظه' ) ),
			'باتری'         => self::spec( $specs, array( 'گنجایش باتری', 'ظرفیت باتری' ) ),
			'دوربین اصلی'   => self::spec( $specs, array( 'دوربین اصلی', 'کیفیت دوربین اصلی' ) ),
			'صفحه نمایش'    => self::spec( $specs, array( 'اندازه صفحه نمایش' ) ),
			'پردازنده'      => self::spec( $specs, array( 'پردازنده', 'پردازنده مرکزی', 'تراشه' ) ),
		);
		$hay = function_exists( 'mb_strtolower' ) ? mb_strtolower( $body, 'UTF-8' ) : strtolower( $body );
		foreach ( $pairs as $label => $val ) {
			if ( '' === $val ) {
				continue;
			}
			$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $val, 'UTF-8' ) : strtolower( $val );
			if ( $needle && false !== strpos( $hay, $needle ) ) {
				continue;
			}
			$facts[] = $label . ' ' . $val;
		}
		if ( $facts ) {
			$body .= "\n\n" . 'طبق مشخصات ثبت‌شده همین کالا: ' . implode( '؛ ', array_slice( $facts, 0, 6 ) ) . '.';
		}
		return trim( $body );
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
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $title, 'UTF-8' ) > 60 ) {
			$short_name = $brand ? ( $brand . ' ' . $label ) : $name;
			$title      = 'خرید ' . $short_name;
			if ( mb_strlen( $title, 'UTF-8' ) > 60 ) {
				$title = mb_substr( $title, 0, 57, 'UTF-8' ) . '…';
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
		$tlen = function_exists( 'mb_strlen' ) ? mb_strlen( $title, 'UTF-8' ) : strlen( $title );
		if ( $tlen < 50 ) {
			$title .= ' | مشخصات کامل';
			$tlen    = function_exists( 'mb_strlen' ) ? mb_strlen( $title, 'UTF-8' ) : strlen( $title );
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
		$dlen = function_exists( 'mb_strlen' ) ? mb_strlen( $desc, 'UTF-8' ) : strlen( $desc );
		if ( $dlen < 140 ) {
			$extra = $bits ? ( ' ' . implode( ' | ', $bits ) ) : '';
			$desc .= $extra . ' مشخصات را ببینید.';
			$dlen   = function_exists( 'mb_strlen' ) ? mb_strlen( $desc, 'UTF-8' ) : strlen( $desc );
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
		$klen = function_exists( 'mb_strlen' ) ? mb_strlen( $keyword, 'UTF-8' ) : strlen( $keyword );
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
	 * مونتاژ HTML نهایی: متن مرتب + جدول مشخصات.
	 *
	 * @param array  $data       داده.
	 * @param string $intro      متن مرتب.
	 * @param array  $highlights نکات.
	 * @param string $analysis   همان متن (سازگاری).
	 * @param string $review     خلاصه مشخصات.
	 * @param string $audience   بلااستفاده.
	 * @param string $verdict    بلااستفاده.
	 * @param array  $faq        پرسش‌ها.
	 * @return string
	 */
	public static function assemble_html( $data, $intro, $highlights, $analysis, $review, $audience, $verdict, $faq = array() ) {
		$body  = $intro ? $intro : $analysis;
		$html  = '<div class="shoper-studio-copy">';
		$html .= '<h2>معرفی محصول</h2>';
		$html .= wpautop( esc_html( $body ) );

		if ( $highlights ) {
			$html .= '<h2>نکات برجسته</h2><ul>';
			foreach ( $highlights as $h ) {
				$html .= '<li>' . esc_html( $h ) . '</li>';
			}
			$html .= '</ul>';
		}

		if ( ! empty( $data['key_specs'] ) && is_array( $data['key_specs'] ) ) {
			$html .= '<h2>مشخصات کلیدی</h2>';
			$html .= self::table( $data['key_specs'] );
		}
		if ( ! empty( $data['spec_groups'] ) && is_array( $data['spec_groups'] ) ) {
			$html .= '<h2>مشخصات فنی کامل</h2>';
			foreach ( $data['spec_groups'] as $group ) {
				if ( empty( $group['specs'] ) ) {
					continue;
				}
				if ( ! empty( $group['header'] ) ) {
					$html .= '<h3>' . esc_html( $group['header'] ) . '</h3>';
				}
				$html .= self::table( $group['specs'] );
			}
		} elseif ( ! empty( $data['specs'] ) && is_array( $data['specs'] ) ) {
			$html .= '<h2>مشخصات فنی کامل</h2>';
			$html .= self::table( $data['specs'] );
		}

		if ( $faq && is_array( $faq ) ) {
			$html .= '<h2>پرسش‌های پرتکرار</h2>';
			foreach ( $faq as $item ) {
				if ( empty( $item['q'] ) || empty( $item['a'] ) ) {
					continue;
				}
				$html .= '<h3>' . esc_html( $item['q'] ) . '</h3>';
				$html .= '<p>' . esc_html( $item['a'] ) . '</p>';
			}
		}

		$src = 'کاتالوگ';
		if ( ! empty( $data['provider'] ) && 'digikala' === $data['provider'] ) {
			$src = 'دیجی‌کالا';
		} elseif ( ! empty( $data['page_url'] ) && false !== strpos( (string) $data['page_url'], 'torob' ) ) {
			$src = 'ترب';
		}
		$html .= '<p class="shoper-source" style="font-size:12px;color:#888;margin-top:20px;">';
		$html .= 'متن از منبع مرتب شده است — <strong>Shoper Studio</strong> خواجوی. منبع مشخصات: ' . esc_html( $src ) . '.';
		if ( ! empty( $data['page_url'] ) ) {
			$html .= ' <a href="' . esc_url( $data['page_url'] ) . '" target="_blank" rel="nofollow">صفحه منبع</a>.';
		}
		$html .= '</p></div>';
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
		$html = '<table class="shoper-specs-table" style="width:100%;border-collapse:collapse;margin:12px 0;"><tbody>';
		$i    = 0;
		foreach ( $pairs as $k => $v ) {
			$bg    = ( 0 === $i % 2 ) ? '#f8f8f8' : '#fff';
			$html .= '<tr style="background:' . esc_attr( $bg ) . ';">';
			$html .= '<th style="width:38%;text-align:right;padding:8px 12px;border:1px solid #eee;vertical-align:top;">' . esc_html( $k ) . '</th>';
			$html .= '<td style="padding:8px 12px;border:1px solid #eee;">' . esc_html( $v ) . '</td>';
			$html .= '</tr>';
			$i++;
		}
		$html .= '</tbody></table>';
		return $html;
	}
}
