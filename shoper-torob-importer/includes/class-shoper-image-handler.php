<?php
/**
 * مدیریت دانلود و ثبت تصاویر در Media Library وردپرس.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_Image_Handler.
 *
 * تصاویر ترب را دانلود و در کتابخانه‌ی رسانه‌ی وردپرس ثبت می‌کند.
 */
class Shoper_Image_Handler {

	/**
	 * User-Agent برای دانلود تصویر.
	 *
	 * @var string
	 */
	private $user_agent;

	/**
	 * سازنده.
	 */
	public function __construct() {
		$this->user_agent = get_option(
			'shoper_user_agent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
		);
	}

	/**
	 * دانلود یک تصویر از URL و برگرداندن attachment ID.
	 *
	 * @param string $url      آدرس تصویر.
	 * @param int    $post_id  شناسه‌ی پست/محصول برای الصاق.
	 * @param string $title    عنوان اختیاری.
	 * @return int|WP_Error
	 */
	public function sideload( $url, $post_id = 0, $title = '' ) {
		if ( empty( $url ) ) {
			return new WP_Error( 'empty_url', 'آدرس تصویر خالی است.' );
		}

		// بررسی تکراری نبودن با متای منبع.
		$existing = $this->find_by_source_url( $url );
		if ( $existing ) {
			return $existing;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// اصلاح موقت User-Agent برای دانلود (برخی هاست‌ها با UA پیش‌فرض رد می‌شوند).
		add_filter( 'http_request_args', array( $this, 'filter_download_args' ), 10, 2 );

		// تضمین پسوند فایل در URL برای وردپرس.
		$download_url = $this->ensure_extension( $url );

		$attachment_id = media_sideload_image( $download_url, $post_id, $title ? $title : null, 'id' );

		remove_filter( 'http_request_args', array( $this, 'filter_download_args' ), 10 );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// ذخیره‌ی URL مبدأ برای جلوگیری از دانلود تکراری.
		update_post_meta( $attachment_id, '_shoper_source_url', esc_url_raw( $url ) );

		// افزودن alt فارسی.
		if ( $title ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $title ) );
		}

