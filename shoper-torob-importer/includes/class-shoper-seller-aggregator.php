<?php
/**
 * تجمیع اطلاعات چند فروشنده برای یک محصول.
 *
 * هدف: یک محصول در ترب ممکن است ۹۶ یا ۱۵۰ فروشنده داشته باشد. بررسی همه‌ی
 * آن‌ها هم کند است و هم بی‌فایده. این کلاس فقط چند فروشنده‌ی «برتر» را
 * انتخاب می‌کند و اطلاعات مفید (گارانتی، ارسال، ویژگی‌های کالا) را از میان
 * آن‌ها بیرون می‌کشد.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_Seller_Aggregator.
 */
class Shoper_Seller_Aggregator {

	/**
	 * تعداد پیش‌فرض فروشندگانی که بررسی می‌شوند.
	 */
	const DEFAULT_LIMIT = 3;

	/**
	 * انتخاب فروشندگان برتر و تجمیع اطلاعاتشان.
	 *
	 * @param array  $sellers لیست کامل فروشندگان نرمال‌شده.
	 * @param int    $limit   چند فروشنده بررسی شود.
	 * @param string $strategy معیار انتخاب: score | cheapest | merge.
	 * @return array
	 */
	public function aggregate( $sellers, $limit = self::DEFAULT_LIMIT, $strategy = 'score' ) {
		$result = array(
			'strategy'      => $strategy,
			'limit'         => (int) $limit,
			'total_sellers' => is_array( $sellers ) ? count( $sellers ) : 0,
			'considered'    => array(),
			'primary'       => null,
			'price'         => 0,
			'cheapest'      => 0,
			'highest'       => 0,
			'features'      => array(),
			'guarantee'     => '',
			'shipping'      => array(),
			'delivery'      => array(),
		);

		if ( empty( $sellers ) || ! is_array( $sellers ) ) {
			return $result;
		}

		// فقط فروشندگان موجود با قیمت معتبر.
		$available = array();
		foreach ( $sellers as $s ) {
			if ( ! empty( $s['price'] ) && (int) $s['price'] > 0 && ! empty( $s['availability'] ) ) {
				$available[] = $s;
			}
		}
		if ( empty( $available ) ) {
			return $result;
		}

		// دامنه‌ی قیمت از کل فروشندگان موجود.
		$prices             = wp_list_pluck( $available, 'price' );
		$result['cheapest'] = (int) min( $prices );
		$result['highest']  = (int) max( $prices );

		// مرتب‌سازی بر اساس استراتژی و برداشتن N مورد اول.
		$ranked     = $this->rank( $available, $strategy );
		$considered = array_slice( $ranked, 0, max( 1, (int) $limit ) );

		$result['considered'] = $considered;
		$result['primary']    = $considered[0];

		// قیمت: در حالت «معتبرترین» قیمتِ فروشنده‌ی منتخب، در بقیه ارزان‌ترین.
		if ( 'cheapest' === $strategy ) {
			$result['price'] = $result['cheapest'];
		} else {
			$result['price'] = (int) $considered[0]['price'];
		}

		// تجمیع اطلاعات از فروشندگان بررسی‌شده.
		$features  = array();
		$shipping  = array();
		$delivery  = array();
		$guarantee = '';

		foreach ( $considered as $s ) {
			// ویژگی‌های کالا از name2 فروشنده (مثل «پلمپ شرکتی, رجیستر شده»).
			if ( ! empty( $s['features'] ) ) {
				foreach ( preg_split( '/[،,]/u', $s['features'] ) as $piece ) {
					$piece = trim( $piece );
					if ( '' !== $piece && ! in_array( $piece, $features, true ) ) {
						$features[] = $piece;
					}
				}
			}
			if ( ! empty( $s['shipping'] ) ) {
				foreach ( $s['shipping'] as $ship ) {
					$ship = trim( $ship );
					if ( '' !== $ship && ! in_array( $ship, $shipping, true ) ) {
						$shipping[] = $ship;
					}
				}
			}
			foreach ( array( 'free_shipping', 'same_day', 'postage_fee' ) as $field ) {
				if ( ! empty( $s[ $field ] ) ) {
					$val = trim( $s[ $field ] );
					if ( '' !== $val && ! in_array( $val, $delivery, true ) ) {
						$delivery[] = $val;
					}
				}
			}
			if ( '' === $guarantee && ! empty( $s['guarantee'] ) && 'enabled' === $s['guarantee'] ) {
				$guarantee = 'دارای ضمانت ترب';
			}
		}

		$result['features']  = $features;
		$result['shipping']  = $shipping;
		$result['delivery']  = $delivery;
		$result['guarantee'] = $guarantee;

		return $result;
	}

	/**
	 * رتبه‌بندی فروشندگان بر اساس استراتژی.
	 *
	 * @param array  $sellers  فروشندگان موجود.
	 * @param string $strategy معیار.
	 * @return array
	 */
	private function rank( $sellers, $strategy ) {
		$list = $sellers;

		if ( count( $list ) < 2 ) {
			return $list;
		}

		if ( 'cheapest' === $strategy ) {
			usort(
				$list,
				function ( $a, $b ) {
					return (int) $a['price'] <=> (int) $b['price'];
				}
			);
			return $list;
		}

		// score یا merge: امتیاز فروشگاه اولویت دارد، سپس قیمت پایین‌تر.
		// آگهی‌های تبلیغاتی (is_adv) کمی جریمه می‌شوند تا نتیجه طبیعی بماند.
		$cheapest = min( wp_list_pluck( $list, 'price' ) );

		usort(
			$list,
			function ( $a, $b ) use ( $cheapest ) {
				$sa = $this->weight( $a, $cheapest );
				$sb = $this->weight( $b, $cheapest );
				if ( $sa === $sb ) {
					return (int) $a['price'] <=> (int) $b['price'];
				}
				return $sb <=> $sa; // نزولی.
			}
		);

		return $list;
	}

	/**
	 * محاسبه‌ی وزن یک فروشنده.
	 *
	 * ترکیبی از امتیاز فروشگاه و نزدیکی قیمت به ارزان‌ترین قیمت.
	 *
	 * @param array $seller   فروشنده.
	 * @param int   $cheapest ارزان‌ترین قیمت موجود.
	 * @return float
	 */
	private function weight( $seller, $cheapest ) {
		$score = isset( $seller['score'] ) ? (float) $seller['score'] : 0.0;

		// نرمال‌سازی امتیاز به بازه‌ی ۰..۱ (امتیاز ترب از ۵).
		$score_norm = min( 1.0, $score / 5.0 );

		// جریمه‌ی گرانی: اگر ۲۰٪ از ارزان‌ترین گران‌تر باشد، امتیاز قیمتش صفر.
		$price = (int) $seller['price'];
		$price_norm = 0.0;
		if ( $cheapest > 0 && $price > 0 ) {
			$ratio      = ( $price - $cheapest ) / $cheapest;
			$price_norm = max( 0.0, 1.0 - ( $ratio / 0.2 ) );
		}

		// گارانتی ترب یک امتیاز مثبت کوچک.
		$guarantee_bonus = ( isset( $seller['guarantee'] ) && 'enabled' === $seller['guarantee'] ) ? 0.1 : 0.0;

		// آگهی تبلیغاتی کمی جریمه می‌شود.
		$adv_penalty = ! empty( $seller['is_adv'] ) ? 0.15 : 0.0;

		return round( ( $score_norm * 0.6 ) + ( $price_norm * 0.4 ) + $guarantee_bonus - $adv_penalty, 4 );
	}
}
