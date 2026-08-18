/**
 * پورت جاوااسکریپتی منطق سمت‌سرور افزونه.
 *
 * این فایل عمداً آینه‌ی دقیق کلاس‌های PHP زیر است تا پیش‌نمایش،
 * رفتار واقعی افزونه را تست کند نه یک شبیه‌سازی سرسری:
 *
 *   includes/class-shoper-torob-client.php     → normalizeDetails / normalizeSearch
 *   includes/class-shoper-seller-aggregator.php → aggregate / rank / weight
 *   includes/class-shoper-product-builder.php   → buildDescriptionHtml
 *   includes/class-shoper-attribute-handler.php → buildAttributes
 *
 * هر تغییری در منطق PHP باید اینجا هم اعمال شود.
 */

'use strict';

/* -------------------------------------------------------------------------- */
/* کمکی                                                                        */
/* -------------------------------------------------------------------------- */

const SUPPORTED_IMAGE = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

function isSupportedImage(url) {
	try {
		const path = new URL(url, 'https://x.invalid').pathname;
		const ext = (path.split('.').pop() || '').toLowerCase();
		return SUPPORTED_IMAGE.includes(ext);
	} catch (e) {
		return true;
	}
}

/** آینه‌ی stringify_spec_value در PHP. */
function stringifySpecValue(value) {
	let out;
	if (Array.isArray(value)) {
		out = value
			.filter((v) => v !== null && typeof v !== 'object')
			.map((v) => String(v).trim())
			.filter((v) => v !== '')
			.join('، ');
	} else if (typeof value === 'boolean') {
		out = value ? 'دارد' : 'ندارد';
	} else if (value === null || typeof value === 'object') {
		return '';
	} else {
		out = String(value);
	}
	out = out.trim();
	if (['', '[]', 'null', 'نامشخص', '-'].includes(out)) {
		return '';
	}
	return out;
}

function escHtml(str) {
	if (str === null || str === undefined) return '';
	return String(str)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#039;');
}

function numberFormatFa(num) {
	return String(num).replace(/\B(?=(\d{3})+(?!\d))/g, '٫');
}

/* -------------------------------------------------------------------------- */
/* نرمال‌سازی (آینه‌ی Shoper_Torob_Client)                                       */
/* -------------------------------------------------------------------------- */

function extractGallery(data) {
	const gallery = [];

	if (Array.isArray(data.media_urls)) {
		for (const m of data.media_urls) {
			const url = typeof m === 'object' && m ? m.url : m;
			if (url && isSupportedImage(url)) gallery.push(url);
		}
	}
	if (Array.isArray(data.image_urls)) {
		for (const group of data.image_urls) {
			if (!group || !Array.isArray(group.urls)) continue;
			for (const url of group.urls) {
				if (url && isSupportedImage(url)) gallery.push(url);
			}
		}
	}
	if (data.image_url && isSupportedImage(data.image_url)) {
		gallery.unshift(data.image_url);
	}
	return [...new Set(gallery)];
}

function extractSearchItem(item) {
	let prk = item.random_key || '';
	let searchId = '';
	const moreUrl = item.more_info_url || '';
	if (moreUrl) {
		try {
			const q = new URL(moreUrl).searchParams;
			if (q.get('prk')) prk = q.get('prk');
			if (q.get('search_id')) searchId = q.get('search_id');
		} catch (e) { /* ignore */ }
	}
	return {
		random_key: prk,
		search_id: searchId,
		name1: item.name1 || '',
		name2: item.name2 || '',
		price: parseInt(item.price, 10) || 0,
		price_text: item.price_text || '',
		shop_text: item.shop_text || '',
		image_url: item.image_url || '',
		gallery: extractGallery(item),
		page_url: item.web_client_absolute_url ? 'https://torob.com' + item.web_client_absolute_url : '',
		more_info_url: moreUrl,
		is_adv: !!item.is_adv,
	};
}

function normalizeSearch(data) {
	const results = (data.results || []).map(extractSearchItem);
	return {
		count: data.count !== undefined ? parseInt(data.count, 10) : results.length,
		results,
		next: data.next || '',
	};
}

function extractSellers(raw, type = 'online') {
	if (!Array.isArray(raw)) return [];
	return raw.filter((s) => s && typeof s === 'object').map((seller) => {
		let score = 0;
		if (seller.score_info && seller.score_info.score !== undefined) {
			score = parseFloat(seller.score_info.score) || 0;
		} else if (seller.shop_score !== undefined) {
			score = parseFloat(seller.shop_score) || 0;
		}
		const mi = seller.more_info || {};
		return {
			type,
			shop_name: seller.shop_name || '',
			city: seller.shop_name2 || '',
			shop_id: parseInt(seller.shop_id, 10) || 0,
			price: parseInt(seller.price, 10) || 0,
			price_text: seller.price_text || '',
			availability: seller.availability !== undefined ? !!seller.availability : true,
			score,
			score_text: (seller.score_info && seller.score_info.score_text) || '',
			title: seller.name1 || '',
			features: seller.name2 || '',
			guarantee: (seller.guarantee_info && seller.guarantee_info.status) || '',
			delivery_info: mi.delivery_info || '',
			free_shipping: mi.free_shipping || '',
			same_day: mi.same_day_delivery || '',
			postage_fee: seller.postage_fee || '',
			shipping: Array.isArray(mi.shipping_types) ? mi.shipping_types.map(String).filter(Boolean) : [],
			is_adv: !!seller.is_adv,
			last_change: seller.last_price_change_date || '',
			url: seller.page_url || '',
		};
	});
}

