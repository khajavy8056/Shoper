<?php
/**
 * سازنده‌ی محصول در ووکامرس از روی داده‌ی ترب.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_Product_Builder.
 */
class Shoper_Product_Builder {

	/**
	 * سرویس‌گیرنده‌ی ترب.
	 *
	 * @var Shoper_Torob_Client
	 */
	private $client;

	/**
	 * مدیریت تصاویر.
	 *
	 * @var Shoper_Image_Handler
	 */
	private $images;

	/**
	 * مدیریت ویژگی‌ها.
	 *
	 * @var Shoper_Attribute_Handler
	 */
	private $attributes;

	/**
	 * تجمیع فروشندگان.
	 *
	 * @var Shoper_Seller_Aggregator
	 */
	private $aggregator;

	/**
	 * سازنده.
	 */
	public function __construct() {
		$this->client     = new Shoper_Torob_Client();
		$this->images     = new Shoper_Image_Handler();
		$this->attributes = new Shoper_Attribute_Handler();
		$this->aggregator = new Shoper_Seller_Aggregator();
	}

	/* --------------------------------------------------------------------- */
	/* جستجو / پیشنهاد / پیش‌نمایش                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * پیشنهاد نام کامل محصول (نوار کشویی).
	 *
	 * @param string $term بخشی از نام.
	 * @return array|WP_Error
	 */
	public function suggest( $term ) {
		return $this->client->suggest( $term, 8 );
	}

	/**
	 * جستجو.
	 *
	 * @param string $query عبارت.
	 * @return array|WP_Error
	 */
	public function search( $query ) {
		return $this->client->search( $query, 0, 10 );
	}

