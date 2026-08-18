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
	return 'generic';
}

function specOf(specs, names) {
	for (const n of names) {
		if (specs && specs[n]) return String(specs[n]).trim();
	}
	return '';
}

function enhanceProduct(data) {
	const name = String((data && data.name1) || '');
	const name2 = String((data && data.name2) || '');
	const specs = (data && data.specs) || {};
	const keys = (data && data.key_specs) || {};
	const source = String((data && data.description) || '').replace(/\s+/g, ' ').trim();
	const cat = detectCategory(name + ' ' + name2);
	const brand = specOf(specs, ['برند', 'سازنده']);
	const highlights = [];
	const prefer = ['برند', 'مدل', 'مقدار رم', 'حافظه RAM', 'حافظه داخلی', 'گنجایش باتری', 'ظرفیت باتری', 'دوربین اصلی', 'اندازه صفحه نمایش', 'پردازنده'];
	for (const k of prefer) {
		const bag = Object.assign({}, keys, specs);
		if (bag[k]) highlights.push(k + ': ' + bag[k]);
		if (highlights.length >= 6) break;
	}
	if (highlights.length < 4) {
		for (const [k, v] of Object.entries(Object.assign({}, keys, specs))) {
			const line = k + ': ' + v;
			if (!highlights.includes(line)) highlights.push(line);
			if (highlights.length >= 6) break;
		}
	}
	const ram = specOf(specs, ['مقدار رم', 'حافظه RAM', 'رم']);
	const rom = specOf(specs, ['حافظه داخلی']);
	const bat = specOf(specs, ['گنجایش باتری', 'ظرفیت باتری']);
	const cam = specOf(specs, ['دوربین اصلی', 'کیفیت دوربین اصلی']);
	let analysis = 'تحلیل زیر فقط از مشخصات اعلام‌شده برای «' + name + '» استخراج شده است؛ هیچ قابلیت خارج از داده اضافه نشده.';
	const bits = [];
	if (ram) bits.push('رم «' + ram + '» برای چندوظیفگی معیار عملی است.');
	if (rom) bits.push('حافظه «' + rom + '» سقف نگهداری فایل را مشخص می‌کند.');
	if (cam) bits.push('دوربین اصلی «' + cam + '» معیار عکاسی روزمره این مدل است.');
	if (bat) bits.push('باتری «' + bat + '» یکی از معیارهای دوام روزانه است.');
	if (bits.length) analysis += '\n\n' + bits.join(' ');
	if (source) analysis += '\n\nتوضیح کارشناسی منبع در معرفی حفظ شده است.';

	const pros = [];
	const cons = [];
	if (brand) pros.push('برند مشخص: ' + brand);
	if (ram && /(\d+)/.test(ram) && parseInt(RegExp.$1, 10) >= 8) pros.push('رم نسبتاً بالا (' + ram + ')');
	if (bat) pros.push('ظرفیت باتری اعلام‌شده: ' + bat);
	if (!pros.length) {
		highlights.slice(0, 3).forEach((h) => pros.push(h));
	}
	if (!specOf(specs, ['گواهی ضدآب'])) cons.push('مقاومت رسمی در برابر آب در مشخصات دیده نشد.');
	cons.push('قیمت نهایی را فروشگاه تعیین می‌کند؛ این بررسی روی مشخصات است.');
	let review = 'بررسی کارشناسی «' + name + '» — نه نظر ساختگی مشتری.\n\nنقاط قوت:\n';
	pros.slice(0, 5).forEach((p) => { review += '• ' + p + '\n'; });
	review += '\nنکات قابل توجه:\n';
	cons.slice(0, 4).forEach((c) => { review += '• ' + c + '\n'; });
	review += '\nجمع‌بندی بررسی: اگر مشخصات با نیاز خریدار هم‌خوان است، مدل برای انتشار در فروشگاه شفاف است.';

	const audience = cat === 'phone'
		? 'مناسب استفاده روزمره، شبکه‌های اجتماعی و کار اداری سبک. خریدار حرفه‌ای جدول دوربین و باتری را خط‌به‌خط بسنجد.'
		: 'خریدارانی که می‌خواهند مشخصات، تصاویر و متن فروش را قبل از انتشار خودشان تأیید کنند.';
	const verdict = name + ' با دادهٔ کامل کاتالوگ برای انتشار در ووکامرس آماده است. ارزش صفحه در شفافیت مشخصات و متن کارشناسی است.';
	const intro = name + ' برای فروشگاه آماده شده است.' + (brand ? ' برند: ' + brand + '.' : '') + (name2 ? ' شناسه بین‌المللی: ' + name2 + '.' : '') + (source ? ' ' + source.slice(0, 420) : ' در ادامه تحلیل و بررسی کارشناسی آمده است.');

	let descriptionHtml = '<div class="shoper-studio-copy">';
	descriptionHtml += '<h2>معرفی محصول</h2><p>' + escHtml(intro) + '</p>';
	if (highlights.length) {
		descriptionHtml += '<h2>نکات برجسته</h2><ul>' + highlights.map((h) => '<li>' + escHtml(h) + '</li>').join('') + '</ul>';
	}
	descriptionHtml += '<h2>تحلیل کارشناسی</h2>' + analysis.split(/\n\n/).map((p) => '<p>' + escHtml(p) + '</p>').join('');
	descriptionHtml += '<h2>بررسی محصول</h2>';
	review.split(/\n+/).forEach((line) => {
		if (line === 'نقاط قوت:') descriptionHtml += '<h3>نقاط قوت</h3>';
		else if (line === 'نکات قابل توجه:') descriptionHtml += '<h3>نکات قابل توجه</h3>';
		else if (line.indexOf('• ') === 0) descriptionHtml += '<p>• ' + escHtml(line.slice(2)) + '</p>';
		else if (line) descriptionHtml += '<p>' + escHtml(line) + '</p>';
	});
	descriptionHtml += '<h2>مناسب برای چه کسانی؟</h2><p>' + escHtml(audience) + '</p>';
	if (keys && Object.keys(keys).length) {
		descriptionHtml += '<h2>مشخصات کلیدی</h2>' + renderSpecTable(keys);
	}
	if (data.spec_groups && data.spec_groups.length) {
		descriptionHtml += '<h2>مشخصات فنی کامل</h2>';
		data.spec_groups.forEach((g) => {
			if (!g.specs || !Object.keys(g.specs).length) return;
			if (g.header) descriptionHtml += '<h3>' + escHtml(g.header) + '</h3>';
			descriptionHtml += renderSpecTable(g.specs);
		});
	} else if (specs && Object.keys(specs).length) {
		descriptionHtml += '<h2>مشخصات فنی کامل</h2>' + renderSpecTable(specs);
	}
	descriptionHtml += '<h2>جمع‌بندی خرید</h2><p>' + escHtml(verdict) + '</p>';
	descriptionHtml += '<p class="shoper-source">متن فروش توسط <strong>Shoper Studio</strong> — خواجوی آماده شده است.</p></div>';

	const seo = buildSeo(data);
	const seoTitle = ('خرید ' + name).slice(0, 70);
	let seoDesc = 'خرید ' + name + ' با مشخصات کامل، بررسی کارشناسی و تصاویر واقعی.';
	if (name2) seoDesc += ' ' + name2;
	if (seoDesc.length > 155) seoDesc = seoDesc.slice(0, 152) + '…';
	const tags = seo.tags.slice();
	if (brand && tags.indexOf(brand) < 0) tags.unshift(brand);

	const shortItems = Object.entries(keys).slice(0, 5).map(([k, v]) => '<li><strong>' + escHtml(k) + ':</strong> ' + escHtml(v) + '</li>').join('');
	const short = (name2 ? '<p>' + escHtml(name2) + '</p>' : '') + '<p>' + escHtml(name) + ' — مشخصات فنی، تصاویر و متن کارشناسی آمادهٔ انتشار.</p>' + (shortItems ? '<ul>' + shortItems + '</ul>' : '');

	return {
		title: name,
		short_description: short,
		description_html: descriptionHtml,
		analysis,
		review,
		highlights,
		audience,
		verdict,
		seo_title: seoTitle,
		seo_desc: seoDesc,
		focus_keyword: brand ? (brand + ' ' + name.split(' ')[0]) : name,
		tags: tags.slice(0, 12),
		provider: 'studio',
		provider_label: 'استودیوی نویسندگی خواجوی',
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
	detectCategory,
};
