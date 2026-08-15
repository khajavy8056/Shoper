# گزارش تحقیقاتی پروژه Shoper

> ابزار ساخت خودکار محصول در ووکامرس با دریافت اطلاعات از ترب (Torob)
> تاریخ: ۱۴۰۵/۰۵/۲۴ (معادل ۲۰۲۶-۰۸-۱۵)
> شاخه: `arena/01a005ab-shoper`

---

## ۱. جمع‌بندی سریع (TL;DR)

- ترب **API رسمی و عمومی برای توسعه‌دهندگان ندارد**، اما وب‌اپلیکیشن ترب از یک API JSON داخلی در دامنه‌ی `api.torob.com` استفاده می‌کند که می‌توان به آن با نام محصول (search) یا شناسه‌ی محصول (جزئیات) متصل شد.
- جستجو **با نام محصول کاملاً ممکن است** (endpoint جستجو)، و پس از انتخاب نتیجه‌ی درست، جزئیات کامل شامل تصاویر و مشخصات فنی دریافت می‌شود.
- اتصال از طریق **لینک محصول** هم ممکن است: لینک صفحه‌ی ترب حاوی `prk` و `search_id` است که مستقیماً به endpoint جزئیات می‌رود.
- داده‌های مشخصات فنی در فیلد `structural_specs` و `key_specs` به‌صورت گروه‌بندی‌شده (key/value) می‌آیند که دقیقاً همان چیزی است که باید به‌صورت «ویژگی محصول» در ووکامرس ثبت شود.
- بهترین پیاده‌سازی: یک **افزونه‌ی وردپرس/ووکامرس** با یک صفحه‌ی مدیریت که:
  1. نام/لینک محصول را می‌گیرد،
  2. نتایج جستجو را نشان می‌دهد،
  3. یک **پیش‌نمایش** کامل قبل از ساخت نمایش می‌دهد،
  4. با تأیید کاربر، محصول را با توضیحات، تصاویر (دانلود شده در Media Library) و تمام مشخصات فنی به‌صورت attribute می‌سازد.
- پروژه‌ی مشابهی برای دیجی‌کالا در گیت‌هاب پیدا شد که الگوی معماری خوبی است (بخش ۴).
- **محدودیت محیط سندباکس:** از داخل این محیط اتصال مستقیم SSL به `torob.com` برقرار نمی‌شود (محدودیت شبکه)، بنابراین کد باید روی سرور وردپرس (که به اینترنت ایران دسترسی دارد) اجرا شود. در مرحله‌ی توسعه، با داده‌های نمونه (mock) و معماری لایه‌ای کار را پیش می‌بریم.

---

## ۲. بررسی API ترب (Torob)

### ۲.۱ وضعیت API رسمی

