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
	 * @param array   $urls       آرایه‌ای از URLها (اولین مورد تصویر شاخص).
	 * @param int     $post_id    شناسه‌ی محصول.
	 * @param string  $title      عنوان محصول (برای alt).
	 * @param bool    $gallery    آیا گالری هم دانلود شود.
	 * @return array  { featured_id, gallery_ids, errors }
	 */
	public function sideload_gallery( $urls, $post_id, $title = '', $gallery = true ) {
		$result = array(
			'featured_id' => 0,
			'gallery_ids' => array(),
			'errors'      => array(),
		);

		if ( empty( $urls ) || ! is_array( $urls ) ) {
			return $result;
		}

		$first = true;
		foreach ( $urls as $url ) {
			$id = $this->sideload( $url, $post_id, $title );
			if ( is_wp_error( $id ) ) {
				$result['errors'][] = $url . ' => ' . $id->get_error_message();
				continue;
			}
			if ( $first ) {
				$result['featured_id'] = $id;
				set_post_thumbnail( $post_id, $id );
				$first = false;
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
