<?php
/**
 * بخش مدیریت افزونه.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_Admin.
 */
class Shoper_Admin {

	/**
	 * سازنده.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// دکمه در صفحه ویرایش/افزودن محصول.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		// ستون‌های لیست محصولات (نمایش منبع).
		add_filter( 'manage_product_posts_columns', array( $this, 'add_list_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_list_column' ), 10, 2 );
	}

	/**
	 * افزودن منو.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			'درون‌ریز از ترب — Shoper',
			'🛒 Shoper (از ترب)',
			'manage_woocommerce',
			'shoper',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * افزودن Meta Box به صفحه محصول.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box(
			'shoper_importer',
			'🛒 پر کردن از ترب (Shoper)',
			array( $this, 'render_meta_box' ),
			'product',
			'side',
			'high'
		);
	}

	/**
	 * بارگذاری استایل و اسکریپت‌ها در صفحات لازم.
	 *
	 * @param string $hook اسلاگ صفحه.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		$load = false;
		if ( $screen && ( 'product' === $screen->id || 'woocommerce_page_shoper' === $screen->id ) ) {
			$load = true;
		}
		// همچنین در صفحه افزودن محصول جدید.
		if ( isset( $_GET['page'] ) && 'shoper' === $_GET['page'] ) { // phpcs:ignore
			$load = true;
		}

		if ( ! $load ) {
			return;
		}

		wp_enqueue_style(
			'shoper-admin',
			SHOPER_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			SHOPER_VERSION
		);

		wp_enqueue_script(
			'shoper-admin',
			SHOPER_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			SHOPER_VERSION,
			true
		);

		wp_localize_script(
			'shoper-admin',
			'ShoperData',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'shoper_nonce' ),
				'apiBase'    => Shoper_Torob_Client::API_BASE,
				'searchPath' => Shoper_Torob_Client::SEARCH_URL,
				'detailsPath'=> Shoper_Torob_Client::DETAIL_URL,
				'fetchMode'  => get_option( 'shoper_fetch_mode', 'auto' ),
				'relayUrl'   => (string) get_option( 'shoper_relay_url', '' ),
				'gateways'   => Shoper_Torob_Client::active_gateways(),
				'i18n'    => array(
					'searching'    => 'در حال جستجو در ترب…',
					'loading'      => 'در حال دریافت اطلاعات محصول…',
					'creating'     => 'در حال ساخت محصول در ووکامرس…',
					'filling'      => 'در حال پر کردن محصول…',
					'empty_query'  => 'نام محصول را وارد کنید.',
					'select_one'   => 'یک محصول را انتخاب کنید.',
					'done'         => 'انجام شد!',
					'error'        => 'خطا',
					'view_product' => 'مشاهده‌ی محصول',
					'edit_product' => 'ویرایش محصول',
				),
			)
		);
	}

	/**
	 * رندر صفحه‌ی اصلی افزونه.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		include SHOPER_PLUGIN_DIR . 'admin/views/page-main.php';
	}

	/**
	 * رندر Meta Box در صفحه ویرایش محصول.
	 *
	 * @return void
	 */
	public function render_meta_box() {
		include SHOPER_PLUGIN_DIR . 'admin/views/meta-box.php';
	}

	/**
	 * افزودن ستون منبع به لیست محصولات.
	 *
	 * @param array $columns ستون‌ها.
	 * @return array
	 */
	public function add_list_column( $columns ) {
		$columns['shoper_source'] = 'منبع ترب';
		return $columns;
	}

	/**
	 * مقدار ستون منبع.
	 *
	 * @param string $column  نام ستون.
	 * @param int    $post_id شناسه پست.
	 * @return void
	 */
	public function render_list_column( $column, $post_id ) {
		if ( 'shoper_source' === $column ) {
			$key = get_post_meta( $post_id, '_shoper_random_key', true );
			if ( $key ) {
				echo '<span title="منبع: ترب" style="color:#2271b1;">🛒 ترب</span>';
			} else {
				echo '—';
			}
		}
	}
}