طبق مستندات و پلتفرم‌های شخص‌ثالث ([Parse.bot](https://parse.bot/marketplace/d925cf61-d99f-41ad-9765-d68fc7fcfac1/torob-com-api)):

> «ترب یک API رسمی عمومی برای توسعه‌دهندگان یا پورتال مستندات توسعه‌دهنده منتشر نکرده است.»

آنچه ترب **رسماً منتشر کرده** فقط یک API برای **فروشندگان** است تا محصولاتشان را **به سمت ترب ارسال کنند** (نه دریافت از ترب) — یعنی فروشگاه شما یک endpoint مثل `https://domain.com/.../products` با متد POST فراهم می‌کند و ربات ترب آن را می‌خواند. این API برای سناریوی ما (گرفتن اطلاعات از ترب) کاربردی ندارد.

با این حال، وب‌کلاینت ترب از اندپوینت‌های JSON زیر استفاده می‌کند و چندین کتابخانه/اسکریپر در گیت‌هاب با موفقیت از آن‌ها استفاده می‌کنند:

### ۲.۲ اندپوینت‌های کلیدی (برگرفته از کد واقعی کتابخانه‌ها)

بر اساس سورس [`hamidrezafarzin/Torob-Integration`](https://github.com/hamidrezafarzin/Torob-Integration/blob/main/src/torob_integration/api.py):

| متد | URL | پارامترها | کاربرد |
|------|-----|-----------|--------|
| پیشنهاد | `https://api.torob.com/suggestion2/` | `q` | پیشنهادهای تایپ‌هنگام جستجو |
| جستجو | `https://api.torob.com/v4/base-product/search/` | `q` یا `query`, `page`, `size`, `sort`, `category`, `source=next_desktop` | جستجوی محصول با نام |
| جزئیات | `https://api.torob.com/v4/base-product/details/` | `prk`, `search_id`, `source=next_desktop` | اطلاعات کامل محصول |
| جزئیات/کلیک | `.../v4/base-product/details-log-click/` | `prk`, `discover_method` | دریافت اطلاعات برای کلیک (مشابه details) |
| پیشنهادهای ویژه | `https://api.torob.com/v4/special-offers/` | `page` | محصولات ویژه |
| نمودار قیمت | `https://api.torob.com/v4/base-product/price-chart/` | `prk`, `search_id` | تاریخچه قیمت |

**نکته مهم:** برای دریافت جزئیات کامل، ابتدا باید جستجو انجام شود؛ زیرا `prk` (کلید محصول) و `search_id` در فیلد `more_info_url` هر نتیجه‌ی جستجو برگردانده می‌شوند.

### ۲.۳ ساختار پاسخ جستجو

بر اساس اسکریپت‌های [`mahdi-marjani/torob-scraper`](https://github.com/mahdi-marjani/torob-scraper/blob/main/product_scraper.py) و نمونه‌ی Parse.bot:

```json
{
  "count": 1200,
  "results": [
    {
      "name1": "گوشی ...",            // نام فارسی
      "name2": "Apple iPhone ...",    // نام انگلیسی/زیرعنوان
      "price": 362840000,             // پایین‌ترین قیمت (تومان)
      "image_url": "https://image.torob.com/base/images/...jpg",
      "random_key": "18d33323-...",   // شناسه UUID محصول
      "web_client_absolute_url": "/p/.../",
      "more_info_url": "/v4/base-product/details/?prk=...&search_id=..."
    }
  ],
  "next": "https://api.torob.com/...",  // صفحه بعد
  "categories": [ { "id": "94", "title": "گوشی موبایل", "cat_id": 94 } ]
}
```

### ۲.۴ ساختار پاسخ جزئیات (مهم‌ترین بخش برای ما)

بر اساس سورس [`omidima/torob-bot`](https://github.com/omidima/torob-bot/blob/main/src/torob_craweler.py):

```json
{
  "random_key": "uuid",
  "name1": "نام فارسی",
  "name2": "نام انگلیسی",
  "min_price": 0,
  "max_price": 0,
  "buy_box_price_text": "...",
  "image_url": "https://image.torob.com/...jpg",
  "variants": [ ... ],
  "products_info": {
    "count": 12,
    "result": [
      { "shop_name": "...", "price": 0, "price_text": "...", "availability": "...", "score_info": ... }
    ]
  },
  "key_specs": [
    {
      "header": "حافظه",
      "items": [ { "key": "حافظه داخلی", "value": "256 گیگابایت" } ]
    }
  ],
  "structural_specs": {
    "headers": [
      {
        "specs": {
          "وزن": "189 گرم",
          "پردازنده": "Apple A18 Pro",
          "نوع شنا": "...",
          "..." : "..."
        }
      }
    ]
  }
}
```

**نکته‌ی کلیدی:** فیلد `structural_specs.headers[0].specs` یک **دیکشنری ساده‌ی key/value** است که دقیقاً تمام مشخصات فنی را در خود دارد و همان چیزی است که باید یکی‌یکی به‌عنوان ویژگی (attribute) به محصول اضافه کنیم. `key_specs` نسخه‌ی گروه‌بندی‌شده و خلاصه‌تر است که می‌توان از آن برای ساخت جدول توضیحات استفاده کرد.

### ۲.۵ اتصال با لینک محصول

لینک یک محصول در ترب شکلی شبیه این دارد:
`https://torob.com/p/abcd1234/نومحصول/`

می‌توان:
1. شناسه‌ی محصول را از URL استخراج کرد (`random_key`/`prk`)،
2. یا خود URL را به ترب داد و از طریق API به `prk`/`search_id` رسید.

برای دقت حداکثری، روش پیشنهادی این است که اگر کاربر لینک داد، ابتدا شناسه را استخراج کنیم؛ اما چون endpoint جزئیات به `search_id` هم نیاز دارد، ممکن است یک فراخوانی جستجوی کوتاه لازم باشد. در عمل کتابخانه‌ها همین کار را می‌کنند.

### ۲.۶ سرویس‌های شخص‌ثالث (در صورت نیاز به fallback)

- [MajidAPI](https://majidapi.ir/doc/ترب): `https://api.majidapi.ir/torob?action=search&s=...` و `action=details&id=...`
- [Parse.bot Torob API](https://parse.bot/marketplace/d925cf61-d99f-41ad-9765-d68fc7fcfac1/torob-com-api): ۱۰ اندپوینت ساختاریافته با کلید API رایگان.

> **توصیه:** در پیاده‌سازی، یک لایه‌ی انتزاعی (Driver) بسازیم تا اگر API مستقیم ترب تغییر کرد یا در برخی سرورها در دسترس نبود، بتوان به‌سادگی بین API مستقیم و سرویس شخص‌ثالث سوییچ کرد.

### ۲.۷ تست‌های اتصال از سندباکس

تست‌های `curl` از داخل این محیط انجام شد:
- DNS برای `api.torob.com` حل می‌شود (IP: `185.53.143.214`, `81.12.31.29`).
- اما در handshake با خطای `SSL_ERROR_SYSCALL` مواجه شدیم (روی HTTP/1.1، HTTP/2 و IP جایگزین هم تست شد).
- `image.torob.com` و `api.majidapi.ir` نیز از این سندباکس قابل دسترسی نیستند.

**نتیجه‌گیری:** این محدودیتِ شبکه‌ی سندباکس است، نه مشکل API. افزونه باید روی سرور وردپرس میزبان (که به ترب دسترسی دارد) اجرا شود. در طول توسعه از داده‌های نمونه‌ی ضبط‌شده استفاده می‌کنیم.

---

## ۳. نحوه‌ی کار با ووکامرس (WooCommerce)

### ۳.۱ ساخت محصول به‌صورت برنامه‌نویسی‌شده

ووکامرس کلاس‌های `WC_Product_Simple`، `WC_Product_Variable` و `WC_Product_Variation` را ارائه می‌دهد. الگوی استاندارد:

```php
$product = new WC_Product_Simple();
$product->set_name($name);
$product->set_status('draft');          // پیشنهاد: اول draft تا کاربر بررسی کند
$product->set_description($description);
$product->set_short_description($short_desc);
$product->set_regular_price($price);
$product->set_sku('TRB-' . $random_key);
$product->save();
```

### ۳.۲ افزودن مشخصات فنی به‌عنوان ویژگی (Attributes)

هر مشخصه باید یک `WC_Product_Attribute` شود. دو نوع attribute داریم:

1. **سراسری (taxonomy-based)** با پیشوند `pa_` مثل `pa_processor` — قابل فیلتر در فروشگاه و قابل استفاده بین محصولات.
2. **محصول‌محور (custom/local)** — فقط برای همین محصول ذخیره می‌شود.

برای مشخصات فنی که ممکن است مقادیر متنوع و زیاد داشته باشند (وزن، پردازنده، نوع شنا...)، رویکرد **هیبریدی** بهترین است: ویژگی‌های مهم/قابل‌فیلتر را به‌صورت taxonomy و بقیه را به‌صورت local attribute ذخیره می‌کنیم. کد پایه:

```php
$attributes = array();
foreach ($specs as $key => $value) {
    $attribute = new WC_Product_Attribute();
    // برای attribute سراسری:
    $taxonomy = wc_attribute_taxonomy_name($key_slug);   // pa_...
    $attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
    $attribute->set_name($taxonomy);
    $term = wp_insert_term($value, $taxonomy);
    $attribute->set_options(array((int)$term['term_id']));
    $attribute->set_visible(true);
    $attribute->set_variation(false);
    $attributes[] = $attribute;
}
$product->set_attributes($attributes);
$product->save();
```

> **نکته‌ی مهم درباره‌ی کاراکترهای فارسی:** نام ویژگی‌ها فارسی است. پیش از ساخت `pa_` باید slug انگلیسی/لاتین تولید کنیم (مثلاً با یک map دستی برای ویژگی‌های رایج یا transliteration). ووکامرس نام taxonomy را به حروف لاتین محدود می‌کند. مقدار (term) می‌تواند فارسی باشد.

### ۳.۳ دانلود و ثبت تصاویر در Media Library

وردپرس تابع `media_sideload_image()` و `download_url()` را برای دانلود تصویر از URL خارجی دارد:

```php
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// تصویر شاخص:
$thumb_id = media_sideload_image($image_url, $product_id, $name, 'id');
set_post_thumbnail($product_id, $thumb_id);

// گالری:
$gallery_ids = array();
foreach ($gallery_urls as $url) {
    $id = media_sideload_image($url, $product_id, null, 'id');
    if (!is_wp_error($id)) $gallery_ids[] = $id;
}
$product->set_gallery_image_ids($gallery_ids);
```

`media_sideload_image` با پارامتر چهارم `'id'` مستقیماً attachment ID را برمی‌گرداند و فایل را در `wp-content/uploads` ذخیره می‌کند. این یعنی تصاویر ترب به سرور فروشگاه منتقل می‌شوند (وابسته به لینک خارجی نمی‌مانیم).

### ۳.۴ توضیحات محصول

- متن معرفی/توضیح در `set_description()` (تب Description ویرایشگر وردپرس).
- خلاصه در `set_short_description()`.
- می‌توان یک **جدول مشخصات** به‌صورت HTML در توضیحات ساخت (با `key_specs` یا `structural_specs`).
- افزونه‌ی torob-bot از Narangi/ChatGPT برای تولید توضیح خودکار استفاده می‌کند — این یک قابلیت **اختیاری و در فاز بعد** می‌تواند باشد.

### ۳.۵ منابع مستندات مطالعه‌شده

- [WooCommerce: Create Product Programmatically — Business Bloomer](https://www.businessbloomer.com/woocommerce-programmatically-create-product/)
- [Create Products Programmatically — Rudrastyh](https://rudrastyh.com/woocommerce/create-product-programmatically.html)
- [افزودن attribute جدید — Stack Overflow](https://stackoverflow.com/questions/39828377)
- [دانلود تصویر از URL خارجی در Media Library](https://krasenslavov.com/re-building-and-re-inserting-images-into-the-wordpress-media-library-from-external-sources/)
- [مستندات API خروجی محصولات برای ترب (سمت فروشنده)](https://parscoders.com/project/286782)

---

## ۴. پروژه‌های مشابه در گیت‌هاب

| پروژه | زبان/پلتفرم | توضیح | ارزش برای ما |
|-------|-------------|-------|--------------|
| [hamidrezafarzin/Torob-Integration](https://github.com/hamidrezafarzin/Torob-Integration) | Python (pip) | ۳۱ ستاره — پکیج پایتون برای suggestion/search/details/special_offers/price_chart. | **منبع اصلی مرجع برای اندپوینت‌ها و پارامترها** |
| [omidima/torob-bot](https://github.com/omidima/torob-bot) | Python | اسکریپر کامل که داده را به DTO محصول تبدیل می‌کند؛ منطق استخراج `structural_specs` و یافتن ارزان‌ترین فروشنده را دارد. | **منبع عالی برای مپ‌کردن فیلدها** |
| [mahdi-marjani/torob-scraper](https://github.com/mahdi-marjani/torob-scraper) | Python | اسکریپت ساده‌ی جستجو و ذخیره در CSV. | نمونه‌ی استفاده از endpoint search |
| [alireza-aminzadeh/digikala-woocommerce-importer](https://github.com/alireza-aminzadeh/digikala-woocommerce-importer) | **PHP / WordPress plugin** | افزونه‌ی وردپرس برای اسکرپ دیجی‌کالا و import به ووکامرس با دسته، تصاویر، progress tracking و تاریخچه‌ی import. | **الگوی معماری افزونه** (admin/ + includes/، صفحه‌ی مدیریت، AJAX progress) |
| [wp-plugins/woocommerce-product-importer](https://github.com/wp-plugins/woocommerce-product-importer) | PHP | افزونه‌ی رسمی قدیمی‌تر برای import از CSV. | مرجع ساختار استاندارد افزونه |

**هیچ افزونه‌ی آماده‌ای برای import مستقیم از ترب به ووکامرس پیدا نشد** — این یعنی پروژه‌ی ما جدید است و باید ساخته شود. پروژه‌ی دیجی‌کالا نزدیک‌ترین الگوی معماری است.

---

## ۵. معماری پیشنهادی افزونه‌ی Shoper

### ۵.۱ ساختار پوشه‌ها

```
shoper-torob-importer/
├── shoper-torob-importer.php        # فایل اصلی و header افزونه
├── uninstall.php
├── readme.txt
├── includes/
│   ├── class-torob-client.php       # لایه‌ی اتصال به API ترب (با کش و fallback)
│   ├── class-torob-mapper.php       # نگاشت JSON ترب → مدل محصول داخلی
│   ├── class-wc-product-builder.php # ساخت واقعی WC_Product + attributes + تصاویر
│   ├── class-image-downloader.php   # دانلود تصاویر با media_sideload_image
│   └── class-attribute-helper.php   # مدیریت pa_ taxonomyهای فارسی و slug
├── admin/
│   ├── class-admin-menu.php
│   ├── class-ajax-handler.php       # جستجو، پیش‌نمایش، ساخت
│   ├── css/admin.css
│   └── js/admin.js                  # UI پیش‌نمایش با Vue/React یا Alpine
├── languages/                       # ترجمه فارسی
└── assets/
    └── mock/                        # داده‌های نمونه برای تست در سندباکس
```

### ۵.۲ جریان کار کاربر

1. کاربر وارد **ووکامرس ← Shoper ← ساخت محصول از ترب** می‌شود.
2. یکی از دو حالت را انتخاب می‌کند:
   - **جستجو با نام محصول** (حالت پیش‌فرض و اولویت‌دار طبق درخواست شما)،
   - **ورود لینک مستقیم محصول** (fallback).
3. نتایج جستجو (با تصویر بندانگشتی، نام فارسی/انگلیسی و قیمت) نمایش داده می‌شوند.
4. کاربر روی یک نتیجه کلیک می‌کند → **صفحه‌ی پیش‌نمایش** باز می‌شود:
   - تصویر اصلی و گالری،
   - نام، قیمت،
   - متن توضیحات (قابل ویرایش قبل از ساخت)،
   - جدول مشخصات فنی (هر سطر یک attribute، با امکان حذف/ویرایش)،
   - تنظیمات: دسته، وضعیت انتشار (پیش‌نویز/منتشرشده)، نوع محصول.
5. کاربر دکمه‌ی **«ایجاد محصول در ووکامرس»** را می‌زند.
6. افزونه با AJAX:
   - تصاویر را با `media_sideload_image` دانلود می‌کند،
   - attributeها را می‌سازد،
   - محصول را ایجاد می‌کند و لینک ویرایش آن را نشان می‌دهد.

### ۵.۳ رفع نگرانی‌های مطرح‌شده

| نگرانی شما | راه‌حل پیشنهادی |
|-----------|-----------------|
| «با نام محصول هم بشود؟» | بله — حالت پیش‌فرض جستجو با `q` است. |
| «اگر نشد با لینک» | حالت دوم لینک مستقیم. |
| «اطلاعات دقیق و قشنگ بگیرد» | استفاده از `structural_specs` + `key_specs` + `products_info`. |
| «تصاویر کامل» | `image_url` + تصاویر variants در صورت وجود؛ دانلود در Media Library. |
| «توضیحات متنی در بخش توضیحات» | `set_description()` با HTML جدول‌دار. |
| «مشخصات فنی دونه‌دونه به‌عنوان ویژگی» | `WC_Product_Attribute` برای هر spec، با موقعیت (position). |
| «پردازنده و نوع شنا مشخص شود» | این‌ها خودبه‌خود از `structural_specs` می‌آیند؛ می‌توانیم کلیدواژه‌های مهم را به‌عنوان taxonomy سراسری (`pa_processor` و ...) ثبت کنیم تا قابل‌فیلتر باشند. |
| «جای درست و صحیح» | تصاویر → Featured + Gallery؛ متن → Description؛ specs → Attributes. |
| «پیش‌نمایش قبل از ساخت» | صفحه‌ی مجزا با تمام فیلدها قابل‌ویرایش. |
| «افزونه باشد نه فایل درون‌ریزی» | بله، افزونه‌ی کامل وردپرس/ووکامرس. |

### ۵.۴ ملاحظات فنی و ریسک‌ها

1. **تغییر API ترب:** چون رسمی نیست، ممکن است تغییر کند. → لایه‌ی client را با کش و چندین استراتژی (direct + third-party fallback) می‌سازیم.
2. **محدودیت نرخ (rate limit):** ترب ممکن است درخواست‌های زیاد را ببندد. → تاخیر بین درخواست‌ها + کش نتایج در ترنزینت‌های وردپرس.
3. **کاراکترهای فارسی در نام attribute:** slug لاتین لازم دارد. → نقشه‌ی slug دستی برای ویژگی‌های رایج + sanitize.
4. **تصاویر:** `image.torob.com` ممکن است hotlink protection داشته باشد. چون در سمت سرور دانلود می‌کنیم (با User-Agent مناسب) معمولاً مشکلی نیست، اما باید با هدر درست دانلود کنیم.
5. **قوانین و کپی‌رایت:** محتوا و تصاویر متعلق به فروشندگان/ترب است. در مستندات افزونه هشدار لازم داده می‌شود که کاربر باید مجوز استفاده از داده‌ها را داشته باشد.
6. **هاست ایران:** افزونه باید روی سروری اجرا شود که به `torob.com` دسترسی دارد.

---

## ۶. نقشه‌ی راه پیشنهادی (فازها)

- **فاز ۱ — هسته:** ساختار افزونه، `Torob_Client` با داده‌های mock قابل‌سوئیچ، صفحه‌ی جستجو با نام، نمایش لیست نتایج.
- **فاز ۲ — پیش‌نمایش:** صفحه‌ی پیش‌نمایش با اطلاعات کامل و جدول specs و امکان ویرایش.
- **فاز ۳ — ساخت محصول:** WC_Product builder، تصاویر، attributes فارسی، وضعیت پیش‌نویس.
- **فاز ۴ — پشتیبانی لینک + تاریخچه:** ورود لینک مستقیم، ذخیره‌ی تاریخچه‌ی importها، جلوگیری از ساخت تکراری (با SKU بر اساس random_key).
- **فاز ۵ (اختیاری):** تولید توضیح با AI، تنظیمات پیشرفته (تاخیر، انتخاب دسته‌ی پیش‌فرض، attributeهای taxonomy/local)، bulk import.

---

## ۷. سؤالات برای تصمیم‌گیری قبل از شروع کدنویسی

برای اینکه فاز ۱ را دقیق شروع کنیم، لطفاً تأیید/انتخاب کنید:

1. **پلتفرم اجرا:** آیا افزونه‌ی وردپرس/ووکامرس (PHP) را تأیید می‌کنید؟ (پیشنهاد ما بله است.)
2. **دسترسی به یک نصب وردپرس برای تست:** آیا محیط وردپرس+ووکامرس در دسترس دارید یا در این سندباکس یک نمونه‌ی تست بالا بیاوریم؟
3. **نوع محصول پیش‌فرض:** محصول ساده (`simple`) یا متغیر (`variable` با variants ترب)؟ پیشنهاد: شروع با simple.
4. **مشخصات فنی:** همه به‌صورت taxonomy (`pa_...`) باشند یا حالت ترکیبی (مهم‌ها taxonomy، بقیه local)؟ پیشنهاد: ترکیبی.
5. **وضعیت انتشار پیش‌فرض:** محصول به‌صورت `draft` ساخته شود تا قبل از انتشار بررسی کنید یا مستقیم `publish`؟ پیشنهاد: draft.
6. **نام/نام تجاری افزونه:** «Shoper – درون‌ریز محصول از ترب» مناسب است یا عنوان دیگری مد نظرتان است؟

---

## ۸. منابع

- [Parse.bot Torob API (مرجع ساختار فیلدها)](https://parse.bot/marketplace/d925cf61-d99f-41ad-9765-d68fc7fcfac1/torob-com-api)
- [Torob-Integration (Python)](https://github.com/hamidrezafarzin/Torob-Integration)
- [torob-bot (Python scraper)](https://github.com/omidima/torob-bot)
- [torob-scraper (Python)](https://github.com/mahdi-marjani/torob-scraper)
- [Digikala WooCommerce Importer (PHP plugin)](https://github.com/alireza-aminzadeh/digikala-woocommerce-importer)
- [MajidAPI Torob docs](https://majidapi.ir/doc/%D8%AA%D8%B1%D8%A8)
- [WooCommerce Code Reference](https://woocommerce.github.io/code-reference/)
- [تابع media_sideload_image](https://developer.wordpress.org/reference/functions/media_sideload_image/)
