<?php
/**
 * نمای یکپارچه منابع محصول (دیجی‌کالا + ترب).
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_Catalog.
 */
class Shoper_Catalog {

	/**
	 * منبع تنظیم‌شده: auto | digikala | torob | direct | mock.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * کلاینت ترب.
	 *
	 * @var Shoper_Torob_Client
	 */
	private $torob;

	/**
	 * کلاینت دیجی‌کالا.
	 *
	 * @var Shoper_Digikala_Client
	 */
	private $digikala;

	/**
	 * سازنده.
	 */
	public function __construct() {
		$mode = get_option( 'shoper_catalog_source', '' );
		if ( '' === $mode ) {
			$legacy = get_option( 'shoper_data_source', 'auto' );
			$mode   = ( 'direct' === $legacy ) ? 'torob' : $legacy;
		}
		if ( ! in_array( $mode, array( 'auto', 'digikala', 'torob', 'mock' ), true ) ) {
			$mode = 'auto';
		}
		$this->mode     = $mode;
		$this->torob    = new Shoper_Torob_Client();
		$this->digikala = new Shoper_Digikala_Client();
	}

	/**
	 * پیشنهاد.
	 *
	 * @param string $term عبارت.
	 * @return array|WP_Error
	 */
	public function suggest( $term ) {
		$errors = array();
		foreach ( $this->order_for_query( $term ) as $provider ) {
			$result = ( 'digikala' === $provider ) ? $this->digikala->suggest( $term ) : $this->torob->suggest( $term );
			if ( ! is_wp_error( $result ) && ! empty( $result['suggestions'] ) ) {
				$result['provider'] = $provider;
				return $result;
			}
			if ( is_wp_error( $result ) ) {
				$errors[] = $provider . ': ' . $result->get_error_message();
			}
		}
		if ( $errors ) {
			return new WP_Error( 'no_results', implode( ' | ', $errors ) );
		}
		return array(
			'term'        => $term,
			'suggestions' => array(),
		);
	}

	/**
	 * جستجو.
	 *
	 * @param string $query عبارت.
	 * @return array|WP_Error
	 */
	public function search( $query ) {
		$errors = array();
		foreach ( $this->order_for_query( $query ) as $provider ) {
			$result = ( 'digikala' === $provider ) ? $this->digikala->search( $query ) : $this->torob->search( $query );
			if ( ! is_wp_error( $result ) && ! empty( $result['results'] ) ) {
				$result['provider'] = $provider;
				return $result;
			}
			if ( is_wp_error( $result ) ) {
				$errors[] = $result;
			}
		}
		return $errors ? $errors[0] : new WP_Error( 'no_results', 'نتیجه‌ای یافت نشد.' );
	}

	/**
	 * جزئیات از شناسه.
	 *
	 * @param string $prk           شناسه.
	 * @param string $search_id     شناسه جستجوی ترب.
	 * @param string $more_info_url لینک جزئیات ترب.
	 * @return array|WP_Error
	 */
	public function details( $prk, $search_id = '', $more_info_url = '' ) {
		if ( Shoper_Digikala_Client::is_dkp( $prk ) ) {
			return $this->digikala->details( $prk );
		}
		if ( 'digikala' === $this->mode ) {
			return $this->digikala->details( $prk );
		}
		return $this->torob->details( $prk, $search_id, $more_info_url );
	}

	/**
	 * جزئیات از لینک.
	 *
	 * @param string $url لینک.
	 * @return array|WP_Error
	 */
	public function details_from_url( $url ) {
		$dkp = Shoper_Digikala_Client::extract_id( $url );
		if ( $dkp && ( false !== stripos( $url, 'digikala' ) || false !== stripos( $url, 'dkp-' ) ) ) {
			return $this->digikala->details( $dkp );
		}
		return $this->torob->details_from_url( $url );
	}

	/**
	 * نرمال‌سازی JSON خام مرورگر.
	 *
	 * @param string $kind نوع.
	 * @param mixed  $raw  داده.
	 * @return array|WP_Error
	 */
	public function ingest( $kind, $raw ) {
		if ( 'dk_search' === $kind || ( 'search' === $kind && is_array( $raw ) && ( isset( $raw['data']['products'] ) || isset( $raw['status'] ) ) && ! isset( $raw['results'] ) ) ) {
			return $this->digikala->ingest_search( $raw );
		}
		if ( 'dk_details' === $kind || ( 'details' === $kind && is_array( $raw ) && ( isset( $raw['data']['product'] ) || ( isset( $raw['product']['specifications'] ) ) ) ) ) {
			return $this->digikala->ingest_details( $raw );
		}
		if ( 'search' === $kind ) {
			return $this->torob->ingest_search( $raw );
		}
		if ( 'search_item' === $kind ) {
			return $this->torob->ingest_search_item( $raw );
		}
		return $this->torob->ingest_details( $raw );
	}

	/**
	 * ترتیب منابع برای یک عبارت/لینک.
	 *
	 * @param string $query عبارت.
	 * @return array
	 */
	private function order_for_query( $query ) {
		if ( 'mock' === $this->mode ) {
			return array( 'torob' );
		}
		if ( 'digikala' === $this->mode ) {
			return array( 'digikala' );
		}
		if ( 'torob' === $this->mode ) {
			return array( 'torob' );
		}
		if ( Shoper_Digikala_Client::extract_id( $query ) && false !== stripos( $query, 'dkp' ) ) {
			return array( 'digikala', 'torob' );
		}
		// پیش‌فرض auto: اول دیجی‌کالا (مشخصات کامل، هاست ایران)، بعد ترب.
		return array( 'digikala', 'torob' );
	}
}
