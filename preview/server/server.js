/**
 * سرور پیش‌نمایش افزونه Shoper.
 *
 * هدف: تست عملکرد واقعی افزونه قبل از نصب روی سایت.
 *
 * این سرور:
 *   1. همان فایل‌های واقعی افزونه (admin.js / admin.css) را سرو می‌کند،
 *      پس رابط کاربری‌ای که می‌بینید دقیقاً همان چیزی است که در وردپرس اجرا می‌شود.
 *   2. اندپوینت admin-ajax.php وردپرس را شبیه‌سازی می‌کند
 *      (shoper_suggest / shoper_search / shoper_preview / shoper_create).
 *   3. منطق سرور را از preview/server/torob-logic.js می‌گیرد که آینه‌ی
 *      دقیق کلاس‌های PHP افزونه است.
 *   4. دو حالت داده دارد:
 *        - live    : درخواست واقعی به api.torob.com (نیازمند دسترسی اینترنت سرور)
 *        - fixture : پاسخ‌های واقعیِ ضبط‌شده از ترب در preview/fixtures/
 *      حالت پیش‌فرض «auto» است: اول live را امتحان می‌کند و اگر شبکه در دسترس
 *      نبود خودکار روی fixture برمی‌گردد و این را در UI اعلام می‌کند.
 */

'use strict';

const http = require('http');
const fs = require('fs');
const fsp = require('fs/promises');
const path = require('path');
const { URL } = require('url');

const logic = require('./torob-logic');

const ROOT = path.resolve(__dirname, '../..');
const PLUGIN_DIR = path.join(ROOT, 'shoper-torob-importer');
const PREVIEW_DIR = path.join(ROOT, 'preview');
const FIXTURE_DIR = path.join(PREVIEW_DIR, 'fixtures');

const PORT = parseInt(process.env.PORT, 10) || 3000;
const HOST = '0.0.0.0';

const TOROB_BASE = 'https://api.torob.com';
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

/** وضعیت دسترسی به شبکه — یک‌بار تشخیص داده می‌شود. */
let liveAvailable = null;

/* -------------------------------------------------------------------------- */
/* لایه‌ی داده                                                                  */
/* -------------------------------------------------------------------------- */

async function torobFetch(pathname, params) {
	const url = new URL(TOROB_BASE + pathname);
	for (const [k, v] of Object.entries(params)) {
		if (v !== undefined && v !== '') url.searchParams.set(k, v);
	}

	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), 12000);
	try {
		const res = await fetch(url.toString(), {
			signal: controller.signal,
			headers: {
				Accept: 'application/json, text/plain, */*',
				'Accept-Language': 'fa-IR,fa;q=0.9,en;q=0.8',
				'User-Agent': UA,
				Referer: 'https://torob.com/',
				Origin: 'https://torob.com',
			},
		});
		if (res.status === 429) throw new Error('ترب تعداد درخواست‌ها را محدود کرده است (429).');
		if (!res.ok) throw new Error(`پاسخ غیرمنتظره از ترب (کد ${res.status}).`);
		const json = await res.json();
		if (json && json.error) {
			throw new Error(json.error.message || 'خطای ترب.');
		}
		return json;
	} finally {
		clearTimeout(timer);
	}
}

async function readFixture(name) {
	const raw = await fsp.readFile(path.join(FIXTURE_DIR, name), 'utf8');
	return JSON.parse(raw);
}

/** آیا سرور به api.torob.com دسترسی دارد؟ */
async function probeLive() {
	if (liveAvailable !== null) return liveAvailable;
	try {
		await torobFetch('/v4/base-product/search/', { page: 0, size: 1, q: 'test', source: 'next_desktop' });
		liveAvailable = true;
	} catch (e) {
		liveAvailable = false;
	}
	return liveAvailable;
}

/**
 * گرفتن نتایج جستجو با احترام به حالت انتخابی.
 *
 * @returns {{data: object, source: 'live'|'fixture', note: string}}
 */
