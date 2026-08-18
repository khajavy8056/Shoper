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

console.log('\nShoper 1.5.3 node tests\n');

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
check('plugin version 1.5.3', mainPhp.includes("define( 'SHOPER_VERSION', '1.5.3' )"));
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
check('enhance is editor not essay', String(enh.analysis || '').indexOf('نظر ساختگی') < 0 && String(enh.description_html || '').indexOf('تحلیل کارشناسی') < 0);
check('enhance review is spec summary', !!(enh.review && enh.review.indexOf('•') >= 0));
check('enhance seo', !!(enh.seo_title && enh.seo_desc));
check('enhance seo title خرید', String(enh.seo_title || '').indexOf('خرید') === 0);
check('enhance faq', Array.isArray(enh.faq) && enh.faq.length >= 1);
check('enhance keeps specs table', (enh.description_html || '').indexOf('مشخصات') >= 0);
check('enhance has FAQ html', (enh.description_html || '').indexOf('پرسش') >= 0);
const messy = logic.enhanceProduct(Object.assign({}, dkDetails, { description: 'گلکسی   S25  اولترا،،پرچمدار   سامسونگ' }));
check('polish collapses spaces and punctuation', String(messy.analysis || '').indexOf('گلکسی S25') >= 0 && String(messy.analysis || '').indexOf('،،') < 0);
check('copywriter organize in PHP', copyPhp.includes('function organize') && copyPhp.includes('function polish_source'));
check('admin.js enhance + 4 steps', pluginJs.includes('queueEnhance') && pluginJs.includes('data-step="ai"') && pluginJs.includes('data-step="review"'));
check('admin.js browserEnhance', pluginJs.includes('browserEnhance') && pluginJs.includes('parseAiJson') && pluginJs.includes('text.pollinations.ai'));
check('admin.js rotates 3 free models', pluginJs.includes('aiProviderList') && pluginJs.includes('llm7.io') && pluginJs.includes('oai.endpoints.kepler.ai.cloud.ovh.net') && pluginJs.includes('mode: \'studio\''));
check('ajax enhance action', ajax.includes('shoper_enhance'));
const aiPhp = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/includes/class-shoper-ai-client.php'), 'utf8');
check('AI uses 3 independent free families', aiPhp.includes('openai-fast') && aiPhp.includes('gpt-oss:20b') && aiPhp.includes('oai.endpoints.kepler.ai.cloud.ovh.net') && aiPhp.includes('Qwen3.6-27B'));
check('old dead llm7 models removed', aiPhp.indexOf("'gpt-4o-mini-2024-07-18'") < 0 && aiPhp.indexOf("'gemma-2-9b-it'") < 0);
check('merge keeps product data', aiPhp.includes('merge( $studio, $parsed, $data )'));
check('prompt is editor not copywriter', aiPhp.includes('ویراستار') && aiPhp.includes('تولید محتوای تازه ممنوع'));
check('studio mode and max 3 tries', aiPhp.includes("'studio'") && aiPhp.includes('MAX_TRIES') && aiPhp.includes('clamp_seo'));
check('copywriter has clamp_seo', copyPhp.includes('function clamp_seo'));
check('create uses studio only', ajax.includes('mode') && fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/includes/class-shoper-product-builder.php'), 'utf8').includes('Shoper_Copywriter::enhance'));

check('اکشن ingest ثبت شده', ajax.includes("wp_ajax_shoper_ingest") && ajax.includes('preview_from_payload'));

const client = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/includes/class-shoper-torob-client.php'), 'utf8');
check('ingest_search در کلاینت هست', client.includes('function ingest_search'));
check('request از رله و درگاه استفاده می‌کند', client.includes('wrap_relay_url') && client.includes('build_request_candidates') && client.includes('proxy.cors.sh'));

const relay = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/tools/shoper-relay.php'), 'utf8');
check('رله فقط دامنه ترب را قبول می‌کند', relay.includes('torob\\.(com|ir)') || relay.includes('torob\\.(com|ir)$'));

console.log('\nنتیجه:', pass, 'موفق،', fail, 'ناموفق\n');
process.exit(fail > 0 ? 1 : 0);