	/**
	 * گرفتن داده‌ی کامل برای پیش‌نمایش.
	 *
	 * @param string $prk           شناسه.
	 * @param string $search_id     شناسه جستجو.
	 * @param string $more_info_url لینک کامل جزئیات (از نتایج جستجو).
	 * @return array|WP_Error
	 */
	public function preview( $prk, $search_id = '', $more_info_url = '' ) {
		$data = $this->client->details( $prk, $search_id, $more_info_url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		// نگه‌داشتن لینک جزئیات برای استفاده‌ی مجدد در مرحله‌ی ساخت/پر کردن.
		$data['more_info_url'] = $more_info_url;
		return $this->finalize( $data );
	}

	/**
	 * پیش‌نمایش از طریق لینک.
	 *
	 * @param string $url لینک.
	 * @return array|WP_Error
	 */
	public function preview_from_url( $url ) {
		$data = $this->client->details_from_url( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return $this->finalize( $data );
	}

	/**
	 * غنی‌سازی نهایی: تجمیع فروشندگان + ساخت توضیحات.
	 *
	 * @param array $data داده‌ی نرمال‌شده.
	 * @return array
	 */
	private function finalize( $data ) {
		$data = $this->enrich( $data );
		$data['description_html'] = $this->build_description_html( $data );
		return $data;
	}

	/**
	 * غنی‌سازی داده‌ی خام برای نمایش/ساخت.
	 *
	 * @param array $data داده.
	 * @return array
	 */
	private function enrich( $data ) {
		$limit    = (int) get_option( 'shoper_seller_limit', Shoper_Seller_Aggregator::DEFAULT_LIMIT );
		$strategy = get_option( 'shoper_seller_strategy', 'score' );

		$sellers = ! empty( $data['sellers'] ) ? $data['sellers'] : array();

		// تجمیع اطلاعات چند فروشنده‌ی برتر.
		$agg                 = $this->aggregator->aggregate( $sellers, $limit, $strategy );
		$data['aggregate']   = $agg;
		$data['sellers']     = $sellers;
		$data['sellers_count'] = count( $sellers );

		// قیمت نهایی از تجمیع.
		if ( ! empty( $agg['price'] ) ) {
			$data['price'] = (int) $agg['price'];
		}

		// اطمینان از وجود حداقل یک تصویر.
		if ( empty( $data['gallery'] ) ) {
			$data['gallery'] = ! empty( $data['image_url'] ) ? array( $data['image_url'] ) : array();
		}
		if ( empty( $data['image_url'] ) && ! empty( $data['gallery'][0] ) ) {
			$data['image_url'] = $data['gallery'][0];
		}

		return $data;
	}

	/* --------------------------------------------------------------------- */
	/* ساخت و پر کردن محصول                                                    */
	/* --------------------------------------------------------------------- */

	/**
	 * ساخت واقعی محصول.
	 *
	 * @param array $data داده.
	 * @param array $args آرگومان‌ها.
	 * @return array|WP_Error
	 */
	public function create_product( $data, $args = array() ) {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return new WP_Error( 'no_woocommerce', 'ووکامرس فعال نیست.' );
		}

		$status      = get_option( 'shoper_product_status', 'draft' );
		$set_gallery = 'yes' === get_option( 'shoper_import_gallery', 'yes' );
		$price_mode  = get_option( 'shoper_price_behavior', 'cheapest' );

		if ( ! empty( $args['status'] ) ) {
			$status = sanitize_text_field( $args['status'] );
		}

		$data = $this->enrich( $data );

		// بررسی تکراری.
		$existing = $this->find_existing( $data['random_key'] );
		if ( $existing ) {
			return new WP_Error(
				'duplicate',
				'این محصول قبلاً ساخته شده است.',
				array(
					'product_id' => $existing,
					'edit_link'  => get_edit_post_link( $existing, 'url' ),
				)
			);
		}

		$product = new WC_Product_Simple();
		$product->set_name( sanitize_text_field( $data['name1'] ) );
		$product->set_status( in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ? $status : 'draft' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_short_description( wp_kses_post( $this->build_short_description( $data ) ) );

		if ( ! empty( $args['description'] ) ) {
			$product->set_description( wp_kses_post( $args['description'] ) );
		} else {
			$product->set_description( $this->build_description_html( $data ) );
		}

		if ( ! empty( $data['random_key'] ) ) {
			$product->set_sku( 'TRB-' . $data['random_key'] );
		}

		if ( 'none' !== $price_mode && ! empty( $data['price'] ) ) {
			$product->set_regular_price( (string) (int) $data['price'] );
		}

		// ویژگی‌ها (هر مشخصه یک attribute سراسری).
		$attr_errors = array();
		if ( ! empty( $data['specs'] ) && is_array( $data['specs'] ) ) {
			$built = $this->attributes->build_attributes( $data['specs'] );
			if ( ! empty( $built['attrs'] ) ) {
				$product->set_attributes( $built['attrs'] );
			}
			if ( ! empty( $built['errors'] ) ) {
				$attr_errors = $built['errors'];
			}
		}

		$product_id = $product->save();
		if ( ! $product_id ) {
			return new WP_Error( 'save_failed', 'ذخیره محصول در ووکامرس ناموفق بود.' );
		}

		$this->save_source_meta( $product_id, $data );

		// تصاویر: دانلود در کتابخانه‌ی رسانه با نام محصول + شماره.
		// کاربر می‌تواند فقط برخی تصاویر و یک «تصویر اصلی» را انتخاب کرده باشد.
		$selected_images = ! empty( $args['selected_images'] ) && is_array( $args['selected_images'] )
			? array_map( 'absint', $args['selected_images'] )
			: null;
		$featured_image  = isset( $args['featured_image'] ) ? (int) $args['featured_image'] : 0;

		$image_info = array(
			'featured_id' => 0,
			'gallery_ids' => array(),
			'filenames'   => array(),
			'errors'      => array(),
		);
		if ( ! empty( $data['gallery'] ) && is_array( $data['gallery'] ) ) {
			$image_info = $this->images->sideload_gallery(
				$data['gallery'],
				$product_id,
				$data['name1'],
				$set_gallery,
				$selected_images,
				$featured_image
			);
			if ( ! empty( $image_info['gallery_ids'] ) ) {
				$product->set_gallery_image_ids( array_map( 'intval', $image_info['gallery_ids'] ) );
				$product->save();
			}
		}

		// سئو و برچسب‌ها.
		$seo = $this->build_seo( $data );
		$seo_title = ! empty( $args['seo_title'] )
			? sanitize_text_field( $args['seo_title'] )
			: $seo['title'];
		$seo_desc  = ! empty( $args['seo_desc'] )
			? sanitize_textarea_field( $args['seo_desc'] )
			: $seo['description'];
		$tags      = ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) )
			? array_map( 'sanitize_text_field', $args['tags'] )
			: $seo['tags'];

		if ( ! empty( $tags ) ) {
			wp_set_object_terms( $product_id, $tags, 'product_tag' );
		}
		update_post_meta( $product_id, '_shoper_seo_title', $seo_title );
		update_post_meta( $product_id, '_shoper_seo_desc', $seo_desc );

		// اگر Yoast نصب باشد، متادیتای آن را هم پر می‌کنیم.
		if ( defined( 'WPSEO_VERSION' ) || class_exists( '\Yoast\WP\SEO\Main' ) ) {
			update_post_meta( $product_id, '_yoast_wpseo_title', $seo_title );
			update_post_meta( $product_id, '_yoast_wpseo_metadesc', $seo_desc );
		}

		return array(
			'product_id'    => $product_id,
			'edit_link'     => get_edit_post_link( $product_id, 'url' ),
			'view_link'     => get_permalink( $product_id ),
			'image_info'    => $image_info,
			'filenames'     => ! empty( $image_info['filenames'] ) ? $image_info['filenames'] : array(),
			'specs_count'   => ! empty( $data['specs'] ) ? count( $data['specs'] ) : 0,
			'price'         => ! empty( $data['price'] ) ? (int) $data['price'] : 0,
			'sellers_used'  => ! empty( $data['aggregate']['considered'] ) ? count( $data['aggregate']['considered'] ) : 0,
			'sellers_total' => ! empty( $data['sellers_count'] ) ? (int) $data['sellers_count'] : 0,
			'seo'           => array(
				'title'       => $seo_title,
				'description' => $seo_desc,
				'tags'        => $tags,
			),
			'attr_errors'   => $attr_errors,
		);
	}