async function getSearch(query, mode) {
	if (mode !== 'fixture') {
		try {
			const raw = await torobFetch('/v4/base-product/search/', {
				page: 0, size: 10, q: query, source: 'next_desktop',
			});
			liveAvailable = true;
			return { data: logic.normalizeSearch(raw), source: 'live', note: '' };
		} catch (err) {
			liveAvailable = false;
			if (mode === 'live') throw err;
			// auto → سقوط به fixture.
			const raw = await readFixture('search-samsung.json');
			return {
				data: filterFixtureSearch(logic.normalizeSearch(raw), query),
				source: 'fixture',
				note: 'دسترسی زنده به ترب از این محیط ممکن نشد؛ از داده‌ی واقعیِ ضبط‌شده استفاده شد. (' + err.message + ')',
			};
		}
	}
	const raw = await readFixture('search-samsung.json');
	return { data: filterFixtureSearch(logic.normalizeSearch(raw), query), source: 'fixture', note: '' };
}

/** در حالت fixture، نتایج را بر اساس عبارت کاربر فیلتر می‌کنیم تا واقعی رفتار کند. */
function filterFixtureSearch(normalized, query) {
	const q = String(query || '').trim().toLowerCase();
	if (!q) return normalized;

	const tokens = q.split(/\s+/).filter(Boolean);
	const scored = normalized.results
		.map((item) => {
			const hay = (item.name1 + ' ' + item.name2).toLowerCase();
			let score = 0;
			for (const t of tokens) {
				if (hay.includes(t)) score += 1;
			}
			return { item, score };
		})
		.filter((x) => x.score > 0)
		.sort((a, b) => b.score - a.score);

	// اگر هیچ‌کدام مطابقت نداشت، همه را برگردان تا کاربر چیزی ببیند.
	const results = scored.length ? scored.map((x) => x.item) : normalized.results;
	return { ...normalized, results, count: results.length };
}

async function getDetails(prk, searchId, mode) {
	if (String(prk || '').indexOf('DKP-') === 0 || /^\d{4,}$/.test(String(prk || ''))) {
		const raw = await readFixture('dk-details-17918956.json');
		const data = logic.normalizeDigikalaDetails(raw);
		return { data, source: 'fixture', note: '' };
	}
	if (mode !== 'fixture') {
		try {
			const raw = await torobFetch('/v4/base-product/details/', {
				prk, search_id: searchId, source: 'next_desktop',
			});
			liveAvailable = true;
			return { data: logic.normalizeDetails(raw), source: 'live', note: '' };
		} catch (err) {
			liveAvailable = false;
			if (mode === 'live') throw err;
			const fx = await fixtureDetailsFor(prk);
			return {
				data: logic.normalizeDetails(fx),
				source: 'fixture',
				note: 'دسترسی زنده ممکن نشد؛ داده‌ی واقعیِ ضبط‌شده نمایش داده می‌شود.',
			};
		}
	}
	const fx = await fixtureDetailsFor(prk);
	return { data: logic.normalizeDetails(fx), source: 'fixture', note: '' };
}

/** انتخاب فایل fixture مناسب برای یک prk. */
async function fixtureDetailsFor(prk) {
	const files = await fsp.readdir(FIXTURE_DIR);
	const short = String(prk || '').split('-')[0];
	const match = files.find((f) => f.startsWith('details-') && short && f.includes(short));
	return readFixture(match || 'details-9bcf3364.json');
}

/* -------------------------------------------------------------------------- */
/* اندپوینت‌ها (شبیه‌سازی admin-ajax.php)                                        */
/* -------------------------------------------------------------------------- */

/** پاسخ موفق به سبک wp_send_json_success. */
function ok(res, data) {
	sendJson(res, 200, { success: true, data });
}

/** پاسخ خطا به سبک wp_send_json_error. */
function fail(res, message, code = 200) {
	sendJson(res, code, { success: false, data: { message } });
}

function sendJson(res, status, body) {
	const payload = JSON.stringify(body);
	res.writeHead(status, {
		'Content-Type': 'application/json; charset=utf-8',
		'Cache-Control': 'no-store',
	});
	res.end(payload);
}