function normalizeDetails(data) {
	const gallery = extractGallery(data);

	const specs = {};
	const specGroups = [];
	const headers = (data.structural_specs && data.structural_specs.headers) || [];
	for (const group of headers) {
		if (!group || !group.specs || typeof group.specs !== 'object') continue;
		const groupPairs = {};
		for (const [rawKey, rawVal] of Object.entries(group.specs)) {
			const key = String(rawKey).trim();
			const val = stringifySpecValue(rawVal);
			if (!key || !val) continue;
			specs[key] = val;
			groupPairs[key] = val;
		}
		if (Object.keys(groupPairs).length) {
			specGroups.push({ header: group.header || 'مشخصات', specs: groupPairs });
		}
	}

	const keySpecs = {};
	for (const group of data.key_specs || []) {
		if (!group || !Array.isArray(group.items)) continue;
		for (const item of group.items) {
			if (!item || item.key === undefined) continue;
			const k = String(item.key).trim();
			const v = stringifySpecValue(item.value !== undefined ? item.value : '');
			if (k && v) keySpecs[k] = v;
		}
	}

	const sellers = extractSellers((data.products_info && data.products_info.result) || [], 'online');
	const storeSellers = extractSellers(
		(data.products_in_store_info && data.products_in_store_info.result) || [],
		'in_store'
	);

	let cheapest = 0;
	for (const s of sellers) {
		if (s.price > 0 && (cheapest === 0 || s.price < cheapest)) cheapest = s.price;
	}
	if (!cheapest && data.price) cheapest = parseInt(data.price, 10) || 0;

	let searchId = '';
	if (data.more_info_url) {
		try {
			searchId = new URL(data.more_info_url).searchParams.get('search_id') || '';
		} catch (e) { /* ignore */ }
	}

	const categories = (data.breadcrumbs || [])
		.filter((c) => c && c.title && c.cat_id)
		.map((c) => String(c.title));

	return {
		random_key: data.random_key || '',
		search_id: searchId,
		name1: data.name1 || '',
		name2: data.name2 || '',
		description: data.description || '',
		price: cheapest,
		price_text: data.price_text || '',
		min_price: data.min_price !== undefined ? parseInt(data.min_price, 10) : cheapest,
		max_price: data.max_price !== undefined ? parseInt(data.max_price, 10) : 0,
		image_url: data.image_url || '',
		gallery,
		specs,
		spec_groups: specGroups,
		key_specs: keySpecs,
		sellers,
		store_sellers: storeSellers,
		sellers_count: sellers.length,
		shop_text: data.shop_text || '',
		categories,
		page_url: data.web_client_absolute_url ? 'https://torob.com' + data.web_client_absolute_url : '',
		variants: data.variants || [],
	};
}

/* -------------------------------------------------------------------------- */
/* تجمیع فروشندگان (آینه‌ی Shoper_Seller_Aggregator)                            */
/* -------------------------------------------------------------------------- */

function sellerWeight(seller, cheapest) {
	const scoreNorm = Math.min(1, (seller.score || 0) / 5);
	let priceNorm = 0;
	if (cheapest > 0 && seller.price > 0) {
		const ratio = (seller.price - cheapest) / cheapest;
		priceNorm = Math.max(0, 1 - ratio / 0.2);
	}
	const guaranteeBonus = seller.guarantee === 'enabled' ? 0.1 : 0;
	const advPenalty = seller.is_adv ? 0.15 : 0;
	return Number((scoreNorm * 0.6 + priceNorm * 0.4 + guaranteeBonus - advPenalty).toFixed(4));
}

function rankSellers(sellers, strategy) {
	const list = [...sellers];
	if (list.length < 2) return list;
	if (strategy === 'cheapest') {
		return list.sort((a, b) => a.price - b.price);
	}
	const cheapest = Math.min(...list.map((s) => s.price));
	return list.sort((a, b) => {
		const wa = sellerWeight(a, cheapest);
		const wb = sellerWeight(b, cheapest);
		if (wa === wb) return a.price - b.price;
		return wb - wa;
	});
}

function aggregate(sellers, limit = 3, strategy = 'score') {
	const result = {
		strategy,
		limit: parseInt(limit, 10),
		total_sellers: Array.isArray(sellers) ? sellers.length : 0,
		considered: [],
		primary: null,
		price: 0,
		cheapest: 0,
		highest: 0,
		features: [],
		guarantee: '',
		shipping: [],
		delivery: [],
	};

	if (!Array.isArray(sellers) || !sellers.length) return result;

	const available = sellers.filter((s) => s.price > 0 && s.availability !== false);
	if (!available.length) return result;

	const prices = available.map((s) => s.price);
	result.cheapest = Math.min(...prices);
	result.highest = Math.max(...prices);

	const ranked = rankSellers(available, strategy);
	const considered = ranked.slice(0, Math.max(1, parseInt(limit, 10)));

	result.considered = considered;
	result.primary = considered[0];
	result.price = strategy === 'cheapest' ? result.cheapest : considered[0].price;

	const features = [];
	const shipping = [];
	const delivery = [];
	let guarantee = '';

	for (const s of considered) {
		if (s.features) {
			for (const piece of String(s.features).split(/[،,]/)) {
				const p = piece.trim();
				if (p && !features.includes(p)) features.push(p);
			}
		}
		for (const ship of s.shipping || []) {
			const t = String(ship).trim();
			if (t && !shipping.includes(t)) shipping.push(t);
		}
		for (const field of ['free_shipping', 'same_day', 'postage_fee']) {
			const val = (s[field] || '').trim();
			if (val && !delivery.includes(val)) delivery.push(val);
		}
		if (!guarantee && s.guarantee === 'enabled') guarantee = 'دارای ضمانت ترب';
	}

	result.features = features;
	result.shipping = shipping;
	result.delivery = delivery;
	result.guarantee = guarantee;

	return result;
}

/* -------------------------------------------------------------------------- */
/* ساخت توضیحات (آینه‌ی Shoper_Product_Builder)                                 */
/* -------------------------------------------------------------------------- */

function renderSpecTable(pairs) {
	const entries = Object.entries(pairs || {});
	if (!entries.length) return '';
	let html = '<table class="shoper-specs-table" style="width:100%;border-collapse:collapse;margin:12px 0;"><tbody>';
	entries.forEach(([k, v], i) => {
		const bg = i % 2 === 0 ? '#f8f8f8' : '#fff';
		html += `<tr style="background:${bg};">`;
		html += `<th style="width:38%;text-align:right;padding:8px 12px;border:1px solid #eee;vertical-align:top;">${escHtml(k)}</th>`;
		html += `<td style="padding:8px 12px;border:1px solid #eee;">${escHtml(v)}</td>`;
		html += '</tr>';
	});
	return html + '</tbody></table>';
}

