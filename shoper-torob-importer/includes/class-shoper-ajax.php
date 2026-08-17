<?php
/**
 * هندلر درخواست‌های AJAX.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_Ajax.
 */
class Shoper_Ajax {

	/**
	 * سازنده.
	 */
	public function __construct() {
		add_action( 'wp_ajax_shoper_suggest', array( $this, 'suggest' ) );
		add_action( 'wp_ajax_shoper_search', array( $this, 'search' ) );
		add_action( 'wp_ajax_shoper_preview', array( $this, 'preview' ) );
		add_action( 'wp_ajax_shoper_create', array( $this, 'create' ) );
		add_action( 'wp_ajax_shoper_fill', array( $this, 'fill' ) );
		add_action( 'wp_ajax_shoper_test_connection', array( $this, 'test_connection' ) );
	}

	/**
	 * بررسی امنیت و دسترسی.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
		}
		check_ajax_referer( 'shoper_nonce', 'nonce' );
	}

	/**
	 * پیشنهاد نام محصول برای نوار کشویی زیر فیلد جستجو.
	 *
	 * کاربر نام کامل محصول را نمی‌داند؛ با نوشتن بخشی از نام،
	 * نام‌های کامل پیشنهاد می‌شوند.
	 *
	 * @return void
	 */
	public function suggest() {
		$this->guard();

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		if ( mb_strlen( $term, 'UTF-8' ) < 2 ) {
			wp_send_json_success( array( 'suggestions' => array() ) );
		}

		$builder = new Shoper_Product_Builder();
		$result  = $builder->suggest( $term );

		if ( is_wp_error( $result ) ) {
			// پیشنهاد یک قابلیت کمکی است؛ خطایش نباید مزاحم تایپ کاربر شود.
			wp_send_json_success( array( 'suggestions' => array() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * جستجو.
	 *
	 * @return void
	 */
	public function search() {
		$this->guard();

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		$builder = new Shoper_Product_Builder();

		// اگر لینک داده شده بود، مستقیماً شناسه را استخراج و prevew می‌کنیم.
		if ( $url ) {
			$details = $builder->preview_from_url( $url );
			if ( is_wp_error( $details ) ) {
				wp_send_json_error( array( 'message' => $details->get_error_message() ) );
			}
			wp_send_json_success(
				array(
					'mode'    => 'direct',
					'product' => $details,
				)
			);
		}

		if ( '' === $query ) {
			wp_send_json_error( array( 'message' => 'نام محصول را وارد کنید.' ) );
		}

		$result = $builder->search( $query );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * پیش‌نمایش.
	 *
	 * @return void
	 */
	public function preview() {
		$this->guard();

		$prk           = isset( $_POST['prk'] ) ? sanitize_text_field( wp_unslash( $_POST['prk'] ) ) : '';
		$search_id     = isset( $_POST['search_id'] ) ? sanitize_text_field( wp_unslash( $_POST['search_id'] ) ) : '';
		$more_info_url = isset( $_POST['more_info_url'] ) ? sanitize_text_field( wp_unslash( $_POST['more_info_url'] ) ) : '';

		if ( ! $prk ) {
			wp_send_json_error( array( 'message' => 'شناسه محصول نامعتبر است.' ) );
		}

		$builder = new Shoper_Product_Builder();
		$result  = $builder->preview( $prk, $search_id, $more_info_url );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * ساخت محصول جدید.
	 *
	 * @return void
	 */
	public function create() {
		$this->guard();

		$prk           = isset( $_POST['prk'] ) ? sanitize_text_field( wp_unslash( $_POST['prk'] ) ) : '';
		$search_id     = isset( $_POST['search_id'] ) ? sanitize_text_field( wp_unslash( $_POST['search_id'] ) ) : '';
		$more_info_url = isset( $_POST['more_info_url'] ) ? sanitize_text_field( wp_unslash( $_POST['more_info_url'] ) ) : '';
		$name          = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$status    = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$desc      = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';
		$specs_raw = isset( $_POST['specs'] ) ? wp_unslash( $_POST['specs'] ) : '';

		// انتخاب تصاویر توسط کاربر: ایندکس‌هایی که نگه داشته شوند + ایندکس تصویر اصلی.
		$selected_raw = isset( $_POST['selected_images'] ) ? wp_unslash( $_POST['selected_images'] ) : '';
		$featured_raw = isset( $_POST['featured_image'] ) ? absint( $_POST['featured_image'] ) : 0;
		$selected     = null;
		if ( '' !== $selected_raw ) {
			$dec = json_decode( $selected_raw, true );
			if ( is_array( $dec ) ) {
				$selected = array_map( 'absint', $dec );
			}
		}

		// سئو: تیتر، توضیح متا و برچسب‌ها.
		$seo_title = isset( $_POST['seo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['seo_title'] ) ) : '';
		$seo_desc  = isset( $_POST['seo_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['seo_desc'] ) ) : '';
		$tags_raw  = isset( $_POST['tags'] ) ? wp_unslash( $_POST['tags'] ) : '';
		$tags      = null;
		if ( '' !== $tags_raw ) {
			$dec = json_decode( $tags_raw, true );
			if ( is_array( $dec ) ) {
				$tags = array_map( 'sanitize_text_field', $dec );
			}
		}

		if ( ! $prk ) {
			wp_send_json_error( array( 'message' => 'شناسه محصول نامعتبر است.' ) );
		}

		$builder = new Shoper_Product_Builder();
		$data    = $builder->preview( $prk, $search_id, $more_info_url );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		if ( $name ) {
			$data['name1'] = $name;
		}

		// فیلتر مشخصات انتخاب‌شده.
		if ( $specs_raw ) {
			$allowed = is_array( $specs_raw ) ? $specs_raw : json_decode( $specs_raw, true );
			if ( is_array( $allowed ) && ! empty( $data['specs'] ) ) {
				$filtered = array();
				foreach ( $data['specs'] as $k => $v ) {
					if ( in_array( $k, $allowed, true ) ) {
						$filtered[ $k ] = $v;
					}
				}
				$data['specs'] = $filtered;
			}
		}

		$args = array();
		if ( $desc ) {
			$args['description'] = $desc;
		}
		if ( $status ) {
			$args['status'] = $status;
		}
		if ( $selected ) {
			$args['selected_images'] = $selected;
		}
		if ( $featured_raw >= 0 ) {
			$args['featured_image'] = $featured_raw;
		}
		if ( $seo_title ) {
			$args['seo_title'] = $seo_title;
		}
		if ( $seo_desc ) {
			$args['seo_desc'] = $seo_desc;
		}
		if ( $tags ) {
			$args['tags'] = $tags;
		}

		$result = $builder->create_product( $data, $args );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				)
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * پر کردن محصول موجود.
	 *
	 * @return void
	 */
	public function fill() {
		$this->guard();

		$post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$prk           = isset( $_POST['prk'] ) ? sanitize_text_field( wp_unslash( $_POST['prk'] ) ) : '';
		$search_id     = isset( $_POST['search_id'] ) ? sanitize_text_field( wp_unslash( $_POST['search_id'] ) ) : '';
		$more_info_url = isset( $_POST['more_info_url'] ) ? sanitize_text_field( wp_unslash( $_POST['more_info_url'] ) ) : '';

		// انتخاب تصاویر + سئو (همان‌طور که در صفحه‌ی اصلی افزونه هست).
		$selected     = null;
		$selected_raw = isset( $_POST['selected_images'] ) ? wp_unslash( $_POST['selected_images'] ) : '';
		if ( '' !== $selected_raw ) {
			$dec = json_decode( $selected_raw, true );
			if ( is_array( $dec ) ) {
				$selected = array_map( 'absint', $dec );
			}
		}
		$featured_raw = isset( $_POST['featured_image'] ) ? absint( $_POST['featured_image'] ) : 0;
		$seo_title    = isset( $_POST['seo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['seo_title'] ) ) : '';
		$seo_desc     = isset( $_POST['seo_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['seo_desc'] ) ) : '';
		$tags         = null;
		$tags_raw     = isset( $_POST['tags'] ) ? wp_unslash( $_POST['tags'] ) : '';
		if ( '' !== $tags_raw ) {
			$dec = json_decode( $tags_raw, true );
			if ( is_array( $dec ) ) {
				$tags = array_map( 'sanitize_text_field', $dec );
			}
		}

		if ( ! $post_id || ! $prk ) {
			wp_send_json_error( array( 'message' => 'شناسه محصول یا post_id نامعتبر است.' ) );
		}

		$builder = new Shoper_Product_Builder();
		$data    = $builder->preview( $prk, $search_id, $more_info_url );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		$args = array();
		if ( $selected ) {
			$args['selected_images'] = $selected;
		}
		if ( $featured_raw >= 0 ) {
			$args['featured_image'] = $featured_raw;
		}
		if ( $seo_title ) {
			$args['seo_title'] = $seo_title;
		}
		if ( $seo_desc ) {
			$args['seo_desc'] = $seo_desc;
		}
		if ( $tags ) {
			$args['tags'] = $tags;
		}

		$result = $builder->fill_product( $post_id, $data, $args );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'     => 'محصول با موفقیت از ترب پر شد. برای ذخیره‌ی نهایی دکمه‌ی «به‌روزرسانی» را بزنید.',
				'post_id'     => $post_id,
				'specs_count' => $result['specs_count'],
				'images'      => $result['images'],
				'attr_errors' => ! empty( $result['attr_errors'] ) ? $result['attr_errors'] : array(),
				'reload'      => true,
			)
		);
	}

	/**
	 * تست اتصال.
	 *
	 * @return void
	 */
	public function test_connection() {
		$this->guard();
		$client = new Shoper_Torob_Client();
		$result = $client->test_connection();
		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}
}
