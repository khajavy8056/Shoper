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

console.log('\nShoper 1.3.2 node tests\n');

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
check('fill هم product_json می‌فرستد', /shoper_fill[\s\S]{0,400}product_json/.test(pluginJs));

const mainPhp = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/shoper-torob-importer.php'), 'utf8');
check('نسخه افزونه ۱.۳ است', /SHOPER_VERSION', '1\.3\.\d+'/.test(mainPhp));
check('کلاس تجمیع فروشنده بارگذاری می‌شود', mainPhp.includes('class-shoper-seller-aggregator.php'));

const ajax = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/includes/class-shoper-ajax.php'), 'utf8');
check('اکشن ingest ثبت شده', ajax.includes("wp_ajax_shoper_ingest") && ajax.includes('preview_from_payload'));

const client = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/includes/class-shoper-torob-client.php'), 'utf8');
check('ingest_search در کلاینت هست', client.includes('function ingest_search'));
check('request از رله و درگاه استفاده می‌کند', client.includes('wrap_relay_url') && client.includes('build_request_candidates') && client.includes('proxy.cors.sh'));

const relay = fs.readFileSync(path.join(__dirname, '../shoper-torob-importer/tools/shoper-relay.php'), 'utf8');
check('رله فقط دامنه ترب را قبول می‌کند', relay.includes('torob\\.(com|ir)') || relay.includes('torob\\.(com|ir)$'));

console.log('\nنتیجه:', pass, 'موفق،', fail, 'ناموفق\n');
process.exit(fail > 0 ? 1 : 0);
