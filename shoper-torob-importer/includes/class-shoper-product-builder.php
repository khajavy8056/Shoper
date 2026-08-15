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
	 * سازنده.
	 */
	public function __construct() {
		$this->client     = new Shoper_Torob_Client();
		$this->images     = new Shoper_Image_Handler();
		$this->attributes = new Shoper_Attribute_Handler();
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
	 * @param string $prk       شناسه.
	 * @param string $search_id شناسه جستجو.
	 * @return array|WP_Error
	 */
	public function preview( $prk, $search_id = '' ) {
		$data = $this->client->details( $prk, $search_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data = $this->enrich( $data );
		$data['description_html'] = $this->build_description_html( $data );
		return $data;
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
		$data = $this->enrich( $data );
		$data['description_html'] = $this->build_description_html( $data );
		return $data;
	}

	/**
	 * غنی‌سازی داده‌ی خام برای نمایش/ساخت.
	 *
	 * - انتخاب هوشمند بهترین فروشنده.
	 * - فیلتر فروشندگان ناموجود.
	 * - اطمینان از حداقل یک تصویر.
	 *
	 * @param array $data داده.
	 * @return array
	 */
	private function enrich( $data ) {
		// 1) مرتب‌سازی و فیلتر فروشندگان موجود.
		if ( ! empty( $data['sellers'] ) && is_array( $data['sellers'] ) ) {
			$available = array();
			foreach ( $data['sellers'] as $s ) {
				if ( ! empty( $s['price'] ) && $s['price'] > 0 ) {
					$available[] = $s;
				}
			}
			usort( $available, function ( $a, $b ) {
				return (int) $a['price'] - (int) $b['price'];
			});
			$data['sellers']         = $available;
			$data['sellers_count']   = count( $available );
			if ( ! empty( $available[0]['price'] ) && empty( $data['price'] ) ) {
				$data['price'] = (int) $available[0]['price'];
			}
		}

		// 2) اطمینان از وجود حداقل یک تصویر.
		if ( empty( $data['gallery'] ) ) {
			if ( ! empty( $data['image_url'] ) ) {
				$data['gallery'] = array( $data['image_url'] );
			} else {
				$data['gallery'] = array();
			}
		}
		if ( empty( $data['image_url'] ) && ! empty( $data['gallery'][0] ) ) {
			$data['image_url'] = $data['gallery'][0];
		}

		return $data;
	}

	/**
	 * ساخت واقعی محصول.
	 *
	 * @param array $data داده.
	 * @param array $args آرگومان‌ها.
	 * @return array|WP_Error
	 */
	public function create_product( $data, $args = array() ) {
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
				array( 'product_id' => $existing, 'edit_link' => get_edit_post_link( $existing, 'url' ) )
			);
		}

		// ساخت محصول.
		$product = new WC_Product_Simple();
		$product->set_name( sanitize_text_field( $data['name1'] ) );
		$product->set_status( in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ? $status : 'draft' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_short_description( wp_kses_post( $data['name2'] ) );

		if ( ! empty( $args['description'] ) ) {
			$product->set_description( wp_kses_post( wp_unslash( $args['description'] ) ) );
		} else {
			$product->set_description( $this->build_description_html( $data ) );
		}

		if ( ! empty( $data['random_key'] ) ) {
			$product->set_sku( 'TRB-' . $data['random_key'] );
		}

		// قیمت.
		if ( 'none' !== $price_mode && ! empty( $data['price'] ) ) {
			$product->set_regular_price( (string) (int) $data['price'] );
			$product->set_price( (string) (int) $data['price'] );
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

		// متاهای مبدأ.
		update_post_meta( $product_id, '_shoper_random_key', $data['random_key'] );
		update_post_meta( $product_id, '_shoper_source_url', esc_url_raw( $data['page_url'] ) );
		update_post_meta( $product_id, '_shoper_imported_at', current_time( 'mysql' ) );

		// تصاویر (دانلود در Media Library).
		$image_info = array( 'featured_id' => 0, 'gallery_ids' => array(), 'errors' => array() );
		if ( ! empty( $data['gallery'] ) && is_array( $data['gallery'] ) ) {
			$image_info = $this->images->sideload_gallery(
				$data['gallery'],
				$product_id,
				$data['name1'],
				$set_gallery
			);
			if ( ! empty( $image_info['gallery_ids'] ) ) {
				$product->set_gallery_image_ids( array_map( 'intval', $image_info['gallery_ids'] ) );
				$product->save();
			}
		}

		return array(
			'product_id'   => $product_id,
			'edit_link'    => get_edit_post_link( $product_id, 'url' ),
			'view_link'    => get_permalink( $product_id ),
			'image_info'   => $image_info,
			'specs_count'  => ! empty( $data['specs'] ) ? count( $data['specs'] ) : 0,
			'price'        => ! empty( $data['price'] ) ? (int) $data['price'] : 0,
			'attr_errors'  => $attr_errors,
		);
	}

	/**
	 * پر کردن یک محصول موجود (در صفحه ویرایش).
	 *
	 * @param int    $post_id   شناسه.
	 * @param array  $data      داده.
	 * @return array|WP_Error
	 */
	public function fill_product( $post_id, $data ) {
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'محصول یافت نشد.' );
		}

		$data = $this->enrich( $data );

		$product->set_name( sanitize_text_field( $data['name1'] ) );
		$product->set_short_description( wp_kses_post( $data['name2'] ) );
		$product->set_description( $this->build_description_html( $data ) );

		if ( ! empty( $data['price'] ) && ! $product->get_regular_price() ) {
			$product->set_regular_price( (string) (int) $data['price'] );
			$product->set_price( (string) (int) $data['price'] );
		}

		// ویژگی‌های جدید.
		$attr_errors = array();
		if ( ! empty( $data['specs'] ) && is_array( $data['specs'] ) ) {
			$built      = $this->attributes->build_attributes( $data['specs'] );
			$new_attrs  = $built['attrs'];
			$current    = $product->get_attributes();
			$merged     = $this->attributes->merge_attributes( $current, $new_attrs );
			$product->set_attributes( $merged );
			$attr_errors = $built['errors'];
		}

		$product->save();

		update_post_meta( $post_id, '_shoper_random_key', $data['random_key'] );
		update_post_meta( $post_id, '_shoper_source_url', esc_url_raw( $data['page_url'] ) );
		update_post_meta( $post_id, '_shoper_filled_at', current_time( 'mysql' ) );

		// تصاویر.
		$image_info = array( 'featured_id' => 0, 'gallery_ids' => array(), 'errors' => array() );
		if ( ! has_post_thumbnail( $post_id ) && ! empty( $data['gallery'] ) ) {
			$set_gallery   = 'yes' === get_option( 'shoper_import_gallery', 'yes' );
			$image_info    = $this->images->sideload_gallery( $data['gallery'], $post_id, $data['name1'], $set_gallery );
			if ( ! empty( $image_info['gallery_ids'] ) ) {
				$product->set_gallery_image_ids( array_map( 'intval', $image_info['gallery_ids'] ) );
				$product->save();
			}
		}

		return array(
			'post_id'     => $post_id,
			'specs_count' => ! empty( $data['specs'] ) ? count( $data['specs'] ) : 0,
			'images'      => $image_info,
			'attr_errors' => $attr_errors,
		);
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

	/**
	 * ساخت HTML توضیحات کامل.
	 *
	 * بخش‌های توضیح:
	 *   - توضیح اصلی ترب.
	 *   - جدول مشخصات کلیدی (key_specs).
	 *   - جدول کامل مشخصات فنی.
	 *   - معرفی بهترین فروشنده.
	 *   - لینک منبع.
	 *
	 * @param array $data داده.
	 * @return string
	 */
	public function build_description_html( $data ) {
		$html = '';

		// توضیح اصلی.
		if ( ! empty( $data['description'] ) ) {
			$desc = wp_kses_post( $data['description'] );
			$html .= wpautop( $desc );
		} else {
			$html .= '<p>معرفی ' . esc_html( $data['name1'] ) . '.</p>';
		}

		// معرفی کوتاه محصول بر اساس ویژگی‌های کلیدی.
		if ( ! empty( $data['key_specs'] ) && is_array( $data['key_specs'] ) ) {
			$html .= '<h3>مشخصات کلیدی</h3>';
			$html .= $this->render_spec_table( $this->key_specs_to_pairs( $data['key_specs'] ) );
		}

		// جدول کامل مشخصات فنی.
		if ( ! empty( $data['specs'] ) && is_array( $data['specs'] ) ) {
			$html .= '<h3>مشخصات فنی کامل</h3>';
			$html .= $this->render_spec_table( $data['specs'] );
		}

		// بهترین فروشنده.
		if ( ! empty( $data['sellers'][0] ) ) {
			$best = $data['sellers'][0];
			$html .= '<h3>بهترین قیمت</h3>';
			$html .= '<p>ارزان‌ترین فروشنده: <strong>' . esc_html( $best['shop_name'] ) . '</strong>';
			if ( ! empty( $best['city'] ) ) {
				$html .= ' (' . esc_html( $best['city'] ) . ')';
			}
			$html .= ' با قیمت <strong>' . esc_html( number_format_i18n( (int) $best['price'] ) ) . ' تومان</strong>';
			if ( ! empty( $data['sellers_count'] ) && $data['sellers_count'] > 1 ) {
				$html .= ' (از بین ' . (int) $data['sellers_count'] . ' فروشنده)';
			}
			$html .= '.</p>';
		}

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
	 * تبدیل key_specs گروهی به آرایه‌ی key/value.
	 *
	 * @param array $groups گروه‌ها.
	 * @return array
	 */
	private function key_specs_to_pairs( $groups ) {
		$pairs = array();
		foreach ( $groups as $group ) {
			if ( empty( $group['items'] ) || ! is_array( $group['items'] ) ) {
				continue;
			}
			foreach ( $group['items'] as $item ) {
				if ( isset( $item['key'], $item['value'] ) ) {
					$pairs[ (string) $item['key'] ] = (string) $item['value'];
				}
			}
		}
		return $pairs;
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
		$html  = '<table class="shoper-specs-table woocommerce-product-attributes-item__table" style="width:100%;border-collapse:collapse;margin:12px 0;">';
		$html .= '<tbody>';
		$i = 0;
		foreach ( $pairs as $k => $v ) {
			$bg = ( $i % 2 === 0 ) ? '#f8f8f8' : '#fff';
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
