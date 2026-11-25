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
	 * کاتالوگ چندمنبعی.
	 *
	 * @var Shoper_Catalog
	 */
	private $catalog;

	/**
	 * سرویس‌گیرنده‌ی ترب (سازگاری).
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
		if ( ! class_exists( 'Shoper_Seller_Aggregator' ) ) {
			require_once SHOPER_PLUGIN_DIR . 'includes/class-shoper-seller-aggregator.php';
		}
		$this->catalog    = new Shoper_Catalog();
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
		return $this->catalog->suggest( $term );
	}

	/**
	 * جستجو.
	 *
	 * @param string $query عبارت.
	 * @return array|WP_Error
	 */
	public function search( $query ) {
		return $this->catalog->search( $query );
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
		$data = $this->catalog->details( $prk, $search_id, $more_info_url );
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
		$data = $this->catalog->details_from_url( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return $this->finalize( $data );
	}

	/**
	 * پیش‌نمایش از JSON خام ترب (جستجو یا جزئیات) که مرورگر/رله گرفته است.
	 *
	 * @param string $kind search | details | search_item
	 * @param mixed  $raw  دادهٔ خام.
	 * @return array|WP_Error
	 */
	public function preview_from_raw( $kind, $raw ) {
		$data = $this->catalog->ingest( $kind, $raw );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( 'search' === $kind || 'dk_search' === $kind ) {
			return $data;
		}
		return $this->finalize( $data );
	}

	/**
	 * نهایی‌کردن داده‌ی از قبل نرمال‌شده (بدون تماس دوباره با ترب).
	 *
	 * @param array $data داده.
	 * @return array|WP_Error
	 */
	public function preview_from_payload( $data ) {
		if ( ! is_array( $data ) || ( empty( $data['random_key'] ) && empty( $data['name1'] ) ) ) {
			return new WP_Error( 'invalid_payload', 'دادهٔ محصول برای ساخت ناقص است.' );
		}
		return $this->finalize( $data );
	}

	/**
	 * غنی‌سازی نهایی: تجمیع فروشندگان + ساخت توضیحات.
	 *
	 * @param array $data داده‌ی نرمال‌شده.
	 * @return array
	 */
	public function finalize( $data ) {
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
	/**
	 * حل‌کردن انتخاب متغیرهای کاربر در برابر داده‌ی منبع.
	 *
	 * $args['selected_variants']: فهرست انتخاب‌ها [{group, key}] یا [{group, title}].
	 * فقط گروه‌هایی برمی‌گردند که دست‌کم یک گزینه‌ی انتخاب‌شده دارند.
	 *
	 * @param array $data داده‌ی نرمال‌شده (شامل variants).
	 * @param array $args آرگومان‌ها.
	 * @return array [{title, items: [{key, title, price}]}]
	 */
	private function resolve_selected_variants( $data, $args ) {
		if ( empty( $data['variants'] ) || ! is_array( $data['variants'] ) ) {
			return array();
		}
		if ( empty( $args['selected_variants'] ) || ! is_array( $args['selected_variants'] ) ) {
			return array();
		}
		$wanted = array(); // groupTitle => [keyOrTitle => true]
		foreach ( $args['selected_variants'] as $sel ) {
			if ( ! is_array( $sel ) || empty( $sel['group'] ) ) {
				continue;
			}
			$mark = '';
			if ( ! empty( $sel['key'] ) ) {
				$mark = (string) $sel['key'];
			} elseif ( ! empty( $sel['title'] ) ) {
				$mark = (string) $sel['title'];
			}
			if ( '' === $mark ) {
				continue;
			}
			$wanted[ (string) $sel['group'] ][ $mark ] = true;
		}
		if ( empty( $wanted ) ) {
			return array();
		}
		$out = array();
		foreach ( $data['variants'] as $group ) {
			if ( empty( $group['title'] ) || empty( $group['items'] ) || ! is_array( $group['items'] ) ) {
				continue;
			}
			$gtitle = (string) $group['title'];
			if ( empty( $wanted[ $gtitle ] ) ) {
				continue;
			}
			$items = array();
			$by_title = array();
			foreach ( $group['items'] as $item ) {
				if ( ! is_array( $item ) || empty( $item['title'] ) ) {
					continue;
				}
				$key   = isset( $item['key'] ) ? (string) $item['key'] : '';
				$title = (string) $item['title'];
				if ( ! isset( $wanted[ $gtitle ][ $key ] ) && ! isset( $wanted[ $gtitle ][ $title ] ) ) {
					continue;
				}
				// دو گزینه‌ی هم‌عنوان یعنی دو variation یکسان در ووکامرس؛
				// فقط ارزان‌ترین نگه داشته می‌شود تا variation تکراری ساخته نشود.
				$price = isset( $item['price'] ) ? (int) $item['price'] : 0;
				if ( isset( $by_title[ $title ] ) ) {
					$idx = $by_title[ $title ];
					if ( $price > 0 && ( 0 === $items[ $idx ]['price'] || $price < $items[ $idx ]['price'] ) ) {
						$items[ $idx ]['price'] = $price;
					}
					continue;
				}
				$by_title[ $title ] = count( $items );
				$items[] = array(
					'key'   => '' !== $key ? $key : substr( md5( $title ), 0, 8 ),
					'title' => $title,
					'price' => $price,
				);
			}
			if ( ! empty( $items ) ) {
				$out[] = array( 'title' => $gtitle, 'items' => $items );
			}
			// حداکثر ۳ بُعد متغیر.
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * ساخت ویژگی‌های متغیر و خودِ variationها روی یک محصول ذخیره‌شده.
	 *
	 * @param WC_Product $product       محصول (هنوز ذخیره نشده یا شده).
	 * @param array      $groups        گروه‌های انتخاب‌شده.
	 * @param int        $base_position موقعیت شروع ویژگی‌ها.
	 * @return array { attrs: WC_Product_Attribute[], taxonomies: string[], errors: string[] }
	 */
	private function build_variation_attributes( $product, $groups, $base_position = 0 ) {
		$attrs      = array();
		$taxonomies = array();
		$errors     = array();
		$position   = (int) $base_position;
		foreach ( $groups as $group ) {
			$titles = array();
			foreach ( $group['items'] as $item ) {
				$titles[] = $item['title'];
			}
			$built = $this->attributes->make_variation_attribute( $group['title'], $titles, $position );
			if ( ! empty( $built['errors'] ) ) {
				$errors = array_merge( $errors, $built['errors'] );
			}
			if ( ! empty( $built['attribute'] ) ) {
				$attrs[]                    = $built['attribute'];
				$taxonomies[ $group['title'] ] = array(
					'taxonomy' => $built['taxonomy'],
					'term_ids' => $built['term_ids'],
				);
			}
			$position++;
		}
		return array(
			'attrs'      => $attrs,
			'taxonomies' => $taxonomies,
			'errors'     => $errors,
		);
	}

	/**
	 * ساخت WC_Product_Variation برای همه‌ی ترکیب‌های انتخاب‌شده.
	 *
	 * @param int   $product_id شناسه‌ی محصول والد.
	 * @param array $groups     گروه‌ها با نگاشت taxonomy/term_ids.
	 * @param array $tax_map    خروجی build_variation_attributes.
	 * @param int   $base_price قیمت پایه (پشتیبان).
	 * @param string $base_sku  SKU پایه.
	 * @return int تعداد variationهای ساخته‌شده.
	 */
	private function create_variations( $product_id, $groups, $tax_map, $base_price, $base_sku = '' ) {
		if ( ! class_exists( 'WC_Product_Variation' ) || empty( $groups ) ) {
			return 0;
		}
		// ترکیب دکارتی گزینه‌های گروه‌ها.
		$combos = array( array() );
		foreach ( $groups as $group ) {
			$next = array();
			foreach ( $combos as $combo ) {
				foreach ( $group['items'] as $item ) {
					$c              = $combo;
					$c[ $group['title'] ] = $item;
					$next[]         = $c;
				}
			}
			$combos = $next;
		}
		$count = 0;
		foreach ( $combos as $combo ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product_id );
			$variation->set_status( 'publish' );
			$attrs = array();
			$price = 0;
			$suffix = array();
			foreach ( $combo as $gtitle => $item ) {
				if ( empty( $tax_map[ $gtitle ] ) ) {
					continue;
				}
				$taxonomy = $tax_map[ $gtitle ]['taxonomy'];
				$term_id  = isset( $tax_map[ $gtitle ]['term_ids'][ $item['title'] ] ) ? (int) $tax_map[ $gtitle ]['term_ids'][ $item['title'] ] : 0;
				if ( $term_id ) {
					$term = get_term( $term_id, $taxonomy );
					if ( $term && ! is_wp_error( $term ) ) {
						$attrs[ $taxonomy ] = $term->slug;
					}
				}
				if ( $item['price'] > 0 && 0 === $price ) {
					$price = (int) $item['price'];
				}
				$suffix[] = $item['key'];
			}
			if ( empty( $attrs ) ) {
				continue;
			}
			$variation->set_attributes( $attrs );
			$variation->set_stock_status( 'instock' );
			if ( 0 === $price ) {
				$price = (int) $base_price;
			}
			if ( $price > 0 ) {
				$variation->set_regular_price( (string) $price );
			}
			if ( '' !== $base_sku && ! empty( $suffix ) ) {
				$variation->set_sku( $base_sku . '-' . implode( '-', $suffix ) );
			}
			if ( $variation->save() ) {
				$count++;
			}
		}
		if ( $count && class_exists( 'WC_Product_Variable' ) ) {
			WC_Product_Variable::sync( $product_id );
		}
		if ( $count && function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		return $count;
	}

	/**
	 * ادغام ویژگی‌های متغیر داخل فهرست ویژگی‌های محصول.
	 *
	 * نکته‌ی کلیدی ووکامرس: برای هر taxonomy فقط یک WC_Product_Attribute
	 * می‌تواند روی محصول باشد. پس اگر مشخصه‌ای (مثل «رنگ» یا «حافظه داخلی»)
	 * هم در مشخصات فنی هست و هم بُعد متغیر شده، نباید حذف یا دوتا شود؛
	 * همان ویژگی با variation=true و اجتماع همه‌ی مقدارها (مقدار مشخصه +
	 * گزینه‌های متغیر) باقی می‌ماند تا هم ویژگی‌ها کامل بمانند و هم
	 * variationها درست ساخته شوند.
	 *
	 * @param array $current_attrs ویژگی‌های فعلی/مشخصات.
	 * @param array $var_attrs     ویژگی‌های متغیر ساخته‌شده.
	 * @return array
	 */
	private function merge_variation_into_attributes( $current_attrs, $var_attrs ) {
		$out = is_array( $current_attrs ) ? array_values( $current_attrs ) : array();
		foreach ( (array) $var_attrs as $va ) {
			$merged = false;
			foreach ( $out as $i => $sa ) {
				if ( $sa->get_name() === $va->get_name() ) {
					$options = array_map( 'intval', (array) $sa->get_options() );
					foreach ( (array) $va->get_options() as $opt ) {
						if ( ! in_array( (int) $opt, $options, true ) ) {
							$options[] = (int) $opt;
						}
					}
					$va->set_options( $options );
					$va->set_visible( true );
					$va->set_variation( true );
					$out[ $i ] = $va;
					$merged    = true;
					break;
				}
			}
			if ( ! $merged ) {
				$out[] = $va;
			}
		}
		return $out;
	}

	/**
	 * اتصال صریح termهای ویژگی‌های متغیر به محصول والد.
	 *
	 * ذخیره‌ی CRUD ووکامرس معمولاً خودش این کار را می‌کند، ولی اتصال صریح
	 * تضمین می‌کند منوی انتخاب متغیر در فرانت‌اند و ویرایشگر محصول حتماً
	 * مقدارها را ببیند (مطابق الگوی رسمی ساخت محصول متغیر).
	 *
	 * @param int   $product_id شناسه.
	 * @param array $attrs      ویژگی‌های نهایی محصول.
	 * @return void
	 */
	private function attach_variation_terms( $product_id, $attrs ) {
		foreach ( (array) $attrs as $attr ) {
			if ( ! is_object( $attr ) || ! $attr->get_variation() || 0 !== strpos( (string) $attr->get_name(), 'pa_' ) ) {
				continue;
			}
			$options = array_map( 'intval', (array) $attr->get_options() );
			if ( $options ) {
				wp_set_object_terms( $product_id, $options, $attr->get_name(), false );
			}
		}
	}

	/**
	 * برنامه‌ی ساخت متغیر: قبل از دست‌کاری هر محصولی اعتبارسنجی کامل می‌شود.
	 *
	 * اگر هر بخشی هماهنگ نباشد (ویژگی ساخته نشود، termای گم شود، تعداد
	 * ترکیب‌ها از سقف امن ووکامرس بگذرد) برنامه نامعتبر است و صداکننده باید
	 * محصول را ساده بسازد — هرگز محصول متغیر نیمه‌کاره ساخته نمی‌شود.
	 *
	 * @param array $groups گروه‌های انتخاب‌شده.
	 * @return array { ok, var_built, tax_map, expected, errors }
	 */
	private function plan_variations( $groups ) {
		$plan = array(
			'ok'        => false,
			'var_built' => array( 'attrs' => array(), 'taxonomies' => array(), 'errors' => array() ),
			'tax_map'   => array(),
			'expected'  => 0,
			'errors'    => array(),
		);
		if ( empty( $groups ) || count( $groups ) > 3 ) {
			$plan['errors'][] = 'تعداد بُعدهای متغیر خارج از محدوده‌ی امن است.';
			return $plan;
		}
		$expected = 1;
		foreach ( $groups as $group ) {
			$n = isset( $group['items'] ) && is_array( $group['items'] ) ? count( $group['items'] ) : 0;
			if ( $n < 1 || $n > 50 ) {
				$plan['errors'][] = 'تعداد گزینه‌های «' . $group['title'] . '» خارج از محدوده‌ی امن است.';
				return $plan;
			}
			$expected *= $n;
		}
		// سقف عملی ساخت variation در ووکامرس؛ بیشتر از این یعنی داده ناهماهنگ است.
		if ( $expected > 50 ) {
			$plan['errors'][] = 'تعداد ترکیب‌های متغیر (' . $expected . ') از سقف امن ۵۰ بیشتر است.';
			return $plan;
		}
		$var_built = $this->build_variation_attributes( null, $groups, 0 );
		if ( ! empty( $var_built['errors'] ) || count( $var_built['attrs'] ) !== count( $groups ) ) {
			$plan['errors'][] = 'ساخت ویژگی‌های متغیر کامل نشد.';
			$plan['var_built'] = $var_built;
			return $plan;
		}
		// برای تک‌تک گزینه‌ها باید term معتبر داشته باشیم.
		foreach ( $groups as $group ) {
			$info = isset( $var_built['taxonomies'][ $group['title'] ] ) ? $var_built['taxonomies'][ $group['title'] ] : null;
			if ( ! $info || empty( $info['taxonomy'] ) ) {
				$plan['errors'][] = 'taxonomy ویژگی «' . $group['title'] . '» ساخته نشد.';
				return $plan;
			}
			foreach ( $group['items'] as $item ) {
				if ( empty( $info['term_ids'][ $item['title'] ] ) ) {
					$plan['errors'][] = 'term گزینه‌ی «' . $item['title'] . '» ساخته نشد.';
					return $plan;
				}
			}
		}
		$plan['ok']        = true;
		$plan['var_built'] = $var_built;
		$plan['tax_map']   = $var_built['taxonomies'];
		$plan['expected']  = $expected;
		return $plan;
	}

	/**
	 * راستی‌آزمایی بعد از ساخت: دقیقاً به تعداد مورد انتظار variation سالم هست؟
	 *
	 * @param int   $product_id شناسه.
	 * @param int   $expected   تعداد مورد انتظار.
	 * @param array $tax_map    نگاشت taxonomyها.
	 * @return bool
	 */
	private function verify_variations( $product_id, $expected, $tax_map ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$parent = wc_get_product( $product_id );
		if ( ! $parent || 'variable' !== $parent->get_type() ) {
			return false;
		}
		$children = $parent->get_children();
		if ( count( $children ) !== (int) $expected ) {
			return false;
		}
		$taxonomies = array();
		foreach ( (array) $tax_map as $info ) {
			if ( ! empty( $info['taxonomy'] ) ) {
				$taxonomies[] = $info['taxonomy'];
			}
		}
		foreach ( $children as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation || 'variation' !== $variation->get_type() ) {
				return false;
			}
			$attrs = $variation->get_attributes();
			foreach ( $taxonomies as $taxonomy ) {
				if ( empty( $attrs[ $taxonomy ] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * حذف همه‌ی variationهای یک محصول با متد CRUD (سازگار با HPOS).
	 *
	 * @param int $product_id شناسه.
	 * @return void
	 */
	private function delete_all_variations( $product_id ) {
		$ids = array();
		if ( function_exists( 'wc_get_product' ) ) {
			$parent = wc_get_product( $product_id );
			if ( $parent && method_exists( $parent, 'get_children' ) ) {
				$ids = $parent->get_children();
			}
		}
		if ( empty( $ids ) ) {
			$ids = get_children( array( 'post_parent' => $product_id, 'post_type' => 'product_variation', 'numberposts' => -1, 'fields' => 'ids' ) );
		}
		foreach ( (array) $ids as $child_id ) {
			$child = function_exists( 'wc_get_product' ) ? wc_get_product( $child_id ) : null;
			if ( $child && method_exists( $child, 'delete' ) ) {
				$child->delete( true );
			} else {
				wp_delete_post( $child_id, true );
			}
		}
	}

	/**
	 * شبکه‌ی امنیت: برگرداندن محصول به «ساده» وقتی ساخت متغیر کامل نشد.
	 *
	 * variationهای احتمالاً ساخته‌شده پاک، نوع محصول ساده، ویژگی‌های متغیر
	 * به حالت نمایشی ساده برمی‌گردند و قیمت پایه ثبت می‌شود — محصول هیچ‌وقت
	 * به‌صورت والدِ متغیرِ بدون variation رها نمی‌شود و محصول دومی هم
	 * ساخته نمی‌شود؛ همان محصول واحد به شکل سالم ساده ادامه می‌دهد.
	 *
	 * @param int   $product_id شناسه.
	 * @param array $data       داده‌ی منبع (قیمت پایه).
	 * @param array $var_taxonomies taxonomyهای متغیر.
	 * @return void
	 */
	private function rollback_to_simple( $product_id, $data, $var_taxonomies = array() ) {
		$this->delete_all_variations( $product_id );
		wp_set_object_terms( $product_id, 'simple', 'product_type' );
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		$attrs = $product->get_attributes();
		$dirty = false;
		foreach ( $attrs as $attr ) {
			if ( is_object( $attr ) && in_array( $attr->get_name(), (array) $var_taxonomies, true ) && $attr->get_variation() ) {
				$attr->set_variation( false );
				$dirty = true;
			}
		}
		if ( $dirty ) {
			$product->set_attributes( $attrs );
		}
		if ( ! empty( $data['price'] ) && ! $product->get_regular_price() ) {
			$product->set_regular_price( (string) (int) $data['price'] );
		}
		$product->save();
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
	}

	/**
	 * عکس‌برداری از variationهای فعلی (برای restore در صورت خطای بازسازی).
	 *
	 * @param int $product_id شناسه.
	 * @return array
	 */
	private function snapshot_variations( $product_id ) {
		$out = array();
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $out;
		}
		$parent = wc_get_product( $product_id );
		if ( ! $parent || ! method_exists( $parent, 'get_children' ) ) {
			return $out;
		}
		foreach ( $parent->get_children() as $child_id ) {
			$v = wc_get_product( $child_id );
			if ( ! $v ) {
				continue;
			}
			$out[] = array(
				'attributes'    => $v->get_attributes(),
				'regular_price' => $v->get_regular_price(),
				'sku'           => $v->get_sku(),
				'status'        => $v->get_status(),
			);
		}
		return $out;
	}

	/**
	 * بازگرداندن variationها از روی عکس‌برداری.
	 *
	 * @param int   $product_id شناسه.
	 * @param array $snapshot   خروجی snapshot_variations.
	 * @return int تعداد.
	 */
	private function restore_variations( $product_id, $snapshot ) {
		$count = 0;
		foreach ( (array) $snapshot as $row ) {
			if ( empty( $row['attributes'] ) || ! class_exists( 'WC_Product_Variation' ) ) {
				continue;
			}
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product_id );
			$variation->set_status( ! empty( $row['status'] ) ? $row['status'] : 'publish' );
			$variation->set_attributes( $row['attributes'] );
			$variation->set_stock_status( 'instock' );
			if ( ! empty( $row['regular_price'] ) ) {
				$variation->set_regular_price( (string) $row['regular_price'] );
			}
			if ( ! empty( $row['sku'] ) ) {
				$variation->set_sku( (string) $row['sku'] );
			}
			if ( $variation->save() ) {
				$count++;
			}
		}
		if ( $count && class_exists( 'WC_Product_Variable' ) ) {
			WC_Product_Variable::sync( $product_id );
		}
		return $count;
	}

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

		// بررسی تکراری (دو لایه: متای منبع + SKU) تا در یک اجرا هرگز دو
		// محصول از یک منبع ساخته نشود؛ همه‌ی متغیرها داخل همان یک محصول می‌روند.
		$existing = $this->find_existing( $data['random_key'] );
		if ( ! $existing && ! empty( $data['random_key'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$key = (string) $data['random_key'];
			$sku = ( 0 === strpos( $key, 'DKP-' ) || 0 === strpos( $key, 'TRB-' ) ) ? $key : ( 'TRB-' . $key );
			$existing = (int) wc_get_product_id_by_sku( $sku );
		}
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

		// متغیرهای انتخاب‌شده‌ی کاربر: اول «برنامه» اعتبارسنجی می‌شود؛ فقط
		// اگر همه‌چیز هماهنگ بود محصول متغیر ساخته می‌شود، وگرنه همان یک
		// محصول به‌صورت ساده و سالم ساخته می‌شود (با هشدار شفاف به کاربر).
		$selected_variants = $this->resolve_selected_variants( $data, $args );
		$variant_plan      = null;
		$variant_warning   = '';
		$is_variable       = false;
		if ( ! empty( $selected_variants ) && class_exists( 'WC_Product_Variable' ) ) {
			$variant_plan = $this->plan_variations( $selected_variants );
			if ( $variant_plan['ok'] ) {
				$is_variable = true;
			} else {
				$variant_warning = 'متغیرها به‌صورت کامل و هماهنگ ساخته نشدند (' . implode( ' ', $variant_plan['errors'] ) . ')؛ برای جلوگیری از محصول خراب، محصول به‌صورت ساده ساخته شد.';
			}
		}

		$product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
		$product->set_name( sanitize_text_field( $data['name1'] ) );
		$product->set_status( in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ? $status : 'draft' );
		$product->set_catalog_visibility( 'visible' );

		$ai_meta = $this->maybe_enhance( $data, $args );
		if ( ! empty( $args['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $args['short_description'] ) );
		} elseif ( ! empty( $ai_meta['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $ai_meta['short_description'] ) );
		} else {
			$product->set_short_description( wp_kses_post( $this->build_short_description( $data ) ) );
		}

		if ( ! empty( $args['description'] ) ) {
			$product->set_description( wp_kses_post( $args['description'] ) );
		} elseif ( ! empty( $ai_meta['description_html'] ) ) {
			$product->set_description( wp_kses_post( $ai_meta['description_html'] ) );
		} else {
			$product->set_description( $this->build_description_html( $data ) );
		}

		if ( ! empty( $data['random_key'] ) ) {
			$key = (string) $data['random_key'];
			$sku = ( 0 === strpos( $key, 'DKP-' ) || 0 === strpos( $key, 'TRB-' ) ) ? $key : ( 'TRB-' . $key );
			$product->set_sku( $sku );
		}

		if ( ! $is_variable && 'none' !== $price_mode && ! empty( $data['price'] ) ) {
			$product->set_regular_price( (string) (int) $data['price'] );
		}

		// ویژگی‌ها (هر مشخصه یک attribute سراسری).
		$attr_errors  = array();
		$all_attrs    = array();
		$var_tax_map  = array();
		if ( ! empty( $data['specs'] ) && is_array( $data['specs'] ) ) {
			$built = $this->attributes->build_attributes( $data['specs'] );
			if ( ! empty( $built['attrs'] ) ) {
				$all_attrs = $built['attrs'];
			}
			if ( ! empty( $built['errors'] ) ) {
				$attr_errors = $built['errors'];
			}
		}
		if ( $is_variable ) {
			$var_built = $this->build_variation_attributes( $product, $selected_variants, count( $all_attrs ) );
			$var_tax_map = $var_built['taxonomies'];
			if ( ! empty( $var_built['errors'] ) ) {
				$attr_errors = array_merge( $attr_errors, $var_built['errors'] );
			}
			// مشخصه‌ای که خودش بُعد متغیر شده (مثل «رنگ») حذف نمی‌شود؛ همان
			// ویژگی با همه‌ی مقدارهایش می‌ماند و فقط variation=true می‌شود.
			$all_attrs = $this->merge_variation_into_attributes( $all_attrs, $var_built['attrs'] );
		}
		if ( ! empty( $all_attrs ) ) {
			$product->set_attributes( $all_attrs );
		}

		$product_id = $product->save();
		if ( ! $product_id ) {
			return new WP_Error( 'save_failed', 'ذخیره محصول در ووکامرس ناموفق بود.' );
		}

		$this->save_source_meta( $product_id, $data );

		// ساخت variationها عمداً در انتهای ساخت محصول انجام می‌شود (پایین‌تر)
		// تا اگر راستی‌آزمایی رد شد و محصول به ساده برگشت، هیچ ذخیره‌ی بعدی
		// با آبجکت قدیمی آن را دوباره متغیر نکند.
		$variations_count = 0;

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
		$seo = $this->compose_seo( $data, $args, $ai_meta );
		$seo_title = $seo['title'];
		$seo_desc  = $seo['description'];
		$tags      = $seo['tags'];
		$focus_kw  = $seo['keyword'];

		if ( ! empty( $tags ) ) {
			wp_set_object_terms( $product_id, $tags, 'product_tag' );
		}
		$this->save_seo_meta( $product_id, $seo_title, $seo_desc, $focus_kw, $ai_meta );

		// ساخت variationها با قیمت هر گزینه + راستی‌آزمایی نهایی؛ اگر دقیقاً
		// به تعداد مورد انتظار ساخته نشد، همه‌چیز به محصول ساده‌ی سالم برمی‌گردد.
		if ( $is_variable && $variant_plan ) {
			$this->attach_variation_terms( $product_id, $all_attrs );
			$base_sku = '';
			if ( ! empty( $data['random_key'] ) ) {
				$key      = (string) $data['random_key'];
				$base_sku = ( 0 === strpos( $key, 'DKP-' ) || 0 === strpos( $key, 'TRB-' ) ) ? $key : ( 'TRB-' . $key );
			}
			$variations_count = $this->create_variations(
				$product_id,
				$selected_variants,
				$var_tax_map,
				! empty( $data['price'] ) ? (int) $data['price'] : 0,
				$base_sku
			);
			if ( ! $this->verify_variations( $product_id, $variant_plan['expected'], $var_tax_map ) ) {
				$var_taxonomies = array();
				foreach ( $var_tax_map as $info ) {
					$var_taxonomies[] = $info['taxonomy'];
				}
				$this->rollback_to_simple( $product_id, $data, $var_taxonomies );
				$variations_count = 0;
				$is_variable      = false;
				$variant_warning  = 'ساخت متغیرها در راستی‌آزمایی نهایی کامل نشد؛ برای جلوگیری از محصول متغیر خراب، همان محصول به‌صورت ساده و سالم ساخته شد.';
			} else {
				update_post_meta( $product_id, '_shoper_variations_count', $variations_count );
			}
		}

		return array(
			'product_id'       => $product_id,
			'product_type'     => $is_variable ? 'variable' : 'simple',
			'variations_count' => $variations_count,
			'variant_warning'  => $variant_warning,
			'edit_link'        => get_edit_post_link( $product_id, 'url' ),
			'view_link'        => get_permalink( $product_id ),
			'image_info'       => $image_info,
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
		$ai_meta = $this->maybe_enhance( $data, $args );
		if ( ! empty( $args['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $args['short_description'] ) );
		} elseif ( ! empty( $ai_meta['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $ai_meta['short_description'] ) );
		} else {
			$product->set_short_description( wp_kses_post( $this->build_short_description( $data ) ) );
		}
		if ( ! empty( $args['description'] ) ) {
			$product->set_description( wp_kses_post( $args['description'] ) );
		} elseif ( ! empty( $ai_meta['description_html'] ) ) {
			$product->set_description( wp_kses_post( $ai_meta['description_html'] ) );
		} else {
			$product->set_description( $this->build_description_html( $data ) );
		}

		// متغیرهای انتخاب‌شده: اول برنامه اعتبارسنجی می‌شود؛ اگر هماهنگ نبود
		// اصلاً دست به نوع محصول و variationهای فعلی نمی‌زنیم.
		$selected_variants = $this->resolve_selected_variants( $data, $args );
		$variant_plan      = null;
		$variant_warning   = '';
		$want_variable     = false;
		if ( ! empty( $selected_variants ) && class_exists( 'WC_Product_Variable' ) ) {
			$variant_plan = $this->plan_variations( $selected_variants );
			if ( $variant_plan['ok'] ) {
				$want_variable = true;
			} else {
				$variant_warning = 'متغیرها کامل و هماهنگ نبودند (' . implode( ' ', $variant_plan['errors'] ) . ')؛ محصول بدون تغییر در بخش متغیرها پر شد.';
			}
		}
		$is_variable       = $want_variable;
		$variations_count  = 0;
		$var_tax_map       = array();
		$was_variable      = ( 'variable' === $product->get_type() );
		// از variationهای فعلی عکس می‌گیریم تا اگر بازسازی کامل نشد عیناً برگردند.
		$old_snapshot      = ( $want_variable && $was_variable ) ? $this->snapshot_variations( $post_id ) : array();
		if ( $want_variable && ! $was_variable ) {
			wp_set_object_terms( $post_id, 'variable', 'product_type' );
			$reloaded = wc_get_product( $post_id );
			if ( $reloaded ) {
				$product = $reloaded;
			}
		}

		if ( ! $want_variable && ! empty( $data['price'] ) && ! $product->get_regular_price() ) {
			$product->set_regular_price( (string) (int) $data['price'] );
		}

		$attr_errors = array();
		if ( ! empty( $data['specs'] ) && is_array( $data['specs'] ) ) {
			$built       = $this->attributes->build_attributes( $data['specs'] );
			$merged      = $this->attributes->merge_attributes( $product->get_attributes(), $built['attrs'] );
			$product->set_attributes( $merged );
			$attr_errors = $built['errors'];
		}

		if ( $want_variable ) {
			$current   = $product->get_attributes();
			$var_built = $this->build_variation_attributes( $product, $selected_variants, count( $current ) );
			$var_tax_map = $var_built['taxonomies'];
			if ( ! empty( $var_built['errors'] ) ) {
				$attr_errors = array_merge( $attr_errors, $var_built['errors'] );
			}
			if ( ! empty( $var_built['attrs'] ) ) {
				// merge_attributes ساده ویژگی متغیرِ هم‌taxonomy را کنار می‌زد؛
				// اینجا همان ویژگی موجود به variation=true ارتقا پیدا می‌کند.
				$product->set_attributes( $this->merge_variation_into_attributes( $current, $var_built['attrs'] ) );
			}
		}

		$product->save();

		if ( $want_variable && $variant_plan ) {
			$this->attach_variation_terms( $post_id, $product->get_attributes() );
			// variationهای قبلی جایگزین می‌شوند (عکسشان را برای برگشت داریم).
			$this->delete_all_variations( $post_id );
			$base_sku = (string) $product->get_sku();
			$variations_count = $this->create_variations(
				$post_id,
				$selected_variants,
				$var_tax_map,
				! empty( $data['price'] ) ? (int) $data['price'] : 0,
				$base_sku
			);
			if ( $this->verify_variations( $post_id, $variant_plan['expected'], $var_tax_map ) ) {
				update_post_meta( $post_id, '_shoper_variations_count', $variations_count );
			} else {
				// شبکه‌ی امنیت: یا variationهای قبلی عیناً برمی‌گردند، یا محصول
				// به ساده‌ی سالم برمی‌گردد — هرگز والد متغیرِ خالی نمی‌ماند.
				$this->delete_all_variations( $post_id );
				$variations_count = 0;
				$restored = ! empty( $old_snapshot ) ? $this->restore_variations( $post_id, $old_snapshot ) : 0;
				if ( $restored && count( $old_snapshot ) === $restored ) {
					$variant_warning = 'ساخت متغیرهای تازه کامل نشد؛ متغیرهای قبلی محصول سر جایشان برگشتند.';
				} else {
					$this->delete_all_variations( $post_id );
					$var_taxonomies = array();
					foreach ( $var_tax_map as $info ) {
						$var_taxonomies[] = $info['taxonomy'];
					}
					$this->rollback_to_simple( $post_id, $data, $var_taxonomies );
					$is_variable     = false;
					$variant_warning = 'ساخت متغیرها کامل نشد؛ برای جلوگیری از محصول خراب، محصول به‌صورت ساده و سالم درآمد.';
				}
			}
		}

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
		$seo       = $this->compose_seo( $data, $args, $ai_meta );
		$seo_title = $seo['title'];
		$seo_desc  = $seo['description'];
		$tags      = $seo['tags'];
		$focus_kw  = $seo['keyword'];
		if ( ! empty( $tags ) ) {
			wp_set_object_terms( $post_id, $tags, 'product_tag' );
		}
		$this->save_seo_meta( $post_id, $seo_title, $seo_desc, $focus_kw, $ai_meta );

		return array(
			'post_id'          => $post_id,
			'product_type'     => ( $fresh_product = wc_get_product( $post_id ) ) ? $fresh_product->get_type() : ( $is_variable ? 'variable' : 'simple' ),
			'variations_count' => $variations_count,
			'variant_warning'  => $variant_warning,
			'specs_count'      => ! empty( $data['specs'] ) ? count( $data['specs'] ) : 0,
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
			$html .= 'متن و مشخصات توسط <strong>Shoper Studio</strong> — خواجوی آماده شده است. منبع: ';
			$src_label = ( ! empty( $data['provider'] ) && 'digikala' === $data['provider'] ) ? 'دیجی‌کالا' : 'ترب';
			$html .= '<a href="' . esc_url( $data['page_url'] ) . '" target="_blank" rel="nofollow">' . esc_html( $src_label ) . '</a>.';
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

	/**
	 * اگر ناظر متن نفرستاده، استودیو/هوش مصنوعی متن را کامل می‌کند.
	 *
	 * @param array $data داده.
	 * @param array $args آرگومان.
	 * @return array
	 */
	private function maybe_enhance( $data, $args ) {
		if ( ! empty( $args['description'] ) ) {
			return array();
		}
		if ( 'no' === get_option( 'shoper_ai_enabled', 'yes' ) ) {
			return array();
		}
		if ( class_exists( 'Shoper_Copywriter' ) ) {
			// ساخت محصول نباید منتظر API ابری بماند؛ متن کامل همین‌جا آماده است.
			return Shoper_Copywriter::enhance( $data );
		}
		return array();
	}

	/**
	 * ترکیب سئوی منبع + استودیو + ویرایش ناظر.
	 *
	 * @param array $data  داده.
	 * @param array $args  آرگومان.
	 * @param array $ai    خروجی هوش مصنوعی.
	 * @return array
	 */
	private function compose_seo( $data, $args, $ai ) {
		$seo = $this->build_seo( $data );
		if ( ! empty( $ai['seo_title'] ) ) {
			$seo['title'] = $ai['seo_title'];
		}
		if ( ! empty( $ai['seo_desc'] ) ) {
			$seo['description'] = $ai['seo_desc'];
		}
		if ( ! empty( $ai['tags'] ) && is_array( $ai['tags'] ) ) {
			$seo['tags'] = $ai['tags'];
		}
		$title = ! empty( $args['seo_title'] ) ? sanitize_text_field( $args['seo_title'] ) : $seo['title'];
		$desc  = ! empty( $args['seo_desc'] ) ? sanitize_textarea_field( $args['seo_desc'] ) : $seo['description'];
		$tags  = ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) ) ? array_map( 'sanitize_text_field', $args['tags'] ) : $seo['tags'];
		$kw    = ! empty( $args['focus_keyword'] ) ? sanitize_text_field( $args['focus_keyword'] ) : ( ! empty( $ai['focus_keyword'] ) ? sanitize_text_field( $ai['focus_keyword'] ) : $title );
		return array(
			'title'       => $title,
			'description' => $desc,
			'tags'        => $tags,
			'keyword'     => $kw,
		);
	}

	/**
	 * ذخیره متادیتای سئو و افزونه‌های رایج.
	 *
	 * @param int    $post_id شناسه.
	 * @param string $title   عنوان.
	 * @param string $desc    توضیح.
	 * @param string $keyword کلمه کلیدی.
	 * @param array  $ai      متای هوش مصنوعی.
	 * @return void
	 */
	private function save_seo_meta( $post_id, $title, $desc, $keyword, $ai ) {
		update_post_meta( $post_id, '_shoper_seo_title', $title );
		update_post_meta( $post_id, '_shoper_seo_desc', $desc );
		update_post_meta( $post_id, '_shoper_focus_keyword', $keyword );
		if ( ! empty( $ai['provider'] ) ) {
			update_post_meta( $post_id, '_shoper_ai_provider', sanitize_text_field( $ai['provider'] ) );
			update_post_meta( $post_id, '_shoper_ai_at', current_time( 'mysql' ) );
		}
		if ( defined( 'WPSEO_VERSION' ) || class_exists( '\Yoast\WP\SEO\Main' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', $title );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $keyword );
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath' ) ) {
			update_post_meta( $post_id, 'rank_math_title', $title );
			update_post_meta( $post_id, 'rank_math_description', $desc );
			update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );
		}
	}

}