function renderSellerSection(data) {
	const agg = data.aggregate;
	if (!agg || !agg.considered || !agg.considered.length) return '';

	let html = '<h3>اطلاعات خرید</h3>';
	html += `<p>این اطلاعات از میان <strong>${numberFormatFa(agg.total_sellers)}</strong> فروشنده، `;
	html += `بر اساس <strong>${agg.considered.length}</strong> فروشنده‌ی برتر جمع‌آوری شده است.</p>`;

	html += '<table class="shoper-sellers-table" style="width:100%;border-collapse:collapse;margin:12px 0;">';
	html += '<thead><tr style="background:#f1f1f1;">';
	html += '<th style="padding:8px 12px;border:1px solid #eee;text-align:right;">فروشنده</th>';
	html += '<th style="padding:8px 12px;border:1px solid #eee;text-align:right;">شهر</th>';
	html += '<th style="padding:8px 12px;border:1px solid #eee;text-align:right;">امتیاز</th>';
	html += '<th style="padding:8px 12px;border:1px solid #eee;text-align:right;">قیمت</th>';
	html += '</tr></thead><tbody>';
	agg.considered.forEach((s, i) => {
		const bg = i % 2 === 0 ? '#fafafa' : '#fff';
		html += `<tr style="background:${bg};">`;
		html += `<td style="padding:8px 12px;border:1px solid #eee;">${escHtml(s.shop_name)}</td>`;
		html += `<td style="padding:8px 12px;border:1px solid #eee;">${escHtml(s.city)}</td>`;
		html += `<td style="padding:8px 12px;border:1px solid #eee;">${escHtml(s.score_text || s.score)}</td>`;
		html += `<td style="padding:8px 12px;border:1px solid #eee;">${numberFormatFa(s.price)} تومان</td>`;
		html += '</tr>';
	});
	html += '</tbody></table>';

	if (agg.cheapest && agg.highest && agg.highest > agg.cheapest) {
		html += `<p>محدوده‌ی قیمت در بازار: از <strong>${numberFormatFa(agg.cheapest)}</strong> `;
		html += `تا <strong>${numberFormatFa(agg.highest)}</strong> تومان.</p>`;
	}
	if (agg.features.length) {
		html += '<h4>ویژگی‌های اعلام‌شده توسط فروشندگان</h4><ul>';
		for (const f of agg.features.slice(0, 10)) html += `<li>${escHtml(f)}</li>`;
		html += '</ul>';
	}
	if (agg.shipping.length || agg.delivery.length) {
		html += '<h4>ارسال و تحویل</h4><ul>';
		for (const d of [...agg.delivery, ...agg.shipping].slice(0, 8)) html += `<li>${escHtml(d)}</li>`;
		html += '</ul>';
	}
	if (agg.guarantee) {
		html += `<p><strong>${escHtml(agg.guarantee)}</strong></p>`;
	}
	return html;
}

function buildDescriptionHtml(data) {
	let html = '';

	if (data.description) {
		html += `<p>${escHtml(data.description)}</p>`;
	} else {
		html += `<p>${escHtml(data.name1)}</p>`;
	}

	if (data.key_specs && Object.keys(data.key_specs).length) {
		html += '<h3>مشخصات کلیدی</h3>' + renderSpecTable(data.key_specs);
	}

	if (data.spec_groups && data.spec_groups.length) {
		html += '<h3>مشخصات فنی</h3>';
		for (const group of data.spec_groups) {
			if (!group.specs || !Object.keys(group.specs).length) continue;
			if (group.header) html += `<h4>${escHtml(group.header)}</h4>`;
			html += renderSpecTable(group.specs);
		}
	} else if (data.specs && Object.keys(data.specs).length) {
		html += '<h3>مشخصات فنی</h3>' + renderSpecTable(data.specs);
	}

	html += renderSellerSection(data);

	if (data.page_url) {
		html += '<p class="shoper-source" style="font-size:12px;color:#888;margin-top:20px;">';
		html += 'این محصول توسط افزونه‌ی <strong>Shoper</strong> از ';
		html += `<a href="${escHtml(data.page_url)}" target="_blank" rel="nofollow">ترب</a> وارد شده است.`;
		html += '</p>';
	}
	return html;
}

function buildShortDescription(data) {
	const parts = [];
	if (data.name2) parts.push(`<p>${escHtml(data.name2)}</p>`);

	const keyEntries = Object.entries(data.key_specs || {}).slice(0, 6);
	if (keyEntries.length) {
		parts.push('<ul>' + keyEntries.map(([k, v]) => `<li><strong>${escHtml(k)}:</strong> ${escHtml(v)}</li>`).join('') + '</ul>');
	}
	if (data.aggregate && data.aggregate.features.length) {
		parts.push(`<p>${escHtml(data.aggregate.features.slice(0, 5).join(' • '))}</p>`);
	}
	return parts.join('\n');
}

/* -------------------------------------------------------------------------- */
/* ویژگی‌ها (آینه‌ی Shoper_Attribute_Handler)                                    */
/* -------------------------------------------------------------------------- */

const SLUG_MAP = {
	'برند': 'brand', 'مدل': 'model', 'وزن': 'weight', 'ابعاد': 'dimensions',
	'جنس بدنه': 'body-material', 'رنگ': 'color', 'پردازنده': 'processor',
	'حافظه داخلی': 'storage', 'حافظه RAM': 'ram', 'مقدار رم': 'ram',
	'اندازه صفحه نمایش': 'screen-size', 'نوع صفحه نمایش': 'screen-type',
	'دقت صفحه نمایش': 'resolution', 'تراکم پیکسل': 'pixel-density',
	'نرخ نوسازی تصویر': 'refresh-rate', 'محافظ صفحه': 'screen-protection',
	'دوربین اصلی': 'main-camera', 'دوربین سلفی': 'front-camera',
	'گنجایش باتری': 'battery-capacity', 'ظرفیت باتری': 'battery-capacity',
	'سیستم عامل': 'os', 'سیم‌کارت': 'sim', 'سیم کارت': 'sim',
	'گواهی ضدآب': 'water-resistance', 'بلندگو': 'speaker',
	'کیفیت دوربین جلو': 'front-camera-quality', 'کیفیت دوربین اصلی': 'main-camera-quality',
	'کشور ROM': 'rom-country', 'وضعیت رجیستر': 'registration-status',
	'وضعیت فعال بودن': 'activation-status', 'سال تولید': 'production-year',
	'پشتیبانی از کارت حافظه': 'memory-card', 'اقلام همراه': 'box-contents',
	'اصالت کالا': 'authenticity', 'پردازنده مرکزی': 'cpu', 'نسخه سیستم عامل': 'os-version',
};

