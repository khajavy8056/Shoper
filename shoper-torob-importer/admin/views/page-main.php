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
		'shoper_data_source'    => isset( $_POST['data_source'] ) ? sanitize_text_field( wp_unslash( $_POST['data_source'] ) ) : 'auto',
		'shoper_catalog_source' => isset( $_POST['catalog_source'] ) ? sanitize_text_field( wp_unslash( $_POST['catalog_source'] ) ) : 'auto',
		'shoper_product_status' => isset( $_POST['product_status'] ) ? sanitize_text_field( wp_unslash( $_POST['product_status'] ) ) : 'draft',
		'shoper_import_gallery' => isset( $_POST['import_gallery'] ) ? 'yes' : 'no',
		'shoper_price_behavior' => isset( $_POST['price_behavior'] ) ? sanitize_text_field( wp_unslash( $_POST['price_behavior'] ) ) : 'cheapest',
		'shoper_proxy_url'      => isset( $_POST['proxy_url'] ) ? esc_url_raw( wp_unslash( $_POST['proxy_url'] ) ) : '',
		'shoper_relay_url'      => isset( $_POST['relay_url'] ) ? esc_url_raw( wp_unslash( $_POST['relay_url'] ) ) : '',
		'shoper_fetch_mode'     => isset( $_POST['fetch_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['fetch_mode'] ) ) : 'auto',
		'shoper_use_default_gateways' => isset( $_POST['use_default_gateways'] ) ? 'yes' : 'no',
		'shoper_extra_gateways' => isset( $_POST['extra_gateways'] ) ? sanitize_textarea_field( wp_unslash( $_POST['extra_gateways'] ) ) : '',
		'shoper_debug'         => isset( $_POST['debug'] ) ? '1' : '',
		'shoper_ai_enabled'    => isset( $_POST['ai_enabled'] ) ? 'yes' : 'no',
		'shoper_ai_auto'       => isset( $_POST['ai_auto'] ) ? 'yes' : 'no',
	);
	foreach ( $options as $k => $v ) {
		update_option( $k, $v );
	}
	echo '<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>';
}

