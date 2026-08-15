<?php
/**
 * صفحه‌ی اصلی افزونه در پیشخوان ووکامرس.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ذخیره‌ی تنظیمات.
if ( isset( $_POST['shoper_save_settings'] ) && check_admin_referer( 'shoper_settings' ) ) {
	$options = array(
		'shoper_data_source'    => isset( $_POST['data_source'] ) ? sanitize_text_field( wp_unslash( $_POST['data_source'] ) ) : 'direct',
		'shoper_product_status' => isset( $_POST['product_status'] ) ? sanitize_text_field( wp_unslash( $_POST['product_status'] ) ) : 'draft',
		'shoper_import_gallery' => isset( $_POST['import_gallery'] ) ? 'yes' : 'no',
		'shoper_price_behavior' => isset( $_POST['price_behavior'] ) ? sanitize_text_field( wp_unslash( $_POST['price_behavior'] ) ) : 'cheapest',
		'shoper_seller_limit'   => isset( $_POST['seller_limit'] ) ? max( 1, min( 10, absint( $_POST['seller_limit'] ) ) ) : 3,
		'shoper_seller_strategy' => isset( $_POST['seller_strategy'] ) ? sanitize_text_field( wp_unslash( $_POST['seller_strategy'] ) ) : 'score',
	);
	foreach ( $options as $k => $v ) {
		update_option( $k, $v );
	}
	echo '<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>';
}

$data_source    = get_option( 'shoper_data_source', 'direct' );
$product_status = get_option( 'shoper_product_status', 'draft' );
$import_gallery = get_option( 'shoper_import_gallery', 'yes' );
$price_behavior  = get_option( 'shoper_price_behavior', 'cheapest' );
$seller_limit    = (int) get_option( 'shoper_seller_limit', 3 );
$seller_strategy = get_option( 'shoper_seller_strategy', 'score' );
?>
<div class="wrap shoper-wrap" dir="rtl">
	<h1 class="wp-heading-inline">🛒 Shoper — درون‌ریز محصول از ترب</h1>
	<hr class="wp-header-end">

	<div class="shoper-layout">
		<div class="shoper-main-col">
			<div class="shoper-card">
				<h2>۱. جستجوی محصول در ترب</h2>
				<p class="description">
					بخشی از نام محصول را بنویسید — لازم نیست نام کامل را بدانید.
					نوار کشویی، <strong>نام کامل</strong> محصولات را پیشنهاد می‌دهد
					(با کلیدهای ↑ ↓ حرکت کنید و با Enter انتخاب کنید).
					می‌توانید لینک مستقیم محصول ترب را هم بچسبانید.
				</p>

				<div class="shoper-field shoper-mode-toggle">
					<label><input type="radio" name="shoper_mode" value="query" checked> جستجو با نام</label>
					<label><input type="radio" name="shoper_mode" value="url"> لینک محصول</label>
				</div>

				<div class="shoper-input-row">
					<input type="text" id="shoper-query" class="regular-text"
						placeholder="مثال: گوشی سامسونگ S25 Ultra" autocomplete="off">
					<input type="url" id="shoper-url" class="regular-text"
						placeholder="https://torob.com/p/..." style="display:none;">
					<button type="button" class="button button-primary button-hero" id="shoper-search-btn">
						🔍 جستجو
					</button>
				</div>

				<div id="shoper-results" class="shoper-results"></div>
			</div>

			<div class="shoper-card" id="shoper-preview-card" style="display:none;">
				<h2>۲. پیش‌نمایش و ویرایش</h2>
				<p class="description">
					اطلاعات دریافت‌شده از ترب را بررسی کنید. می‌توانید قبل از ساخت، عنوان و توضیحات را ویرایش کنید
					و تیک مشخصات فنی‌ که می‌خواهید به‌عنوان ویژگی اضافه شوند را بزنید.
				</p>
				<div id="shoper-preview"></div>

				<div class="shoper-create-row">
					<label>وضعیت انتشار:
						<select id="shoper-create-status">
							<option value="draft" <?php selected( $product_status, 'draft' ); ?>>پیش‌نویس</option>
							<option value="publish" <?php selected( $product_status, 'publish' ); ?>>منتشر شده</option>
							<option value="pending" <?php selected( $product_status, 'pending' ); ?>>در انتظار بررسی</option>
						</select>
					</label>
					<button type="button" class="button button-primary button-hero" id="shoper-create-btn">
						✅ ساخت محصول در ووکامرس
					</button>
				</div>
			</div>
		</div>

		<aside class="shoper-side-col">
			<div class="shoper-card">
				<h3>تنظیمات</h3>
				<form method="post">
					<?php wp_nonce_field( 'shoper_settings' ); ?>
					<table class="form-table">
						<tr>
							<th>منبع داده</th>
							<td>
								<select name="data_source">
									<option value="direct" <?php selected( $data_source, 'direct' ); ?>>API مستقیم ترب</option>
									<option value="mock" <?php selected( $data_source, 'mock' ); ?>>داده نمونه (آزمایشی)</option>
								</select>
							</td>
						</tr>
						<tr>
							<th>وضعیت پیش‌فرض</th>
							<td>
								<select name="product_status">
									<option value="draft" <?php selected( $product_status, 'draft' ); ?>>پیش‌نویس</option>
									<option value="publish" <?php selected( $product_status, 'publish' ); ?>>منتشر شده</option>
								</select>
							</td>
						</tr>
						<tr>
							<th>قیمت</th>
							<td>
								<select name="price_behavior">
									<option value="cheapest" <?php selected( $price_behavior, 'cheapest' ); ?>>ارزان‌ترین فروشنده</option>
									<option value="none" <?php selected( $price_behavior, 'none' ); ?>>بدون قیمت</option>
								</select>
							</td>
						</tr>
						<tr>
							<th>تعداد فروشنده</th>
							<td>
								<input type="number" name="seller_limit" min="1" max="10" style="width:70px;"
									value="<?php echo esc_attr( $seller_limit ); ?>">
								<p class="description" style="margin:4px 0 0;">
									اطلاعات فقط از این تعداد فروشنده‌ی برتر جمع‌آوری می‌شود، نه از همه‌ی فروشگاه‌ها.
								</p>
							</td>
						</tr>
						<tr>
							<th>معیار انتخاب</th>
							<td>
								<select name="seller_strategy">
									<option value="score" <?php selected( $seller_strategy, 'score' ); ?>>معتبرترین (امتیاز بالاتر)</option>
									<option value="cheapest" <?php selected( $seller_strategy, 'cheapest' ); ?>>ارزان‌ترین موجود</option>
									<option value="merge" <?php selected( $seller_strategy, 'merge' ); ?>>ترکیب هوشمند</option>
								</select>
							</td>
						</tr>
						<tr>
							<th>گالری تصاویر</th>
							<td>
								<label><input type="checkbox" name="import_gallery" <?php checked( $import_gallery, 'yes' ); ?>>
									دانلود همه‌ی تصاویر به Media Library
								</label>
							</td>
						</tr>
					</table>
					<p><button type="submit" name="shoper_save_settings" class="button">ذخیره تنظیمات</button></p>
				</form>

				<hr>

				<h3>تست اتصال</h3>
				<button type="button" class="button" id="shoper-test-conn">بررسی اتصال به ترب</button>
				<div id="shoper-conn-result" style="margin-top:10px;"></div>
			</div>

			<div class="shoper-card shoper-help">
				<h3>راهنما</h3>
				<ul>
					<li>تصاویر ابتدا در <strong>کتابخانه‌ی رسانه‌ی</strong> وردپرس ذخیره و سپس به محصول وصل می‌شوند.</li>
					<li>هر مشخصه‌ی فنی مانند «پردازنده»، «وزن» و «نوع شنا» به‌صورت <strong>یک ویژگی مجزا</strong> در تب ویژگی‌ها ثبت می‌شود.</li>
					<li>برای جلوگیری از تکرار، SKU محصول برابر با <code>TRB-{random_key}</code> قرار می‌گیرد.</li>
					<li>پیش‌فرض محصول به‌صورت پیش‌نویس ساخته می‌شود تا قبل از انتشار بررسی کنید.</li>
				</ul>
			</div>
		</aside>
	</div>
</div>
