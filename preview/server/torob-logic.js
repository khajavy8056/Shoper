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

	const available = sellers.filter((s) => s.price > 0 && s.availability);
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

module.exports = {
	normalizeSearch,
	normalizeDetails,
	aggregate,
	buildDescriptionHtml,
	buildShortDescription,
	buildAttributes,
	buildSeo,
	fileBase,
	numberFormatFa,
	escHtml,
};
