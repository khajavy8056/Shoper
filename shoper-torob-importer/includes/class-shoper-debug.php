<?php
/**
 * لاگ اشکال‌زدایی امن افزونه.
 *
 * هدف: ثبت جزئیات درخواست‌های HTTP به ترب (بدون ذخیره‌ی اطلاعات حساس)
 * برای تشخیص دقیق علت شکست اتصال.
 *
 * فعال‌سازی (هر دو اختیاری):
 *   - ثابت:  define( 'SHOPER_DEBUG', true );  در wp-config.php
 *   - گزینه: فعال‌کردن «ثبت لاگ اشکال‌زدایی» در صفحه‌ی تنظیمات افزونه.
 *
 * در حالت عادی (غیرفعال) هیچ چیزی ثبت نمی‌شود.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_Debug.
 */
class Shoper_Debug {

	/**
	 * بررسی فعال بودن لاگ.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( defined( 'SHOPER_DEBUG' ) && SHOPER_DEBUG ) {
			return true;
		}
		if ( function_exists( 'get_option' ) && get_option( 'shoper_debug' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * ثبت یک رکورد لاگ.
	 *
	 * @param string $context برچسب بخش (request/retry/fallback/suggest...).
	 * @param array  $data    داده‌ی ساختاریافته.
	 * @return void
	 */
	public static function log( $context, $data = array() ) {
		if ( ! self::enabled() ) {
			return;
		}

		$payload = $data;
		if ( is_array( $data ) ) {
			if ( function_exists( 'wp_json_encode' ) ) {
				$payload = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
			} else {
				$payload = wp_json_encode( $data );
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[Shoper][%s] %s', (string) $context, (string) $payload ) );
	}
}
