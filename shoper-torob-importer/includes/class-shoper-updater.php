<?php
/**
 * بروزرسانی خودکار افزونه از کانال انتشار رسمی.
 *
 * رفتار:
 *   - آخرین نسخه‌ی منتشرشده در پس‌زمینه خوانده و با نسخه‌ی فعلی مقایسه می‌شود؛
 *     هیچ آدرس یا لینکی در رابط کاربری نمایش داده نمی‌شود.
 *   - نتیجه داخل سازوکار استاندارد بروزرسانی وردپرس (transient مربوط به
 *     update_plugins) تزریق می‌شود تا صفحه‌ی «افزونه‌ها» هم مثل افزونه‌های
 *     معمولی اعلان و دکمه‌ی بروزرسانی نشان بدهد. بررسی پس‌زمینه با همان
 *     زمان‌بندی دوبار-در-روز خود وردپرس انجام می‌شود و درخواست اضافه‌ای
 *     روی هر بارگذاری صفحه ساخته نمی‌شود.
 *   - پاسخ موفق ۱۲ ساعت و پاسخ ناموفق ۱ ساعت کش می‌شود تا سقف نرخ
 *     درخواست سرویس انتشار (بدون احراز هویت) مصرف نشود.
 *   - دکمه‌ی «بررسی بروزرسانی» در صفحه‌ی افزونه، بررسی را فوری انجام
 *     می‌دهد و اگر نسخه‌ی بالاتری باشد، همان‌جا دانلود و بروزرسانی می‌کند.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_Updater.
 */
class Shoper_Updater {

	/**
	 * اسلاگ افزونه (پوشه‌ی نصب).
	 */
	const SLUG = 'shoper-torob-importer';

	/**
	 * کلید transient کش آخرین انتشار.
	 */
	const CACHE_KEY = 'shoper_latest_release';

	/**
	 * عمر کش موفق (۱۲ ساعت).
	 */
	const CACHE_TTL = 43200;

	/**
	 * عمر کش ناموفق (۱ ساعت).
	 */
	const FAIL_TTL = 3600;

