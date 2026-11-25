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
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 0 );
		add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cache' ), 10, 0 );
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
	 * @param string $tag     برچسب انتشار مثل v1.5.9.
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
	 * @return string|WP_Error شماره‌ی نسخه‌ی نصب‌شده در موفقیت.
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
		return $release['version'];
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
