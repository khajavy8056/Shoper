<?php
/**
 * نمای متاباکس در صفحه ویرایش محصول.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id        = get_the_ID();
$existing_key   = $post_id ? get_post_meta( $post_id, '_shoper_random_key', true ) : '';
$existing_url   = $post_id ? get_post_meta( $post_id, '_shoper_source_url', true ) : '';
$is_new_product = ( $post_id && 'auto-draft' === get_post_status( $post_id ) ) || ! $post_id;
?>
<div class="shoper-metabox">
	<p class="shoper-hint">
		بخشی از نام محصول را بنویسید — <strong>لازم نیست نام کامل را بدانید</strong>؛
		نام‌های کامل به‌صورت کشویی پیشنهاد می‌شوند. سپس عنوان، توضیحات، تصاویر و
		<strong>همه‌ی مشخصات فنی به‌صورت ویژگی‌های مجزا</strong> برای این محصول پر می‌شود.
	</p>

	<div class="shoper-field">
		<label>
			<input type="radio" name="shoper_input_mode" value="query" checked>
			جستجو با نام محصول
		</label>
		<label style="margin-right:12px;">
			<input type="radio" name="shoper_input_mode" value="url">
			لینک محصول
		</label>
	</div>

	<div class="shoper-field" id="shoper-query-wrap">
		<input type="text" id="shoper-query"
			placeholder="مثال: گوشی سامسونگ S25 Ultra"
			class="widefat" autocomplete="off">
	</div>

	<div class="shoper-field" id="shoper-url-wrap" style="display:none;">
		<input type="url" id="shoper-url"
			placeholder="https://torob.com/p/..."
			class="widefat" autocomplete="off">
	</div>

	<button type="button" class="button button-primary" id="shoper-search-btn">
		🔍 جستجو در ترب
	</button>

	<div id="shoper-results" class="shoper-results" style="display:none;"></div>
	<div id="shoper-preview" class="shoper-preview" style="display:none;"></div>

	<p id="shoper-fill-row" style="display:none; margin-top:10px;">
		<button type="button" class="button button-primary button-large" id="shoper-fill-btn" style="width:100%;">
			✅ پر کردن این محصول
		</button>
	</p>

	<div id="shoper-status" class="shoper-status"></div>

	<?php if ( $existing_key ) : ?>
		<p class="description">
			🛒 این محصول قبلاً از ترب پر شده است.
			<?php if ( $existing_url ) : ?>
				<a href="<?php echo esc_url( $existing_url ); ?>" target="_blank">مشاهده در ترب</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( $post_id ) : ?>
		<input type="hidden" id="shoper-post-id" value="<?php echo esc_attr( $post_id ); ?>">
	<?php endif; ?>
</div>
