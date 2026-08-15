<?php
/**
 * اجرای حذف افزونه.
 *
 * هنگام حذف افزونه (نه غیرفعال‌سازی) گزینه‌ها پاک می‌شوند.
 *
 * @package Shoper
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// حذف گزینه‌ها.
$options = array(
	'shoper_data_source',
	'shoper_product_status',
	'shoper_product_type',
	'shoper_import_gallery',
	'shoper_set_first_as_feat',
	'shoper_price_behavior',
	'shoper_user_agent',
	'shoper_request_timeout',
);
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// پاک کردن کش‌های موقت.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_shoper_sid_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_shoper_sid_%'" );