$data_source    = get_option( 'shoper_data_source', 'auto' );
$catalog_source = get_option( 'shoper_catalog_source', 'auto' );
$product_status = get_option( 'shoper_product_status', 'draft' );
$import_gallery = get_option( 'shoper_import_gallery', 'yes' );
$price_behavior = get_option( 'shoper_price_behavior', 'cheapest' );
$proxy_url = get_option( 'shoper_proxy_url', '' );
$relay_url = get_option( 'shoper_relay_url', '' );
$fetch_mode = get_option( 'shoper_fetch_mode', 'auto' );
$use_default_gateways = get_option( 'shoper_use_default_gateways', 'yes' );
$extra_gateways = get_option( 'shoper_extra_gateways', '' );
$shoper_debug = get_option( 'shoper_debug', '' );
$ai_enabled = get_option( 'shoper_ai_enabled', 'yes' );
$ai_auto = get_option( 'shoper_ai_auto', 'yes' );
?>
<div class="wrap shoper-wrap" dir="rtl">
	<div class="shoper-brand">
		<div class="shoper-brand-mark" aria-hidden="true">S</div>
		<div class="shoper-brand-text">
			<h1 class="wp-heading-inline">Shoper Studio</h1>
			<p class="shoper-brand-tag">سازنده هوشمند محصول ووکامرس — از نام تا صفحه فروش، با نظارت شما</p>
			<p class="shoper-brand-author">طراحی و توسعه: <strong>خواجوی</strong> · نسخه <?php echo esc_html( SHOPER_VERSION ); ?></p>
		</div>
	</div>
	<hr class="wp-header-end">

	<div class="shoper-layout">
		<div class="shoper-main-col">
			<div class="shoper-card">
				<h2>۱. جستجوی محصول</h2>
				<p class="description">
					نام محصول را بنویسید. کاتالوگ، تصاویر، مشخصات و بعد متن کارشناسی آماده می‌شود؛ شما ناظر نهایی هستید.
				</p>
				<div class="notice notice-info inline" style="margin:10px 0 0;">
					<p>نام محصول را بنویسید. افزونه اول از <strong>دیجی‌کالا</strong> مشخصات، توضیحات و تصاویر کامل می‌گیرد (هر مشخصه یک ویژگی ووکامرس). اگر لازم شد سراغ ترب می‌رود. لینک <code>digikala.com/product/dkp-…</code> هم قبول است.</p>
				</div>

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
				<h2>۲. پیش‌نمایش، بازنویسی و نظارت</h2>
				<p class="description">
					مرحله‌ها: اطلاعات → تصاویر → بازنویسی هوشمند (تحلیل و بررسی) → نظارت شما روی متن و سئو.
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
					<button type="button" class="button" id="shoper-prev-step">مرحله قبل</button>
					<button type="button" class="button" id="shoper-next-step">مرحله بعد</button>
					<button type="button" class="button button-primary button-hero" id="shoper-create-btn">
						تأیید ناظر و ساخت محصول
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
								<select name="catalog_source">
									<option value="auto" <?php selected( $catalog_source, 'auto' ); ?>>خودکار (دیجی‌کالا، بعد ترب)</option>
									<option value="digikala" <?php selected( $catalog_source, 'digikala' ); ?>>فقط دیجی‌کالا</option>
									<option value="torob" <?php selected( $catalog_source, 'torob' ); ?>>فقط ترب</option>
									<option value="mock" <?php selected( $catalog_source, 'mock' ); ?>>داده نمونه</option>
								</select>
								<input type="hidden" name="data_source" value="auto">
							</td>
						</tr>
						<tr>
							<th>روش دریافت از ترب</th>
							<td>
								<select name="fetch_mode">
									<option value="auto" <?php selected( $fetch_mode, 'auto' ); ?>>خودکار (مرورگر، بعد سرور)</option>
									<option value="browser" <?php selected( $fetch_mode, 'browser' ); ?>>فقط مرورگر (هاست مسدود / خارج)</option>
									<option value="server" <?php selected( $fetch_mode, 'server' ); ?>>فقط سرور</option>
									<option value="relay" <?php selected( $fetch_mode, 'relay' ); ?>>رله ایران</option>
								</select>
								<p class="description">اگر عیب‌یابی سرور کد ۴۹۰ داد نگران نباشید؛ آن فقط IP هاست را می‌سنجد. جستجو را از کادر بالا امتحان کنید.</p>
							</td>
						</tr>
						<tr>
							<th>درگاه پیش‌فرض</th>
							<td>
								<label><input type="checkbox" name="use_default_gateways" value="yes" <?php checked( $use_default_gateways, 'yes' ); ?>>
									استفاده از درگاه تست‌شده (CORS.SH) وقتی ترب IP هاست را مسدود کند
								</label>
								<p class="description">فقط درگاهی که در تست زنده JSON ترب برگرداند به‌صورت پیش‌فرض فعال است. پروکسی‌های باز تصادفی اضافه نشدند چون قابل اعتماد و امن نبودند.</p>
							</td>
						</tr>
						<tr>
							<th>درگاه سفارشی</th>
							<td>
								<textarea class="large-text" rows="3" name="extra_gateways" placeholder="https://your-gateway.example/&#10;https://other.example/?url={url}"><?php echo esc_textarea( $extra_gateways ); ?></textarea>
								<p class="description">اختیاری؛ هر خط یک پیشوند یا الگو با <code>{url}</code>.</p>
							</td>
						</tr>
						<tr><th>پروکسی خروجی</th><td><input type="url" class="regular-text" name="proxy_url" value="<?php echo esc_attr( $proxy_url ); ?>" placeholder="http://user:pass@proxy.example:8080"><p class="description">پروکسی HTTP CONNECT اختیاری. فقط اگر خودتان پروکسی معتبر دارید پر کنید.</p></td></tr>
						<tr><th>آدرس رله ایران</th><td><input type="url" class="regular-text" name="relay_url" id="shoper-relay-url" value="<?php echo esc_attr( $relay_url ); ?>" placeholder="https://your-iran-host.com/shoper-relay.php?token=..."><p class="description">اگر جستجو از مرورگر هم کار نکرد، فایل <code>tools/shoper-relay.php</code> را روی یک هاست ایران بگذارید و آدرسش را اینجا ذخیره کنید.</p>
							<p><button type="button" class="button" id="shoper-download-relay">دانلود فایل رله</button></p></td></tr>
						<tr>
							<th>لاگ اشکال‌زدایی</th>
							<td>
								<label><input type="checkbox" name="debug" value="1" <?php checked( $shoper_debug, '1' ); ?>>
									ثبت جزئیات درخواست‌ها به ترب در لاگ سرور (فقط هنگام عیب‌یابی فعال کنید)
								</label>
								<p class="description">می‌توانید به‌جای این گزینه، ثابت <code>SHOPER_DEBUG</code> را در <code>wp-config.php</code> تعریف کنید. در حالت عادی هیچ اطلاعات حساسی ثبت نمی‌شود.</p>
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

				<h3>تست اتصال و عیب‌یابی</h3>
				<button type="button" class="button" id="shoper-test-conn">بررسی سریع اتصال</button>
				<button type="button" class="button" id="shoper-diagnostics-btn">🔍 عیب‌یابی کامل</button>
				<div id="shoper-conn-result" style="margin-top:10px;"></div>
				<div id="shoper-diagnostics" style="margin-top:12px;display:none;"></div>
			</div>

			<div class="shoper-card shoper-help">
				<h3>راهنما</h3>
				<ul>
					<li>جریان کار: <strong>نام</strong> → <strong>تصاویر</strong> → <strong>بازنویسی هوشمند</strong> → <strong>نظارت شما</strong>.</li>
					<li>توضیحات شامل معرفی، تحلیل کارشناسی، بررسی نقاط قوت و سئو است؛ نظر جعلی مشتری ساخته نمی‌شود.</li>
					<li>هر مشخصه به‌صورت <strong>یک ویژگی مجزا</strong> در تب ویژگی‌های ووکامرس ثبت می‌شود.</li>
					<li>تصاویر در <strong>کتابخانه رسانه</strong> با نام محصول ذخیره می‌شوند.</li>
					<li>محصول پیش‌فرض پیش‌نویس است تا ناظر قبل از انتشار تأیید کند.</li>
					<li>طراحی و توسعه: <strong>خواجوی</strong>.</li>
				</ul>
			</div>
		</aside>
	</div>
</div>