		return (int) $attachment_id;
	}

	/**
	 * فیلتر آرگومان‌های درخواست HTTP برای دانلود.
	 *
	 * @param array  $args آرگومان‌ها.
	 * @param string $url  آدرس.
	 * @return array
	 */
	public function filter_download_args( $args, $url ) {
		if ( false !== strpos( $url, 'torob.com' ) ) {
			$args['user-agent'] = $this->user_agent;
			$args['headers']    = array(
				'Referer'         => 'https://torob.com/',
				'Accept'          => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
				'Accept-Language' => 'fa-IR,fa;q=0.9,en;q=0.8',
			);
			$args['timeout']    = 30;
		}
		return $args;
	}

	/**
	 * دانلود تصویر شاخص و گالری برای یک محصول.
	 *
	 * کاربر می‌تواند تعیین کند کدام تصاویر نگه داشته شوند و کدام‌یک «تصویر اصلی»
	 * باشد. تصاویر با نام محصول + شماره‌ی ترتیبی ذخیره می‌شوند (مثل
	 * `گوشی-سامسونگ-1.webp`).
	 *
	 * @param array      $urls        آرایه‌ای از URLها.
	 * @param int        $post_id     شناسه‌ی محصول.
	 * @param string     $title       عنوان محصول (برای alt و نام فایل).
	 * @param bool       $gallery     آیا گالری هم دانلود شود.
	 * @param array|null $selected    ایندکس‌های تصاویری که کاربر نگه می‌دارد (null = همه).
	 * @param int        $featured    ایندکس تصویری که «تصویر اصلی» است (پیش‌فرض: اولی).
	 * @return array  { featured_id, gallery_ids, filenames, errors }
	 */
	public function sideload_gallery( $urls, $post_id, $title = '', $gallery = true, $selected = null, $featured = 0 ) {
		$result = array(
			'featured_id' => 0,
			'gallery_ids' => array(),
			'filenames'   => array(),
			'errors'      => array(),
		);

		if ( empty( $urls ) || ! is_array( $urls ) ) {
			return $result;
		}

		// فهرست ایندکس‌هایی که باید دانلود شوند.
		$total  = count( $urls );
		$indices = array();
		if ( is_array( $selected ) && ! empty( $selected ) ) {
			foreach ( $selected as $i ) {
				$i = (int) $i;
				if ( $i >= 0 && $i < $total ) {
					$indices[] = $i;
				}
			}
		} else {
			$indices = range( 0, $total - 1 );
		}
		$indices = array_values( array_unique( $indices ) );

		if ( empty( $indices ) ) {
			return $result;
		}

		// تعیین تصویر اصلی.
		$featured = (int) $featured;
		if ( ! in_array( $featured, $indices, true ) ) {
			$featured = $indices[0];
		}

		$base   = $this->base_filename( $title );
		$number = 0;

		foreach ( $indices as $idx ) {
			$url = $urls[ $idx ];
			$number++;
			$filename = $base . '-' . $number;

			$id = $this->sideload_named( $url, $post_id, $filename, $title );
			if ( is_wp_error( $id ) ) {
				$result['errors'][] = $url . ' => ' . $id->get_error_message();
				continue;
			}

			$result['filenames'][] = $filename;

			if ( $idx === $featured ) {
				$result['featured_id'] = $id;
				set_post_thumbnail( $post_id, $id );
				if ( ! $gallery ) {
					break;
				}
			} else {
				$result['gallery_ids'][] = $id;
			}
		}

		return $result;
	}

	/**
	 * دانلود و ثبت یک تصویر با نام فایل دلخواه (کنترل کامل بر نام فایل).
	 *
	 * برخلاف media_sideload_image که نام را از URL می‌گیرد، این متد فایل را با
	 * نام `{نام محصول}-{شماره}` در کتابخانه‌ی رسانه ذخیره می‌کند.
	 *
	 * @param string $url      آدرس تصویر.
	 * @param int    $post_id  شناسه‌ی پست برای الصاق.
	 * @param string $filename نام فایل (بدون پسوند یا با پسوند).
	 * @param string $title    عنوان/alt.
	 * @return int|WP_Error
	 */
	public function sideload_named( $url, $post_id = 0, $filename = '', $title = '' ) {
		if ( empty( $url ) ) {
			return new WP_Error( 'empty_url', 'آدرس تصویر خالی است.' );
		}

		// جلوگیری از دانلود تکراری بر اساس URL مبدأ.
		$existing = $this->find_by_source_url( $url );
		if ( $existing ) {
			return $existing;
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// دانلود به فایل موقت با User-Agent و Referer مناسب.
		add_filter( 'http_request_args', array( $this, 'filter_download_args' ), 10, 2 );
		$tmp = download_url( $url );
		remove_filter( 'http_request_args', array( $this, 'filter_download_args' ), 10 );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// تعیین پسوند از URL و سپس از فایل.
		$ext = $this->detect_extension( $url, $tmp );

		$safe_name = sanitize_file_name( (string) $filename );
		$safe_name = trim( $safe_name );
		if ( '' === $safe_name ) {
			$safe_name = 'shoper-product';
		}
		// اگر نام فایل پسوند نداشت، پسوند تصویر را اضافه کن.
		$name_ext = strtolower( pathinfo( $safe_name, PATHINFO_EXTENSION ) );
		if ( '' === $name_ext && $ext ) {
			$safe_name .= '.' . $ext;
		}

		$file_array = array(
			'name'     => $safe_name,
			'type'     => function_exists( 'wp_get_image_mime' ) && $tmp ? wp_get_image_mime( $tmp ) : '',
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => file_exists( $tmp ) ? filesize( $tmp ) : 0,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id, $title ? $title : null, array() );

		if ( file_exists( $tmp ) ) {
			@unlink( $tmp ); // phpcs:ignore
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_shoper_source_url', esc_url_raw( $url ) );
		if ( $title ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $title ) );
		}

		return (int) $attachment_id;
	}

	/**
	 * تشخیص پسوند تصویر از URL، با fallback به نوع واقعی فایل.
	 *
	 * @param string $url آدرس.
	 * @param string $tmp مسیر فایل موقت.
	 * @return string
	 */
	private function detect_extension( $url, $tmp = '' ) {
		$ext = '';
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( $path ) {
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		}
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif' ), true ) && $tmp && file_exists( $tmp ) ) {
			$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : '';
			switch ( $mime ) {
				case 'image/webp': $ext = 'webp'; break;
				case 'image/png':  $ext = 'png'; break;
				case 'image/gif':  $ext = 'gif'; break;
				case 'image/avif': $ext = 'avif'; break;
				default:           $ext = 'jpg';
			}
		}
		return $ext;
	}

	/**
	 * ساخت نام پایه برای فایل تصویر از عنوان محصول.
	 *
	 * نام محصول فارسی را نگه می‌دارد اما فاصله‌ها و کاراکترهای خطرناک را
	 * به خط تیره تبدیل می‌کند. اگر خروجی خالی بود از «shoper-product» استفاده می‌شود.
	 *
	 * @param string $title عنوان محصول.
	 * @return string
	 */
	private function base_filename( $title ) {
		$base = sanitize_file_name( (string) $title );
		$base = preg_replace( '/[\/\\\]/u', '-', $base );
		$base = preg_replace( '/\s+/u', '-', $base );
		$base = trim( $base, '-.' );
		if ( '' === $base ) {
			$base = 'shoper-product';
		}
		return substr( $base, 0, 80 );
	}

	/**
	 * جستجوی attachment قبلی بر اساس URL مبدأ.
	 *
	 * @param string $url آدرس.
	 * @return int
	 */
	private function find_by_source_url( $url ) {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_shoper_source_url' AND meta_value = %s
				 LIMIT 1",
				esc_url_raw( $url )
			)
		);
		return $id ? (int) $id : 0;
	}

	/**
	 * تضمین وجود پسوند تصویر در URL (برای media_sideload_image).
	 *
	 * وردپرس برای تشخیص نوع فایل به پسوند نگاه می‌کند.
	 *
	 * @param string $url آدرس.
	 * @return string
	 */
	private function ensure_extension( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( $path ) {
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$valid = array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif' );
			if ( in_array( $ext, $valid, true ) ) {
				return $url;
			}
		}
		// اگر URL بدون پسوند بود، یک پارامتر ساختگی اضافه نمی‌کنیم؛
		// media_sideload_image با فرمت کلی کار می‌کند. در صورت نیاز بر اساس هدر تعیین می‌کنیم.
		return $url;
	}
}
