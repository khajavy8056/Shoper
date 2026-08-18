<?php
/**
 * استودیوی نویسندگی تجاری خواجوی.
 *
 * بدون کلید خارجی کار می‌کند. متن را فقط از دادهٔ واقعی محصول می‌سازد
 * و هیچ مشخصه یا نظر جعلی اختراع نمی‌کند.
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
	 * بازنویسی کامل محصول برای فروشگاه.
	 *
	 * @param array $data دادهٔ نرمال محصول.
	 * @return array
	 */
	public static function enhance( $data ) {
		$data     = is_array( $data ) ? $data : array();
		$name     = self::s( isset( $data['name1'] ) ? $data['name1'] : '' );
		$name2    = self::s( isset( $data['name2'] ) ? $data['name2'] : '' );
		$specs    = ( isset( $data['specs'] ) && is_array( $data['specs'] ) ) ? $data['specs'] : array();
		$keys     = ( isset( $data['key_specs'] ) && is_array( $data['key_specs'] ) ) ? $data['key_specs'] : array();
		$source   = self::s( isset( $data['description'] ) ? $data['description'] : '' );
		$cat      = self::detect_category( $name . ' ' . $name2, $specs );
		$brand    = self::spec( $specs, array( 'برند', 'سازنده', 'Brand' ) );
		$highlights = self::highlights( $cat, $specs, $keys );
		$analysis = self::analysis( $cat, $name, $specs, $keys, $source );
		$review   = self::review( $cat, $name, $specs, $keys );
		$intro    = self::intro( $cat, $name, $name2, $brand, $source, $specs );
		$audience = self::audience( $cat, $name, $specs );
		$verdict  = self::verdict( $cat, $name, $highlights );
		$faq      = self::faq( $cat, $name, $specs, $keys );
		$short    = self::short_html( $name, $name2, $keys, $highlights );
		$html     = self::assemble_html( $data, $intro, $highlights, $analysis, $review, $audience, $verdict, $faq );
		$seo      = self::seo( $name, $name2, $brand, $cat, $keys, $specs );

		return array(
			'title'              => $name,
			'short_description'  => $short,
			'description_html'   => $html,
			'analysis'           => $analysis,
			'review'             => $review,
			'highlights'         => $highlights,
			'audience'           => $audience,
			'verdict'            => $verdict,
			'faq'                => $faq,
			'seo_title'          => $seo['title'],
			'seo_desc'           => $seo['description'],
			'focus_keyword'      => $seo['keyword'],
			'tags'               => $seo['tags'],
			'provider'           => 'studio',
			'provider_label'     => 'استودیوی نویسندگی خواجوی',
			'category'           => $cat,
		);
	}

	/**
	 * برش امن رشته.
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
	 * تشخیص دسته از نام و مشخصات.
	 *
	 * @param string $hay  متن.
	 * @param array  $specs مشخصات.
	 * @return string
	 */
	public static function detect_category( $hay, $specs ) {
		$hay = mb_strtolower( (string) $hay, 'UTF-8' );
		$map = array(
			'phone'     => array( 'گوشی', 'موبایل', 'smartphone', 'galaxy', 'iphone', 'redmi', 'poco', 'xiaomi', 'سامسونگ', 'شیائومی' ),
			'laptop'    => array( 'لپ تاپ', 'لپ‌تاپ', 'لپتاپ', 'macbook', 'notebook', 'لپ تاپ' ),
			'tablet'    => array( 'تبلت', 'ipad', 'tablet' ),
			'headphone' => array( 'هدفون', 'هندزفری', 'ایرپاد', 'earbuds', 'headset' ),
			'watch'     => array( 'ساعت هوشمند', 'smartwatch', 'watch' ),
			'tv'        => array( 'تلویزیون', 'tv ', 'smart tv' ),
		);
		foreach ( $map as $cat => $needles ) {
			foreach ( $needles as $n ) {
				if ( false !== mb_strpos( $hay, $n, 0, 'UTF-8' ) ) {
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
	 * معرفی تجاری.
	 *
	 * @param string $cat   دسته.
	 * @param string $name  نام.
	 * @param string $name2 انگلیسی.
	 * @param string $brand برند.
	 * @param string $source توضیح منبع.
	 * @param array  $specs مشخصات.
	 * @return string
	 */
	public static function intro( $cat, $name, $name2, $brand, $source, $specs ) {
		$label = self::category_label( $cat );
		$parts = array();
		$lead  = 'خرید ' . $name . ' یعنی انتخاب یک ' . $label;
		if ( $brand ) {
			$lead .= ' از برند ' . $brand;
		}
		$lead .= ' با مشخصات ثبت‌شده و قابل استناد برای خریدار ایرانی در سال ۲۰۲۶.';
		$parts[] = $lead;
		if ( $name2 ) {
			$parts[] = 'شناسهٔ بین‌المللی محصول: ' . $name2 . '.';
		}
		if ( $source ) {
			$parts[] = self::polish_source( $source );
		} else {
			$parts[] = 'در ادامه، تحلیل کارشناسی، بررسی نقاط قوت و جدول مشخصات فنی همین کالا آمده است تا تصمیم خرید بدون حدس و گمان باشد.';
		}
		$ram = self::spec( $specs, array( 'مقدار رم', 'حافظه RAM', 'رم' ) );
		$storage = self::spec( $specs, array( 'حافظه داخلی', 'ظرفیت حافظه', 'حافظه' ) );
		if ( $ram || $storage ) {
			$bits = array();
			if ( $ram ) {
				$bits[] = 'رم ' . $ram;
			}
			if ( $storage ) {
				$bits[] = 'حافظه ' . $storage;
			}
			$parts[] = 'پیکربندی اعلام‌شده شامل ' . implode( ' و ', $bits ) . ' است.';
		}
		return implode( ' ', $parts );
	}

	/**
	 * پاکسازی توضیح منبع بدون اضافه کردن ادعا.
	 *
	 * @param string $source متن.
	 * @return string
	 */
	public static function polish_source( $source ) {
		$source = self::s( $source );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $source, 'UTF-8' ) > 1800 ) {
			$source = mb_substr( $source, 0, 1780, 'UTF-8' ) . '…';
		} elseif ( strlen( $source ) > 2200 ) {
			$source = substr( $source, 0, 2180 ) . '…';
		}
		return $source;
	}

	/**
	 * نکات برجسته از مشخصات واقعی.
	 *
	 * @param string $cat  دسته.
	 * @param array  $specs مشخصات.
	 * @param array  $keys کلیدها.
	 * @return array
	 */
	public static function highlights( $cat, $specs, $keys ) {
		$out   = array();
		$pairs = array_merge( (array) $keys, (array) $specs );
		$pref  = array(
			'phone'     => array( 'برند', 'مدل', 'مقدار رم', 'حافظه RAM', 'حافظه داخلی', 'گنجایش باتری', 'ظرفیت باتری', 'دوربین اصلی', 'اندازه صفحه نمایش', 'سیستم عامل' ),
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
	 * تحلیل کارشناسی بر اساس مشخصات واقعی.
	 *
	 * @param string $cat    دسته.
	 * @param string $name   نام.
	 * @param array  $specs  مشخصات.
	 * @param array  $keys   کلیدها.
	 * @param string $source توضیح منبع.
	 * @return string
	 */
	public static function analysis( $cat, $name, $specs, $keys, $source ) {
		$paras = array();
		$paras[] = 'تحلیل زیر فقط از مشخصات اعلام‌شده برای «' . $name . '» استخراج شده است؛ هیچ عدد یا قابلیتی خارج از همین داده اضافه نشده.';

		if ( 'phone' === $cat ) {
			$paras[] = self::phone_analysis( $specs );
		} elseif ( 'laptop' === $cat ) {
			$paras[] = self::named_analysis(
				$specs,
				array(
					array( array( 'پردازنده', 'پردازنده مرکزی' ), 'پردازندهٔ اعلام‌شده «%s» بار اصلی اجرای برنامه‌ها را مشخص می‌کند.' ),
					array( array( 'مقدار رم', 'حافظه RAM' ), 'رم «%s» برای چندوظیفگی روزمره معیار مهمی است.' ),
					array( array( 'حافظه داخلی' ), 'فضای ذخیره‌سازی «%s» سقف نگهداری فایل و نرم‌افزار را نشان می‌دهد.' ),
					array( array( 'کارت گرافیک', 'پردازنده گرافیکی' ), 'گرافیک «%s» توان نمایش و کارهای تصویری را تعیین می‌کند.' ),
				)
			);
		} else {
			$paras[] = self::generic_analysis( $specs, $keys );
		}

		$os = self::spec( $specs, array( 'سیستم عامل', 'نسخه سیستم عامل' ) );
		if ( $os ) {
			$paras[] = 'سیستم‌عامل ثبت‌شده «' . $os . '» است؛ همین نسخه مبنای سازگاری نرم‌افزار و به‌روزرسانی در نظر گرفته شود.';
		}
		$weight = self::spec( $specs, array( 'وزن' ) );
		$dim    = self::spec( $specs, array( 'ابعاد' ) );
		if ( $weight || $dim ) {
			$paras[] = 'از نظر فیزیکی' . ( $weight ? ' وزن ' . $weight : '' ) . ( $dim ? ( $weight ? ' و ابعاد ' : ' ابعاد ' ) . $dim : '' ) . ' اعلام شده که برای حمل روزانه و جای‌گیری در جعبه یا کیف قابل استناد است.';
		}
		if ( $source ) {
			$paras[] = 'توضیح کارشناسی منبع نیز در معرفی محصول حفظ شده تا لحن فروشگاهی جای دادهٔ فنی را نگیرد.';
		}
		$extra = array();
		$i     = 0;
		foreach ( array_merge( (array) $keys, (array) $specs ) as $k => $v ) {
			$line = self::s( $k ) . ' «' . self::s( $v ) . '»';
			if ( '' === $line || in_array( $line, $extra, true ) ) {
				continue;
			}
			$extra[] = $line;
			if ( ++$i >= 8 ) {
				break;
			}
		}
		if ( $extra ) {
			$paras[] = 'جزئیات دقیق همین مدل برای مقایسهٔ به‌روز: ' . implode( '؛ ', $extra ) . '. هر عدد فقط از کاتالوگ همین کالا آمده است.';
		}
		$paras[] = 'برای تصمیم خرید در سال ۲۰۲۶ همین جدول را با نیاز واقعی بسنجید؛ نسل جدید، امتیاز مشتری یا قابلیت خارج از مشخصات ثبت‌شده به متن اضافه نشده است.';
		return implode( "\n\n", array_filter( $paras ) );
	}

	/**
	 * تحلیل گوشی.
	 *
	 * @param array $specs مشخصات.
	 * @return string
	 */
	private static function phone_analysis( $specs ) {
		$bits = array();
		$ram  = self::spec( $specs, array( 'مقدار رم', 'حافظه RAM', 'رم' ) );
		$rom  = self::spec( $specs, array( 'حافظه داخلی' ) );
		$bat  = self::spec( $specs, array( 'گنجایش باتری', 'ظرفیت باتری' ) );
		$cam  = self::spec( $specs, array( 'دوربین اصلی', 'کیفیت دوربین اصلی' ) );
		$front = self::spec( $specs, array( 'دوربین سلفی', 'کیفیت دوربین جلو' ) );
		$scr  = self::spec( $specs, array( 'اندازه صفحه نمایش' ) );
		$type = self::spec( $specs, array( 'نوع صفحه نمایش' ) );
		$ref  = self::spec( $specs, array( 'نرخ نوسازی تصویر' ) );
		$cpu  = self::spec( $specs, array( 'پردازنده', 'پردازنده مرکزی', 'تراشه' ) );
		if ( $cpu ) {
			$bits[] = 'تراشهٔ «' . $cpu . '» چارچوب توان پردازشی را مشخص می‌کند.';
		}
		if ( $ram ) {
			$bits[] = 'رم «' . $ram . '» برای باز نگه داشتن چند برنامه هم‌زمان معیار عملی است.';
		}
		if ( $rom ) {
			$bits[] = 'حافظهٔ داخلی «' . $rom . '» سقف نصب برنامه، عکس و ویدیو را تعیین می‌کند.';
		}
		if ( $scr || $type || $ref ) {
			$screen = 'نمایشگر';
			if ( $scr ) {
				$screen .= ' ' . $scr;
			}
			if ( $type ) {
				$screen .= ' از نوع ' . $type;
			}
			if ( $ref ) {
				$screen .= ' با نرخ نوسازی ' . $ref;
			}
			$bits[] = $screen . ' تجربهٔ مشاهده و روانی رابط را شکل می‌دهد.';
		}
		if ( $cam ) {
			$bits[] = 'دوربین اصلی «' . $cam . '» برای ثبت روزمره معیار اصلی عکاسی این مدل است.';
		}
		if ( $front ) {
			$bits[] = 'دوربین جلو «' . $front . '» برای تماس تصویری و سلفی اعلام شده است.';
		}
		if ( $bat ) {
			$bits[] = 'باتری «' . $bat . '» یکی از معیارهای دوام روزانه است؛ مصرف واقعی به تنظیمات نمایشگر و شبکه بستگی دارد.';
		}
		$reg = self::spec( $specs, array( 'وضعیت رجیستر' ) );
		if ( $reg ) {
			$bits[] = 'وضعیت رجیستر در منبع: «' . $reg . '».';
		}
		return $bits ? implode( ' ', $bits ) : 'مشخصات سخت‌افزاری این مدل در جدول فنی همین صفحه آمده و مبنای مقایسه با رقبا است.';
	}

	/**
	 * تحلیل با الگوهای نام‌دار.
	 *
	 * @param array $specs مشخصات.
	 * @param array $rules قوانین.
	 * @return string
	 */
	private static function named_analysis( $specs, $rules ) {
		$bits = array();
		foreach ( $rules as $rule ) {
			$val = self::spec( $specs, $rule[0] );
			if ( $val ) {
				$bits[] = sprintf( $rule[1], $val );
			}
		}
		return $bits ? implode( ' ', $bits ) : self::generic_analysis( $specs, array() );
	}

	/**
	 * تحلیل عمومی.
	 *
	 * @param array $specs مشخصات.
	 * @param array $keys  کلیدها.
	 * @return string
	 */
	private static function generic_analysis( $specs, $keys ) {
		$use = $keys ? $keys : $specs;
		$i   = 0;
		$bits = array();
		foreach ( $use as $k => $v ) {
			$bits[] = $k . ' برابر «' . self::s( $v ) . '» ثبت شده است';
			if ( ++$i >= 6 ) {
				break;
			}
		}
		if ( ! $bits ) {
			return 'برای این کالا توضیح کارشناسی منبع محدود بود؛ جدول مشخصات و تصاویر مبنای تصمیم خرید است.';
		}
		return 'بر اساس دادهٔ کاتالوگ، ' . implode( '؛ ', $bits ) . '. همین مقادیر باید در تب ویژگی‌های ووکامرس هم دیده شوند.';
	}

	/**
	 * بررسی (نقاط قوت / نکات قابل توجه) بدون نظر جعلی مشتری.
	 *
	 * @param string $cat   دسته.
	 * @param string $name  نام.
	 * @param array  $specs مشخصات.
	 * @param array  $keys  کلیدها.
	 * @return string
	 */
	public static function review( $cat, $name, $specs, $keys ) {
		$pros = array();
		$cons = array();
		$pairs = array_merge( (array) $keys, (array) $specs );

		foreach ( $pairs as $k => $v ) {
			$v = self::s( $v );
			if ( '' === $v ) {
				continue;
			}
			if ( preg_match( '/رجیستر|گارانتی|ضدآب|IP68|IP67|وای‌فای 6|5G|OLED|AMOLED|120\s*Hz|144\s*Hz/iu', $k . ' ' . $v ) ) {
				$pros[] = $k . ' — ' . $v;
			}
		}

		$ram = self::spec( $specs, array( 'مقدار رم', 'حافظه RAM', 'رم' ) );
		if ( $ram && preg_match( '/(\d+)/', $ram, $m ) && (int) $m[1] >= 8 ) {
			$pros[] = 'رم نسبتاً بالا (' . $ram . ') برای استفادهٔ هم‌زمان چند برنامه.';
		} elseif ( $ram && preg_match( '/(\d+)/', $ram, $m ) && (int) $m[1] > 0 && (int) $m[1] <= 4 ) {
			$cons[] = 'رم اعلام‌شده (' . $ram . ') برای کارهای سنگین ممکن است محدود باشد.';
		}

		$bat = self::spec( $specs, array( 'گنجایش باتری', 'ظرفیت باتری' ) );
		if ( $bat && preg_match( '/(\d{3,5})/', $bat, $m ) && (int) $m[1] >= 5000 ) {
			$pros[] = 'ظرفیت باتری ' . $bat . ' برای استفادهٔ روزانه مناسب به‌نظر می‌رسد.';
		}

		$brand = self::spec( $specs, array( 'برند' ) );
		if ( $brand ) {
			$pros[] = 'برند مشخص و قابل پیگیری: ' . $brand . '.';
		}

		if ( empty( $pros ) ) {
			foreach ( array_slice( $pairs, 0, 4, true ) as $k => $v ) {
				$pros[] = $k . ' مشخص و ثبت‌شده است (' . self::s( $v ) . ').';
			}
		}

		$missing = array(
			'گواهی ضدآب' => 'مقاومت رسمی در برابر آب در مشخصات دیده نشد.',
			'گارانتی'    => 'شرح گارانتی در دادهٔ منبع خالی است؛ قبل از انتشار در فروشگاه تکمیل شود.',
		);
		foreach ( $missing as $key => $msg ) {
			if ( ! self::spec( $specs, array( $key ) ) ) {
				$cons[] = $msg;
			}
			if ( count( $cons ) >= 3 ) {
				break;
			}
		}
		if ( empty( $cons ) ) {
			$cons[] = 'قیمت نهایی فروشگاه را خودتان تعیین کنید؛ این بررسی روی مشخصات فنی است نه نرخ بازار.';
		}

		$out  = 'بررسی کارشناسی «' . $name . '» — نه نظر ساختگی مشتری، بلکه جمع‌بندی دادهٔ کاتالوگ.' . "\n\n";
		$out .= "نقاط قوت:\n";
		foreach ( array_slice( array_unique( $pros ), 0, 5 ) as $p ) {
			$out .= '• ' . $p . "\n";
		}
		$out .= "\nنکات قابل توجه:\n";
		foreach ( array_slice( array_unique( $cons ), 0, 4 ) as $c ) {
			$out .= '• ' . $c . "\n";
		}
		$out .= "\nجمع‌بندی بررسی: اگر مشخصات بالا با نیاز خریدار هم‌خوان است، این مدل گزینهٔ شفافی برای انتشار در فروشگاه است. ناظر باید متن را یک‌بار بخواند و بعد منتشر کند.";
		return trim( $out );
	}

	/**
	 * مخاطب هدف.
	 *
	 * @param string $cat   دسته.
	 * @param string $name  نام.
	 * @param array  $specs مشخصات.
	 * @return string
	 */
	public static function audience( $cat, $name, $specs ) {
		if ( 'phone' === $cat ) {
			$ram = self::spec( $specs, array( 'مقدار رم', 'حافظه RAM', 'رم' ) );
			$extra = $ram ? ' پیکربندی رم «' . $ram . '» را با الگوی استفادهٔ روزانه بسنجید.' : '';
			return 'مناسب کسانی که به‌دنبال ' . $name . ' با مشخصات شفاف‌اند؛ عکاسی روزمره، شبکه‌های اجتماعی و کار اداری سبک. خریدار حرفه‌ای بهتر است جدول دوربین و باتری را خط‌به‌خط تطبیق دهد.' . $extra;
		}
		if ( 'laptop' === $cat ) {
			return 'برای دانشجو، کار اداری و تولید محتوای سبک، به‌شرط آن‌که پردازنده و رم اعلام‌شده با نرم‌افزارهای موردنظر سازگار باشد.';
		}
		return 'خریدارانی که می‌خواهند قبل از انتشار محصول، مشخصات، تصاویر و متن فروش را در یک صفحه ببینند و خودشان تأیید کنند.';
	}

	/**
	 * جمع‌بندی خرید.
	 *
	 * @param string $cat        دسته.
	 * @param string $name       نام.
	 * @param array  $highlights نکات.
	 * @return string
	 */
	public static function verdict( $cat, $name, $highlights ) {
		$hint = $highlights ? ' محورهای اصلی: ' . implode( '؛ ', array_slice( $highlights, 0, 3 ) ) . '.' : '';
		return $name . ' با دادهٔ کامل کاتالوگ برای انتشار در ووکامرس آماده است. قیمت را فروشگاه تعیین می‌کند؛ ارزش این صفحه در شفافیت مشخصات، تصاویر و متن کارشناسی است.' . $hint;
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
		$html .= '<p>' . esc_html( $name ) . ' — مشخصات فنی دانه‌دانه، تصاویر فروشگاهی و متن کارشناسی آمادهٔ انتشار.</p>';
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
	 * سئو تجاری.
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
		$desc = 'خرید ' . $name . ' با مشخصات کامل، بررسی کارشناسی و تصاویر واقعی. ' . implode( ' | ', $bits );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $desc, 'UTF-8' ) > 155 ) {
			$desc = mb_substr( $desc, 0, 152, 'UTF-8' ) . '…';
		} elseif ( strlen( $desc ) > 155 ) {
			$desc = substr( $desc, 0, 152 ) . '…';
		}

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
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $keyword, 'UTF-8' ) > 40 ) {
			$keyword = mb_substr( $keyword, 0, 40, 'UTF-8' );
		}
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
			$desc = 'خرید ' . $name . ' با مشخصات کامل، بررسی کارشناسی و تصاویر واقعی. مشاهده جزئیات.';
			if ( $bits ) {
				$desc .= ' ' . implode( ' | ', $bits );
			}
		}
		$dlen = function_exists( 'mb_strlen' ) ? mb_strlen( $desc, 'UTF-8' ) : strlen( $desc );
		if ( $dlen < 140 ) {
			$extra = $bits ? ( ' ' . implode( ' | ', $bits ) ) : '';
			$desc .= $extra . ' همین حالا مشخصات را ببینید و مقایسه کنید.';
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
	 * مونتاژ HTML نهایی توضیحات.
	 *
	 * @param array  $data       داده.
	 * @param string $intro      معرفی.
	 * @param array  $highlights نکات.
	 * @param string $analysis   تحلیل.
	 * @param string $review     بررسی.
	 * @param string $audience   مخاطب.
	 * @param string $verdict    جمع‌بندی.
	 * @return string
	 */
	public static function faq( $cat, $name, $specs, $keys ) {
		$out  = array();
		$bag  = array_merge( (array) $keys, (array) $specs );
		$pref = array(
			'حافظه داخلی'      => 'حافظه داخلی این محصول چقدر است؟',
			'مقدار رم'         => 'رم این محصول چقدر است؟',
			'حافظه RAM'        => 'رم این محصول چقدر است؟',
			'گنجایش باتری'     => 'ظرفیت باتری چقدر اعلام شده؟',
			'ظرفیت باتری'      => 'ظرفیت باتری چقدر اعلام شده؟',
			'دوربین اصلی'      => 'دوربین اصلی چه مشخصه‌ای دارد؟',
			'اندازه صفحه نمایش'=> 'اندازه نمایشگر چقدر است؟',
			'سیستم عامل'       => 'سیستم‌عامل چیست؟',
			'پردازنده'         => 'پردازنده چه مدلی است؟',
			'برند'             => 'برند سازنده چیست؟',
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
		if ( count( $out ) < 2 ) {
			$out[] = array(
				'q' => 'آیا مشخصات فنی کامل در صفحه آمده است؟',
				'a' => 'بله. جدول مشخصات همین صفحه از کاتالوگ منبع پر شده و هر مورد به‌صورت ویژگی ووکامرس هم ثبت می‌شود.',
			);
		}
		return $out;
	}

	/**
	 * مونتاژ HTML نهایی توضیحات.
	 *
	 * @param array  $data       داده.
	 * @param string $intro      معرفی.
	 * @param array  $highlights نکات.
	 * @param string $analysis   تحلیل.
	 * @param string $review     بررسی.
	 * @param string $audience   مخاطب.
	 * @param string $verdict    جمع‌بندی.
	 * @param array  $faq        پرسش‌ها.
	 * @return string
	 */
	public static function assemble_html( $data, $intro, $highlights, $analysis, $review, $audience, $verdict, $faq = array() ) {
		$html  = '<div class="shoper-studio-copy">';
		$html .= '<h2>معرفی محصول</h2>';
		$html .= wpautop( esc_html( $intro ) );

		if ( $highlights ) {
			$html .= '<h2>نکات برجسته</h2><ul>';
			foreach ( $highlights as $h ) {
				$html .= '<li>' . esc_html( $h ) . '</li>';
			}
			$html .= '</ul>';
		}

		$html .= '<h2>تحلیل کارشناسی</h2>';
		$html .= wpautop( esc_html( $analysis ) );

		$html .= '<h2>بررسی محصول</h2>';
		$html .= self::review_to_html( $review );

		$html .= '<h2>مناسب برای چه کسانی؟</h2>';
		$html .= wpautop( esc_html( $audience ) );

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

		$html .= '<h2>جمع‌بندی خرید</h2>';
		$html .= wpautop( esc_html( $verdict ) );

		$src = 'کاتالوگ';
		if ( ! empty( $data['provider'] ) && 'digikala' === $data['provider'] ) {
			$src = 'دیجی‌کالا';
		} elseif ( ! empty( $data['page_url'] ) && false !== strpos( (string) $data['page_url'], 'torob' ) ) {
			$src = 'ترب';
		}
		$html .= '<p class="shoper-source" style="font-size:12px;color:#888;margin-top:20px;">';
		$html .= 'متن فروش توسط <strong>Shoper Studio</strong> — خواجوی آماده شده است. منبع مشخصات: ' . esc_html( $src ) . '.';
		if ( ! empty( $data['page_url'] ) ) {
			$html .= ' <a href="' . esc_url( $data['page_url'] ) . '" target="_blank" rel="nofollow">صفحه منبع</a>.';
		}
		$html .= '</p></div>';
		return $html;
	}

	/**
	 * تبدیل متن بررسی به HTML.
	 *
	 * @param string $review متن.
	 * @return string
	 */
	private static function review_to_html( $review ) {
		$lines = preg_split( '/\n+/', (string) $review );
		$html  = '';
		$ul    = false;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				if ( $ul ) {
					$html .= '</ul>';
					$ul    = false;
				}
				continue;
			}
			if ( 0 === strpos( $line, '• ' ) ) {
				if ( ! $ul ) {
					$html .= '<ul>';
					$ul    = true;
				}
				$html .= '<li>' . esc_html( substr( $line, 2 ) ) . '</li>';
				continue;
			}
			if ( $ul ) {
				$html .= '</ul>';
				$ul    = false;
			}
			if ( 'نقاط قوت:' === $line ) {
				$html .= '<h3>نقاط قوت</h3>';
			} elseif ( 'نکات قابل توجه:' === $line ) {
				$html .= '<h3>نکات قابل توجه</h3>';
			} else {
				$html .= '<p>' . esc_html( $line ) . '</p>';
			}
		}
		if ( $ul ) {
			$html .= '</ul>';
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
		$html  = '<table class="shoper-specs-table" style="width:100%;border-collapse:collapse;margin:12px 0;"><tbody>';
		$i     = 0;
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
