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
		'shoper_proxy_url'      => isset( $_POST['proxy_url'] ) ? esc_url_raw( wp_unslash( $_POST['proxy_url'] ) ) : '',
		'shoper_relay_url'      => isset( $_POST['relay_url'] ) ? esc_url_raw( wp_unslash( $_POST['relay_url'] ) ) : '',
		'shoper_fetch_mode'     => isset( $_POST['fetch_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['fetch_mode'] ) ) : 'auto',
		'shoper_use_default_gateways' => isset( $_POST['use_default_gateways'] ) ? 'yes' : 'no',
		'shoper_extra_gateways' => isset( $_POST['extra_gateways'] ) ? sanitize_textarea_field( wp_unslash( $_POST['extra_gateways'] ) ) : '',
		'shoper_debug'         => isset( $_POST['debug'] ) ? '1' : '',
	);
	foreach ( $options as $k => $v ) {
		update_option( $k, $v );
	}
	echo '<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>';
}

$data_source    = get_option( 'shoper_data_source', 'direct' );
$product_status = get_option( 'shoper_product_status', 'draft' );
$import_gallery = get_option( 'shoper_import_gallery', 'yes' );
$price_behavior = get_option( 'shoper_price_behavior', 'cheapest' );
$proxy_url = get_option( 'shoper_proxy_url', '' );
$relay_url = get_option( 'shoper_relay_url', '' );
$fetch_mode = get_option( 'shoper_fetch_mode', 'auto' );
$use_default_gateways = get_option( 'shoper_use_default_gateways', 'yes' );
$extra_gateways = get_option( 'shoper_extra_gateways', '' );
$shoper_debug = get_option( 'shoper_debug', '' );
?>
<div class="wrap shoper-wrap" dir="rtl">
	<h1 class="wp-heading-inline">🛒 Shoper — درون‌ریز محصول از ترب</h1>
	<hr class="wp-header-end">

	<div class="shoper-layout">
		<div class="shoper-main-col">
			<div class="shoper-card">
				<h2>۱. جستجوی محصول در ترب</h2>
				<p class="description">
					نام محصول را بنویسید تا از ترب پیدا کنیم. می‌توانید لینک مستقیم محصول ترب را هم بچسبانید.
				</p>
				<div class="notice notice-info inline" style="margin:10px 0 0;">
					<p>در نسخه ۱.۲ لیست از <strong>همان مسیر سرور</strong> می‌آمد؛ کلیک به‌خاطر نرفتن لینک جزئیات خالی می‌ماند. اگر امروز مسیر مستقیم کد ۴۹۰ بدهد، افزونه خودکار از <strong>درگاه تست‌شده</strong> همان لیست را می‌آورد — نام را همین‌جا بنویسید.</p>
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
					<li>اگر ترب IP هاست را مسدود کند، افزونه از درگاه پیش‌فرض تست‌شده و بعد از مرورگر شما داده را می‌گیرد؛ کلیک روی پیشنهاد دیگر جزئیات را خالی نمی‌گذارد.</li>
					<li>تصاویر ابتدا در <strong>کتابخانه‌ی رسانه‌ی</strong> وردپرس ذخیره و سپس به محصول وصل می‌شوند.</li>
					<li>هر مشخصه‌ی فنی مانند «پردازنده»، «وزن» و «نوع شنا» به‌صورت <strong>یک ویژگی مجزا</strong> در تب ویژگی‌ها ثبت می‌شود.</li>
					<li>برای جلوگیری از تکرار، SKU محصول برابر با <code>TRB-{random_key}</code> قرار می‌گیرد.</li>
					<li>پیش‌فرض محصول به‌صورت پیش‌نویس ساخته می‌شود تا قبل از انتشار بررسی کنید.</li>
				</ul>
			</div>
		</aside>
	</div>
</div>