function slugify(name) {
	if (SLUG_MAP[name]) return SLUG_MAP[name];
	// آینه‌ی رفتار sanitize_title وردپرس برای فارسی: کاراکترهای غیرمجاز حذف.
	const slug = String(name).trim().toLowerCase()
		.replace(/[\s_]+/g, '-')
		.replace(/[^\p{L}\p{N}-]/gu, '')
		.replace(/-+/g, '-')
		.replace(/^-|-$/g, '');
	return slug || 'attr';
}

/**
 * تبدیل مشخصات به ویژگی‌های ووکامرس.
 *
 * وردپرس نام taxonomy را به ۳۲ نویسه محدود می‌کند (pa_ + 28).
 */
function buildAttributes(specs) {
	const attrs = [];
	const errors = [];
	let position = 0;

	for (const [name, value] of Object.entries(specs || {})) {
		const cleanName = String(name).trim();
		const cleanValue = stringifySpecValue(value);
		if (!cleanName || !cleanValue) continue;

		let slug = slugify(cleanName);
		const taxonomy = 'pa_' + slug;
		if (taxonomy.length > 32) {
			slug = slug.substring(0, 28);
		}

		attrs.push({
			name: cleanName,
			taxonomy: 'pa_' + slug,
			value: cleanValue,
			position: position++,
			visible: true,
			variation: false,
		});
	}
	return { attrs, errors };
}

/* -------------------------------------------------------------------------- */
/* سئو و برچسب (آینه‌ی Shoper_Product_Builder::build_seo)                        */
/* -------------------------------------------------------------------------- */

function buildSeo(data) {
	const title = data.name1 || '';
	const parts = [];
	if (data.name2) parts.push(String(data.name2));
	let i = 0;
	for (const [k, v] of Object.entries(data.key_specs || {})) {
		if (i++ >= 5) break;
		parts.push(`${k}: ${v}`);
	}
	let desc = parts.join(' | ');
	if (desc.length > 155) desc = desc.slice(0, 152) + '…';

	const tags = [];
	const seen = {};
	const cands = [];
	if (data.name1) cands.push(String(data.name1));
	if (data.name2) cands.push(String(data.name2));
	if (data.specs) {
		['برند', 'مدل', 'سازنده'].forEach((key) => {
			if (data.specs[key]) cands.push(String(data.specs[key]));
		});
	}
	for (const c of cands) {
		for (const t of String(c).split(/[|\/،,]+/)) {
			const clean = t.trim();
			if (clean && !seen[clean]) {
				seen[clean] = true;
				tags.push(clean);
				if (tags.length >= 12) break;
			}
		}
		if (tags.length >= 12) break;
	}

	return { title, description: desc, tags };
}

/**
 * نام پایه برای فایل تصویر (آینه‌ی Shoper_Image_Handler::base_filename).
 */