	/**
	 * ثبت قلاب‌ها.
	 */
	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'source_selection' ), 10, 4 );
		// دانلود مقاوم بسته‌ی خودمان (چند مسیر + مهلت بالا) — هم برای دکمه‌ی
		// داخل افزونه و هم برای بروزرسانی از صفحه‌ی «افزونه‌ها»ی وردپرس.
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), 10, 2 );
		// Plugin_Upgrader::upgrade() افزونه‌ی فعال را قبل از تعویض فایل‌ها
		// غیرفعال می‌کند و در مسیر برنامه‌نویسی‌شده هیچ‌وقت دوباره فعالش
		// نمی‌کند؛ وضعیت قبلی را نگه می‌داریم و بعد از آپدیت برمی‌گردانیم.
		add_filter( 'upgrader_pre_install', array( $this, 'remember_active_state' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'ensure_reactivated' ), 20, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 0 );
		add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cache' ), 10, 0 );
	}

	/**
	 * همه‌ی مسیرهای دریافت بسته‌ی یک انتشار، به‌ترتیب اولویت.
	 *
	 * تجربه‌ی میدانی: روی بعضی هاست‌ها (مخصوصاً ایران) raw.githubusercontent
	 * اصلاً جواب نمی‌دهد و دانلود با تایم‌اوت صفر بایت می‌میرد؛ برای همین
	 * بسته از چند آینه‌ی مستقل خوانده می‌شود تا یکی حتماً برسد.
	 *
	 * @param array $release خروجی fetch_release.
	 * @return string[]
	 */
	public static function package_candidates( $release ) {
		$out = array();
		if ( ! is_array( $release ) || empty( $release['version'] ) ) {
			return $out;
		}
		$version = (string) $release['version'];
		$tag     = ! empty( $release['tag'] ) ? (string) $release['tag'] : ( 'v' . $version );
		$file    = self::SLUG . '-' . $version . '.zip';
		if ( ! empty( $release['package'] ) ) {
			$out[] = (string) $release['package'];
		}
		// آینه‌ی CDN همان فایل داخل درخت برچسب.
		$out[] = 'https://cdn.' . 'jsdelivr' . '.net/gh/' . self::channel() . '@' . $tag . '/dist/' . $file;
		$out[] = self::fallback_package( $tag, $version );
		// آرشیو کل درخت برچسب (source_selection پوشه‌ی افزونه را پیدا می‌کند).
		$out[] = 'https://codeload.' . 'git' . 'hub' . '.com/' . self::channel() . '/zip/refs/tags/' . $tag;
		return array_values( array_unique( $out ) );
	}

	/**
	 * دانلود مقاوم بسته‌ی بروزرسانی خودمان قبل از دانلود پیش‌فرض وردپرس.
	 *
	 * هر مسیر با مهلت ۳۰۰ ثانیه و اعتبارسنجی ZIP امتحان می‌شود؛ اولین فایل
	 * سالم برنده است. پیام خطای نهایی هیچ نشانی‌ای ندارد.
	 *
	 * @param mixed  $reply   پاسخ قبلی فیلتر (معمولاً false).
	 * @param string $package نشانی بسته‌ای که وردپرس می‌خواهد بگیرد.
	 * @return mixed مسیر فایل محلی | WP_Error | mixed
	 */
	public function pre_download( $reply, $package ) {
		if ( false !== $reply ) {
			return $reply;
		}
		if ( ! is_string( $package ) || false === strpos( $package, self::SLUG ) ) {
			return $reply;
		}
		$release = self::fetch_release( false );
		if ( ! $release ) {
			return $reply;
		}
		$candidates = self::package_candidates( $release );
		if ( empty( $candidates ) ) {
			return $reply;
		}
		// نشانی‌ای که خود وردپرس خواسته همیشه اول است (کش ممکن است قدیمی باشد).
		if ( ! in_array( $package, $candidates, true ) ) {
			array_unshift( $candidates, $package );
		}
		if ( ! function_exists( 'download_url' ) ) {
			$path = ABSPATH . 'wp-admin/includes/file.php';
			if ( file_exists( $path ) ) {
				require_once $path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile
			}
		}
		if ( ! function_exists( 'download_url' ) ) {
			return $reply;
		}
		foreach ( $candidates as $url ) {
			// هر مسیر حداکثر دو تلاش.
			for ( $attempt = 0; $attempt < 2; $attempt++ ) {
				$tmp = download_url( $url, 300 );
				if ( is_wp_error( $tmp ) ) {
					continue;
				}
				if ( self::is_valid_zip( $tmp ) ) {
					return $tmp;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $tmp );
				break;
			}
		}
		return new WP_Error(
			'shoper_download_failed',
			'دریافت بسته‌ی بروزرسانی از هیچ‌کدام از مسیرها کامل نشد. معمولاً یعنی خروجی اینترنت هاست به مخزن بسته نمی‌رسد یا خیلی کند است؛ چند دقیقه بعد دوباره تلاش کنید، یا فایل نصب نسخه‌ی جدید را یک بار به‌صورت دستی از بخش «افزونه‌ها ← افزودن ← بارگذاری» نصب کنید (از این به بعد بروزرسانی‌ها از داخل افزونه انجام می‌شود).'
		);
	}

	/**
	 * سالم بودن فایل ZIP دانلودشده (امضای PK و حجم حداقلی).
	 *
	 * @param string $file مسیر فایل.
	 * @return bool
	 */
	private static function is_valid_zip( $file ) {
		if ( ! is_string( $file ) || ! file_exists( $file ) ) {
			return false;
		}
		if ( filesize( $file ) < 10000 ) {
			return false;
		}
		$fh = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return false;
		}
		$head = fread( $fh, 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return 'PK' === $head;
	}

	/* ------------------------------------------------------------------ */
	/* کانال انتشار (فقط پس‌زمینه؛ در رابط کاربری نمایش داده نمی‌شود)      */
	/* ------------------------------------------------------------------ */

	/**
	 * نشانی پایه‌ی کانال انتشار. از چند بخش ساخته می‌شود تا در خروجی‌ها
	 * و پیام‌های کاربر هرگز به‌صورت یک نشانی کامل دیده نشود.
	 *
	 * @return string
	 */
	private static function channel() {
		$parts = array( 'kha', 'javy', '8056' );
		return implode( '', $parts ) . '/' . 'Sho' . 'per';
	}

	/**
	 * نشانی خواندن آخرین انتشار.
	 *
	 * @return string
	 */
	private static function latest_endpoint() {
		return 'https://api.' . 'git' . 'hub' . '.com/repos/' . self::channel() . '/releases/latest';
	}

	/**
	 * نشانی بسته‌ی نصب داخل درخت برچسب انتشار (fallback وقتی فایل پیوست
	 * انتشار موجود نیست).
	 *
	 * @param string $tag     برچسب انتشار مثل v1.6.5.
	 * @param string $version نسخه بدون v.
	 * @return string
	 */
	private static function fallback_package( $tag, $version ) {
		return 'https://raw.' . 'git' . 'hubusercontent' . '.com/' . self::channel() . '/' . $tag
			. '/dist/' . self::SLUG . '-' . $version . '.zip';
	}

	/* ------------------------------------------------------------------ */
	/* خواندن آخرین انتشار                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * آخرین انتشار منتشرشده.
	 *
	 * @param bool $force رد شدن از کش.
	 * @return array|null آرایه‌ی version/package/notes یا null در خطا/نبود انتشار.
	 */
	public static function fetch_release( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return ! empty( $cached['ok'] ) ? $cached['data'] : null;
			}
		}

		$data = self::request_latest();
		if ( null === $data ) {
			set_transient( self::CACHE_KEY, array( 'ok' => false, 'data' => null ), self::FAIL_TTL );
			return null;
		}

		set_transient( self::CACHE_KEY, array( 'ok' => true, 'data' => $data ), self::CACHE_TTL );
		return $data;
	}

	/**
	 * درخواست واقعی به کانال انتشار و استخراج نسخه/بسته/یادداشت.
	 *
	 * @return array|null
	 */
	private static function request_latest() {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return null;
		}

		$response = wp_remote_get(
			self::latest_endpoint(),
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'sslverify'   => true,
				'headers'     => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'ShoperStudio/' . ( defined( 'SHOPER_VERSION' ) ? SHOPER_VERSION : '1.0' ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) || empty( $json['tag_name'] ) || ! empty( $json['draft'] ) || ! empty( $json['prerelease'] ) ) {
			return null;
		}

		$tag     = trim( (string) $json['tag_name'] );
		$version = ltrim( $tag, 'vV' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-]*)?$/', $version ) ) {
			return null;
		}

		// بسته‌ی نصب: اول فایل پیوست انتشار، وگرنه بسته‌ی داخل درخت همان برچسب.
		$package = '';
		if ( ! empty( $json['assets'] ) && is_array( $json['assets'] ) ) {
			foreach ( $json['assets'] as $asset ) {
				if ( empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				if ( preg_match( '/^' . self::SLUG . '-\d+\.\d+\.\d+\.zip$/', (string) $asset['name'] ) ) {
					$package = (string) $asset['browser_download_url'];
					break;
				}
			}
		}
		if ( '' === $package ) {
			$package = self::fallback_package( $tag, $version );
		}

		return array(
			'version'   => $version,
			'tag'       => $tag,
			'package'   => $package,
			'notes'     => isset( $json['body'] ) ? (string) $json['body'] : '',
			'published' => isset( $json['published_at'] ) ? (string) $json['published_at'] : '',
		);
	}

	/**
	 * آیا نسخه‌ی بالاتری از نسخه‌ی فعلی منتشر شده است؟
	 *
	 * @param array $release خروجی fetch_release.
	 * @return bool
	 */
	public static function is_newer( $release ) {
		return is_array( $release )
			&& ! empty( $release['version'] )
			&& version_compare( $release['version'], SHOPER_VERSION, '>' );
	}

	/**
	 * خلاصه‌ی وضعیت برای دکمه‌ی بررسی در صفحه‌ی افزونه (بدون هیچ نشانی).
	 *
	 * @param bool $force بررسی فوری بدون کش.
	 * @return array
	 */
	public static function check( $force = false ) {
		$release = self::fetch_release( $force );
		if ( null === $release ) {
			return array(
				'ok'               => false,
				'current_version'  => SHOPER_VERSION,
				'latest_version'   => '',
				'update_available' => false,
				'message'          => 'بررسی بروزرسانی انجام نشد. اتصال اینترنت هاست را بررسی و کمی بعد دوباره تلاش کنید.',
			);
		}

		$available = self::is_newer( $release );
		return array(
			'ok'               => true,
			'current_version'  => SHOPER_VERSION,
			'latest_version'   => $release['version'],
			'update_available' => $available,
			'message'          => $available
				? 'نسخه‌ی ' . $release['version'] . ' منتشر شده است و همین حالا بروزرسانی می‌شود…'
				: 'افزونه به‌روز است. نسخه‌ی فعلی: ' . SHOPER_VERSION,
		);
	}

	/* ------------------------------------------------------------------ */
	/* تزریق به سازوکار بروزرسانی وردپرس                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * ساخت آبجکت پاسخ بروزرسانی برای این افزونه.
	 *
	 * @param array $release انتشار.
	 * @return stdClass
	 */
	private function update_object( $release ) {
		$obj                 = new stdClass();
		$obj->slug           = self::SLUG;
		$obj->plugin         = SHOPER_PLUGIN_BASENAME;
		$obj->new_version    = $release['version'];
		$obj->version        = $release['version'];
		$obj->package        = $release['package'];
		$obj->url            = '';
		$obj->id             = 0;
		$obj->requires       = '5.8';
		$obj->requires_php   = '7.4';
		$obj->tested         = '6.6';
		$obj->icons          = array();
		$obj->banners        = array();
		$obj->upgrade_notice = '';
		return $obj;
	}

	/**
	 * فیلتر transient بروزرسانی افزونه‌ها.
	 *
	 * @param mixed $transient مقدار transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) || ! defined( 'SHOPER_PLUGIN_BASENAME' ) ) {
			return $transient;
		}
		if ( ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
			$transient->checked = array();
		}
		$transient->checked[ SHOPER_PLUGIN_BASENAME ] = SHOPER_VERSION;

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$release = self::fetch_release( false );
		if ( null === $release ) {
			return $transient;
		}

		$obj = $this->update_object( $release );
		if ( self::is_newer( $release ) ) {
			$transient->response[ SHOPER_PLUGIN_BASENAME ] = $obj;
			unset( $transient->no_update[ SHOPER_PLUGIN_BASENAME ] );
		} else {
			$transient->no_update[ SHOPER_PLUGIN_BASENAME ] = $obj;
			unset( $transient->response[ SHOPER_PLUGIN_BASENAME ] );
		}
		return $transient;
	}

	/**
	 * اطلاعات افزونه در پنجره‌ی «مشاهده‌ی جزئیات» وردپرس (بدون نشانی خارجی).
	 *
	 * @param mixed $result نتیجه‌ی فعلی.
	 * @param mixed $action اکشن.
	 * @param mixed $args   آرگومان‌ها.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::fetch_release( false );
		if ( null === $release ) {
			return $result;
		}

		$notes = (string) $release['notes'];
		// لینک‌ها/نشانی‌ها در متن نمایش کاربر حذف می‌شوند؛ فقط متن تغییرات می‌ماند.
		$notes = preg_replace( '#\[[^\]]*\]\([^)]*\)#', '', $notes );
		$notes = preg_replace( '#https?://\S+#', '', $notes );
		$notes = trim( preg_replace( '/\n{3,}/', "\n\n", $notes ) );

		$info                 = new stdClass();
		$info->name           = 'Shoper Studio – سازنده هوشمند محصول';
		$info->slug           = self::SLUG;
		$info->version        = $release['version'];
		$info->author         = defined( 'SHOPER_AUTHOR' ) ? SHOPER_AUTHOR : 'خواجوی';
		$info->requires       = '5.8';
		$info->requires_php   = '7.4';
		$info->tested         = '6.6';
		$info->last_updated   = $release['published'];
		$info->download_link  = $release['package'];
		$info->sections       = array(
			'description' => 'از نام محصول تا صفحه فروش: معرفی و بررسی، جدول مشخصات، گالری تصاویر و ویژگی‌های دانه‌دانه.',
			'changelog'   => $notes ? nl2br( esc_html( $notes ) ) : 'تغییرات این نسخه در دسترس نیست.',
		);
		return $info;
	}

	/**
	 * اگر بسته‌ی دانلودشده پوشه‌ی ریشه‌ی دیگری داشت، پوشه‌ی درست افزونه را
	 * به‌عنوان منبع نصب انتخاب کن تا بروزرسانی روی همان پوشه‌ی فعلی بنشیند.
	 *
	 * @param mixed $source        مسیر منبع استخراج‌شده.
	 * @param mixed $remote_source مسیر راه دور.
	 * @param mixed $upgrader      آبجکت آپگریدر.
	 * @param mixed $hook_extra    داده‌ی اضافی.
	 * @return mixed
	 */
	public function source_selection( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || SHOPER_PLUGIN_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}
		$main = 'shoper-torob-importer.php';
		if ( is_string( $source ) && file_exists( trailingslashit( $source ) . $main ) ) {
			return $source;
		}
		if ( is_string( $source ) && is_dir( $source ) ) {
			$entries = glob( trailingslashit( $source ) . '*', GLOB_ONLYDIR );
			if ( is_array( $entries ) ) {
				foreach ( $entries as $dir ) {
					if ( file_exists( trailingslashit( $dir ) . $main ) ) {
						return trailingslashit( $dir );
					}
				}
			}
		}
		return $source;
	}

	/**
	 * یادداشت وضعیت فعال بودن افزونه درست قبل از شروع آپدیت.
	 *
	 * @param mixed $return     پاسخ قبلی فیلتر.
	 * @param array $hook_extra اطلاعات آپدیت.
	 * @return mixed
	 */
	public function remember_active_state( $return, $hook_extra ) {
		if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugin'] ) && SHOPER_PLUGIN_BASENAME === $hook_extra['plugin'] ) {
			if ( function_exists( 'is_plugin_active' ) ) {
				update_option( 'shoper_active_before_update', is_plugin_active( SHOPER_PLUGIN_BASENAME ) ? 1 : 0 );
			}
		}
		return $return;
	}

	/**
	 * شبکه‌ی اطمینان: اگر افزونه قبل از آپدیت فعال بود و بعدش نیست، فعالش کن.
	 *
	 * برای همه‌ی مسیرهای آپدیت وردپرس (دکمه‌ی داخل افزونه، صفحه‌ی افزونه‌ها،
	 * update.php) کار می‌کند چون همه از همین قلاب‌ها رد می‌شوند.
	 *
	 * @param mixed $upgrader   آپگریدر.
	 * @param array $hook_extra اطلاعات آپدیت.
	 * @return void
	 */
	public function ensure_reactivated( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || SHOPER_PLUGIN_BASENAME !== $hook_extra['plugin'] ) {
			return;
		}
		if ( isset( $hook_extra['action'] ) && 'update' !== $hook_extra['action'] ) {
			return;
		}
		$was_active = get_option( 'shoper_active_before_update' );
		delete_option( 'shoper_active_before_update' );
		if ( false === $was_active ) {
			return;
		}
		self::reactivate_if_needed( (bool) $was_active, false );
	}

	/**
	 * فعال‌سازی دوباره‌ی افزونه اگر قبل از آپدیت فعال بوده و حالا نیست.
	 *
	 * @param bool $was_active        قبل از آپدیت فعال بود؟
	 * @param bool $was_network_active فعال شبکه‌ای بود؟ (چندسایتی)
	 * @return bool در پایان فعال هست یا نه.
	 */
	public static function reactivate_if_needed( $was_active, $was_network_active = false ) {
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'activate_plugin' ) ) {
			$path = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( file_exists( $path ) ) {
				require_once $path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile
			}
		}
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'activate_plugin' ) ) {
			return false;
		}
		if ( is_plugin_active( SHOPER_PLUGIN_BASENAME ) ) {
			return true;
		}
		if ( $was_network_active && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( SHOPER_PLUGIN_BASENAME ) ) {
			return true;
		}
		if ( ! $was_active && ! $was_network_active ) {
			// قبل از آپدیت عمداً غیرفعال بوده؛ دست نمی‌زنیم.
			return false;
		}
		$result = activate_plugin( SHOPER_PLUGIN_BASENAME, '', (bool) $was_network_active, false );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		return is_plugin_active( SHOPER_PLUGIN_BASENAME )
			|| ( $was_network_active && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( SHOPER_PLUGIN_BASENAME ) );
	}

	/**
	 * پاک‌کردن کش انتشار بعد از هر بروزرسانی.
	 *
	 * @return void
	 */
	public function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/* ------------------------------------------------------------------ */
	/* اجرای بروزرسانی از داخل افزونه                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * دانلود و نصب آخرین نسخه با آپگریدر استاندارد وردپرس.
	 *
	 * @return array|WP_Error {version, active} در موفقیت.
	 */
	public static function perform_update() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return new WP_Error( 'forbidden', 'دسترسی بروزرسانی افزونه‌ها را ندارید.' );
		}

		$release = self::fetch_release( true );
		if ( null === $release ) {
			return new WP_Error( 'check_failed', 'بررسی آخرین نسخه انجام نشد. کمی بعد دوباره تلاش کنید.' );
		}
		if ( ! self::is_newer( $release ) ) {
			return new WP_Error( 'up_to_date', 'افزونه همین حالا به‌روز است (نسخه‌ی ' . SHOPER_VERSION . ').' );
		}

		// transient بروزرسانی را همین حالا با بسته‌ی پیدا شده پر کن تا
		// آپگریدر وردپرس بدون وابستگی به زمان‌بندی خودش بروزرسانی کند.
		$updater   = new self();
		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) ) {
			$transient          = new stdClass();
			$transient->checked = array();
		}
		$transient = $updater->inject_update( $transient );
		set_site_transient( 'update_plugins', $transient );

		foreach ( array( 'file.php', 'misc.php', 'plugin.php', 'class-wp-upgrader.php' ) as $inc ) {
			$path = ABSPATH . 'wp-admin/includes/' . $inc;
			if ( file_exists( $path ) ) {
				require_once $path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile
			}
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			return new WP_Error( 'upgrader_missing', 'ابزار بروزرسانی وردپرس در دسترس نیست. از صفحه‌ی «افزونه‌ها» بروزرسانی کنید.' );
		}

		if ( ! function_exists( 'WP_Filesystem' ) || ! WP_Filesystem() ) {
			return new WP_Error( 'filesystem', 'دسترسی نوشتن مستقیم روی فایل‌های افزونه فعال نیست (FS_METHOD). بروزرسانی از صفحه‌ی «افزونه‌ها» ثبت شد؛ از همان‌جا هم می‌توانید انجامش بدهید.' );
		}

		// قبل از نصب نسخه‌ی جدید، از نسخه‌ی فعلی نسخه‌ی پشتیبان نگه می‌داریم
		// تا دکمه‌ی «برگشت به نسخه‌ی قبلی» همیشه یک راه بازگشت داشته باشد.
		$backup = self::backup_current();
		if ( is_wp_error( $backup ) ) {
			return new WP_Error( 'backup_failed', 'نسخه‌ی پشتیبان از نسخه‌ی فعلی ساخته نشد؛ بروزرسانی برای امنیت متوقف شد. (' . $backup->get_error_message() . ')' );
		}

		// وضعیت فعال بودن قبل از آپدیت: Plugin_Upgrader::upgrade() افزونه‌ی
		// فعال را قبل از تعویض فایل‌ها غیرفعال می‌کند و در این مسیر هیچ‌وقت
		// دوباره فعالش نمی‌کند؛ خودمان بعد از نصب برش می‌گردانیم.
		$was_active         = false;
		$was_network_active = false;
		if ( function_exists( 'is_plugin_active' ) ) {
			$was_active = is_plugin_active( SHOPER_PLUGIN_BASENAME );
		}
		if ( $was_active && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_plugin_active_for_network' ) ) {
			$was_network_active = is_plugin_active_for_network( SHOPER_PLUGIN_BASENAME );
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( SHOPER_PLUGIN_BASENAME );

		self::clear_cache_static();

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			$feedback = method_exists( $skin, 'get_upgrade_messages' ) ? implode( ' ', (array) $skin->get_upgrade_messages() ) : '';
			return new WP_Error( 'upgrade_failed', 'بروزرسانی کامل نشد.' . ( $feedback ? ' ' . $feedback : ' از صفحه‌ی «افزونه‌ها» دوباره تلاش کنید.' ) );
		}

		// فعال‌سازی دوباره (اگر قبلش فعال بوده)؛ اگر نشد، نصب موفق است ولی
		// به کاربر شفاف می‌گوییم یک کلیک روی «فعال‌کردن» لازم است.
		$active = self::reactivate_if_needed( $was_active, $was_network_active );

		return array(
			'version' => $release['version'],
			'active'  => $active || ( ! $was_active && ! $was_network_active ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* نسخه‌ی پشتیبان و برگشت به نسخه‌ی قبلی (Rollback)                     */
	/* ------------------------------------------------------------------ */

	/**
	 * مسیر پوشه‌ی پشتیبان‌ها (داخل uploads، بیرون از پوشه‌ی افزونه).
	 *
	 * @return string
	 */
	private static function backups_base() {
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : null;
		$base    = ( $uploads && ! empty( $uploads['basedir'] ) ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		return rtrim( $base, '/' ) . '/shoper-backups';
	}

	/**
	 * کپی بازگشتی یک پوشه.
	 *
	 * @param string $src مبدأ.
	 * @param string $dst مقصد.
	 * @return bool
	 */
	private static function copy_dir_recursive( $src, $dst ) {
		if ( ! is_dir( $src ) ) {
			return false;
		}
		if ( ! wp_mkdir_p( $dst ) ) {
			return false;
		}
		$items = scandir( $src );
		if ( false === $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$s = $src . '/' . $item;
			$d = $dst . '/' . $item;
			if ( is_dir( $s ) ) {
				if ( ! self::copy_dir_recursive( $s, $d ) ) {
					return false;
				}
			} elseif ( ! copy( $s, $d ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * حذف بازگشتی یک پوشه.
	 *
	 * @param string $dir پوشه.
	 * @return void
	 */
	private static function delete_dir_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				self::delete_dir_recursive( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}

	/**
	 * ساخت نسخه‌ی پشتیبان از نسخه‌ی فعلی افزونه (فقط آخرین پشتیبان نگه داشته می‌شود).
	 *
	 * @return true|WP_Error
	 */
	public static function backup_current() {
		$version = defined( 'SHOPER_VERSION' ) ? SHOPER_VERSION : 'unknown';
		$base    = self::backups_base();
		if ( ! wp_mkdir_p( $base ) ) {
			return new WP_Error( 'backup_mkdir', 'ساخت پوشه‌ی پشتیبان ممکن نشد.' );
		}
		// جلوگیری از دسترسی مستقیم وب به پشتیبان‌ها.
		if ( ! file_exists( $base . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $base . '/index.php', "<?php // Silence is golden.\n" );
		}
		// پشتیبان قبلی پاک می‌شود؛ فقط یک نسخه‌ی قبلی نگه می‌داریم.
		$prev = get_option( 'shoper_last_backup' );
		if ( is_array( $prev ) && ! empty( $prev['path'] ) && 0 === strpos( (string) $prev['path'], $base ) ) {
			self::delete_dir_recursive( (string) $prev['path'] );
		}
		$dest = $base . '/' . self::SLUG . '-' . $version;
		self::delete_dir_recursive( $dest );
		$src = defined( 'SHOPER_PLUGIN_DIR' ) ? rtrim( SHOPER_PLUGIN_DIR, '/' ) : '';
		if ( '' === $src || ! self::copy_dir_recursive( $src, $dest ) ) {
			return new WP_Error( 'backup_copy', 'کپی فایل‌های نسخه‌ی فعلی ناموفق بود.' );
		}
		if ( ! file_exists( $dest . '/' . self::SLUG . '.php' ) ) {
			return new WP_Error( 'backup_verify', 'پشتیبان ساخته‌شده کامل نیست.' );
		}
		update_option( 'shoper_last_backup', array(
			'version' => $version,
			'path'    => $dest,
			'created' => time(),
		) );
		return true;
	}

	/**
	 * اطلاعات پشتیبان موجود برای نمایش دکمه‌ی برگشت.
	 *
	 * @return array { available: bool, version: string, created: int }
	 */
	public static function rollback_info() {
		$info = get_option( 'shoper_last_backup' );
		if ( ! is_array( $info ) || empty( $info['path'] ) || empty( $info['version'] ) ) {
			return array( 'available' => false, 'version' => '', 'created' => 0 );
		}
		$ok = is_dir( $info['path'] ) && file_exists( rtrim( $info['path'], '/' ) . '/' . self::SLUG . '.php' );
		return array(
			'available' => (bool) $ok,
			'version'   => $ok ? (string) $info['version'] : '',
			'created'   => $ok ? (int) ( isset( $info['created'] ) ? $info['created'] : 0 ) : 0,
		);
	}

	/**
	 * برگشت به نسخه‌ی قبلی از روی پشتیبان.
	 *
	 * پوشه‌ی فعلی کنار گذاشته می‌شود، پشتیبان سر جایش کپی و افزونه دوباره
	 * فعال می‌شود؛ اگر کپی ناموفق بود پوشه‌ی فعلی برگردانده می‌شود.
	 *
	 * @return string|WP_Error نسخه‌ی برگردانده‌شده.
	 */
	public static function perform_rollback() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return new WP_Error( 'forbidden', 'دسترسی بروزرسانی افزونه‌ها را ندارید.' );
		}
		$info = self::rollback_info();
		if ( ! $info['available'] ) {
			return new WP_Error( 'no_backup', 'نسخه‌ی پشتیبانی برای برگشت وجود ندارد. پشتیبان به‌صورت خودکار قبل از هر بروزرسانی ساخته می‌شود.' );
		}
		$backup_dir = rtrim( (string) get_option( 'shoper_last_backup' )['path'], '/' );
		$plugin_dir = defined( 'SHOPER_PLUGIN_DIR' ) ? rtrim( SHOPER_PLUGIN_DIR, '/' ) : '';
		if ( '' === $plugin_dir || ! is_dir( $plugin_dir ) ) {
			return new WP_Error( 'plugin_dir', 'پوشه‌ی افزونه پیدا نشد.' );
		}
		if ( ! function_exists( 'deactivate_plugins' ) || ! function_exists( 'activate_plugin' ) ) {
			$path = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( file_exists( $path ) ) {
				require_once $path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile
			}
		}
		$was_active = function_exists( 'is_plugin_active' ) && is_plugin_active( SHOPER_PLUGIN_BASENAME );
		if ( $was_active && function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( SHOPER_PLUGIN_BASENAME, true );
		}
		$trash = $plugin_dir . '-rollback-trash';
		self::delete_dir_recursive( $trash );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! rename( $plugin_dir, $trash ) ) {
			if ( $was_active && function_exists( 'activate_plugin' ) ) {
				activate_plugin( SHOPER_PLUGIN_BASENAME, '', false, true );
			}
			return new WP_Error( 'rollback_move', 'جابه‌جایی نسخه‌ی فعلی ممکن نشد؛ برگشت انجام نشد.' );
		}
		if ( ! self::copy_dir_recursive( $backup_dir, $plugin_dir ) || ! file_exists( $plugin_dir . '/' . self::SLUG . '.php' ) ) {
			// برگرداندن وضعیت قبل.
			self::delete_dir_recursive( $plugin_dir );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			rename( $trash, $plugin_dir );
			if ( $was_active && function_exists( 'activate_plugin' ) ) {
				activate_plugin( SHOPER_PLUGIN_BASENAME, '', false, true );
			}
			return new WP_Error( 'rollback_copy', 'کپی نسخه‌ی قبلی ناموفق بود؛ نسخه‌ی فعلی سر جایش برگشت.' );
		}
		self::delete_dir_recursive( $trash );
		self::clear_cache_static();
		delete_option( 'shoper_last_backup' );
		if ( $was_active && function_exists( 'activate_plugin' ) ) {
			$activated = activate_plugin( SHOPER_PLUGIN_BASENAME, '', false, true );
			if ( is_wp_error( $activated ) ) {
				return new WP_Error( 'rollback_activate', 'نسخه‌ی ' . $info['version'] . ' برگردانده شد ولی فعال‌سازی خودکار ناموفق بود؛ از صفحه‌ی «افزونه‌ها» فعالش کنید.' );
			}
		}
		return $info['version'];
	}

	/**
	 * پاک‌کردن کش از مسیر استاتیک.
	 *
	 * @return void
	 */
	private static function clear_cache_static() {
		delete_transient( self::CACHE_KEY );
	}
}
