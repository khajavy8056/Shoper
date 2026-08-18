/**
 * تست‌های نسخه ۱.۳ بدون PHP: منطق نرمال‌سازی، رله، پیشنهاد و ساخت از payload.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const logic = require('../preview/server/torob-logic');

let pass = 0;
let fail = 0;
function check(label, cond, extra) {
	if (cond) {
		pass++;
		console.log('  [PASS]', label, extra ? '— ' + extra : '');
	} else {
		fail++;
		console.log('  [FAIL]', label, extra ? '— ' + extra : '');
	}
}

const fixtureDir = path.join(__dirname, '../preview/fixtures');
const searchRaw = JSON.parse(fs.readFileSync(path.join(fixtureDir, 'search-samsung.json'), 'utf8'));
const detailsRaw = JSON.parse(fs.readFileSync(path.join(fixtureDir, 'details-9bcf3364.json'), 'utf8'));

console.log('\nShoper 1.5.7 node tests\n');

const search = logic.normalizeSearch(searchRaw);
check('جستجوی fixture نرمال می‌شود', search.results && search.results.length > 0, search.results.length + ' نتیجه');
check('آیتم جستجو more_info_url دارد', !!search.results[0].more_info_url);
check('آیتم جستجو گالری دارد', Array.isArray(search.results[0].gallery));

const details = logic.normalizeDetails(detailsRaw);
check('جزئیات fixture نام دارد', !!details.name1);
check('جزئیات مشخصات فنی دارد', details.specs && Object.keys(details.specs).length > 0, Object.keys(details.specs || {}).length + ' مشخصه');
check('فروشندگان availability پیش‌فرض دارند', details.sellers.every((s) => s.availability === true || s.price > 0));

const agg = logic.aggregate(details.sellers, 3, 'score');
check('تجمیع فروشنده بدون کلید availability کار می‌کند', agg.considered.length > 0, agg.considered.length + ' فروشنده');

const noAvail = details.sellers.map((s) => {
	const copy = { ...s };
	delete copy.availability;
	return copy;
});
const agg2 = logic.aggregate(noAvail, 3, 'score');
check('تجمیع وقتی availability نباشد قیمت مثبت را موجود می‌داند', agg2.considered.length > 0);

const partial = {
	name1: search.results[0].name1,
	random_key: search.results[0].random_key,
	price: search.results[0].price,
	image_url: search.results[0].image_url,
	gallery: search.results[0].gallery,
	more_info_url: search.results[0].more_info_url,
	partial: true,
	specs: {},
	sellers: [],
};
check('پیش‌نمایش جزئی از نتیجه جستجو قابل ساخت است', !!partial.name1 && !!partial.random_key && (partial.gallery || []).length >= 0);

function wrapRelay(relay, url) {
	if (!relay) return '';
	return relay + (relay.includes('?') ? '&' : '?') + 'url=' + encodeURIComponent(url);
}
const wrapped = wrapRelay('https://relay.example/shoper-relay.php?token=abc', 'https://api.torob.com/v4/base-product/search/?q=s25');
check('رله توکن را نگه می‌دارد', wrapped.includes('token=abc') && wrapped.includes('url='));

function wrapGateway(g, url) {
	if (g.style === 'template') return String(g.template).replace('{url}', encodeURIComponent(url));
	const base = String(g.base || '').replace(/\/+$/, '');
	if (g.style === 'query') return base + (base.includes('?') ? '&' : '?') + (g.param || 'url') + '=' + encodeURIComponent(url);
	return base + '/' + url;
}
const gw = wrapGateway({ style: 'prefix', base: 'https://proxy.cors.sh/' }, 'https://api.torob.com/v4/base-product/search/?q=s25');
check('درگاه پیشوندی CORS.SH درست ساخته می‌شود', gw === 'https://proxy.cors.sh/https://api.torob.com/v4/base-product/search/?q=s25');

const pluginJs = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/admin/js/admin.js'), 'utf8');
check('admin.js مسیر مرورگر و درگاه دارد', pluginJs.includes('browserFetch') && pluginJs.includes('wrapGateway') && pluginJs.includes('product_json'));
check('chooseSuggest لینک more_info را می‌فرستد', /preview\(it\.random_key[\s\S]{0,80}more_info_url/.test(pluginJs));
check('fill هم product_json می‌فرستد', /shoper_fill[\s\S]{0,900}product_json/.test(pluginJs));

const mainPhp = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/shoper-torob-importer.php'), 'utf8');
check('plugin version 1.5.7', mainPhp.includes("define( 'SHOPER_VERSION', '1.5.7' )"));
check('کلاس تجمیع فروشنده بارگذاری می‌شود', mainPhp.includes('class-shoper-seller-aggregator.php'));

check('digikala client required', mainPhp.includes('class-shoper-digikala-client.php') && mainPhp.includes('class-shoper-catalog.php'));
const dkSearch = logic.normalizeDigikalaSearch(JSON.parse(fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/assets/mock/digikala-search-sample.json'), 'utf8')));
check('digikala search normalize', dkSearch.results && dkSearch.results.length > 0, dkSearch.results.length + ' items');
check('digikala id prefix', /^DKP-\d+$/.test(dkSearch.results[0].random_key));
const dkDetails = logic.normalizeDigikalaDetails(JSON.parse(fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/assets/mock/digikala-details-sample.json'), 'utf8')));
check('digikala details name', !!dkDetails.name1);
check('digikala details specs', dkDetails.specs && Object.keys(dkDetails.specs).length >= 8, Object.keys(dkDetails.specs || {}).length + ' specs');
check('digikala details gallery', Array.isArray(dkDetails.gallery) && dkDetails.gallery.length >= 3);
check('digikala expert review', (dkDetails.description || '').length > 20);
check('admin.js knows dkp', pluginJs.includes('extractDkp') && pluginJs.includes('dk_search'));

const ajax = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/includes/class-shoper-ajax.php'), 'utf8');
const copyPhp = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/includes/class-shoper-copywriter.php'), 'utf8');

check('copywriter/ai classes required', mainPhp.includes('class-shoper-copywriter.php') && mainPhp.includes('class-shoper-ai-client.php'));
check('author Khajavy', mainPhp.includes('خواجوی'));
const enh = logic.enhanceProduct(dkDetails);
check('enhance keeps source meaning', String(enh.analysis || '').indexOf('گلکسی') >= 0 || String(enh.analysis || '').indexOf('S25') >= 0);
check('enhance is not fake essay', String(enh.analysis || '').indexOf('نظر ساختگی') < 0 && String(enh.description_html || '').indexOf('تحلیل کارشناسی') < 0 && String(enh.description_html || '').indexOf('سال ۲۰۲۶') < 0);
check('enhance uses fixed review template', (enh.description_html || '').indexOf('product-description-wrapper') >= 0);
check('enhance has intro review heading', (enh.description_html || '').indexOf('معرفی و بررسی محصول') >= 0);
check('enhance has specs table heading', (enh.description_html || '').indexOf('مشخصات فنی کامل') >= 0);
check('enhance has analysis section', (enh.description_html || '').indexOf('تحلیل و آنالیز فنی') >= 0);
check('enhance has verdict section', (enh.description_html || '').indexOf('نتیجه‌گیری و پیشنهاد خرید') >= 0);
check('enhance has pros box', (enh.description_html || '').indexOf('مزایا') >= 0);
check('enhance article is flowing not a spec dump', String(enh.analysis || '').indexOf('طبق مشخصات ثبت‌شده همین کالا:') < 0);
check('enhance review is spec summary', !!(enh.review && enh.review.indexOf('•') >= 0));
check('enhance seo', !!(enh.seo_title && enh.seo_desc));
check('enhance seo title خرید', String(enh.seo_title || '').indexOf('خرید') === 0);
check('enhance faq', Array.isArray(enh.faq) && enh.faq.length >= 1);
check('enhance keeps specs table', (enh.description_html || '').indexOf('مشخصات') >= 0);
const messy = logic.enhanceProduct(Object.assign({}, dkDetails, { description: 'گلکسی   S25  اولترا،،پرچمدار   سامسونگ' }));
check('polish collapses spaces and punctuation', String(messy.analysis || '').indexOf('گلکسی S25') >= 0 && String(messy.analysis || '').indexOf('،،') < 0);
const emptyDesc = logic.enhanceProduct(Object.assign({}, dkDetails, { description: '' }));
check('empty source still writes article from specs', String(emptyDesc.analysis || '').indexOf('سامسونگ') >= 0 && String(emptyDesc.analysis || '').length > 80);
check('empty source still has fixed html sections', (emptyDesc.description_html || '').indexOf('معرفی و بررسی محصول') >= 0 && (emptyDesc.description_html || '').indexOf('مشخصات فنی کامل') >= 0);
check('copywriter compose article in PHP', copyPhp.includes('function compose_article') && copyPhp.includes('function polish_source') && copyPhp.includes('معرفی و بررسی محصول'));
const glove = logic.enhanceProduct({ name1: 'دستکش کار ساده', name2: '', description: '', specs: { رنگ: 'مشکی' }, key_specs: { رنگ: 'مشکی' } });
check('simple product stays short', String(glove.verdict || '').length < 220 && (glove.description_html || '').indexOf('product-description-wrapper') >= 0);
check('phone layout uses source group headings', (enh.description_html || '').indexOf('صفحه نمایش') >= 0 && (enh.description_html || '').indexOf('دوربین') >= 0 && (enh.description_html || '').indexOf('product-spec-group') >= 0);
check('phone analysis cites this product specs', String(enh.tech_analysis || enh.description_html || '').indexOf('Snapdragon') >= 0 || String(enh.description_html || '').indexOf('200 مگاپیکسل') >= 0);
const gloveFull = logic.enhanceProduct({
	name1: 'دستکش ایمنی چرمی',
	name2: '',
	description: '',
	specs: { رنگ: 'مشکی', جنس: 'چرم طبیعی' },
	key_specs: { جنس: 'چرم طبیعی' },
	spec_groups: [{ header: 'جنس و رنگ', specs: { رنگ: 'مشکی', جنس: 'چرم طبیعی' } }],
});
check('glove layout uses its own group not phone groups', (gloveFull.description_html || '').indexOf('جنس و رنگ') >= 0 && (gloveFull.description_html || '').indexOf('Snapdragon') < 0 && (gloveFull.description_html || '').indexOf('دوربین اصلی') < 0);
check('glove skips long technical analysis', (gloveFull.description_html || '').indexOf('تحلیل و آنالیز فنی') < 0);
const laptop = logic.enhanceProduct({
	name1: 'لپ‌تاپ ایسوس Vivobook 15',
	name2: 'ASUS Vivobook 15',
	description: '',
	specs: {
		برند: 'ایسوس',
		پردازنده: 'Core i7-1355U',
		'مقدار رم': '16 گیگابایت',
		'حافظه داخلی': '512 گیگابایت',
		'کارت گرافیک': 'Intel Iris Xe',
		'اندازه صفحه نمایش': '15.6 اینچ',
		وزن: '1.7 کیلوگرم',
		'سیستم عامل': 'Windows 11',
	},
	key_specs: { پردازنده: 'Core i7-1355U', 'کارت گرافیک': 'Intel Iris Xe' },
	spec_groups: [
		{ header: 'پردازنده', specs: { پردازنده: 'Core i7-1355U', 'مقدار رم': '16 گیگابایت' } },
		{ header: 'کارت گرافیک', specs: { 'کارت گرافیک': 'Intel Iris Xe' } },
		{ header: 'صفحه نمایش', specs: { 'اندازه صفحه نمایش': '15.6 اینچ' } },
		{ header: 'حافظه', specs: { 'حافظه داخلی': '512 گیگابایت' } },
	],
});
check('laptop layout uses gpu group from source', (laptop.description_html || '').indexOf('کارت گرافیک') >= 0 && (laptop.description_html || '').indexOf('Intel Iris Xe') >= 0);
check('laptop analysis follows its groups', String(laptop.tech_analysis || '').indexOf('در بخش') >= 0 && String(laptop.tech_analysis || '').indexOf('Core i7') >= 0);
check('phone and glove html are not the same shape', ((gloveFull.description_html || '').match(/product-spec-group/g) || []).length !== ((enh.description_html || '').match(/product-spec-group/g) || []).length);
check('admin.js enhance + 4 steps', pluginJs.includes('queueEnhance') && pluginJs.includes('data-step="ai"') && pluginJs.includes('data-step="review"'));
check('admin.js browserEnhance', pluginJs.includes('browserEnhance') && pluginJs.includes('parseAiJson') && pluginJs.includes('text.pollinations.ai'));
check('admin.js rotates 3 free models', pluginJs.includes('aiProviderList') && pluginJs.includes('llm7.io') && pluginJs.includes('oai.endpoints.kepler.ai.cloud.ovh.net') && pluginJs.includes('mode: \'studio\''));
check('ajax enhance action', ajax.includes('shoper_enhance'));
const aiPhp = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/includes/class-shoper-ai-client.php'), 'utf8');
check('AI uses 3 independent free families', aiPhp.includes('openai-fast') && aiPhp.includes('gpt-oss:20b') && aiPhp.includes('oai.endpoints.kepler.ai.cloud.ovh.net') && aiPhp.includes('Qwen3.6-27B'));
check('old dead llm7 models removed', aiPhp.indexOf("'gpt-4o-mini-2024-07-18'") < 0 && aiPhp.indexOf("'gemma-2-9b-it'") < 0);
check('merge keeps product data', aiPhp.includes('merge( $studio, $parsed, $data )') && aiPhp.includes('fact_check') && aiPhp.includes('remote_rejected'));
check('prompt writes review template', aiPhp.includes('نقد و بررسی') && aiPhp.includes('verdict') && aiPhp.includes('pros') && aiPhp.includes('گروه‌'));
check('copywriter builds layout from spec groups', copyPhp.includes('function spec_groups') && copyPhp.includes('product-spec-group') && copyPhp.includes('function group_analysis_paragraph'));
check('studio mode and max 3 tries', aiPhp.includes("'studio'") && aiPhp.includes('MAX_TRIES') && aiPhp.includes('clamp_seo'));
check('copywriter has clamp_seo', copyPhp.includes('function clamp_seo'));
check('create uses studio only', ajax.includes('mode') && fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/includes/class-shoper-product-builder.php'), 'utf8').includes('Shoper_Copywriter::enhance'));

check('اکشن ingest ثبت شده', ajax.includes("wp_ajax_shoper_ingest") && ajax.includes('preview_from_payload'));

const client = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/includes/class-shoper-torob-client.php'), 'utf8');
check('ingest_search در کلاینت هست', client.includes('function ingest_search'));
check('request از رله و درگاه استفاده می‌کند', client.includes('wrap_relay_url') && client.includes('build_request_candidates') && client.includes('proxy.cors.sh'));

const relay = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/tools/shoper-relay.php'), 'utf8');
check('رله فقط دامنه ترب را قبول می‌کند', relay.includes('torob\\.(com|ir)') || relay.includes('torob\\.(com|ir)$'));
check('prompt is three-step with self-check', aiPhp.includes('مرحله ۱') && aiPhp.includes('مرحله ۳') && aiPhp.includes('checked') && aiPhp.includes('اینترنت نداری'));
check('copywriter has fact_check and briefing', copyPhp.includes('function fact_check') && copyPhp.includes('function briefing') && copyPhp.includes('function filter_claims'));
const gloveFacts = { name1: 'دستکش ایمنی چرمی', specs: { رنگ: 'مشکی', جنس: 'چرم طبیعی' }, key_specs: { جنس: 'چرم طبیعی' } };
const checked = logic.factCheck('جنس چرم طبیعی است. پردازنده ۹۹۹ گیگاهرتز دارد.', gloveFacts);
check('fact_check drops invented numbers', checked.indexOf('999') < 0 && checked.indexOf('چرم طبیعی') >= 0);
check('fact_check drops fake review phrases', logic.factCheck('نظر مشتری این است که عالی است. رنگ مشکی ثبت شده است.', gloveFacts).indexOf('نظر مشتری') < 0);
const kept = logic.filterClaims(['جنس: چرم طبیعی', 'باتری ۵۰۰۰ میلی‌آمپر'], gloveFacts).join(' ');
check('filter_claims keeps grounded highlight', kept.indexOf('چرم طبیعی') >= 0 && kept.indexOf('۵۰۰۰') < 0 && kept.indexOf('5000') < 0);
check('admin.js three-step prompt', pluginJs.includes('مرحله ۱') && pluginJs.includes('checked') && pluginJs.includes('اینترنت نداری'));

console.log('\nنتیجه:', pass, 'موفق،', fail, 'ناموفق\n');
process.exit(fail > 0 ? 1 : 0);