	/**
	 * پر کردن یک محصول موجود (در صفحه ویرایش).
	 *
	 * @param int   $post_id شناسه.
	 * @param array $data    داده.
	 * @param array $args    آرگومان‌های اختیاری (selected_images, featured_image, seo_title, seo_desc, tags).
	 * @return array|WP_Error
	 */
	public function fill_product( $post_id, $data, $args = array() ) {
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'محصول یافت نشد.' );
		}

		$data = $this->enrich( $data );

		$product->set_name( sanitize_text_field( $data['name1'] ) );
		$product->set_short_description( wp_kses_post( $this->build_short_description( $data ) ) );
		$product->set_description( $this->build_description_html( $data ) );

		if ( ! empty( $data['price'] ) && ! $product->get_regular_price() ) {
			$product->set_regular_price( (string) (int) $data['price'] );
		}

		$attr_errors = array();
		if ( ! empty( $data['specs'] ) && is_array( $data['specs'] ) ) {
			$built       = $this->attributes->build_attributes( $data['specs'] );
			$merged      = $this->attributes->merge_attributes( $product->get_attributes(), $built['attrs'] );
			$product->set_attributes( $merged );
			$attr_errors = $built['errors'];
		}

		$product->save();

		$this->save_source_meta( $post_id, $data );
		update_post_meta( $post_id, '_shoper_filled_at', current_time( 'mysql' ) );

		// انتخاب تصاویر + سئو و برچسب.
		$selected_images = ! empty( $args['selected_images'] ) && is_array( $args['selected_images'] )
			? array_map( 'absint', $args['selected_images'] )
			: null;
		$featured_image  = isset( $args['featured_image'] ) ? (int) $args['featured_image'] : 0;

		$image_info = array(
			'featured_id' => 0,
			'gallery_ids' => array(),
			'filenames'   => array(),
			'errors'      => array(),
		);
		if ( ! empty( $data['gallery'] ) ) {
			$set_gallery = 'yes' === get_option( 'shoper_import_gallery', 'yes' );
			$image_info  = $this->images->sideload_gallery(
				$data['gallery'],
				$post_id,
				$data['name1'],
				$set_gallery,
				$selected_images,
				$featured_image
			);
			if ( ! empty( $image_info['gallery_ids'] ) ) {
				$product->set_gallery_image_ids( array_map( 'intval', $image_info['gallery_ids'] ) );
				$product->save();
			}
		}

		// سئو و برچسب.
		$seo       = $this->build_seo( $data );
		$seo_title = ! empty( $args['seo_title'] ) ? sanitize_text_field( $args['seo_title'] ) : $seo['title'];
		$seo_desc  = ! empty( $args['seo_desc'] ) ? sanitize_textarea_field( $args['seo_desc'] ) : $seo['description'];
		$tags      = ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) )
			? array_map( 'sanitize_text_field', $args['tags'] )
			: $seo['tags'];
		if ( ! empty( $tags ) ) {
			wp_set_object_terms( $post_id, $tags, 'product_tag' );
		}
		update_post_meta( $post_id, '_shoper_seo_title', $seo_title );
		update_post_meta( $post_id, '_shoper_seo_desc', $seo_desc );
		if ( defined( 'WPSEO_VERSION' ) || class_exists( '\Yoast\WP\SEO\Main' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $seo_desc );
		}

		return array(
			'post_id'     => $post_id,
			'specs_count' => ! empty( $data['specs'] ) ? count( $data['specs'] ) : 0,
			'images'      => $image_info,
			'filenames'   => ! empty( $image_info['filenames'] ) ? $image_info['filenames'] : array(),
			'attr_errors' => $attr_errors,
		);
	}

	/**
	 * ذخیره‌ی متاهای مبدأ.
	 *
	 * @param int   $post_id شناسه.
	 * @param array $data    داده.
	 * @return void
	 */
	private function save_source_meta( $post_id, $data ) {
		update_post_meta( $post_id, '_shoper_random_key', $data['random_key'] );
		update_post_meta( $post_id, '_shoper_source_url', esc_url_raw( $data['page_url'] ) );
		update_post_meta( $post_id, '_shoper_imported_at', current_time( 'mysql' ) );

		if ( ! empty( $data['aggregate']['considered'] ) ) {
			$shops = wp_list_pluck( $data['aggregate']['considered'], 'shop_name' );
			update_post_meta( $post_id, '_shoper_sellers_used', implode( '، ', array_filter( $shops ) ) );
		}
		if ( ! empty( $data['sellers_count'] ) ) {
			update_post_meta( $post_id, '_shoper_sellers_total', (int) $data['sellers_count'] );
		}
	}

	/**
	 * جستجوی محصول قبلی.
	 *
	 * @param string $random_key شناسه.
	 * @return int
	 */
	private function find_existing( $random_key ) {
		if ( empty( $random_key ) ) {
			return 0;
		}
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_shoper_random_key' AND meta_value = %s LIMIT 1",
				$random_key
			)
		);
		return $id ? (int) $id : 0;
	}

	/* --------------------------------------------------------------------- */
	/* ساخت توضیحات                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * توضیح کوتاه محصول.
	 *
	 * @param array $data داده.
	 * @return string
	 */
	public function build_short_description( $data ) {
		$parts = array();

		if ( ! empty( $data['name2'] ) ) {
			$parts[] = '<p>' . esc_html( $data['name2'] ) . '</p>';
		}

		// چند ویژگی کلیدی به‌صورت لیست کوتاه.
		if ( ! empty( $data['key_specs'] ) && is_array( $data['key_specs'] ) ) {
			$items = '';
			$i     = 0;
			foreach ( $data['key_specs'] as $k => $v ) {
				if ( $i++ >= 6 ) {
					break;
				}
				$items .= '<li><strong>' . esc_html( $k ) . ':</strong> ' . esc_html( $v ) . '</li>';
			}
			if ( $items ) {
				$parts[] = '<ul>' . $items . '</ul>';
			}
		}

		// ویژگی‌های تجمیع‌شده از فروشندگان (گارانتی، رجیستر، پلمپ...).
		if ( ! empty( $data['aggregate']['features'] ) ) {
			$features = array_slice( $data['aggregate']['features'], 0, 5 );
			$parts[]  = '<p>' . esc_html( implode( ' • ', $features ) ) . '</p>';
		}

		return implode( "\n", $parts );
	}

	/**
	 * ساخت اطلاعات سئو: تیتر، توضیح متا و برچسب‌های محصول.
	 *
	 * برچسب‌ها از نام فارسی/انگلیسی محصول و مشخصات کلیدی (مثل برند و مدل)
	 * استخراج می‌شوند و در بخش «برچسب‌ها» (product_tag) ثبت می‌گردند.
	 *
	 * @param array $data داده.
	 * @return array { title, description, tags }
	 */
	public function build_seo( $data ) {
		$title = isset( $data['name1'] ) ? (string) $data['name1'] : '';

		// توضیح متا از نام انگلیسی + چند مشخصه‌ی کلیدی.
		$desc_parts = array();
		if ( ! empty( $data['name2'] ) ) {
			$desc_parts[] = (string) $data['name2'];
		}
		if ( ! empty( $data['key_specs'] ) && is_array( $data['key_specs'] ) ) {
			$i = 0;
			foreach ( $data['key_specs'] as $k => $v ) {
				if ( $i++ >= 5 ) {
					break;
				}
				$desc_parts[] = (string) $k . ': ' . (string) $v;
			}
		}
		$desc = implode( ' | ', $desc_parts );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $desc, 'UTF-8' ) > 155 ) {
			$desc = mb_substr( $desc, 0, 152, 'UTF-8' ) . '…';
		} elseif ( strlen( $desc ) > 155 ) {
			$desc = substr( $desc, 0, 152 ) . '…';
		}

		// برچسب‌ها.
		$tags  = array();
		$seen  = array();
		$cands = array();
		if ( ! empty( $data['name1'] ) ) {
			$cands[] = $data['name1'];
		}
		if ( ! empty( $data['name2'] ) ) {
			$cands[] = $data['name2'];
		}
		foreach ( array( 'برند', 'مدل', 'سازنده' ) as $key ) {
			if ( ! empty( $data['specs'][ $key ] ) ) {
				$cands[] = (string) $data['specs'][ $key ];
			}
		}

		foreach ( $cands as $c ) {
			$tokens = preg_split( '/[\|\/،,]+/u', (string) $c );
			foreach ( $tokens as $t ) {
				$t = trim( $t );
				if ( '' === $t || isset( $seen[ $t ] ) ) {
					continue;
				}
				$seen[ $t ] = true;
				$tags[]     = $t;
				if ( count( $tags ) >= 12 ) {
					break 2;
				}
			}
		}

		return array(
			'title'       => $title,
			'description' => $desc,
			'tags'        => $tags,
		);
	}

	/**
	 * ساخت HTML توضیحات کامل.
	 *
	 * @param array $data داده.
	 * @return string
	 */
	public function build_description_html( $data ) {
		$html = '';

		// توضیح اصلی.
		if ( ! empty( $data['description'] ) ) {
			$html .= wpautop( wp_kses_post( $data['description'] ) );
		} else {
			$html .= '<p>' . esc_html( $data['name1'] ) . '</p>';
		}

		// مشخصات کلیدی.
		if ( ! empty( $data['key_specs'] ) && is_array( $data['key_specs'] ) ) {
			$html .= '<h3>مشخصات کلیدی</h3>';
			$html .= $this->render_spec_table( $data['key_specs'] );
		}

		// مشخصات فنی کامل (گروه‌بندی‌شده در صورت وجود).
		if ( ! empty( $data['spec_groups'] ) && is_array( $data['spec_groups'] ) ) {
			$html .= '<h3>مشخصات فنی</h3>';
			foreach ( $data['spec_groups'] as $group ) {
				if ( empty( $group['specs'] ) ) {
					continue;
				}
				if ( ! empty( $group['header'] ) ) {
					$html .= '<h4>' . esc_html( $group['header'] ) . '</h4>';
				}
				$html .= $this->render_spec_table( $group['specs'] );
			}
		} elseif ( ! empty( $data['specs'] ) && is_array( $data['specs'] ) ) {
			$html .= '<h3>مشخصات فنی</h3>';
			$html .= $this->render_spec_table( $data['specs'] );
		}

		// اطلاعات تجمیع‌شده از فروشندگان.
		$html .= $this->render_seller_section( $data );

		// منبع.
		if ( ! empty( $data['page_url'] ) ) {
			$html .= '<p class="shoper-source" style="font-size:12px;color:#888;margin-top:20px;">';
			$html .= 'این محصول توسط افزونه‌ی <strong>Shoper</strong> از ';
			$html .= '<a href="' . esc_url( $data['page_url'] ) . '" target="_blank" rel="nofollow">ترب</a> وارد شده است.';
			$html .= '</p>';
		}

		return $html;
	}

	/**
	 * بخش اطلاعات فروشندگان در توضیحات.
	 *
	 * @param array $data داده.
	 * @return string
	 */
	private function render_seller_section( $data ) {
		if ( empty( $data['aggregate']['considered'] ) ) {
			return '';
		}

		$agg   = $data['aggregate'];
		$html  = '<h3>اطلاعات خرید</h3>';

		$total   = (int) $agg['total_sellers'];
		$checked = count( $agg['considered'] );
		$html   .= '<p>این اطلاعات از میان <strong>' . esc_html( number_format_i18n( $total ) ) . '</strong> فروشنده، ';
		$html   .= 'بر اساس <strong>' . esc_html( $checked ) . '</strong> فروشنده‌ی برتر جمع‌آوری شده است.</p>';

		// جدول فروشندگان بررسی‌شده.
		$html .= '<table class="shoper-sellers-table" style="width:100%;border-collapse:collapse;margin:12px 0;">';
		$html .= '<thead><tr style="background:#f1f1f1;">';
		$html .= '<th style="padding:8px 12px;border:1px solid #eee;text-align:right;">فروشنده</th>';
		$html .= '<th style="padding:8px 12px;border:1px solid #eee;text-align:right;">شهر</th>';
		$html .= '<th style="padding:8px 12px;border:1px solid #eee;text-align:right;">امتیاز</th>';
		$html .= '<th style="padding:8px 12px;border:1px solid #eee;text-align:right;">قیمت</th>';
		$html .= '</tr></thead><tbody>';
		foreach ( $agg['considered'] as $i => $s ) {
			$bg    = ( 0 === $i % 2 ) ? '#fafafa' : '#fff';
			$html .= '<tr style="background:' . esc_attr( $bg ) . ';">';
			$html .= '<td style="padding:8px 12px;border:1px solid #eee;">' . esc_html( $s['shop_name'] ) . '</td>';
			$html .= '<td style="padding:8px 12px;border:1px solid #eee;">' . esc_html( $s['city'] ) . '</td>';
			$html .= '<td style="padding:8px 12px;border:1px solid #eee;">' . esc_html( $s['score_text'] ? $s['score_text'] : (string) $s['score'] ) . '</td>';
			$html .= '<td style="padding:8px 12px;border:1px solid #eee;">' . esc_html( number_format_i18n( (int) $s['price'] ) ) . ' تومان</td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		// دامنه‌ی قیمت.
		if ( ! empty( $agg['cheapest'] ) && ! empty( $agg['highest'] ) && $agg['highest'] > $agg['cheapest'] ) {
			$html .= '<p>محدوده‌ی قیمت در بازار: از <strong>' . esc_html( number_format_i18n( $agg['cheapest'] ) ) . '</strong> ';
			$html .= 'تا <strong>' . esc_html( number_format_i18n( $agg['highest'] ) ) . '</strong> تومان.</p>';
		}

		// ویژگی‌های تجمیع‌شده.
		if ( ! empty( $agg['features'] ) ) {
			$html .= '<h4>ویژگی‌های اعلام‌شده توسط فروشندگان</h4><ul>';
			foreach ( array_slice( $agg['features'], 0, 10 ) as $f ) {
				$html .= '<li>' . esc_html( $f ) . '</li>';
			}
			$html .= '</ul>';
		}

		// ارسال.
		if ( ! empty( $agg['shipping'] ) || ! empty( $agg['delivery'] ) ) {
			$html .= '<h4>ارسال و تحویل</h4><ul>';
			foreach ( array_slice( array_merge( $agg['delivery'], $agg['shipping'] ), 0, 8 ) as $d ) {
				$html .= '<li>' . esc_html( $d ) . '</li>';
			}
			$html .= '</ul>';
		}

		if ( ! empty( $agg['guarantee'] ) ) {
			$html .= '<p><strong>' . esc_html( $agg['guarantee'] ) . '</strong></p>';
		}

		return $html;
	}

	/**
	 * رندر جدول مشخصات.
	 *
	 * @param array $pairs زوج‌ها.
	 * @return string
	 */
	private function render_spec_table( $pairs ) {
		if ( empty( $pairs ) ) {
			return '';
		}
		$html  = '<table class="shoper-specs-table" style="width:100%;border-collapse:collapse;margin:12px 0;">';
		$html .= '<tbody>';
		$i = 0;
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
