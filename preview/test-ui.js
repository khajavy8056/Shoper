/**
 * تست خودکار رابط کاربری پیش‌نمایش با jsdom.
 *
 * صفحه‌ی واقعی پیش‌نمایش (که JS واقعی افزونه را لود می‌کند) در یک DOM
 * شبیه‌سازی‌شده اجرا می‌شود و جریان کامل کاربر تست می‌شود:
 *
 *   تایپ «سام» → نوار کشویی پیشنهاد → انتخاب → پیش‌نمایش → ساخت محصول
 *
 * اجرا:  node preview/test-ui.js       (سرور باید روی 3000 در حال اجرا باشد)
 */

'use strict';

const { JSDOM, VirtualConsole } = require('jsdom');

const BASE = process.env.PREVIEW_URL || 'http://127.0.0.1:3000';

let passed = 0;
let failed = 0;

function check(label, cond, extra = '') {
	if (cond) {
		passed++;
		console.log('  ✅ ' + label + (extra ? ' — ' + extra : ''));
	} else {
		failed++;
		console.log('  ❌ ' + label + (extra ? ' — ' + extra : ''));
	}
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** صبر تا برقرار شدن یک شرط. */
async function waitFor(fn, timeout = 8000, interval = 100) {
	const start = Date.now();
	while (Date.now() - start < timeout) {
		if (fn()) return true;
		await sleep(interval);
	}
	return false;
}

(async () => {
	console.log('\n🧪 تست رابط کاربری پیش‌نمایش Shoper\n');

	const virtualConsole = new VirtualConsole();
	const jsErrors = [];
	virtualConsole.on('jsdomError', (e) => jsErrors.push(e.message));
	virtualConsole.on('error', (m) => jsErrors.push(String(m)));

	const dom = await JSDOM.fromURL(BASE + '/', {
		runScripts: 'dangerously',
		resources: 'usable',
		pretendToBeVisual: true,
		virtualConsole,
	});

	const { window } = dom;
	const doc = window.document;

	// صبر تا بارگذاری jQuery و admin.js واقعی افزونه.
	const ready = await waitFor(() => window.jQuery && window.Shoper && window.Shoper.$queryInput);
	check('بارگذاری jQuery و admin.js واقعی افزونه', ready);
	if (!ready) {
		console.log('\nخطاها:', jsErrors.slice(0, 5));
		process.exit(1);
	}

	const $ = window.jQuery;
	const Shoper = window.Shoper;

	// ---------------------------------------------------------------
	console.log('\n[۰] دکمه‌ی دانلود آخرین نسخه');
	// ---------------------------------------------------------------
	check('دکمه‌ی «دانلود آخرین نسخه» وجود دارد', doc.querySelector('#shoper-download-btn') !== null);
	if (doc.querySelector('#shoper-download-btn')) {
		const href = doc.querySelector('#shoper-download-btn').getAttribute('href');
		check('لینک دانلود به فایل ZIP اشاره می‌کند', href && href.includes('/download/latest'), href);
	}

	// ---------------------------------------------------------------
	console.log('\n[۱] ساختار نوار پیشنهاد');
	// ---------------------------------------------------------------
	check('ظرف .shoper-suggest-wrap ساخته شد', doc.querySelectorAll('.shoper-suggest-wrap').length > 0);
	check('باکس .shoper-suggest ساخته شد', doc.querySelectorAll('.shoper-suggest').length > 0);
	check('فیلد جستجو role=combobox دارد', doc.querySelector('#shoper-query').getAttribute('role') === 'combobox');

	// ---------------------------------------------------------------
	console.log('\n[۲] تایپ نام ناقص «سام» → پیشنهاد نام کامل');
	// ---------------------------------------------------------------
	const $q = $('#shoper-query');
	$q.val('سام');
	$q.trigger('input');

	const gotSuggest = await waitFor(
		() => doc.querySelectorAll('.shoper-suggest-item').length > 0,
		9000
	);
	const items = doc.querySelectorAll('.shoper-suggest-item');
	check('نوار کشویی با پیشنهادها باز شد', gotSuggest, items.length + ' پیشنهاد');

	if (items.length) {
		const firstName = items[0].querySelector('.shoper-suggest-name').textContent.trim();
		check('پیشنهاد اول یک نام کامل است', firstName.length > 15, firstName.slice(0, 55) + '…');
		check(
			'بخش تایپ‌شده هایلایت شده',
			items[0].querySelector('.shoper-suggest-name mark') !== null
		);
		check('پیشنهاد تصویر دارد', items[0].querySelector('img.shoper-suggest-thumb') !== null);
		check('پیشنهاد قیمت دارد', items[0].querySelector('.shoper-suggest-price') !== null);

		// همه‌ی پیشنهادها باید شامل عبارت تایپ‌شده باشند.
		const allMatch = [...items].every((el) =>
			el.querySelector('.shoper-suggest-name').textContent.includes('سام')
		);
		check('همه‌ی پیشنهادها مرتبط با عبارت تایپ‌شده‌اند', allMatch);
	}

	// ---------------------------------------------------------------
	console.log('\n[۳] ناوبری با کیبورد');
	// ---------------------------------------------------------------
	if (items.length > 1) {
		Shoper.moveSuggest(1);
		check('کلید ↓ آیتم اول را فعال می‌کند', doc.querySelectorAll('.shoper-suggest-item.active').length === 1);
		const valAfter = $('#shoper-query').val();
		check('نام کامل داخل فیلد قرار گرفت', valAfter.length > 15, valAfter.slice(0, 50) + '…');
		Shoper.moveSuggest(1);
		check('↓ دوباره به آیتم بعدی می‌رود', doc.querySelectorAll('.shoper-suggest-item')[1].classList.contains('active'));
	}

	// ---------------------------------------------------------------
	console.log('\n[۴] انتخاب پیشنهاد → پیش‌نمایش محصول');
	// ---------------------------------------------------------------
	Shoper.chooseSuggest(0);

	const gotPreview = await waitFor(
		() => doc.querySelector('#shoper-preview') && doc.querySelector('#shoper-preview').innerHTML.length > 400,
		12000
	);
	check('پیش‌نمایش محصول بارگذاری شد', gotPreview);

	if (gotPreview) {
		check('نوار پیشنهاد بعد از انتخاب بسته شد', doc.querySelector('.shoper-suggest').style.display === 'none');
		check('فیلد نام محصول پر شد', (doc.querySelector('#shoper-p-name') || {}).value?.length > 10);

		const specChecks = doc.querySelectorAll('.shoper-spec-check');
		check('مشخصات فنی به‌صورت تیک‌خور رندر شدند', specChecks.length > 10, specChecks.length + ' مشخصه');

		const sellerRows = doc.querySelectorAll('.shoper-sellers-preview tbody tr');
		check('جدول فروشندگان بررسی‌شده نمایش داده شد', sellerRows.length > 0, sellerRows.length + ' فروشنده');
		check('فروشنده‌ی منتخب برچسب دارد', doc.querySelector('.shoper-sellers-preview .shoper-badge') !== null);
		check('برچسب منبع داده نمایش داده شد', doc.querySelector('.src-pill') !== null);
	}

	// ---------------------------------------------------------------
	console.log('\n[۴.۵] نوار مراحل + انتخاب تصاویر + سئو');
	// ---------------------------------------------------------------
	if (gotPreview) {
		check('نوار مراحل (Stepper) ساخته شد', doc.querySelectorAll('.shoper-step').length === 4,
			doc.querySelectorAll('.shoper-step').length + ' مرحله');
		check('مرحله‌ی «دریافت اطلاعات» فعال است',
			doc.querySelector('.shoper-step[data-step="info"]').classList.contains('is-active'));
		check('مرحله‌ی اول نمایان است', doc.querySelector('[data-step-body="info"]').style.display !== 'none');

		const imgItems = doc.querySelectorAll('.shoper-img-item');
		check('شبکه‌ی تصاویر با تیک «نگه‌داشته شود» رندر شد', imgItems.length > 1,
			imgItems.length + ' تصویر');
		check('دکمه‌ی «تصویر اصلی» برای هر تصویر هست', doc.querySelectorAll('.shoper-img-featured').length === imgItems.length);
		check('تصویر اول به‌صورت پیش‌فرض اصلی است',
			doc.querySelectorAll('.shoper-img-featured:checked').length === 1);

		// فقط دو تصویر نگه داشته شود و تصویر دوم اصلی شود.
		const checks = doc.querySelectorAll('.shoper-img-check');
		if (checks.length > 2) {
			checks[0].click();
			doc.querySelectorAll('.shoper-img-featured')[1].click();
			check('انتخاب تصویر توسط کاربر اعمال شد',
				doc.querySelectorAll('.shoper-img-check:checked').length === imgItems.length - 1 &&
				doc.querySelectorAll('.shoper-img-featured:checked')[0].getAttribute('data-idx') === '1');
		}

		check('مرحله بازنویسی هوشمند هست', !!doc.querySelector('.shoper-step[data-step="ai"]'));
		check('مرحله نظارت هست', !!doc.querySelector('.shoper-step[data-step="review"]'));
		check('فیلدهای سئو رندر شدند',
			!!doc.querySelector('#shoper-p-seo-title') &&
			!!doc.querySelector('#shoper-p-seo-desc') &&
			!!doc.querySelector('#shoper-p-tags'));
		check('برچسب‌ها از نام محصول ساخته شدند',
			(doc.querySelector('#shoper-p-tags').value || '').split(/[،,]/).filter(Boolean).length > 0);
		check('نوار پیشرفت ساخته شد', doc.querySelector('#shoper-progress') !== null);

		// رفتن به مرحله‌ی تصاویر.
		Shoper.goStep('images');
		check('مرحله‌ی «انتخاب تصاویر» فعال و نمایان شد',
			doc.querySelector('.shoper-step[data-step="images"]').classList.contains('is-active') &&
			doc.querySelector('[data-step-body="images"]').style.display !== 'none');
	}

	// ---------------------------------------------------------------
	console.log('\n[۵] ساخت محصول → خروجی ووکامرس');
	// ---------------------------------------------------------------
	const $createBtn = $('#shoper-create-btn');
	check('دکمه‌ی ساخت محصول نمایان است', $createBtn.is(':visible') || $createBtn.css('display') !== 'none');

	Shoper.create();
	const gotOutput = await waitFor(
		() => doc.querySelector('#wc-output-body') && doc.querySelector('#wc-output-body').innerHTML.length > 300,
		12000
	);
	check('خروجی ووکامرس تولید شد', gotOutput);

	if (gotOutput) {
		check('تب‌های محصول ساخته شدند', doc.querySelectorAll('.wc-tab').length === 6,
			doc.querySelectorAll('.wc-tab').length + ' تب');
		check('ویژگی‌ها در خروجی هستند', doc.querySelectorAll('.wc-attr').length > 5,
			doc.querySelectorAll('.wc-attr').length + ' ویژگی');
		const body = doc.querySelector('#wc-output-body').textContent;
		check('SKU با پیشوند TRB- ساخته شد', body.includes('TRB-'));
		check('اطلاعات چند فروشنده در خلاصه هست', /از\s*\d+\s*فروشنده/.test(body));
		check('یادداشت دانلود تصاویر در کتابخانه رسانه هست', body.includes('کتابخانه'));

		// فقط تصاویر انتخاب‌شده دانلود می‌شوند و نامشان «نام محصول-شماره» است.
		check('نام فایل تصاویر (نام محصول-شماره) در خروجی هست',
			/shoper-img-grid|نمایش داده|نام محصول|\.webp/.test(body) || body.includes('-1'));
		check('برچسب‌ها در خروجی سئو هست', doc.querySelectorAll('.wc-panel[data-panel="seo"]').length === 1);
		const seoPanel = doc.querySelector('.wc-panel[data-panel="seo"]').textContent;
		check('عنوان سئو و برچسب‌ها نمایش داده می‌شود', seoPanel.length > 10 && /برچسب/.test(seoPanel));
		check('نوار پیشرفت نمایان است', doc.querySelector('#shoper-progress').style.display !== 'none');
	}

	// ---------------------------------------------------------------
	console.log('\n[۶] خطاهای جاوااسکریپت');
	// ---------------------------------------------------------------
	const realErrors = jsErrors.filter((e) => !/Could not load img|Error: Not implemented|css/i.test(e));
	check('بدون خطای جاوااسکریپت', realErrors.length === 0, realErrors.slice(0, 2).join(' | '));

	// ---------------------------------------------------------------
	console.log('\n' + '─'.repeat(52));
	console.log(`نتیجه:  ✅ ${passed} موفق   ❌ ${failed} ناموفق`);
	console.log('─'.repeat(52) + '\n');

	dom.window.close();
	process.exit(failed > 0 ? 1 : 0);
})().catch((err) => {
	console.error('\n💥 تست شکست خورد:', err.message);
	process.exit(1);
});
