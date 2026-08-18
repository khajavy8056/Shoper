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
		add_action( 'wp_ajax_shoper_ingest', array( $this, 'ingest' ) );
		add_action( 'wp_ajax_shoper_create', array( $this, 'create' ) );
		add_action( 'wp_ajax_shoper_fill', array( $this, 'fill' ) );
		add_action( 'wp_ajax_shoper_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_shoper_diagnostics', array( $this, 'diagnostics' ) );
	}

	/**
	 * بررسی امنیت و دسترسی.
	 *
	 * خطای دسترسی و خطای nonce جدا از هم به جاوااسکریپت برگردانده می‌شوند.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'forbidden',
					'message' => 'دسترسی غیرمجاز برای انجام این عملیات.',
				),
				403
			);
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'shoper_nonce' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'nonce_failed',
					'message' => 'نشانه‌ی امنیتی منقضی شده است. صفحه را تازه‌سازی و دوباره تلاش کنید.',
				),
				403
			);
		}
	}

	/**
	 * نگاشت کد خطای داخلی به وضعیت HTTP پاسخ AJAX.
	 *
	 * @return array
	 */
	private function error_status_map() {
		return array(
			'rate_limited'     => 429,
			'blocked'          => 403,
			'torob_blocked'    => 403,
			'http_error'       => 502,
			'connection_failed'=> 502,
			'curl_failed'      => 502,
			'curl_unavailable' => 502,
			'invalid_json'     => 502,
			'invalid_response' => 502,
			'torob_error'      => 502,
			'mock_missing'     => 500,
			'mock_invalid'     => 500,
		);
	}

	/**
	 * ارسال خطای ساختاریافته به جاوااسکریپت.
	 *
	 * @param WP_Error $error           خطا.
	 * @param int      $fallback_status وضعیت پیش‌فرض.
	 * @return void
	 */
	private function send_error( $error, $fallback_status = 400 ) {
		$code = $error->get_error_code();
		$data = array(
			'code'    => $code,
			'message' => $error->get_error_message(),
		);

		$err_data = $error->get_error_data();
		if ( is_array( $err_data ) && isset( $err_data['status'] ) ) {
			$data['status'] = (int) $err_data['status'];
		}

		$map    = $this->error_status_map();
		$status = isset( $map[ $code ] ) ? $map[ $code ] : $fallback_status;

		wp_send_json_error( $data, $status );
	}

	/**
	 * خواندن JSON محصول ارسال‌شده از مرورگر (بدون تماس دوباره با ترب).
	 *
	 * @return array|null
	 */
	private function posted_product_payload() {
		if ( empty( $_POST['product_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return null;
		}
		$raw = wp_unslash( $_POST['product_json'] ); // phpcs:ignore
		$data = is_array( $raw ) ? $raw : json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * نرمال‌سازی JSON خام ترب که مرورگر/رله گرفته است.
	 *
	 * @return void
	 */
	public function ingest() {
		$this->guard();

		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : 'details';
		$raw  = isset( $_POST['raw'] ) ? wp_unslash( $_POST['raw'] ) : ''; // phpcs:ignore

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$decoded = $raw;
		} else {
			$decoded = null;
		}

		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'code' => 'invalid_json', 'message' => 'دادهٔ دریافتی از مرورگر قابل پردازش نیست.' ) );
		}

		$builder = new Shoper_Product_Builder();
		$result  = $builder->preview_from_raw( $kind, $decoded );
		if ( is_wp_error( $result ) ) {
			$this->send_error( $result );
		}

		if ( is_array( $result ) && empty( $result['_source'] ) ) {
			$result['_source'] = 'browser';
		}

		wp_send_json_success( $result );
	}

	/**
	 * پیشنهاد نام محصول برای نوار کشویی زیر فیلد جستجو.
	 *
	 * @return void
	 */
	public function suggest() {
		$this->guard();

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $term, 'UTF-8' ) : strlen( $term );
		if ( $len < 2 ) {
			wp_send_json_success( array( 'suggestions' => array() ) );
		}

		$builder = new Shoper_Product_Builder();
		$result  = $builder->suggest( $term );

		if ( is_wp_error( $result ) ) {
			Shoper_Debug::log(
				'suggest_error',
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
			$payload = array(
				'suggestions' => array(),
				'error'       => $result->get_error_code(),
				'message'     => $result->get_error_message(),
			);
			$err_data = $result->get_error_data();
			if ( is_array( $err_data ) && isset( $err_data['status'] ) ) {
				$payload['status'] = (int) $err_data['status'];
			}
			wp_send_json_success( $payload );
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

		// اگر لینک داده شده بود، مستقیماً شناسه را استخراج و preview می‌کنیم.
		if ( $url ) {
			$details = $builder->preview_from_url( $url );
			if ( is_wp_error( $details ) ) {
				$this->send_error( $details );
			}
			wp_send_json_success(
				array(
					'mode'    => 'direct',
					'product' => $details,
				)
			);
		}

		if ( '' === $query ) {
			wp_send_json_error( array( 'code' => 'empty_query', 'message' => 'نام محصول را وارد کنید.' ) );
		}

		$result = $builder->search( $query );
		if ( is_wp_error( $result ) ) {
			$this->send_error( $result );
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
			wp_send_json_error( array( 'code' => 'invalid_prk', 'message' => 'شناسه محصول نامعتبر است.' ) );
		}

		$builder = new Shoper_Product_Builder();
		$result  = $builder->preview( $prk, $search_id, $more_info_url );

		if ( is_wp_error( $result ) ) {
			$this->send_error( $result );
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
		$status        = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$desc          = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';
		$specs_raw     = isset( $_POST['specs'] ) ? wp_unslash( $_POST['specs'] ) : '';

		// انتخاب تصاویر توسط کاربر.
		$selected_raw = isset( $_POST['selected_images'] ) ? wp_unslash( $_POST['selected_images'] ) : '';
		$featured_raw = isset( $_POST['featured_image'] ) ? absint( $_POST['featured_image'] ) : 0;
		$selected     = null;
		if ( '' !== $selected_raw ) {
			$dec = json_decode( $selected_raw, true );
			if ( is_array( $dec ) ) {
				$selected = array_map( 'absint', $dec );
			}
		}

		// سئو.
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

		if ( ! $prk && ! $this->posted_product_payload() ) {
			wp_send_json_error( array( 'code' => 'invalid_prk', 'message' => 'شناسه محصول نامعتبر است.' ) );
		}

		$builder = new Shoper_Product_Builder();
		$payload = $this->posted_product_payload();
		if ( $payload ) {
			$data = $builder->preview_from_payload( $payload );
		} else {
			$data = $builder->preview( $prk, $search_id, $more_info_url );
		}
		if ( is_wp_error( $data ) ) {
			$this->send_error( $data );
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
			$this->send_error( $result );
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

		// انتخاب تصاویر + سئو.
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

		if ( ! $post_id || ( ! $prk && ! $this->posted_product_payload() ) ) {
			wp_send_json_error( array( 'code' => 'invalid_prk', 'message' => 'شناسه محصول یا post_id نامعتبر است.' ) );
		}

		$builder = new Shoper_Product_Builder();
		$payload = $this->posted_product_payload();
		if ( $payload ) {
			$data = $builder->preview_from_payload( $payload );
		} else {
			$data = $builder->preview( $prk, $search_id, $more_info_url );
		}
		if ( is_wp_error( $data ) ) {
			$this->send_error( $data );
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
			$this->send_error( $result );
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
		$dk = new Shoper_Digikala_Client();
		$dk_result = $dk->test_connection();
		if ( ! empty( $dk_result['ok'] ) ) {
			wp_send_json_success( $dk_result );
		}
		$client = new Shoper_Torob_Client();
		$result = $client->test_connection();
		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( $result );
		}
		$code    = isset( $dk_result['code'] ) ? $dk_result['code'] : ( isset( $result['code'] ) ? $result['code'] : 'connection_failed' );
		$message = 'دیجی‌کالا: ' . ( isset( $dk_result['message'] ) ? $dk_result['message'] : 'ناموفق' ) . ' | ترب: ' . ( isset( $result['message'] ) ? $result['message'] : 'ناموفق' );
		$error   = new WP_Error( $code, $message );
		$this->send_error( $error );
	}

	/**
	 * عیب‌یابی کامل اتصال — اجرای همه‌ی بررسی‌ها و برگرداندن گزارش قابل کپی.
	 *
	 * @return void
	 */
	public function diagnostics() {
		$this->guard();

		if ( ! class_exists( 'Shoper_Diagnostics' ) ) {
			wp_send_json_error( array( 'code' => 'diagnostics_unavailable', 'message' => 'ماژول عیب‌یابی در دسترس نیست.' ), 500 );
		}

		$report = Shoper_Diagnostics::run();

		// در صورت فعال بودن لاگ اشکال‌زدایی، خلاصه را هم ثبت کن.
		Shoper_Debug::log(
			'diagnostics',
			array(
				'verdict' => $report['summary']['verdict'],
				'counts'  => $report['summary']['counts'],
			)
		);

		wp_send_json_success( $report );
	}
}
