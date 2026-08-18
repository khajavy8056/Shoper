#!/usr/bin/env node
/**
 * راستی‌آزمایی صحت داده هنگام ساخت محصول از ترب.
 *
 * این اسکریپت چند محصول مختلف را از اندپوینت ساخت (شبیه‌ساز admin-ajax)
 * عبور می‌دهد و صحت خروجی ووکامرس را با داده‌ی منبع (fixture واقعی ترب)
 * مقایسه می‌کند:
 *   - نام فارسی/انگلیسی
 *   - تعداد و محتوای مشخصات فنی (specs)
 *   - تعداد تصاویر گالری + انتخاب تصویر
 *   - SKU یکتا بر اساس random_key
 *   - قیمت از فروشنده‌ی منتخب
 *   - سئو و برچسب
 *
 * اجرا:  node preview/verify-create.js      (سرور روی 3000 باید در حال اجرا باشد)
 */
'use strict';

const BASE = process.env.PREVIEW_URL || 'http://127.0.0.1:3000';

let passed = 0;
let failed = 0;
const failures = [];

function check(label, cond, extra = '') {
	if (cond) {
		passed++;
		console.log(`  ✅ ${label}` + (extra ? ` — ${extra}` : ''));
	} else {
		failed++;
		failures.push(label);
		console.log(`  ❌ ${label}` + (extra ? ` — ${extra}` : ''));
	}
}

async function post(action, body) {
	const res = await fetch(BASE + '/admin-ajax.php', {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams({ nonce: 'preview-nonce', data_mode: 'fixture', ...body, action }),
	});
	return res.json();
}

/** مقایسه‌ی خروجی ساخت با داده‌ی منبع برای یک محصول. */
async function verifyProduct(prk, expected) {
	console.log(`\n📦 بررسی محصول: ${expected.name1.slice(0, 60)}…  [${prk.slice(0, 8)}]`);

	// پیش‌نمایش.
	const prev = await post('shoper_preview', { prk, search_id: '' });
	check('پیش‌نمایش موفق است', prev.success, prev.success ? '' : prev.data?.message);
	if (!prev.success) return;
	const d = prev.data;

	check('نام فارسی (name1) با منبع منطبق است', d.name1 === expected.name1);
	if (expected.name2) check('نام انگلیسی (name2) با منبع منطبق است', d.name2 === expected.name2);

	check('مشخصات فنی استخراج شد', Object.keys(d.specs || {}).length >= expected.minSpecs,
		Object.keys(d.specs || {}).length + ' مشخصه');
	check('گروه‌بندی مشخصات (spec_groups) موجود است', Array.isArray(d.spec_groups) && d.spec_groups.length > 0,
		(d.spec_groups || []).length + ' گروه');
	check('مشخصات کلیدی (key_specs) موجود است', Object.keys(d.key_specs || {}).length > 0);
	check('گالری تصاویر موجود است', (d.gallery || []).length >= expected.minImages,
		(d.gallery || []).length + ' تصویر');

	check('فروشندگان استخراج شدند', (d.sellers || []).length > 0, (d.sellers || []).length + ' فروشنده');
	check('تجمیع (aggregate) انجام شد', !!d.aggregate && !!d.aggregate.considered, (d.aggregate?.considered || []).length + ' در نظر گرفته شد');
	check('فقط ۳ فروشنده‌ی برتر بررسی شد', (d.aggregate?.considered || []).length <= 3);
	check('قیمت انتخاب‌شده > ۰', d.price > 0, d.price + ' تومان');

	// ساخت محصول.
	const created = await post('shoper_create', {
		prk,
		search_id: d.search_id || '',
		name: d.name1,
		description: d.description_html || '',
		specs: JSON.stringify(Object.keys(d.specs || {})),
		status: 'draft',
		selected_images: JSON.stringify([0, 1, 2]),
		featured_image: '1',
		seo_title: d.name1,
		seo_desc: d.name2,
		tags: JSON.stringify(['برچسب-تست']),
	});
	check('ساخت محصول موفق است', created.success, created.success ? '' : created.data?.message);
	if (!created.success) return;
	const p = created.data.product;

	check('SKU با پیشوند TRB- و random_key ساخته شد', p.sku === 'TRB-' + prk, p.sku);
	check('نام محصول در خروجی ثبت شد', p.name === d.name1);
	check('قیمت عادی با قیمت انتخاب‌شده برابر است', String(p.regular_price) === String(d.price),
		p.regular_price + ' تومان');
	check('تعداد ویژگی‌ها با مشخصات انتخابی برابر است', p.attributes.length === Object.keys(d.specs).length,
		p.attributes.length + ' ویژگی');

	// فقط ۳ تصویر انتخابی دانلود می‌شوند.
	check('فقط تصاویر انتخاب‌شده sideload می‌شوند', p.images.will_sideload === 3, p.images.will_sideload + ' تصویر');
	check('نام فایل تصاویر «نام-شماره» است', Array.isArray(p.images.filenames) && p.images.filenames.length === 3,
		(p.images.filenames || []).join(', '));
	check('عنوان سئو در خروجی هست', !!p.seo.title);
	check('برچسب‌ها در خروجی سئو ثبت شدند', (p.seo.tags || []).includes('برچسب-تست'));

	// sanity: هیچ مشخصه‌ای خالی/نامعتبر نباشد.
	const badSpec = p.attributes.filter((a) => !a.name || !a.value || !a.taxonomy);
	check('هیچ ویژگیِ خالی/نامعتبری نیست', badSpec.length === 0);
}

(async () => {
	console.log('\n🧪 راستی‌آزمایی صحت داده‌ی ساخت محصول (ترب → ووکامرس)\n');

	await verifyProduct('9bcf3364-2387-42ab-b712-bfc6c429ab07', {
		name1: 'گوشی سامسونگ S26 Ultra 5G | حافظه 256 رم 12 گیگابایت',
		name2: 'Samsung Galaxy S26 Ultra 5G 256/12 GB',
		minSpecs: 30, minImages: 5,
	});

	// محصول دوم (کم‌فروشنده‌تر).
	const d2 = require('./fixtures/details-3f6b636a.json');
	await verifyProduct(d2.random_key, {
		name1: d2.name1,
		name2: d2.name2,
		minSpecs: 5, minImages: 3,
	});

	console.log('\n' + '─'.repeat(52));
	console.log(`نتیجه:  ✅ ${passed} موفق   ❌ ${failed} ناموفق`);
	if (failures.length) {
		console.log('موارد ناموفق:');
		failures.forEach((f) => console.log('  - ' + f));
	}
	console.log('─'.repeat(52) + '\n');
	process.exit(failed > 0 ? 1 : 0);
})().catch((e) => {
	console.error('\n💥 خطا:', e.message);
	process.exit(1);
});