const handlers = {
	/** پیشنهاد نام کامل محصول برای نوار کشویی. */
	async shoper_suggest(params, res) {
		const term = (params.get('term') || '').trim();
		if ([...term].length < 2) return ok(res, { suggestions: [] });

		const mode = params.get('data_mode') || 'auto';
		let out;
		try {
			out = await getSearch(term, mode);
		} catch (err) {
			// پیشنهاد نباید مزاحم تایپ شود.
			return ok(res, { suggestions: [], note: err.message });
		}

		const seen = new Set();
		const suggestions = [];
		for (const item of out.data.results) {
			// فروشندگان تبلیغاتی را در پیشنهادها نشان نده (آینه‌ی Shoper_Torob_Client::suggest).
			if (item.is_adv) continue;
			const name = (item.name1 || '').trim();
			if (!name || seen.has(name)) continue;
			seen.add(name);
			suggestions.push({
				label: name,
				name2: item.name2 || '',
				random_key: item.random_key,
				search_id: item.search_id,
				image_url: item.image_url,
				price: item.price,
				price_text: item.price_text,
				shop_text: item.shop_text,
				more_info_url: item.more_info_url || '',
				gallery: item.gallery || [],
				page_url: item.page_url || '',
			});
			if (suggestions.length >= 8) break;
		}
		ok(res, { term, suggestions, _source: out.source, _note: out.note });
	},

	async shoper_search(params, res) {
		const mode = params.get('data_mode') || 'auto';
		const url = (params.get('url') || '').trim();
		const query = (params.get('query') || '').trim();

		if (url) {
			const m = url.match(/\/p\/([0-9a-f-]{36})/i);
			if (!m) return fail(res, 'لینک محصول ترب معتبر به‌نظر نمی‌رسد.');
			return handlers.shoper_preview(new URLSearchParams({ prk: m[1], data_mode: mode }), res);
		}
		if (!query) return fail(res, 'نام محصول را وارد کنید.');

		try {
			const out = await getSearch(query, mode);
			if (!out.data.results.length) return fail(res, 'نتیجه‌ای برای این عبارت یافت نشد.');
			ok(res, { ...out.data, _source: out.source, _note: out.note });
		} catch (err) {
			fail(res, err.message);
		}
	},

	async shoper_ingest(params, res) {
		const kind = (params.get('kind') || 'details').trim();
		let raw = params.get('raw') || '';
		let decoded = null;
		try { decoded = typeof raw === 'string' ? JSON.parse(raw) : raw; } catch (e) { decoded = null; }
		if (!decoded || typeof decoded !== 'object') return fail(res, 'دادهٔ دریافتی از مرورگر قابل پردازش نیست.');

		try {
			if (kind === 'dk_search') {
				const data = logic.normalizeDigikalaSearch(decoded);
				return ok(res, { ...data, _source: 'browser' });
			}
			if (kind === 'dk_details') {
				const data = logic.normalizeDigikalaDetails(decoded);
				data.aggregate = logic.aggregate([], 3, 'score');
				data.description_html = logic.buildDescriptionHtml(data);
				data._source = 'browser';
				return ok(res, data);
			}
			if (kind === 'search') {
				const data = logic.normalizeSearch(decoded);
				return ok(res, { ...data, _source: 'browser' });
			}
			if (kind === 'search_item') {
				const item = decoded.name1 ? decoded : (logic.normalizeSearch({ results: [decoded] }).results[0] || {});
				const data = {
					...item,
					description: '',
					specs: {},
					key_specs: {},
					sellers: [],
					sellers_count: 0,
					partial: true,
					gallery: item.gallery && item.gallery.length ? item.gallery : (item.image_url ? [item.image_url] : []),
				};
				data.aggregate = logic.aggregate([], 3, 'score');
				data.description_html = logic.buildDescriptionHtml(data);
				data._source = 'partial';
				return ok(res, data);
			}
			const data = logic.normalizeDetails(decoded);
			data.aggregate = logic.aggregate(data.sellers, 3, 'score');
			if (data.aggregate.price) data.price = data.aggregate.price;
			data.description_html = logic.buildDescriptionHtml(data);
			data._source = 'browser';
			return ok(res, data);
		} catch (err) {
			fail(res, err.message);
		}
	},

	async shoper_preview(params, res) {
		const prk = (params.get('prk') || '').trim();
		const searchId = (params.get('search_id') || '').trim();
		const mode = params.get('data_mode') || 'auto';
		const limit = parseInt(params.get('seller_limit') || '3', 10);
		const strategy = params.get('seller_strategy') || 'score';

		if (!prk) return fail(res, 'شناسه محصول نامعتبر است.');

		try {
			const out = await getDetails(prk, searchId, mode);
			const data = out.data;

			// همان مسیر enrich در Shoper_Product_Builder.
			data.aggregate = logic.aggregate(data.sellers, limit, strategy);
			if (data.aggregate.price) data.price = data.aggregate.price;
			if (!data.gallery.length && data.image_url) data.gallery = [data.image_url];
			if (!data.image_url && data.gallery.length) data.image_url = data.gallery[0];

			data.description_html = logic.buildDescriptionHtml(data);
			data.short_description = logic.buildShortDescription(data);
			data._source = out.source;
			data._note = out.note;

			ok(res, data);
		} catch (err) {
			fail(res, err.message);
		}
	},

	/**
	 * شبیه‌سازی ساخت محصول در ووکامرس.
	 *
	 * محصول واقعی ساخته نمی‌شود (اینجا وردپرسی وجود ندارد)، اما دقیقاً
	 * همان چیزی که افزونه در دیتابیس می‌نویسد را برمی‌گرداند تا قبل از
	 * نصب روی سایت بتوانید نتیجه را بازبینی کنید.
	 */
	async shoper_create(params, res) {
		const prk = (params.get('prk') || '').trim();
		if (!prk) return fail(res, 'شناسه محصول نامعتبر است.');

		const mode = params.get('data_mode') || 'auto';
		const limit = parseInt(params.get('seller_limit') || '3', 10);
		const strategy = params.get('seller_strategy') || 'score';
		const status = params.get('status') || 'draft';
		const nameOverride = params.get('name') || '';
		const descOverride = params.get('description') || '';

		let allowedSpecs = null;
		try {
			const rawSpecs = params.get('specs');
			if (rawSpecs) allowedSpecs = JSON.parse(rawSpecs);
		} catch (e) { /* ignore */ }

		// انتخاب تصاویر — آینه‌ی Shoper_Ajax::create.
		let selectedImages = null;
		try {
			const raw = params.get('selected_images');
			if (raw) selectedImages = JSON.parse(raw);
		} catch (e) { /* ignore */ }
		let featuredImage = parseInt(params.get('featured_image') || '0', 10);
		if (isNaN(featuredImage)) featuredImage = 0;

		const seoTitle = params.get('seo_title') || '';
		const seoDesc = params.get('seo_desc') || '';
		let tags = null;
		try {
			const raw = params.get('tags');
			if (raw) tags = JSON.parse(raw);
		} catch (e) { /* ignore */ }

		try {
			let out = { source: 'fixture' };
			let data = null;
			const productJson = params.get('product_json') || '';
			if (productJson) {
				try {
					const parsed = JSON.parse(productJson);
					if (parsed && (parsed.random_key || parsed.name1)) {
						data = parsed;
						out.source = parsed._source || 'payload';
					}
				} catch (e) { /* fall through */ }
			}
			if (!data) {
				out = await getDetails(prk, params.get('search_id') || '', mode);
				data = out.data;
			}

			data.aggregate = data.aggregate || logic.aggregate(data.sellers || [], limit, strategy);
			if (data.aggregate.price) data.price = data.aggregate.price;
			if (nameOverride) data.name1 = nameOverride;

			// فیلتر مشخصات انتخاب‌شده — آینه‌ی Shoper_Ajax::create.
			if (Array.isArray(allowedSpecs)) {
				const filtered = {};
				for (const [k, v] of Object.entries(data.specs)) {
					if (allowedSpecs.includes(k)) filtered[k] = v;
				}
				data.specs = filtered;
			}

			const built = logic.buildAttributes(data.specs);
			const description = descOverride || logic.buildDescriptionHtml(data);
			const seo = logic.buildSeo(data);
			const finalTitle = seoTitle || seo.title;
			const finalDesc = seoDesc || seo.description;
			const finalTags = (Array.isArray(tags) && tags.length) ? tags : seo.tags;

			// انتخاب و نام‌گذاری تصاویر — آینه‌ی Shoper_Image_Handler::sideload_gallery.
			const total = (data.gallery || []).length;
			let indices = [];
			if (Array.isArray(selectedImages) && selectedImages.length) {
				indices = selectedImages.filter((i) => Number.isInteger(i) && i >= 0 && i < total);
			} else {
				indices = [];
				for (let i = 0; i < total; i++) indices.push(i);
			}
			if (!indices.includes(featuredImage)) featuredImage = indices[0];
			const fileBase = logic.fileBase(data.name1);
			const filenames = indices.map((_, idx) => fileBase + '-' + (idx + 1));
			const keptUrls = indices.map((i) => data.gallery[i]);

			ok(res, {
				simulated: true,
				product: {
					name: data.name1,
					status,
					sku: 'TRB-' + data.random_key,
					regular_price: data.price,
					short_description: logic.buildShortDescription(data),
					description,
					attributes: built.attrs,
					images: {
						featured: keptUrls[0] || '',
						gallery: keptUrls.slice(1),
						// در وردپرس این تصاویر با media_handle_sideload با نام
						// «{نام محصول}-{شماره}» در کتابخانه‌ی رسانه ذخیره می‌شوند.
						will_sideload: keptUrls.length,
						selected: indices,
						featured_index: featuredImage,
						filenames,
					},
					seo: {
						title: finalTitle,
						description: finalDesc,
						tags: finalTags,
					},
					meta: {
						_shoper_random_key: data.random_key,
						_shoper_source_url: data.page_url,
						_shoper_sellers_used: data.aggregate.considered.map((s) => s.shop_name).join('، '),
						_shoper_sellers_total: data.sellers_count,
						_shoper_seo_title: finalTitle,
						_shoper_seo_desc: finalDesc,
					},
				},
				specs_count: Object.keys(data.specs).length,
				sellers_used: data.aggregate.considered.length,
				sellers_total: data.sellers_count,
				image_info: { gallery_ids: keptUrls.slice(1).map((_, i) => i + 2), featured_id: 1 },
				filenames,
				seo: { title: finalTitle, description: finalDesc, tags: finalTags },
				_source: out.source,
			});
		} catch (err) {
			fail(res, err.message);
		}
	},

	/**
	 * شبیه‌سازی «پر کردن محصول موجود» از متاباکس صفحه‌ی ویرایش محصول.
	 *
	 * آینه‌ی Shoper_Product_Builder::fill_product. در وردپرس واقعی این داده‌ها
	 * مستقیماً روی WC_Product موجود نوشته می‌شود؛ اینجا فقط نشان می‌دهیم هر
	 * داده به کدام فیلد ووکامرس می‌رود.
	 */
	async shoper_fill(params, res) {
		const postId = parseInt(params.get('post_id') || '0', 10);
		const prk = (params.get('prk') || '').trim();
		if (!postId || !prk) return fail(res, 'شناسه محصول یا post_id نامعتبر است.');

		const mode = params.get('data_mode') || 'auto';
		const limit = parseInt(params.get('seller_limit') || '3', 10);
		const strategy = params.get('seller_strategy') || 'score';

		let selectedImages = null;
		try {
			const raw = params.get('selected_images');
			if (raw) selectedImages = JSON.parse(raw);
		} catch (e) { /* ignore */ }
		let featuredImage = parseInt(params.get('featured_image') || '0', 10);
		if (isNaN(featuredImage)) featuredImage = 0;

		const seoTitle = params.get('seo_title') || '';
		const seoDesc = params.get('seo_desc') || '';
		let tags = null;
		try {
			const raw = params.get('tags');
			if (raw) tags = JSON.parse(raw);
		} catch (e) { /* ignore */ }

		try {
			const out = await getDetails(prk, params.get('search_id') || '', mode);
			const data = out.data;
			data.aggregate = logic.aggregate(data.sellers, limit, strategy);
			if (data.aggregate.price) data.price = data.aggregate.price;

			const built = logic.buildAttributes(data.specs);
			const description = logic.buildDescriptionHtml(data);
			const shortDescription = logic.buildShortDescription(data);
			const seo = logic.buildSeo(data);
			const finalTitle = seoTitle || seo.title;
			const finalDesc = seoDesc || seo.description;
			const finalTags = (Array.isArray(tags) && tags.length) ? tags : seo.tags;

			const total = (data.gallery || []).length;
			let indices = [];
			if (Array.isArray(selectedImages) && selectedImages.length) {
				indices = selectedImages.filter((i) => Number.isInteger(i) && i >= 0 && i < total);
			} else {
				indices = [];
				for (let i = 0; i < total; i++) indices.push(i);
			}
			if (!indices.includes(featuredImage)) featuredImage = indices[0];
			const fileBase = logic.fileBase(data.name1);
			const filenames = indices.map((_, idx) => fileBase + '-' + (idx + 1));
			const keptUrls = indices.map((i) => data.gallery[i]);

			ok(res, {
				simulated: true,
				message: 'محصول با موفقیت از ترب پر شد. برای ذخیره‌ی نهایی دکمه‌ی «به‌روزرسانی» را بزنید.',
				post_id: postId,
				specs_count: Object.keys(data.specs).length,
				attr_errors: built.errors,
				reload: true,
				filenames,
				// فیلدهای ووکامرس که پر می‌شوند:
				wc_fields: {
					title: data.name1,                                  // ← فیلد عنوان محصول
					short_description: shortDescription,                // ← تب «توضیح کوتاه»
					description,                                        // ← تب «توضیحات»
					regular_price: data.price,                          // ← فیلد قیمت عادی (General)
					sku: 'TRB-' + data.random_key,                      // ← فیلد SKU (Inventory)
					attributes: built.attrs,                            // ← تب «ویژگی‌ها»
					images: {
						featured: keptUrls[0] || '',                     // ← تصویر شاخص
						gallery: keptUrls.slice(1),                      // ← گالری
						filenames,
					},
					tags: finalTags,                                    // ← برچسب‌های محصول (product_tag)
					seo: { title: finalTitle, description: finalDesc }, // ← متادیتای سئو / Yoast
				},
				_source: out.source,
			});
		} catch (err) {
			fail(res, err.message);
		}
	},

	async shoper_test_connection(params, res) {
		const mode = params.get('data_mode') || 'auto';
		if (mode === 'fixture') {
			return ok(res, { ok: true, message: 'حالت داده‌ی ضبط‌شده فعال است (بدون تماس با ترب).', source: 'fixture' });
		}
		try {
			const raw = await torobFetch('/v4/base-product/search/', {
				page: 0, size: 3, q: 'گوشی سامسونگ', source: 'next_desktop',
			});
			liveAvailable = true;
			const norm = logic.normalizeSearch(raw);
			ok(res, {
				ok: true,
				message: 'اتصال زنده به API ترب برقرار است.',
				count: norm.count,
				sample: norm.results[0] ? norm.results[0].name1 : '',
				source: 'live',
			});
		} catch (err) {
			liveAvailable = false;
			fail(res, 'اتصال زنده ممکن نشد: ' + err.message);
		}
	},
};

