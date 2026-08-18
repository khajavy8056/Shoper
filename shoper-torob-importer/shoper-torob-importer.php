<?php
/**
 * Plugin Name:       Shoper Studio – سازنده هوشمند محصول
 * Plugin URI:        https://github.com/khajavy8056/Shoper
 * Description:       از نام محصول تا صفحه فروش: معرفی و بررسی، جدول مشخصات، گالری تصاویر و ویژگی‌های دانه‌دانه.
 * Version:           1.5.6
 * Author:            خواجوی
 * Author URI:        https://github.com/khajavy8056
 * License:           GPL v2 or later
 * Text Domain:       shoper
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * WC tested up to:   9.3
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // جلوگیری از دسترسی مستقیم.
}

// تعریف ثابت‌های افزونه.
define( 'SHOPER_VERSION', '1.5.6' );
define( 'SHOPER_AUTHOR', 'خواجوی' );
define( 'SHOPER_PLUGIN_FILE', __FILE__ );
define( 'SHOPER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHOPER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SHOPER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// کلاس اصلی افزونه.
if ( ! class_exists( 'Shoper_Torob_Importer' ) ) {

	/**
	 * کلاس اصلی افزونه‌ی Shoper.
	 */
	final class Shoper_Torob_Importer {

		/**
		 * نمونه‌ی یکتای کلاس (Singleton).
		 *
		 * @var Shoper_Torob_Importer|null
		 */
		private static $instance = null;

		/**
		 * گرفتن نمونه‌ی یکتا.
		 *
		 * @return Shoper_Torob_Importer
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * سازنده‌ی کلاس.
		 */
		private function __construct() {
			// بررسی فعال بودن ووکامرس.
			if ( ! $this->is_woocommerce_active() ) {
				add_action( 'admin_notices', array( $this, 'notice_wc_missing' ) );
				return;
			}

			$this->includes();
			$this->init_hooks();
		}

		/**
		 * بررسی فعال بودن ووکامرس.
		 *
		 * @return bool
		 */
		private function is_woocommerce_active() {
			$active_plugins = (array) get_option( 'active_plugins', array() );
			if ( is_multisite() ) {
				$active_plugins = array_merge(
					$active_plugins,
					(array) get_site_option( 'active_sitewide_plugins', array() )
				);
			}
			return in_array( 'woocommerce/woocommerce.php', $active_plugins, true )
				|| in_array( 'woocommerce/woocommerce.php', array_keys( $active_plugins ), true );
		}

		/**
		 * نمایش خطای عدم وجود ووکامرس.
		 *
		 * @return void
		 */
		public function notice_wc_missing() {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>Shoper</strong> برای کار کردن نیاز به فعال‌بودن افزونه‌ی ';
			echo '<strong>WooCommerce</strong> دارد.';
			echo '</p></div>';
		}

		/**
		 * بارگذاری فایل‌های لازم.
		 *
		 * @return void
		 */
		private function includes() {
		require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-debug.php';
		require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-diagnostics.php';
		require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-torob-client.php';
		require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-digikala-client.php';
		require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-catalog.php';
		require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-seller-aggregator.php';
		require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-image-handler.php';
		require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-attribute-handler.php';
		require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-copywriter.php';
		require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-ai-client.php';
		require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-product-builder.php';
		require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-ajax.php';
		require_once SHOPER_PLUGIN_DIR . 'admin/class-shoper-admin.php';
		}

		/**
		 * ثبت قلاب‌ها.
		 *
		 * @return void
		 */
		private function init_hooks() {
			add_action( 'init', array( $this, 'load_textdomain' ) );

			// راه‌اندازی بخش ادمین.
			new Shoper_Admin();

			// راه‌اندازی هندلر AJAX.
			new Shoper_Ajax();

			// اعلام سازگاری با HPOS ووکامرس.
			add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_storefront' ) );
		}

		/**
		 * بارگذاری ترجمه‌ها.
		 *
		 * @return void
		 */
		public function load_textdomain() {
			load_plugin_textdomain( 'shoper', false, dirname( SHOPER_PLUGIN_BASENAME ) . '/languages' );
		}

		/**
		 * اعلام سازگاری با HPOS.
		 *
		 * @return void
		 */
		public function declare_hpos_compatibility() {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
					'custom_order_tables',
					SHOPER_PLUGIN_FILE,
					true
				);
			}
		}

		/**
		 * استایل ثابت صفحه محصول در فروشگاه.
		 *
		 * @return void
		 */
		public function enqueue_storefront() {
			if ( ! function_exists( 'is_product' ) || ! is_product() ) {
				return;
			}
			wp_enqueue_style(
				'shoper-storefront',
				SHOPER_PLUGIN_URL . 'assets/css/storefront.css',
				array(),
				SHOPER_VERSION
			);
		}
	}
}

// راه‌اندازی افزونه.
add_action( 'plugins_loaded', array( 'Shoper_Torob_Importer', 'instance' ) );

/**
 * فعال‌سازی افزونه — ساخت گزینه‌های پیش‌فرض.
 */
register_activation_hook( __FILE__, 'shoper_activate' );
function shoper_activate() {
	$defaults = array(
		'data_source'      => 'auto',
		'catalog_source'   => 'auto',
		'product_status'   => 'draft',  // draft | publish | pending.
		'product_type'     => 'simple', // simple در فاز اول.
		'import_gallery'   => 'yes',
		'set_first_as_feat'=> 'yes',
		'price_behavior'   => 'cheapest', // cheapest | none.
		'user_agent'       => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
		'request_timeout'  => 25,
		'connect_timeout'  => 10,
		'proxy_url'        => '',
		'debug'            => '', // فعال‌سازی لاگ اشکال‌زدایی.
		'ai_enabled'       => 'yes',
		'ai_auto'          => 'yes',
	);
	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( 'shoper_' . $key ) ) {
			add_option( 'shoper_' . $key, $value );
		}
	}
}
