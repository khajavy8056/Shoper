/**
 * Shoper — منطق رابط کاربری.
 *
 * @package Shoper
 */
(function ($) {
	'use strict';

	var Shoper = {
		currentData: null,
		selectedPid: null,

		// وضعیت نوار پیشنهاد (autocomplete).
		sugTimer: null,
		sugXhr: null,
		sugItems: [],
		sugIndex: -1,
		sugOpen: false,
		sugLastTerm: '',

		init: function () {
			this.cache();
			if (this.$body.length) {
				this.bind();
				this.buildSuggestBox();
			}
		},

		cache: function () {
			this.$body        = $(document);
			this.$modeToggles = $('input[name="shoper_input_mode"], input[name="shoper_mode"]');
			this.$queryInput  = $('#shoper-query');
			this.$urlInput    = $('#shoper-url');
			this.$queryWrap   = $('#shoper-query-wrap');
			this.$urlWrap     = $('#shoper-url-wrap');
			this.$searchBtn   = $('#shoper-search-btn');
			this.$results     = $('#shoper-results');
			this.$previewCard = $('#shoper-preview-card');
			this.$preview     = $('#shoper-preview');
			this.$createBtn   = $('#shoper-create-btn');
			this.$status      = $('#shoper-status');
			this.$postId      = $('#shoper-post-id');
			this.$testConn    = $('#shoper-test-conn');
			this.$connResult  = $('#shoper-conn-result');
			this.$createStatus= $('#shoper-create-status');
			this.$fillBtn     = $('#shoper-fill-btn');
			this.$fillRow     = $('#shoper-fill-row');
		},

		bind: function () {
			var self = this;

			// تعویض حالت ورودی (نام/لینک).
			this.$modeToggles.on('change', function () {
				var mode = $('input[name="shoper_input_mode"]:checked, input[name="shoper_mode"]:checked').val();
				if (mode === 'url') {
					$('#shoper-query, #shoper-query-wrap').hide();
					$('#shoper-url, #shoper-url-wrap').show();
				} else {
					$('#shoper-query, #shoper-query-wrap').show();
					$('#shoper-url, #shoper-url-wrap').hide();
				}
			});

			// --- نوار پیشنهاد نام محصول (autocomplete) ---
			// کاربر لازم نیست نام کامل را بداند؛ با تایپ بخشی از نام،
			// نام‌های کامل زیر فیلد پیشنهاد می‌شوند.
			this.$queryInput.on('input', function () {
				self.onQueryInput($(this).val());
			});

			// ناوبری با کیبورد در لیست پیشنهاد.
			this.$queryInput.on('keydown', function (e) {
				if (!self.sugOpen) {
					return;
				}
				if (e.which === 40) {          // ↓
					e.preventDefault();
					self.moveSuggest(1);
				} else if (e.which === 38) {   // ↑
					e.preventDefault();
					self.moveSuggest(-1);
				} else if (e.which === 27) {   // Esc
					self.closeSuggest();
				} else if (e.which === 13 && self.sugIndex >= 0) { // Enter روی یک پیشنهاد
					e.preventDefault();
					self.chooseSuggest(self.sugIndex);
				}
			});

			this.$queryInput.on('focus', function () {
				if (self.sugItems.length && $(this).val().trim().length >= 2) {
					self.openSuggest();
				}
			});

			// کلیک روی یک پیشنهاد.
			$(document).on('mousedown', '.shoper-suggest-item', function (e) {
				e.preventDefault();
				self.chooseSuggest($(this).index());
			});

			// بستن با کلیک بیرون.
			$(document).on('click', function (e) {
				if (!$(e.target).closest('.shoper-suggest-wrap, #shoper-query').length) {
					self.closeSuggest();
				}
			});

			// جستجو با Enter.
			this.$queryInput.on('keypress', function (e) {
				if (e.which === 13) {
					e.preventDefault();
					if (self.sugIndex < 0) {
						self.closeSuggest();
						self.search();
					}
				}
			});

			this.$searchBtn.on('click', function (e) {
				e.preventDefault();
				self.search();
			});

			$(document).on('click', '.shoper-result-item', function () {
				var $item = $(this);
				$item.addClass('selected').siblings().removeClass('selected');
				self.preview($item.data('prk'), $item.data('searchid'));
			});

			this.$createBtn.on('click', function (e) {
				e.preventDefault();
				self.create();
			});

			this.$testConn.on('click', function (e) {
				e.preventDefault();
				self.testConnection();
			});

			// پر کردن محصول فعلی (در صفحه ویرایش).
			$(document).on('click', '#shoper-fill-btn', function (e) {
				e.preventDefault();
				self.fillCurrent();
			});
		},

		getMode: function () {
			return $('input[name="shoper_input_mode"]:checked, input[name="shoper_mode"]:checked').val() || 'query';
		},

		/* ------------------------------------------------------------------
		 * نوار پیشنهاد نام محصول (autocomplete)
		 * ------------------------------------------------------------------ */

		/**
		 * ساخت ظرف نوار پیشنهاد، دقیقاً زیر فیلد جستجو.
		 */
		buildSuggestBox: function () {
			if (!this.$queryInput.length) {
				return;
			}
			// ظرف با position:relative تا لیست زیر فیلد بچسبد.
			if (!this.$queryInput.parent().hasClass('shoper-suggest-wrap')) {
				this.$queryInput.wrap('<span class="shoper-suggest-wrap"></span>');
			}
			this.$suggest = $('<div class="shoper-suggest" role="listbox"></div>')
				.appendTo(this.$queryInput.parent())
				.hide();

			this.$queryInput.attr({
				'autocomplete': 'off',
				'role': 'combobox',
				'aria-autocomplete': 'list',
				'aria-expanded': 'false'
			});
		},

		/**
		 * هر بار که کاربر تایپ می‌کند (با debounce).
		 *
		 * @param {string} term عبارت فعلی.
		 */
		onQueryInput: function (term) {
			var self = this;
			term = (term || '').trim();

			if (this.sugTimer) {
				clearTimeout(this.sugTimer);
			}

			// کمتر از ۲ نویسه ارزش درخواست ندارد.
			if (term.length < 2) {
				this.closeSuggest();
				this.sugItems = [];
				return;
			}

			// اگر عبارت تغییری نکرده، دوباره درخواست نده.
			if (term === this.sugLastTerm && this.sugItems.length) {
				this.openSuggest();
				return;
			}

			// ۲۵۰ms صبر تا کاربر دست از تایپ بردارد.
			this.sugTimer = setTimeout(function () {
				self.fetchSuggest(term);
			}, 250);
		},

		/**
		 * گرفتن پیشنهادها از سرور.
		 *
		 * @param {string} term عبارت.
		 */
		fetchSuggest: function (term) {
			var self = this;

			// درخواست قبلی را لغو کن تا نتیجه‌ی قدیمی روی جدید ننشیند.
			if (this.sugXhr && this.sugXhr.readyState !== 4) {
				this.sugXhr.abort();
			}

			this.showSuggestLoading();

			this.sugXhr = $.post(ShoperData.ajaxUrl, {
				action: 'shoper_suggest',
				nonce: ShoperData.nonce,
				term: term
			}).done(function (resp) {
				// اگر کاربر در این فاصله عبارت را عوض کرده، نتیجه را دور بریز.
				if (self.$queryInput.val().trim() !== term) {
					return;
				}
				var list = (resp && resp.success && resp.data && resp.data.suggestions) ? resp.data.suggestions : [];
				self.sugLastTerm = term;
				self.sugItems = list;
				self.sugIndex = -1;
				self.renderSuggest(list, term);
			}).fail(function (xhr, status) {
				if (status !== 'abort') {
					self.closeSuggest();
				}
			});
		},

		showSuggestLoading: function () {
			if (!this.$suggest) {
				return;
			}
			this.$suggest
				.html('<div class="shoper-suggest-loading"><span class="shoper-loading-inline"></span> در حال گرفتن پیشنهاد از ترب…</div>')
				.show();
			this.sugOpen = true;
		},

		/**
		 * رندر لیست پیشنهاد.
		 *
		 * @param {Array}  items لیست.
		 * @param {string} term  عبارت تایپ‌شده (برای هایلایت).
		 */
		renderSuggest: function (items, term) {
			if (!this.$suggest) {
				return;
			}
			if (!items.length) {
				this.$suggest
					.html('<div class="shoper-suggest-empty">پیشنهادی یافت نشد — می‌توانید دکمه‌ی جستجو را بزنید.</div>')
					.show();
				this.sugOpen = true;
				return;
			}

			var html = '';
			for (var i = 0; i < items.length; i++) {
				var it = items[i];
				html += '<div class="shoper-suggest-item" role="option" data-index="' + i + '">';
				if (it.image_url) {
					html += '<img class="shoper-suggest-thumb" src="' + this.esc(it.image_url) + '" alt="" loading="lazy">';
				} else {
					html += '<span class="shoper-suggest-thumb shoper-suggest-thumb-empty"></span>';
				}
				html += '<span class="shoper-suggest-text">';
				html += '<span class="shoper-suggest-name">' + this.highlight(it.label, term) + '</span>';
				var meta = [];
				if (it.name2) { meta.push(this.esc(it.name2)); }
				if (it.shop_text) { meta.push(this.esc(it.shop_text)); }
				if (meta.length) {
					html += '<span class="shoper-suggest-meta">' + meta.join(' • ') + '</span>';
				}
				html += '</span>';
				if (it.price_text) {
					html += '<span class="shoper-suggest-price">' + this.esc(it.price_text) + '</span>';
				}
				html += '</div>';
			}

			this.$suggest.html(html).show();
			this.sugOpen = true;
			this.$queryInput.attr('aria-expanded', 'true');
		},

		/**
		 * هایلایت بخش تایپ‌شده در نام پیشنهادی.
		 *
		 * @param {string} text متن کامل.
		 * @param {string} term عبارت.
		 * @return {string} HTML امن.
		 */
		highlight: function (text, term) {
			var safe = this.esc(text);
			if (!term) {
				return safe;
			}
			var safeTerm = this.esc(term).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
			try {
				return safe.replace(new RegExp('(' + safeTerm + ')', 'gi'), '<mark>$1</mark>');
			} catch (e) {
				return safe;
			}
		},

		/**
		 * جابه‌جایی انتخاب با کلیدهای بالا/پایین.
		 *
		 * @param {number} delta جهت.
		 */
		moveSuggest: function (delta) {
			if (!this.sugItems.length) {
				return;
			}
			this.sugIndex += delta;
			if (this.sugIndex < 0) {
				this.sugIndex = this.sugItems.length - 1;
			}
			if (this.sugIndex >= this.sugItems.length) {
				this.sugIndex = 0;
			}
			var $items = this.$suggest.find('.shoper-suggest-item');
			$items.removeClass('active');
			var $active = $items.eq(this.sugIndex).addClass('active');

			// نام کامل را داخل فیلد بگذار تا کاربر ببیند چه انتخاب می‌کند.
			if (this.sugItems[this.sugIndex]) {
				this.$queryInput.val(this.sugItems[this.sugIndex].label);
			}

			// اسکرول به آیتم فعال.
			if ($active.length) {
				var box = this.$suggest[0];
				var el = $active[0];
				if (el.offsetTop < box.scrollTop) {
					box.scrollTop = el.offsetTop;
				} else if (el.offsetTop + el.offsetHeight > box.scrollTop + box.clientHeight) {
					box.scrollTop = el.offsetTop + el.offsetHeight - box.clientHeight;
				}
			}
		},

		/**
		 * انتخاب یک پیشنهاد: نام کامل در فیلد می‌نشیند و
		 * مستقیماً پیش‌نمایش همان محصول بارگذاری می‌شود.
		 *
		 * @param {number} index اندیس.
		 */
		chooseSuggest: function (index) {
			var it = this.sugItems[index];
			if (!it) {
				return;
			}
			this.$queryInput.val(it.label);
			this.closeSuggest();

			// چون prk را از قبل داریم، مستقیم به پیش‌نمایش می‌رویم.
			if (it.random_key) {
				this.preview(it.random_key, it.search_id || '');
			} else {
				this.search();
			}
		},

		openSuggest: function () {
			if (this.$suggest && this.$suggest.children().length) {
				this.$suggest.show();
				this.sugOpen = true;
				this.$queryInput.attr('aria-expanded', 'true');
			}
		},

		closeSuggest: function () {
			if (this.$suggest) {
				this.$suggest.hide();
			}
			this.sugOpen = false;
			this.sugIndex = -1;
			this.$queryInput.attr('aria-expanded', 'false');
		},

		status: function (msg, type) {
			if (!this.$status.length) {
				this.$status = $('<div id="shoper-status" class="shoper-status"></div>').appendTo('.shoper-metabox, .shoper-card').first();
			}
			var html = '';
			if (type === 'loading') {
				html = '<span class="shoper-loading-inline"></span> ' + msg;
			} else {
				html = msg;
			}
			this.$status.removeClass('loading success error').addClass(type || '').html(html).show();
		},

		clearStatus: function () {
			this.$status.removeClass('loading success error').hide().empty();
		},

		ajax: function (action, data, onSuccess) {
			var self = this;
			data = data || {};
			data.action = action;
			data.nonce  = ShoperData.nonce;

			return $.post(ShoperData.ajaxUrl, data)
				.done(function (resp) {
					if (resp && resp.success) {
						onSuccess(resp.data || {});
					} else {
						var msg = (resp && resp.data && resp.data.message) ? resp.data.message : ShoperData.i18n.error;
						self.status(msg, 'error');
					}
				})
				.fail(function () {
					self.status('خطا در برقراری ارتباط با سرور.', 'error');
				});
		},

		search: function () {
			var self = this;
			var mode = this.getMode();
			var query = this.$queryInput.val().trim();
			var url   = this.$urlInput.val().trim();

			if (mode === 'url') {
				if (!url) {
					this.status('لینک محصول را وارد کنید.', 'error');
					return;
				}
				this.status(ShoperData.i18n.loading, 'loading');
				// برای لینک: ابتدا prk را استخراج و سپس مستقیم prevew می‌کنیم.
				var uuidMatch = url.match(/\/p\/([0-9a-f\-]{36})/i);
				if (uuidMatch) {
					this.preview(uuidMatch[1]);
					return;
				}
				// اگر فرمت URL شناخته‌شده نبود، به‌عنوان query استفاده می‌کنیم.
				query = url;
			}

			if (!query) {
				this.status(ShoperData.i18n.empty_query, 'error');
				return;
			}

			this.$results.empty().hide();
			this.$previewCard.hide();
			this.status(ShoperData.i18n.searching, 'loading');

			this.ajax('shoper_search', { query: query }, function (data) {
				self.clearStatus();
				self.renderResults(data.results || []);
			});
		},

		renderResults: function (items) {
			var html = '';
			if (!items.length) {
				this.$results.html('<p>نتیجه‌ای یافت نشد.</p>').show();
				return;
			}
			for (var i = 0; i < items.length; i++) {
				var it = items[i];
				html += '<div class="shoper-result-item" data-prk="' + this.esc(it.random_key) + '" '
					+ 'data-searchid="' + this.esc(it.search_id) + '">';
				if (it.image_url) {
					html += '<img src="' + this.esc(it.image_url) + '" alt="">';
				}
				html += '<div class="shoper-ri-info">';
				html += '<div class="shoper-ri-name">' + this.esc(it.name1) + '</div>';
				if (it.name2) {
					html += '<div class="shoper-ri-meta">' + this.esc(it.name2) + '</div>';
				}
				var meta = [];
				if (it.shop_text) meta.push(this.esc(it.shop_text));
				if (it.gallery && it.gallery.length) meta.push(it.gallery.length + ' تصویر');
				if (meta.length) {
					html += '<div class="shoper-ri-meta">' + meta.join(' | ') + '</div>';
				}
				html += '</div>';
				if (it.price_text || it.price) {
					var priceText = it.price_text || (this.numberFormat(it.price) + ' تومان');
					html += '<div class="shoper-ri-price">' + this.esc(priceText) + '</div>';
				}
				html += '</div>';
			}
			this.$results.html(html).show();
		},

		preview: function (prk, searchId) {
			var self = this;
			if (!prk) return;
			this.status(ShoperData.i18n.loading, 'loading');
			this.$results.find('.shoper-result-item').removeClass('selected');
			this.$results.find('[data-prk="' + prk + '"]').addClass('selected');

			this.ajax('shoper_preview', { prk: prk, search_id: searchId || '' }, function (data) {
				self.clearStatus();
				self.currentData = data;
				self.selectedPid = prk;
				self.renderPreview(data);
				self.$previewCard.show();
				$('html, body').animate({ scrollTop: self.$previewCard.offset().top - 40 }, 400);
			});
		},

		renderPreview: function (d) {
			var html = '';

			// هدر با تصویر.
			html += '<div class="shoper-preview-header">';
			if (d.image_url) {
				html += '<img src="' + this.esc(d.image_url) + '" alt="">';
			}
			html += '<div>';
			html += '<div class="shoper-field-group">';
			html += '<label>نام محصول</label>';
			html += '<input type="text" id="shoper-p-name" value="' + this.esc(d.name1) + '">';
			html += '</div>';
			if (d.name2) {
				html += '<div class="shoper-field-group"><label>نام انگلیسی</label>';
				html += '<input type="text" value="' + this.esc(d.name2) + '" readonly></div>';
			}
			html += '</div></div>';

			// گالری.
			if (d.gallery && d.gallery.length) {
				html += '<div class="shoper-field-group"><label>تصاویر (' + d.gallery.length + ')</label>';
				html += '<div class="shoper-preview-gallery">';
				for (var i = 0; i < d.gallery.length; i++) {
					html += '<img src="' + this.esc(d.gallery[i]) + '" alt="">';
				}
				html += '</div></div>';
			}

			// قیمت + خلاصه‌ی فروشندگان بررسی‌شده.
			var agg = d.aggregate || {};
			if (d.price) {
				html += '<div class="shoper-field-group"><label>قیمت انتخاب‌شده</label>';
				html += '<input type="text" value="' + this.esc(this.numberFormat(d.price)) + ' تومان" readonly></div>';
			}

			if (agg.considered && agg.considered.length) {
				html += '<div class="shoper-field-group">';
				html += '<label>فروشندگان بررسی‌شده (' + agg.considered.length + ' از ' + (agg.total_sellers || 0) + ')</label>';
				html += '<p class="description" style="margin:0 0 6px;">اطلاعات محصول از میان این چند فروشنده‌ی برتر جمع‌آوری شده است، نه از همه‌ی فروشگاه‌ها.</p>';
				html += '<table class="shoper-sellers-preview"><thead><tr>';
				html += '<th>فروشنده</th><th>شهر</th><th>امتیاز</th><th>قیمت</th>';
				html += '</tr></thead><tbody>';
				for (var s = 0; s < agg.considered.length; s++) {
					var sel = agg.considered[s];
					html += '<tr' + (s === 0 ? ' class="primary"' : '') + '>';
					html += '<td>' + this.esc(sel.shop_name) + (s === 0 ? ' <span class="shoper-badge">منتخب</span>' : '') + '</td>';
					html += '<td>' + this.esc(sel.city) + '</td>';
					html += '<td>' + this.esc(sel.score_text || sel.score) + '</td>';
					html += '<td>' + this.esc(this.numberFormat(sel.price)) + ' تومان</td>';
					html += '</tr>';
				}
				html += '</tbody></table>';

				if (agg.cheapest && agg.highest && agg.highest > agg.cheapest) {
					html += '<p class="description">محدوده‌ی بازار: ' + this.esc(this.numberFormat(agg.cheapest));
					html += ' تا ' + this.esc(this.numberFormat(agg.highest)) + ' تومان</p>';
				}
				if (agg.features && agg.features.length) {
					html += '<p class="description"><strong>ویژگی‌های تجمیع‌شده:</strong> ';
					html += this.esc(agg.features.slice(0, 6).join(' • ')) + '</p>';
				}
				html += '</div>';
			}

			// توضیحات.
			var desc = d.description_html || '';
			html += '<div class="shoper-field-group"><label>توضیحات (قابل ویرایش)</label>';
			html += '<textarea id="shoper-p-desc">' + this.esc(desc) + '</textarea></div>';

			// مشخصات فنی.
			if (d.specs) {
				var keys = Object.keys(d.specs);
				html += '<div class="shoper-field-group">';
				html += '<label>مشخصات فنی — هر کدام به‌صورت یک ویژگی مجزا (' + keys.length + ')</label>';
				html += '<p class="description" style="margin:0 0 6px;">تیک مواردی را که می‌خواهید به‌عنوان ویژگی اضافه شوند بزنید.</p>';
				html += '<div class="shoper-specs-list">';
				for (var k in d.specs) {
					if (!d.specs.hasOwnProperty(k)) continue;
					html += '<div class="shoper-spec-item">';
					html += '<label style="display:flex;align-items:center;width:100%;margin:0;">';
					html += '<input type="checkbox" class="shoper-spec-check" value="' + this.esc(k) + '" checked>';
					html += '<span class="shoper-spec-key">' + this.esc(k) + '</span>';
					html += '<span class="shoper-spec-val">' + this.esc(d.specs[k]) + '</span>';
					html += '</label></div>';
				}
				html += '</div></div>';
			}

			// اطلاعات مخفی.
			html += '<input type="hidden" id="shoper-p-prk" value="' + this.esc(d.random_key) + '">';
			html += '<input type="hidden" id="shoper-p-searchid" value="' + this.esc(d.search_id || '') + '">';

			this.$preview.html(html);

			// در متاباکس ویرایش محصول، دکمه‌ی «پر کردن» را نشان بده.
			if (this.$fillRow.length) {
				this.$fillRow.show();
			}
			// در صفحه‌ی اصلی افزونه، دکمه‌ی ساخت را نشان بده.
			if (this.$createBtn.length) {
				this.$createBtn.show();
			}
		},

		create: function () {
			var self = this;
			var prk = $('#shoper-p-prk').val();
			if (!prk) {
				this.status('ابتدا یک محصول را انتخاب کنید.', 'error');
				return;
			}

			// جمع‌آوری مشخصات انتخاب‌شده.
			var specs = [];
			$('.shoper-spec-check:checked').each(function () {
				specs.push($(this).val());
			});

			var payload = {
				prk: prk,
				search_id: $('#shoper-p-searchid').val(),
				name: $('#shoper-p-name').val(),
				description: $('#shoper-p-desc').val(),
				specs: JSON.stringify(specs),
				status: $('#shoper-create-status').val() || $('select#shoper-create-status').val() || 'draft'
			};

			this.status(ShoperData.i18n.creating, 'loading');
			this.$createBtn.prop('disabled', true);

			this.ajax('shoper_create', payload, function (data) {
				self.$createBtn.prop('disabled', false);
				self.clearStatus();

				var html = '<div class="shoper-success-box">';
				html += '<p><strong>✓ محصول ساخته شد!</strong></p>';
				html += '<p>تعداد ویژگی‌های ثبت‌شده: ' + (data.specs_count || 0) + '<br>';
				html += 'تصاویر دانلودشده: ' + ((data.image_info && data.image_info.gallery_ids) ? data.image_info.gallery_ids.length + 1 : 0) + '</p>';
				if (data.edit_link) {
					html += '<a href="' + self.esc(data.edit_link) + '" class="button button-primary">ویرایش محصول</a>';
				}
				if (data.view_link) {
					html += '<a href="' + self.esc(data.view_link) + '" class="button" target="_blank">مشاهده</a>';
				}
				html += '</div>';
				self.$preview.append(html);
				self.$createBtn.hide();
			}).fail(function () {
				self.$createBtn.prop('disabled', false);
			});
		},

		fillCurrent: function () {
			var self = this;
			var postId = this.$postId.val();
			var prk = $('#shoper-p-prk').val() || this.selectedPid;
			var searchId = $('#shoper-p-searchid').val() || '';

			if (!postId) {
				this.status('برای پر کردن، ابتدا محصول را به‌صورت پیش‌نویس ذخیره کنید.', 'error');
				return;
			}
			if (!prk) {
				this.status('ابتدا یک محصول از نتایج جستجو انتخاب کنید.', 'error');
				return;
			}

			this.status('در حال پر کردن محصول…', 'loading');
			this.$fillBtn.prop('disabled', true);

			this.ajax('shoper_fill', {
				post_id: postId,
				prk: prk,
				search_id: searchId
			}, function (data) {
				self.$fillBtn.prop('disabled', false);
				self.status(data.message || 'پر شد!', 'success');
				// رفرش صفحه برای دیدن تغییرات در ویرایشگر.
				if (data.reload) {
					setTimeout(function () { window.location.reload(); }, 1200);
				}
			}).fail(function () {
				self.$fillBtn.prop('disabled', false);
			});
		},

		testConnection: function () {
			var self = this;
			this.$connResult.html('<span class="shoper-loading-inline"></span> در حال تست...');
			this.ajax('shoper_test_connection', {}, function (data) {
				self.$connResult.html('<span style="color:#00a32a;">✓ ' + self.esc(data.message) + '</span>');
			}).fail(function () {
				self.$connResult.html('<span style="color:#d63638;">✗ خطا</span>');
			});
		},

		esc: function (str) {
			if (str === null || str === undefined) return '';
			return $('<div>').text(String(str)).html();
		},

		numberFormat: function (num) {
			return String(num).replace(/\B(?=(\d{3})+(?!\d))/g, '،');
		}
	};

	$(function () {
		Shoper.init();
	});

	// در دسترس قرار دادن برای اکشن fill در متاباکس.
	window.Shoper = Shoper;

})(jQuery);