/* -------------------------------------------------------------------------- */
/* سرو کردن فایل‌های استاتیک                                                     */
/* -------------------------------------------------------------------------- */

const MIME = {
	'.html': 'text/html; charset=utf-8',
	'.js': 'application/javascript; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.json': 'application/json; charset=utf-8',
	'.svg': 'image/svg+xml',
	'.png': 'image/png',
	'.jpg': 'image/jpeg',
	'.webp': 'image/webp',
};

async function serveFile(res, filePath) {
	try {
		const data = await fsp.readFile(filePath);
		res.writeHead(200, {
			'Content-Type': MIME[path.extname(filePath)] || 'application/octet-stream',
			'Cache-Control': 'no-store',
		});
		res.end(data);
	} catch (err) {
		res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
		res.end('404 — فایل یافت نشد: ' + path.basename(filePath));
	}
}

/* -------------------------------------------------------------------------- */
/* سرور                                                                        */
/* -------------------------------------------------------------------------- */

const server = http.createServer(async (req, res) => {
	const parsed = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
	const pathname = parsed.pathname;

	// --- شبیه‌سازی admin-ajax.php ---
	if (pathname === '/admin-ajax.php') {
		let params;
		if (req.method === 'POST') {
			const body = await new Promise((resolve) => {
				let buf = '';
				req.on('data', (c) => { buf += c; });
				req.on('end', () => resolve(buf));
			});
			params = new URLSearchParams(body);
		} else {
			params = parsed.searchParams;
		}

		const action = params.get('action');
		const handler = handlers[action];
		if (!handler) return fail(res, `اکشن ناشناخته: ${action}`);

		try {
			await handler(params, res);
		} catch (err) {
			fail(res, 'خطای سرور پیش‌نمایش: ' + err.message);
		}
		return;
	}

	// --- وضعیت محیط ---
	if (pathname === '/api/env') {
		const live = await probeLive();
		return sendJson(res, 200, {
			live_available: live,
			default_mode: live ? 'live' : 'fixture',
			message: live
				? 'این محیط به api.torob.com دسترسی دارد — پیش‌نمایش با داده‌ی زنده اجرا می‌شود.'
				: 'این محیط sandbox به اینترنت دسترسی ندارد، بنابراین پیش‌نمایش از پاسخ‌های واقعیِ ضبط‌شده‌ی ترب استفاده می‌کند. روی هاست خودتان حالت زنده کار خواهد کرد.',
		});
	}

	// --- فایل‌های واقعی افزونه (تا رابط دقیقاً همان باشد) ---
	if (pathname.startsWith('/plugin/')) {
		const rel = pathname.replace('/plugin/', '');
		const safe = path.normalize(rel).replace(/^(\.\.[/\\])+/, '');
		return serveFile(res, path.join(PLUGIN_DIR, safe));
	}

	// --- فایل‌های پیش‌نمایش ---
	if (pathname === '/' || pathname === '/index.html') {
		return serveFile(res, path.join(PREVIEW_DIR, 'index.html'));
	}

	// --- دانلود آخرین نسخه‌ی افزونه (ZIP) ---
	if (pathname === '/download/latest' || pathname === '/download/shoper-torob-importer-1.4.0.zip') {
		const zip = path.join(ROOT, 'dist', 'shoper-torob-importer-1.4.0.zip');
		try {
			const data = await fsp.readFile(zip);
			res.writeHead(200, {
				'Content-Type': 'application/zip',
				'Content-Disposition': 'attachment; filename="shoper-torob-importer-1.3.2.zip"',
				'Content-Length': data.length,
				'Cache-Control': 'no-store',
			});
			return res.end(data);
		} catch (err) {
			res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
			return res.end('آرشیو ZIP هنوز ساخته نشده است. اسکریپت build را اجرا کنید.');
		}
	}

	const safe = path.normalize(pathname.slice(1)).replace(/^(\.\.[/\\])+/, '');
	return serveFile(res, path.join(PREVIEW_DIR, safe));
});

server.listen(PORT, HOST, async () => {
	console.log(`[Shoper preview] در حال اجرا روی http://${HOST}:${PORT}`);
	const live = await probeLive();
	console.log(
		live
			? '[Shoper preview] دسترسی زنده به api.torob.com: بله — حالت پیش‌فرض «زنده».'
			: '[Shoper preview] دسترسی زنده به api.torob.com: خیر — حالت پیش‌فرض «داده‌ی واقعی ضبط‌شده».'
	);
});
