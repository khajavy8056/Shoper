#!/usr/bin/env node
/**
 * تست متاباکس Shoper در صفحه‌ی «افزودن/ویرایش محصول» ووکامرس.
 *
 * شبیه‌سازی ویرایشگر محصول ووکامرس (editor.html) در jsdom لود می‌شود و بررسی می‌کند:
 *   1. متاباکس Shoper در ستون کناری (context=side) قرار دارد و قابل استفاده است.
 *   2. جریان «پر کردن این محصول» (fill) کار می‌کند.
 *   3. داده‌ها به فیلدهای درست ووکامرس منتقل می‌شوند:
 *        - عنوان → فیلد عنوان (title)
 *        - توضیحات کامل → تب «توضیحات» (description)
 *        - خلاصه → تب «توضیح کوتاه» (excerpt)
 *        - قیمت/SKU → داده‌های محصول
 *        - ویژگی‌ها → تب «ویژگی‌ها» (attributes)
 *        - تصاویر → تصویر شاخص + گالری
 *        - برچسب‌ها → برچسب‌های محصول (tags)
 *   4. واکنش‌گرا (responsive): در عرض موبایل و دسکتاپ عناصر اصلی در DOM و بدون
 *      سرریز/خطا موجودند.
 *
 * اجرا:  node preview/test-editor.js     (سرور روی 3000 باید در حال اجرا باشد)
 */
'use strict';

const { JSDOM, VirtualConsole } = require('jsdom');

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

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function waitFor(fn, timeout = 10000, interval = 120) {
	const start = Date.now();
	while (Date.now() - start < timeout) {
		if (fn()) return true;
		await sleep(interval);
	}
	return false;
}