function fileBase(title) {
	const base = String(title || '')
		.replace(/[?\[\]\/\\=<>:;,"'&$#*()~`!{}%+|]/g, '')
		.replace(/\s+/g, '-')
		.replace(/-+/g, '-')
		.replace(/^-+|-+$/g, '')
		.slice(0, 80);
	return base || 'shoper-product';
}

function firstDkImage(node) {
	if (!node) return '';
	if (typeof node === 'string' && node.indexOf('http') === 0) return node;
	const bag = node.url || node.webp_url;
	if (Array.isArray(bag) && bag[0]) return bag[0];
	if (typeof bag === 'string') return bag;
	return '';
}

function extractDkProducts(data) {
	if (data && data.data && Array.isArray(data.data.products)) {
		if (data.data.products[0] && data.data.products[0].id) return data.data.products;
		if (data.data.products.products) return data.data.products.products;
	}
	if (data && Array.isArray(data.products)) return data.products;
	return [];
}

function normalizeDigikalaSearch(data) {
	const raw = extractDkProducts(data);
	const results = raw.map((item) => {
		const id = item.id || 0;
		const rial = (item.default_variant && item.default_variant.price && item.default_variant.price.selling_price) || 0;
		const price = rial >= 1000 && rial % 10 === 0 ? Math.round(rial / 10) : rial;
		const img = firstDkImage(item.images && item.images.main);
		const uri = (item.url && item.url.uri) || '';
		return {
			random_key: id ? ('DKP-' + id) : '',
			search_id: '',
			name1: item.title_fa || '',
			name2: item.title_en || '',
			price,
			price_text: price ? (price + ' تومان') : '',
			shop_text: 'دیجی‌کالا',
			image_url: img,
			gallery: img ? [img] : [],
			page_url: uri ? ('https://www.digikala.com' + uri) : '',
			more_info_url: '',
			is_adv: false,
			provider: 'digikala',
		};
	}).filter((x) => x.random_key && x.name1);
	return { count: results.length, results, next: '', provider: 'digikala' };
}

function normalizeDigikalaDetails(data) {
	const product = (data && data.data && data.data.product) || (data && data.product) || data || {};
	const id = product.id || 0;
	const gallery = [];
	const main = firstDkImage(product.images && product.images.main);
	if (main) gallery.push(main);
	(product.images && product.images.list || []).forEach((img) => {
		const u = firstDkImage(img);
		if (u && gallery.indexOf(u) < 0) gallery.push(u);
	});
	const specs = {};
	const specGroups = [];
	const keySpecs = {};
	(product.specifications || []).forEach((group) => {
		const pairs = {};
		(group.attributes || []).forEach((attr) => {
			const title = (attr.title || '').trim();
			const values = (attr.values || []).map((v) => String(v).trim()).filter(Boolean);
			if (!title || !values.length) return;
			const value = values.join('، ');
			specs[title] = value;
			pairs[title] = value;
			if (Object.keys(keySpecs).length < 8) keySpecs[title] = value;
		});
		if (Object.keys(pairs).length) specGroups.push({ header: group.title || 'مشخصات', specs: pairs });
	});
	if (!specs['برند'] && product.brand && product.brand.title_fa) {
		specs['برند'] = product.brand.title_fa;
		keySpecs['برند'] = product.brand.title_fa;
	}
	const rial = (product.default_variant && product.default_variant.price && product.default_variant.price.selling_price) || 0;
	const price = rial >= 1000 && rial % 10 === 0 ? Math.round(rial / 10) : rial;
	const uri = (product.url && product.url.uri) || (id ? ('/product/dkp-' + id + '/') : '');
	return {
		random_key: id ? ('DKP-' + id) : '',
		search_id: '',
		name1: product.title_fa || '',
		name2: product.title_en || '',
		description: (product.review && product.review.description) || '',
		price,
		price_text: price ? (price + ' تومان') : '',
		image_url: gallery[0] || '',
		gallery,
		specs,
		key_specs: keySpecs,
		spec_groups: specGroups,
		sellers: [],
		sellers_count: 0,
		page_url: uri ? ('https://www.digikala.com' + uri) : '',
		variants: [],
		provider: 'digikala',
	};
}

function detectCategory(hay) {
	const t = String(hay || '').toLowerCase();
	if (/گوشی|موبایل|galaxy|iphone|redmi|poco|xiaomi|سامسونگ/.test(t)) return 'phone';
	if (/لپ[\s-]?تاپ|macbook|notebook/.test(t)) return 'laptop';
	if (/تبلت|ipad/.test(t)) return 'tablet';
	if (/هدفون|هندزفری|ایرپاد|earbuds/.test(t)) return 'headphone';
	if (/ساعت هوشمند|smartwatch/.test(t)) return 'watch';
	if (/تلویزیون|smart tv/.test(t)) return 'tv';
	if (/پلی.?استیشن|playstation|xbox|nintendo|ps5|ps4|کنسول/.test(t)) return 'console';
	return 'generic';
}

function specOf(specs, names) {
	for (const n of names) {
		if (specs && specs[n]) return String(specs[n]).trim();
	}
	return '';
}

function polishSource(source) {
	let text = String(source || '');
	text = text.replace(/<\s*br\s*\/?>/gi, '\n').replace(/<\/p>/gi, '\n\n');
	text = text.replace(/<[^>]+>/g, ' ');
	text = text.replace(/\r\n|\r/g, '\n');
	text = text.replace(/[ \t\u00a0]+/g, ' ');
	text = text.replace(/ *\n */g, '\n');
	text = text.replace(/\n{3,}/g, '\n\n');
	text = text.replace(/\s+([،,.;:!?])/g, '$1');
	text = text.replace(/([،,.;:!?])\1+/g, '$1');
	text = text.replace(/([،,.;:!?])(\S)/g, '$1 $2');
	return text.trim();
}

function specSentence(label, val) {
	const map = {
		'پردازنده': 'پردازنده آن ' + val + ' است.',
		'رم': 'این مدل با رم ' + val + ' عرضه می‌شود.',
		'حافظه داخلی': 'حافظه داخلی آن ' + val + ' اعلام شده است.',
		'صفحه نمایش': 'اندازه صفحه نمایش ' + val + ' است.',
		'نوع نمایشگر': 'نوع نمایشگر ' + val + ' ثبت شده است.',
		'نرخ نوسازی': 'نرخ نوسازی تصویر ' + val + ' است.',
		'دوربین اصلی': 'دوربین اصلی ' + val + ' دارد.',
		'دوربین سلفی': 'دوربین سلفی ' + val + ' است.',
		'باتری': 'ظرفیت باتری ' + val + ' است.',
		'سیستم عامل': 'سیستم‌عامل ' + val + ' روی این محصول نصب شده است.',
		'وزن': 'وزن دستگاه ' + val + ' است.',
		'ابعاد': 'ابعاد آن ' + val + ' است.',
		'رنگ': 'رنگ ثبت‌شده ' + val + ' است.',
		'گواهی ضدآب': 'گواهی ضدآب ' + val + ' برای آن ثبت شده است.',
		'کارت گرافیک': 'کارت گرافیک ' + val + ' است.',
		'نوع اتصال': 'نوع اتصال ' + val + ' است.',
	};
	return map[label] || (label + ' این محصول ' + val + ' است.');
}

function weaveSpecs(specs, keys) {
	const bag = Object.assign({}, keys || {}, specs || {});
	const map = [
		['پردازنده', ['پردازنده', 'پردازنده مرکزی', 'تراشه']],
		['رم', ['مقدار رم', 'حافظه RAM', 'رم', 'مقدار RAM']],
		['حافظه داخلی', ['حافظه داخلی', 'ظرفیت حافظه']],
		['صفحه نمایش', ['اندازه صفحه نمایش']],
		['نوع نمایشگر', ['نوع صفحه نمایش']],
		['نرخ نوسازی', ['نرخ نوسازی تصویر']],
		['دوربین اصلی', ['دوربین اصلی', 'کیفیت دوربین اصلی']],
		['دوربین سلفی', ['دوربین سلفی', 'کیفیت دوربین جلو']],
		['باتری', ['گنجایش باتری', 'ظرفیت باتری']],
		['سیستم عامل', ['سیستم عامل']],
		['وزن', ['وزن']],
		['ابعاد', ['ابعاد']],
		['رنگ', ['رنگ']],
		['گواهی ضدآب', ['گواهی ضدآب']],
		['کارت گرافیک', ['کارت گرافیک']],
		['نوع اتصال', ['نوع اتصال']],
	];
	const used = {};
	const bits = [];
	map.forEach((row) => {
		const val = specOf(bag, row[1]);
		if (!val) return;
		bits.push(specSentence(row[0], val));
		row[1].forEach((k) => { used[k] = true; });
	});
	let extra = 0;
	Object.keys(bag).forEach((k) => {
		if (used[k] || extra >= 3) return;
		const v = String(bag[k] || '').trim();
		if (!k || !v) return;
		bits.push(k + ' این محصول ' + v + ' است.');
		extra += 1;
	});
	return bits.join(' ').trim();
}

function draftArticle(name, name2, brand, specs, keys, seed) {
	let p1 = '';
	if (name) {
		p1 = name;
		if (name2 && name.indexOf(name2) < 0) p1 += ' (' + name2 + ')';
		p1 += brand ? (' محصول برند ' + brand + ' است.') : ' یک محصول است.';
	} else {
		p1 = 'این محصول بر اساس مشخصات ثبت‌شده معرفی می‌شود.';
	}
	seed = String(seed || '').trim();
	if (seed && p1.indexOf(seed) < 0) p1 += ' ' + seed;
	const woven = weaveSpecs(specs, keys);
	const paras = [p1];
	if (woven) paras.push(woven);
	const last = paras[paras.length - 1] || '';
	if (last.indexOf('جدول مشخصات') < 0) {
		paras.push('جزئیات کامل «' + (name || 'این محصول') + '» در جدول مشخصات همین صفحه آمده است.');
	}
	return paras.map((p) => p.replace(/\s+/g, ' ').trim()).filter(Boolean).join('\n\n');
}

function composeArticle(source, name, name2, brand, specs, keys) {
	const polished = polishSource(source);
	if (polished.length >= 240) {
		const pairs = [
			['رم', ['مقدار رم', 'حافظه RAM', 'رم', 'مقدار RAM']],
			['حافظه داخلی', ['حافظه داخلی', 'ظرفیت حافظه']],
			['پردازنده', ['پردازنده', 'پردازنده مرکزی', 'تراشه']],
			['صفحه نمایش', ['اندازه صفحه نمایش']],
			['دوربین اصلی', ['دوربین اصلی', 'کیفیت دوربین اصلی']],
			['باتری', ['گنجایش باتری', 'ظرفیت باتری']],
		];
		const bag = Object.assign({}, keys || {}, specs || {});
		const miss = [];
		pairs.forEach((row) => {
			const val = specOf(bag, row[1]);
			if (!val) return;
			if (polished.toLowerCase().indexOf(String(val).toLowerCase()) >= 0) return;
			miss.push(specSentence(row[0], val));
		});
		if (miss.length >= 2) {
			return polished + '\n\n' + ('در تکمیل معرفی «' + name + '»، ' + miss.slice(0, 5).join(' '));
		}
		return polished;
	}
	return draftArticle(name, name2, brand, specs, keys, polished);
}

function paragraphsHtml(text) {
	return String(text || '').split(/\n\s*\n/).map((p) => p.replace(/\s+/g, ' ').trim()).filter(Boolean)
		.map((p) => '<p style="margin:0 0 14px;">' + escHtml(p) + '</p>').join('');
}

function contentDepth(cat, specs) {
	const count = Object.keys(specs || {}).length;
	if (['phone', 'laptop', 'tablet', 'console', 'tv', 'watch'].indexOf(cat) >= 0 && count >= 8) return 'full';
	if (count >= 5) return 'medium';
	return 'light';
}

function analysisPros(cat, specs, keys) {
	const bag = Object.assign({}, keys || {}, specs || {});
	const out = [];
	const cpu = specOf(bag, ['پردازنده', 'پردازنده مرکزی', 'تراشه']);
	const ram = specOf(bag, ['مقدار رم', 'حافظه RAM', 'رم', 'مقدار RAM']);
	const stor = specOf(bag, ['حافظه داخلی', 'ظرفیت حافظه']);
	const scr = specOf(bag, ['اندازه صفحه نمایش']);
	const type = specOf(bag, ['نوع صفحه نمایش']);
	const hz = specOf(bag, ['نرخ نوسازی تصویر']);
	const cam = specOf(bag, ['دوربین اصلی', 'کیفیت دوربین اصلی']);
	const bat = specOf(bag, ['گنجایش باتری', 'ظرفیت باتری']);
	const ip = specOf(bag, ['گواهی ضدآب']);
	if (cpu) out.push('پردازنده ' + cpu + ' عملکرد روزمره را روان نگه می‌دارد');
	if (ram) out.push('رم ' + ram + ' برای چندکارگی هم‌زمان مناسب است');
	if (stor) out.push('حافظه داخلی ' + stor + ' فضای نصب برنامه و فایل را پوشش می‌دهد');
	if (scr) out.push('نمایشگر ' + scr + (type ? (' از نوع ' + type) : '') + (hz ? (' با نرخ نوسازی ' + hz) : ''));
	else if (hz) out.push('نرخ نوسازی ' + hz + ' تصویر روان‌تری می‌سازد');
	if (cam) out.push('دوربین اصلی ' + cam + ' ثبت تصویر روزمره را پوشش می‌دهد');
	if (bat) out.push('باتری ' + bat + ' برای استفاده روزانه در نظر گرفته شده است');
	if (ip) out.push('گواهی ' + ip + ' مقاومت در برابر آب و گردوغبار را نشان می‌دهد');
	return out.slice(0, 6);
}

function analysisCons(cat, specs, keys) {
	const bag = Object.assign({}, keys || {}, specs || {});
	const out = [];
	const w = specOf(bag, ['وزن']);
	const stor = specOf(bag, ['حافظه داخلی', 'ظرفیت حافظه']);
	const m = w && w.match(/(\d{2,4})/);
	if (m && ['phone', 'tablet'].indexOf(cat) >= 0 && parseInt(m[1], 10) >= 210) {
		out.push('وزن ' + w + ' ممکن است برای استفاده طولانی کمی سنگین حس شود');
	}
	if (stor && /\b(16|32|64)\b/.test(stor)) {
		out.push('حافظه داخلی ' + stor + ' برای آرشیو سنگین ممکن است محدود باشد');
	}
	Object.keys(bag).forEach((k) => {
		const v = String(bag[k] || '').trim();
		if (['ندارد', 'خیر', 'پشتیبانی نمی‌شود'].indexOf(v) >= 0 && out.length < 3) {
			out.push(k + ' در مشخصات این مدل «' + v + '» ثبت شده است');
		}
	});
	return out.slice(0, 3);
}

function analysisText(cat, name, specs, pros, cons) {
	if (contentDepth(cat, specs) === 'light') return '';
	let text = 'تحلیل «' + (name || 'این محصول') + '» فقط از روی مشخصات ثبت‌شده همین کالا انجام شده است. ';
	text += pros && pros.length ? 'نقاط قوت از عددها و امکانات واقعی خوانده می‌شود، نه از شعار تبلیغاتی. ' : '';
	text += cons && cons.length ? 'محدودیت‌های احتمالی هم فقط جایی آمده که در جدول مشخصات نشانه دارد.' : 'در جدول مشخصات محدودیت واضحی دیده نشد.';
	return text.trim();
}

function verdictText(cat, name, brand, specs, keys) {
	const who = name || 'این محصول';
	if (contentDepth(cat, specs) === 'light') {
		return who + ' با مشخصات ثبت‌شده در همین صفحه معرفی شده است. اگر این مشخصات با نیازتان جور است، خرید آن می‌تواند انتخاب ساده‌ای باشد.';
	}
	const bag = Object.assign({}, keys || {}, specs || {});
	const bits = [];
	const ram = specOf(bag, ['مقدار رم', 'حافظه RAM', 'رم', 'مقدار RAM']);
	const cam = specOf(bag, ['دوربین اصلی', 'کیفیت دوربین اصلی']);
	const bat = specOf(bag, ['گنجایش باتری', 'ظرفیت باتری']);
	if (ram) bits.push('رم ' + ram);
	if (cam) bits.push('دوربین ' + cam);
	if (bat) bits.push('باتری ' + bat);
	let text = who + (brand ? (' از برند ' + brand) : '') + ' است.';
	if (bits.length) text += ' ترکیب ' + bits.join('، ') + ' روی کاغذ برای کار روزمره منطقی به نظر می‌رسد.';
	text += ' اگر همین مشخصات با نیازتان هم‌خوان است، همین صفحه برای تصمیم خرید کافی است.';
	return text;
}

function productSpecsTable(pairs, caption) {
	const entries = Object.entries(pairs || {});
	if (!entries.length) return '';
	let html = '<div class="product-table-wrap" style="overflow-x:auto;"><table class="product-specs-table" style="border-collapse:collapse;min-width:620px;width:100%;">';
	if (caption) html += '<caption style="color:#64748b;font-size:13px;padding:0 0 10px;text-align:right;">جدول مشخصات فنی ' + escHtml(caption) + '</caption>';
	html += '<thead><tr><th style="background:#e8f1ff;border:1px solid #dbe3ea;color:#173b73;padding:11px 13px;text-align:right;" scope="col">مشخصه</th><th style="background:#e8f1ff;border:1px solid #dbe3ea;color:#173b73;padding:11px 13px;text-align:right;" scope="col">مقدار</th></tr></thead><tbody>';
	entries.forEach(([k, v]) => {
		html += '<tr><th style="background:#f8fafc;border:1px solid #dbe3ea;color:#334155;font-weight:bold;padding:11px 13px;text-align:right;vertical-align:top;width:31%;" scope="row">' + escHtml(k) + '</th>';
		html += '<td style="border:1px solid #dbe3ea;padding:11px 13px;text-align:right;vertical-align:top;">' + escHtml(v) + '</td></tr>';
	});
	return html + '</tbody></table></div>';
}

function assembleProductHtml(data, body, highlights, faq, extras) {
	extras = extras || {};
	const specs = (data && data.specs) || {};
	const keys = (data && data.key_specs) || {};
	const name = String((data && data.name1) || '');
	const name2 = String((data && data.name2) || '');
	const brand = specOf(specs, ['برند', 'سازنده']);
	const title = name2 || name;
	const pros = extras.pros || highlights || [];
	const cons = extras.cons || [];
	const analysis = extras.analysis || '';
	const verdict = extras.verdict || '';
	let html = '<article class="product-description-wrapper" dir="rtl" lang="fa" style="direction:rtl;text-align:right;font-family:Tahoma,Arial,sans-serif;color:#263238;line-height:2;max-width:100%;">';
	html += '<header class="product-description-header" style="border-bottom:1px solid #e5e7eb;margin-bottom:22px;padding-bottom:14px;">';
	if (brand) html += '<p class="product-description-brand" style="color:#64748b;font-size:13px;margin:0;">برند: ' + escHtml(brand) + '</p>';
	html += '<h2 style="color:#111827;font-size:24px;line-height:1.7;margin:4px 0 0;">نقد و بررسی تخصصی ' + escHtml(title) + '</h2></header>';
	html += '<section class="product-description-section product-overview" style="background:#f8fafc;border:1px solid #e5e7eb;border-right:5px solid #2563eb;border-radius:12px;margin:0 0 20px;padding:20px;" aria-labelledby="overview-title">';
	html += '<h3 id="overview-title" style="color:#111827;font-size:19px;line-height:1.8;margin:0 0 12px;">معرفی و بررسی محصول</h3>';
	html += paragraphsHtml(body) + '</section>';
	if (highlights && highlights.length) {
		html += '<section class="product-description-section product-highlights" style="background:#f0fdf4;border:1px solid #e5e7eb;border-right:5px solid #16a34a;border-radius:12px;margin:0 0 20px;padding:20px;" aria-labelledby="highlights-title">';
		html += '<h3 id="highlights-title" style="color:#111827;font-size:19px;line-height:1.8;margin:0 0 12px;">ویژگی‌های برجسته</h3><ul style="margin:0;padding:0 22px 0 0;">';
		highlights.forEach((h) => { html += '<li>' + escHtml(h) + '</li>'; });
		html += '</ul></section>';
	}
	const pairs = Object.assign({}, keys, specs);
	if (Object.keys(pairs).length) {
		html += '<section class="product-description-section product-specifications" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin:0 0 20px;overflow:hidden;padding:20px;" aria-labelledby="specifications-title">';
		html += '<h3 id="specifications-title" style="color:#111827;font-size:19px;line-height:1.8;margin:0 0 12px;">مشخصات فنی کامل</h3>';
		html += productSpecsTable(pairs, title) + '</section>';
	}
	if (analysis || (pros && pros.length) || (cons && cons.length)) {
		html += '<section class="product-description-section product-analysis" style="background:#fffbeb;border:1px solid #e5e7eb;border-right:5px solid #d97706;border-radius:12px;margin:0 0 20px;padding:20px;" aria-labelledby="analysis-title">';
		html += '<h3 id="analysis-title" style="color:#111827;font-size:19px;line-height:1.8;margin:0 0 12px;">تحلیل و آنالیز فنی</h3>';
		if (analysis) html += paragraphsHtml(analysis);
		html += '<div class="product-analysis-columns" style="display:block;">';
		if (pros && pros.length) {
			html += '<div class="product-analysis-column product-pros" style="background:#ecfdf5;border-radius:9px;color:#166534;padding:14px;margin-bottom:14px;"><h4 style="font-size:16px;margin:0 0 8px;">مزایا</h4><ul style="margin:0;padding:0 22px 0 0;">';
			pros.forEach((p) => { html += '<li>' + escHtml(p) + '</li>'; });
			html += '</ul></div>';
		}
		if (cons && cons.length) {
			html += '<div class="product-analysis-column product-cons" style="background:#fef2f2;border-radius:9px;color:#991b1b;padding:14px;"><h4 style="font-size:16px;margin:0 0 8px;">معایب احتمالی</h4><ul style="margin:0;padding:0 22px 0 0;">';
			cons.forEach((c) => { html += '<li>' + escHtml(c) + '</li>'; });
			html += '</ul></div>';
		}
		html += '</div></section>';
	}
	if (verdict) {
		html += '<section class="product-description-section product-verdict" style="background:#eff6ff;border:1px solid #e5e7eb;border-right:5px solid #4f46e5;border-radius:12px;margin:0 0 20px;padding:20px;" aria-labelledby="verdict-title">';
		html += '<h3 id="verdict-title" style="color:#111827;font-size:19px;line-height:1.8;margin:0 0 12px;">نتیجه‌گیری و پیشنهاد خرید</h3>';
		html += paragraphsHtml(verdict) + '</section>';
	}
	html += '</article>';
	return html;
}

function enhanceProduct(data) {
	const name = String((data && data.name1) || '');
	const name2 = String((data && data.name2) || '');
	const specs = (data && data.specs) || {};
	const keys = (data && data.key_specs) || {};
	const cat = detectCategory(name + ' ' + name2);
	const brand = specOf(specs, ['برند', 'سازنده']);
	const body = composeArticle((data && data.description) || '', name, name2, brand, specs, keys);

	const highlights = [];
	const prefer = ['برند', 'مدل', 'مقدار رم', 'حافظه RAM', 'مقدار RAM', 'حافظه داخلی', 'گنجایش باتری', 'ظرفیت باتری', 'دوربین اصلی', 'اندازه صفحه نمایش', 'پردازنده'];
	const bag = Object.assign({}, keys, specs);
	for (const k of prefer) {
		if (bag[k]) highlights.push(k + ': ' + bag[k]);
		if (highlights.length >= 6) break;
	}
	if (highlights.length < 4) {
		for (const [k, v] of Object.entries(bag)) {
			const line = k + ': ' + v;
			if (!highlights.includes(line)) highlights.push(line);
			if (highlights.length >= 6) break;
		}
	}

	const summaryLines = [];
	Object.entries(bag).slice(0, 10).forEach(([k, v]) => {
		summaryLines.push('• ' + k + ': ' + v);
	});
	const review = summaryLines.join('\n');

	const faq = [];
	const qmap = { 'حافظه داخلی': 'حافظه داخلی این محصول چقدر است؟', 'مقدار رم': 'رم این محصول چقدر است؟', 'گنجایش باتری': 'ظرفیت باتری چقدر اعلام شده؟', 'برند': 'برند سازنده چیست؟' };
	Object.keys(qmap).forEach((k) => {
		if (specs[k] || keys[k]) faq.push({ q: qmap[k], a: String(specs[k] || keys[k]) + ' — طبق مشخصات ثبت‌شده.' });
	});
	const pros = analysisPros(cat, specs, keys);
	const cons = analysisCons(cat, specs, keys);
	const analysis = analysisText(cat, name, specs, pros, cons);
	const verdict = verdictText(cat, name, brand, specs, keys);
	const descriptionHtml = assembleProductHtml(data, body, highlights, faq, { pros, cons, analysis, verdict });

	let seoTitle = 'خرید ' + name;
	if (seoTitle.length < 50) seoTitle += ' | مشخصات کامل';
	if (seoTitle.length > 60) seoTitle = seoTitle.slice(0, 57) + '…';
	let seoDesc = 'خرید ' + name + ' با مشخصات کامل و تصاویر واقعی.';
	if (name2) seoDesc += ' ' + name2;
	if (seoDesc.length < 140) seoDesc += ' مشخصات را ببینید.';
	if (seoDesc.length > 155) seoDesc = seoDesc.slice(0, 152) + '…';
	const seo = buildSeo(data);
	const tags = seo.tags.slice();
	if (brand && tags.indexOf(brand) < 0) tags.unshift(brand);

	const shortItems = Object.entries(keys).slice(0, 5).map(([k, v]) => '<li><strong>' + escHtml(k) + ':</strong> ' + escHtml(v) + '</li>').join('');
	const short = (name2 ? '<p>' + escHtml(name2) + '</p>' : '') + (name ? '<p>' + escHtml(name) + '</p>' : '') + (shortItems ? '<ul>' + shortItems + '</ul>' : '');

	return {
		title: name,
		short_description: short,
		description_html: descriptionHtml,
		analysis: body,
		review,
		highlights,
		audience: '',
		verdict: '',
		seo_title: seoTitle,
		seo_desc: seoDesc,
		focus_keyword: brand ? ('خرید ' + brand) : ('خرید ' + name.split(' ')[0]),
		tags: tags.slice(0, 12),
		faq,
		provider: 'studio',
		provider_label: 'معرفی و بررسی — استودیو خواجوی',
		category: cat,
	};
}

module.exports = {
	normalizeSearch,
	normalizeDetails,
	normalizeDigikalaSearch,
	normalizeDigikalaDetails,
	aggregate,
	buildDescriptionHtml,
	buildShortDescription,
	buildAttributes,
	buildSeo,
	fileBase,
	numberFormatFa,
	escHtml,
	enhanceProduct,
	polishSource,
	detectCategory,
	composeArticle,
	draftArticle,
	assembleProductHtml,
};