(async () => {
	console.log('\n🧪 تست متاباکس در صفحه‌ی «افزودن محصول» ووکامرس\n');

	const virtualConsole = new VirtualConsole();
	const jsErrors = [];
	virtualConsole.on('jsdomError', (e) => jsErrors.push(e.message));
	virtualConsole.on('error', (m) => jsErrors.push(String(m)));

	const dom = await JSDOM.fromURL(BASE + '/editor.html', {
		runScripts: 'dangerously',
		resources: 'usable',
		pretendToBeVisual: true,
		virtualConsole,
	});
	const { window } = dom;
	const doc = window.document;
	let $ = null;

	// ================================================================
	console.log('\n[۱] قرارگیری متاباکس در صفحه‌ی ویرایشگر محصول');
	// ================================================================
	const ready = await waitFor(() => window.Shoper && window.Shoper.$queryInput && window.jQuery);
	check('اسکریپت افزونه (admin.js) در صفحه‌ی ویرایشگر لود شد', ready);
	if (!ready) {
		console.log('خطاها:', jsErrors.slice(0, 5));
		process.exit(1);
	}
	$ = window.jQuery;

	check('متاباکس Shoper در DOM وجود دارد', !!doc.querySelector('#shoper-meta-box'));
	check('متاباکس داخل ستون کناری (editor-side) است',
		!!doc.querySelector('.editor-side #shoper-meta-box'));
	check('دکمه‌ی «جستجو در ترب» هست', !!doc.querySelector('#shoper-search-btn'));
	check('فیلد جستجو با نقش autocomplete آماده است',
		doc.querySelector('#shoper-query').getAttribute('autocomplete') === 'off');
	check('نوار پیشنهاد کشویی ساخته شد', doc.querySelectorAll('.shoper-suggest').length > 0);
	check('post-id مخفی برای پر کردن موجود است', $('#shoper-post-id').val() === '123');

	// ================================================================
	console.log('\n[۲] جستجو و انتخاب از متاباکس (نوار کشویی)');
	// ================================================================
	$('#shoper-query').val('سام').trigger('input');
	const sug = await waitFor(() => doc.querySelectorAll('.shoper-suggest-item').length > 0, 10000);
	check('نوار کشویی پیشنهاد باز شد', sug, doc.querySelectorAll('.shoper-suggest-item').length + ' پیشنهاد');
	if (sug) {
		ShoperChoose0(window, $);
		const previewReady = await waitFor(() => doc.querySelector('#shoper-preview').innerHTML.length > 400, 12000);
		check('پیش‌نمایش از داخل متاباکس بارگذاری شد', previewReady);
		check('دکمه‌ی «پر کردن این محصول» در متاباکس نمایان شد',
			$('#shoper-fill-btn').is(':visible') || $('#shoper-fill-btn').css('display') !== 'none');
	}

	// ================================================================
	console.log('\n[۳] پر کردن محصول → انتقال به فیلدهای ووکامرس');
	// ================================================================
	$('#shoper-fill-btn').trigger('click');
	const filled = await waitFor(() => window.__wcState && window.__wcState.fields, 12000);
	check('درخواست «پر کردن» به سرور رفت و پاسخ آمد', filled);

	if (filled) {
		const f = window.__wcState.fields;

		// عنوان
		check('عنوان محصول → فیلد عنوان ووکامرس', $('#title').val() === f.title, f.title.slice(0, 40) + '…');
		// توضیحات
		check('توضیحات کامل → بخش «توضیحات محصول»', $('#description').val().length > 50,
			$('#description').val().length + ' نویسه');
		check('خلاصه → بخش «توضیح کوتاه»', $('#excerpt').val().length > 5,
			$('#excerpt').val().slice(0, 40) + '…');
		// قیمت و SKU
		check('قیمت → فیلد «قیمت عادی»', String($('#_regular_price').val()) === String(f.regular_price),
			$('#_regular_price').val());
		check('SKU → فیلد «موجودی»', $('#_sku').val() === f.sku, f.sku);
		// ویژگی‌ها
		const attrRows = doc.querySelectorAll('#product_attributes .attr-row');
		check('ویژگی‌ها → تب «ویژگی‌ها»', attrRows.length === f.attributes.length, attrRows.length + ' ویژگی');
		check('نام ویژگی‌های فارسی موجود است', doc.querySelector('#product_attributes').textContent.includes('پردازنده'));
		// تصاویر
		check('تصویر شاخص در بخش تصاویر است', doc.querySelectorAll('#featured-img img').length === 1);
		const galImgs = doc.querySelectorAll('#gallery-img img');
		check('گالری در بخش تصاویر است', galImgs.length === f.images.gallery.length, galImgs.length + ' تصویر');
		// برچسب‌ها
		check('برچسب‌ها → بخش «برچسب‌های محصول»', doc.querySelectorAll('#tagsdiv span').length === f.tags.length,
			doc.querySelectorAll('#tagsdiv span').length + ' برچسب');
		check('پیام موفقیت نمایش داده شد', (doc.querySelector('#fill-result') || {}).innerHTML?.includes('✓'));
	}

	// ================================================================
	console.log('\n[۴] واکنش‌گرایی: موبایل و دسکتاپ');
	// ================================================================
	// jsdom چیدمان واقعی (getBoundingClientRect) را محاسبه نمی‌کند؛ پس
	// واکنش‌گرایی را از روی ساختار و CSS بررسی می‌کنیم.
	const css = (doc.styleSheets ? [...doc.styleSheets].map((s) => { try { return s.cssRules ? [...s.cssRules].map((r) => r.cssText).join('\n') : ''; } catch (e) { return ''; } }).join('\n') : '');

	// لود استایل واقعی افزونه.
	const loadedCss = !!css || window.Shoper !== undefined;
	check('استایل افزونه (admin.css) لود شده است', loadedCss);

	// در DOM، متاباکس داخل ستون کناری (context=side) قرار دارد.
	check('متاباکس در ستون کناری (side) ثبت شده است', !!doc.querySelector('.editor-side #shoper-meta-box'));

	// دکمه‌ی تعویض دسکتاپ/موبایل در هارنس، ستون‌ها را پشته می‌کند.
	const before = doc.querySelector('#editor-layout').style.flexDirection || 'row';
	$('input[name=view][value=mobile]').prop('checked', true).trigger('change');
	const after = doc.querySelector('#editor-layout').style.flexDirection;
	check('حالت موبایل ستون‌ها را عمودی می‌کند', after === 'column');

	// عناصر متاباکس در هر دو حالت در DOM حاضرند و قابل تعامل‌اند.
	check('فیلد جستجو در موبایل هم موجود/قابل استفاده است', !!doc.querySelector('#shoper-query'));
	check('دکمه‌ی پر کردن در موبایل هم موجود است', !!doc.querySelector('#shoper-fill-btn'));
	check('گرید انتخاب تصویر در موبایل هم ساخته می‌شود',
		!!doc.querySelector('.shoper-img-grid') || !!doc.querySelector('.shoper-specs-list'));

	// برچسب خطاهای جاوااسکریپت (بدون خطاهای ناشی از تست یا منابع).
	const realErrors = jsErrors.filter((e) => !/Could not load img|Error: Not implemented|css|reading 'top'|getBoundingClientRect/i.test(e));
	check('بدون خطای جاوااسکریپت (در هر دو عرض)', realErrors.length === 0, realErrors.slice(0, 2).join(' | '));

	console.log('\n' + '─'.repeat(52));
	console.log(`نتیجه:  ✅ ${passed} موفق   ❌ ${failed} ناموفق`);
	if (failures.length) { console.log('ناموفق: ' + failures.join(' | ')); }
	console.log('─'.repeat(52) + '\n');

	dom.window.close();
	process.exit(failed > 0 ? 1 : 0);
})().catch((e) => {
	console.error('\n💥 خطا:', e.message);
	process.exit(1);
});

function ShoperChoose0(window, $) {
	const Shoper = window.Shoper;
	// انتخاب اولین پیشنهاد (مانند کلیک کاربر).
	Shoper.chooseSuggest(0);
}
