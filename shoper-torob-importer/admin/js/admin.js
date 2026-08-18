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
		pendingItem: null,
		lastResults: [],

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
			this.$diagBtn     = $('#shoper-diagnostics-btn');
			this.$diag        = $('#shoper-diagnostics');
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
				self.preview($item.data('prk'), $item.data('searchid'), $item.data('moreinfo'));
			});

			this.$createBtn.on('click', function (e) {
				e.preventDefault();
				self.create();
			});

			$(document).on('click', '#shoper-next-step', function (e) {
				e.preventDefault();
				self.stepDelta(1);
			});
			$(document).on('click', '#shoper-prev-step', function (e) {
				e.preventDefault();
				self.stepDelta(-1);
			});
			$(document).on('click', '#shoper-ai-rerun', function (e) {
				e.preventDefault();
				if (self.currentData) { self.queueEnhance(self.currentData, true); }
			});
			$(document).on('click', '.shoper-ai-tab', function (e) {
				e.preventDefault();
				var tab = $(this).data('tab');
				self.$preview.find('.shoper-ai-tab').removeClass('is-active');
				$(this).addClass('is-active');
				self.$preview.find('.shoper-ai-pane').hide();
				self.$preview.find('.shoper-ai-pane[data-pane="' + tab + '"]').show();
			});

			this.$testConn.on('click', function (e) {
				e.preventDefault();
				self.testConnection();
			});

			this.$diagBtn.on('click', function (e) {
				e.preventDefault();
				self.diagnostics();
			});

			$(document).on('click', '#shoper-download-relay', function (e) {
				e.preventDefault();
				self.downloadRelay();
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

		cfg: function (key, fallback) {
			if (typeof ShoperData !== 'undefined' && ShoperData && ShoperData[key] !== undefined && ShoperData[key] !== null && ShoperData[key] !== '') {
				return ShoperData[key];
			}
			return fallback;
		},

		fetchMode: function () {
			return this.cfg('fetchMode', 'auto');
		},

		isDkp: function (value) {
			return /^(DKP-)?\d{4,}$/i.test(String(value || '')) || /dkp-\d+/i.test(String(value || ''));
		},

		extractDkp: function (value) {
			var m = String(value || '').match(/dkp-(\d+)/i) || String(value || '').match(/^(\d{4,})$/);
			return m ? m[1] : '';
		},

		dkSearchUrl: function (query) {
			var base = this.cfg('dkApiBase', 'https://api.digikala.com');
			return base + '/v1/search/?q=' + encodeURIComponent(query) + '&page=1';
		},

		dkDetailsUrl: function (id) {
			var base = this.cfg('dkApiBase', 'https://api.digikala.com');
			return base + '/v2/product/' + encodeURIComponent(id) + '/';
		},

		torobSearchUrl: function (query, size) {
			var base = this.cfg('apiBase', 'https://api.torob.com');
			var path = this.cfg('searchPath', '/v4/base-product/search/');
			return base + path + '?page=0&size=' + (size || 10) + '&q=' + encodeURIComponent(query) + '&source=next_desktop';
		},

		torobDetailsUrl: function (prk, moreInfo) {
			if (moreInfo && /torob\.(com|ir)/i.test(String(moreInfo))) {
				return moreInfo;
			}
			var base = this.cfg('apiBase', 'https://api.torob.com');
			var path = this.cfg('detailsPath', '/v4/base-product/details/');
			return base + path + '?prk=' + encodeURIComponent(prk) + '&source=next_desktop';
		},

		wrapRelay: function (url) {
			var relay = this.cfg('relayUrl', '');
			if (!relay) {
				return url;
			}
			return relay + (relay.indexOf('?') >= 0 ? '&' : '?') + 'url=' + encodeURIComponent(url);
		},

		wrapGateway: function (gateway, url) {
			if (!gateway) {
				return '';
			}
			if (gateway.style === 'template' && gateway.template) {
				return String(gateway.template).replace('{url}', encodeURIComponent(url));
			}
			var base = gateway.base ? String(gateway.base).replace(/\/+$/, '') : '';
			if (!base) {
				return '';
			}
			if (gateway.style === 'query') {
				var param = gateway.param || 'url';
				return base + (base.indexOf('?') >= 0 ? '&' : '?') + param + '=' + encodeURIComponent(url);
			}
			return base + '/' + url;
		},

		activeGateways: function () {
			var list = this.cfg('gateways', []);
			return Array.isArray(list) ? list : [];
		},

		/**
		 * یک GET و خواندن JSON. در خطا null.
		 */
		fetchOne: function (target, timeoutMs) {
			var dfd = $.Deferred();
			if (!window.fetch || !target) {
				return dfd.resolve(null).promise();
			}
			var ctrl = window.AbortController ? new AbortController() : null;
			var timer = setTimeout(function () {
				if (ctrl) { ctrl.abort(); }
			}, timeoutMs || 4000);
			fetch(target, {
				method: 'GET',
				mode: 'cors',
				credentials: 'omit',
				headers: { 'Accept': 'application/json, text/plain, */*' },
				signal: ctrl ? ctrl.signal : undefined
			}).then(function (res) {
				clearTimeout(timer);
				if (!res.ok) {
					dfd.resolve(null);
					return null;
				}
				return res.json();
			}).then(function (json) {
				if (dfd.state() !== 'pending') {
					return;
				}
				if (json && typeof json === 'object' && !json.error && !json.corsfix_error) {
					dfd.resolve(json);
				} else {
					dfd.resolve(null);
				}
			}).catch(function () {
				clearTimeout(timer);
				if (dfd.state() === 'pending') {
					dfd.resolve(null);
				}
			});
			return dfd.promise();
		},

		/**
		 * دریافت JSON از مرورگر: مستقیم، رله، بعد درگاه‌های تست‌شده.
		 */
		browserFetch: function (url) {
			var self = this;
			var mode = this.fetchMode();
			if (mode === 'server') {
				return $.Deferred().resolve(null).promise();
			}
			if (!window.fetch) {
				return $.Deferred().resolve(null).promise();
			}

			var targets = [];
			if (mode === 'relay' || this.cfg('relayUrl', '')) {
				targets.push(this.wrapRelay(url));
			}
			if (mode !== 'relay') {
				targets.push(url);
				this.activeGateways().forEach(function (g) {
					var wrapped = self.wrapGateway(g, url);
					if (wrapped && targets.indexOf(wrapped) < 0) {
						targets.push(wrapped);
					}
				});
			}

			var dfd = $.Deferred();
			var i = 0;
			var next = function () {
				if (i >= targets.length) {
					dfd.resolve(null);
					return;
				}
				var target = targets[i++];
				var wait = (i === 1) ? 2500 : 5000;
				self.fetchOne(target, wait).done(function (json) {
					if (json && (json.results || json.random_key || json.name1 || (json.data && (json.data.products || json.data.product)))) {
						dfd.resolve(json);
					} else {
						next();
					}
				});
			};
			next();
			return dfd.promise();
		},

		ingest: function (kind, raw, onSuccess, onError) {
			return this.ajax('shoper_ingest', {
				kind: kind,
				raw: JSON.stringify(raw)
			}, onSuccess, onError);
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
		applySuggestList: function (term, list) {
			if (this.$queryInput.val().trim() !== term) {
				return;
			}
			this.sugLastTerm = term;
			this.sugItems = list || [];
			this.sugIndex = -1;
			this.renderSuggest(this.sugItems, term);
		},

		fetchSuggest: function (term) {
			var self = this;

			// درخواست قبلی را لغو کن تا نتیجه‌ی قدیمی روی جدید ننشیند.
			if (this.sugXhr && this.sugXhr.readyState !== 4) {
				this.sugXhr.abort();
			}

			this.showSuggestLoading();

			var runServer = function () {
				self.sugXhr = $.post(ShoperData.ajaxUrl, {
					action: 'shoper_suggest',
					nonce: ShoperData.nonce,
					term: term
				}).done(function (resp) {
					var list = (resp && resp.success && resp.data && resp.data.suggestions) ? resp.data.suggestions : [];
					if (!list.length && resp && resp.data && resp.data.error && self.$suggest) {
						var msg = resp.data.message || 'اتصال به ترب برقرار نشد.';
						self.$suggest.html('<div class="shoper-suggest-empty">' + self.esc(msg) + '</div>').show();
						self.sugOpen = true;
						return;
					}
					self.applySuggestList(term, list);
				}).fail(function (xhr, status) {
					if (status !== 'abort') {
						self.closeSuggest();
					}
				});
			};

			this.browserFetch(this.dkSearchUrl(term)).done(function (raw) {
				if (raw && raw.data) {
					self.ingest('dk_search', raw, function (data) {
						var list = [];
						var seen = {};
						(data.results || []).forEach(function (item) {
							if (!item.name1 || seen[item.name1]) { return; }
							seen[item.name1] = true;
							list.push({
								label: item.name1,
								name2: item.name2 || '',
								random_key: item.random_key || '',
								search_id: '',
								image_url: item.image_url || '',
								price: item.price || 0,
								price_text: item.price_text || '',
								shop_text: item.shop_text || 'دیجی‌کالا',
								more_info_url: '',
								gallery: item.gallery || [],
								page_url: item.page_url || '',
								provider: 'digikala'
							});
						});
						if (list.length) {
							self.lastResults = data.results || [];
							self.applySuggestList(term, list.slice(0, 8));
							return;
						}
						runServer();
					}, function () { runServer(); });
					return;
				}
				if (!raw || !raw.results) {
					runServer();
					return;
				}
				self.ingest('search', raw, function (data) {
					var list = [];
					var seen = {};
					(data.results || []).forEach(function (item) {
						if (item.is_adv || !item.name1 || seen[item.name1]) { return; }
						seen[item.name1] = true;
						list.push({
							label: item.name1,
							name2: item.name2 || '',
							random_key: item.random_key || '',
							search_id: item.search_id || '',
							image_url: item.image_url || '',
							price: item.price || 0,
							price_text: item.price_text || '',
							shop_text: item.shop_text || '',
							more_info_url: item.more_info_url || '',
							gallery: item.gallery || [],
							page_url: item.page_url || ''
						});
					});
					if (list.length) {
						self.lastResults = data.results || [];
						self.applySuggestList(term, list.slice(0, 8));
					} else {
						runServer();
					}
				}, function () {
					runServer();
				});
			});
		},

		showSuggestLoading: function () {
			if (!this.$suggest) {
				return;
			}
			this.$suggest
				.html('<div class="shoper-suggest-loading"><span class="shoper-loading-inline"></span> در حال گرفتن پیشنهاد…</div>')
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
			this.pendingItem = it;

			// چون prk را از قبل داریم، مستقیم به پیش‌نمایش می‌رویم.
			if (it.random_key) {
				this.preview(it.random_key, it.search_id || '', it.more_info_url || '', it);
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
				// هر پیامی که از سرور/API می‌آید ممکن است کنترل‌نشده باشد؛
				// آن را escape می‌کنیم تا جلو XSS در پنل مدیریت گرفته شود.
				html = this.esc(msg);
			}
			this.$status.removeClass('loading success error').addClass(type || '').html(html).show();
		},

		clearStatus: function () {
			this.$status.removeClass('loading success error').hide().empty();
		},

		/**
		 * درخواست AJAX یکپارچه با پیام خطای دقیق.
		 *
		 * @param {string}   action    نام اکشن.
		 * @param {Object}   data      داده‌ها.
		 * @param {Function} onSuccess موفق.
		 * @param {Function} onError   خطا (اختیاری) — { code, message, status }.
		 * @return {jqXHR}
		 */
		ajax: function (action, data, onSuccess, onError) {
			var self = this;
			data = data || {};
			data.action = action;
			data.nonce  = ShoperData.nonce;

			return $.post(ShoperData.ajaxUrl, data)
				.done(function (resp) {
					if (resp && resp.success) {
						onSuccess(resp.data || {});
					} else {
						var info = self.describeError(resp);
						if (onError) {
							onError(info);
						} else {
							self.status(info.message, 'error');
						}
					}
				})
				.fail(function (xhr, status) {
					var info = self.describeFail(xhr, status);
					if (onError) {
						onError(info);
					} else {
						self.status(info.message, 'error');
					}
				});
		},

		/**
		 * تحلیل پاسخ خطای JSON برگشتی از PHP.
		 *
		 * @param {Object} resp پاسخ.
		 * @return {Object} { code, message, status }
		 */
		describeError: function (resp) {
			var data = (resp && resp.data) ? resp.data : {};
			var code = data.code || 'error';
			return {
				code: code,
				message: data.message || this.errorText(code),
				status: data.status || 0
			};
		},

		/**
		 * تحلیل خطای سطح شبکه/HTTP (fail).
		 *
		 * @param {jqXHR}  xhr    درخواست.
		 * @param {string} status وضعیت jQuery.
		 * @return {Object} { code, message }
		 */
		describeFail: function (xhr, status) {
			if (status === 'timeout') {
				return { code: 'timeout', message: 'زمان پاسخ سرور به پایان رسید؛ دوباره تلاش کنید.' };
			}

			var http = xhr.status || 0;
			var parsed = null;
			try {
				parsed = JSON.parse(xhr.responseText);
			} catch (e) {
				parsed = null;
			}

			if (parsed && parsed.data) {
				return {
					code: parsed.data.code || ('http_' + http),
					message: parsed.data.message || this.errorText(parsed.data.code),
					status: http
				};
			}

			if (http === 0) {
				return { code: 'network', message: 'ارتباط با سرور برقرار نشد. اتصال اینترنت یا سرور را بررسی کنید.' };
			}
			if (http === 403) {
				return { code: 'forbidden', message: 'دسترسی غیرمجاز یا نشانه‌ی امنیتی نامعتبر؛ صفحه را تازه‌سازی کنید.' };
			}
			return { code: 'http_' + http, message: 'پاسخ نامعتبر از سرور دریافت شد (کد ' + http + ').' };
		},

		/**
		 * پیام فارسی پیش‌فرض هر کد خطا (اگر سرور پیام نداد).
		 *
		 * @param {string} code کد خطا.
		 * @return {string}
		 */
		errorText: function (code) {
			var map = {
				'forbidden': 'دسترسی غیرمجاز برای انجام این عملیات.',
				'nonce_failed': 'نشانه‌ی امنیتی منقضی شده است؛ صفحه را تازه‌سازی کنید.',
				'empty_query': 'نام محصول را وارد کنید.',
				'invalid_prk': 'شناسه محصول نامعتبر است.',
				'invalid_url': 'لینک محصول ترب معتبر نیست.',
				'rate_limited': 'ترب تعداد درخواست‌ها را محدود کرده است؛ کمی بعد دوباره تلاش کنید.',
				'blocked': 'ترب این درخواست را مسدود کرده است (کد 403/490).',
				'torob_blocked': 'ترب این درخواست را مسدود کرده است.',
				'connection_failed': 'اتصال به ترب برقرار نشد.',
				'curl_failed': 'خطا در برقراری اتصال (cURL).',
				'curl_unavailable': 'کتابخانه‌ی cURL روی سرور فعال نیست.',
				'http_error': 'پاسخ غیرمنتظره از ترب دریافت شد.',
				'invalid_json': 'پاسخ دریافتی از ترب قابل پردازش نیست.',
				'invalid_response': 'ساختار پاسخ ترب تغییر کرده است.',
				'torob_error': 'خطای ترب.',
				'network': 'ارتباط با سرور برقرار نشد.',
				'timeout': 'زمان پاسخ سرور به پایان رسید.'
			};
			return map[code] || 'خطا در انجام عملیات.';
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
				var dkp = this.extractDkp(url);
				if (dkp) {
					this.preview('DKP-' + dkp);
					return;
				}
				var uuidMatch = url.match(/\/p\/([0-9a-f\-]{36})/i);
				if (uuidMatch) {
					this.preview(uuidMatch[1]);
					return;
				}
				query = url;
			}

			if (!query) {
				this.status(ShoperData.i18n.empty_query, 'error');
				return;
			}

			this.$results.empty().hide();
			this.$previewCard.hide();
			this.status(ShoperData.i18n.searching, 'loading');

			var showSearch = function (data) {
				self.clearStatus();
				self.lastResults = data.results || [];
				self.renderResults(self.lastResults);
			};

			var searchFail = function (info) {
				var msg = (info && info.message) ? info.message : 'جستجو ناموفق بود.';
				if (info && (info.code === 'blocked' || info.status === 490 || info.status === 403)) {
					msg += ' مسیر مستقیم مسدود است. درگاه پیش‌فرض باید لیست را بیاورد؛ اگر نیامد اتصال هاست به درگاه را در عیب‌یابی ببینید.';
				}
				self.status(msg, 'error');
			};
			this.browserFetch(this.dkSearchUrl(query)).done(function (raw) {
				if (raw && raw.data) {
					self.ingest('dk_search', raw, showSearch, function () {
						self.ajax('shoper_search', { query: query }, showSearch, searchFail);
					});
					return;
				}
				if (raw && raw.results) {
					self.ingest('search', raw, showSearch, function () {
						self.ajax('shoper_search', { query: query }, showSearch, searchFail);
					});
					return;
				}
				self.ajax('shoper_search', { query: query }, showSearch, searchFail);
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
					+ 'data-searchid="' + this.esc(it.search_id) + '" '
					+ 'data-moreinfo="' + this.esc(it.more_info_url) + '">';
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

		preview: function (prk, searchId, moreInfo, fallbackItem) {
			var self = this;
			if (!prk) return;
			this.status(ShoperData.i18n.loading, 'loading');
			this.$results.find('.shoper-result-item').removeClass('selected');
			this.$results.find('[data-prk="' + prk + '"]').addClass('selected');

			if (!fallbackItem) {
				fallbackItem = this.pendingItem && this.pendingItem.random_key === prk ? this.pendingItem : null;
				if (!fallbackItem && this.lastResults && this.lastResults.length) {
					for (var i = 0; i < this.lastResults.length; i++) {
						if (this.lastResults[i].random_key === prk) {
							fallbackItem = this.lastResults[i];
							break;
						}
					}
				}
			}

			var showPreview = function (data) {
				self.clearStatus();
				self.currentData = data;
				self.selectedPid = prk;
				self.renderPreview(data);
				self.$previewCard.show();
				if (self.$previewCard.offset()) {
					$('html, body').animate({ scrollTop: self.$previewCard.offset().top - 40 }, 400);
				}
			};

			var fromFallback = function (err) {
				if (fallbackItem) {
					self.ingest('search_item', fallbackItem, function (data) {
						data._source = data._source || 'partial';
						showPreview(data);
					}, function () {
						self.status((err && err.message) ? err.message : 'جزئیات محصول دریافت نشد.', 'error');
					});
					return;
				}
				self.status((err && err.message) ? err.message : 'جزئیات محصول دریافت نشد.', 'error');
			};

			var runServerPreview = function () {
				self.ajax('shoper_preview', {
					prk: prk,
					search_id: searchId || '',
					more_info_url: moreInfo || ''
				}, showPreview, fromFallback);
			};

			if (self.isDkp(prk)) {
				var dkp = self.extractDkp(prk);
				self.browserFetch(self.dkDetailsUrl(dkp)).done(function (raw) {
					if (raw && raw.data && raw.data.product) {
						self.ingest('dk_details', raw, showPreview, runServerPreview);
						return;
					}
					runServerPreview();
				});
				return;
			}

			this.browserFetch(this.torobDetailsUrl(prk, moreInfo)).done(function (raw) {
				if (raw && (raw.random_key || raw.name1)) {
					self.ingest('details', raw, showPreview, runServerPreview);
					return;
				}
				runServerPreview();
			});
		},

		renderPreview: function (d) {
			var self = this;
			var html = '';
			// نوار مراحل/گرید تصویر/سئو هم در صفحه‌ی اصلی افزونه (create) هم در
			// متاباکس صفحه‌ی افزودن/ویرایش محصول (fill) نمایش داده می‌شود.
			var isMain = !!(this.$createBtn.length || this.$fillRow.length);

			// --- نوار مراحل (فقط در صفحه‌ی اصلی) ---
			if (isMain) {
				html += '<div class="shoper-stepper">';
				html += '<div class="shoper-step is-active" data-step="info"><span class="shoper-step-num">۱</span> دریافت اطلاعات</div>';
				html += '<div class="shoper-step" data-step="images"><span class="shoper-step-num">۲</span> انتخاب تصاویر</div>';
				html += '<div class="shoper-step" data-step="ai"><span class="shoper-step-num">۳</span> بازنویسی هوشمند</div>';
				html += '<div class="shoper-step" data-step="review"><span class="shoper-step-num">۴</span> نظارت و سئو</div>';
				html += '</div>';
			}

			// --- بخش ۱: اطلاعات ---
			html += '<div class="shoper-step-body" data-step-body="info">';
			if (d.partial) {
				html += '<div class="notice notice-warning" style="margin:0 0 12px;"><p>جزئیات کامل ترب در دسترس نبود (احتمالاً مسدودسازی ۴۹۰). پیش‌نمایش از نتیجهٔ جستجو ساخته شد؛ نام، قیمت و تصاویر وارد می‌شوند. مشخصات فنی ممکن است خالی باشد.</p></div>';
			} else if (d._source === 'browser') {
				html += '<p class="description" style="margin:0 0 10px;">داده از مرورگر شما دریافت شد — مناسب هاست خارج از ایران.</p>';
			}
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
			html += '</div>'; // end info

			// --- بخش ۲: تصاویر (انتخاب، تصویر اصلی، نام محصول+شماره) ---
			if (isMain && d.gallery && d.gallery.length) {
				html += '<div class="shoper-step-body" data-step-body="images">';
				html += '<div class="shoper-field-group"><label>انتخاب تصاویر (' + d.gallery.length + ')</label>';
				html += '<p class="description" style="margin:0 0 8px;">برای هر تصویر تیک <strong>«نگه‌داشته شود»</strong> را بزنید و یک تصویر را با دکمه‌ی <strong>«تصویر اصلی»</strong> مشخص کنید. تصاویر در کتابخانه‌ی رسانه با نام <strong>نام محصول + شماره</strong> ذخیره می‌شوند (مثل «' + this.esc(this.fileBase(d.name1)) + '-1.webp»).</p>';
				html += '<div class="shoper-img-grid">';
				for (var g = 0; g < d.gallery.length; g++) {
					html += '<div class="shoper-img-item" data-idx="' + g + '">';
					html += '<div class="shoper-img-thumb"><img src="' + this.esc(d.gallery[g]) + '" alt=""></div>';
					html += '<div class="shoper-img-controls">';
					html += '<label class="shoper-img-keep"><input type="checkbox" class="shoper-img-check" data-idx="' + g + '" checked> نگه‌داشته شود</label>';
					html += '<label class="shoper-img-main"><input type="radio" name="shoper-featured" class="shoper-img-featured" data-idx="' + g + '"' + (g === 0 ? ' checked' : '') + '> تصویر اصلی</label>';
					html += '</div></div>';
				}
				html += '</div></div>';
				html += '</div>';
			}

			// --- بخش ۳: بازنویسی هوشمند ---
			if (isMain) {
				html += '<div class="shoper-step-body" data-step-body="ai">';
				html += '<div id="shoper-ai-status" class="shoper-ai-status">پس از دریافت تصاویر، متن کارشناسی آماده می‌شود.</div>';
				html += '<div class="shoper-ai-tabs">';
				html += '<button type="button" class="shoper-ai-tab is-active" data-tab="analysis">تحلیل کارشناسی</button>';
				html += '<button type="button" class="shoper-ai-tab" data-tab="review">بررسی</button>';
				html += '<button type="button" class="shoper-ai-tab" data-tab="audience">مخاطب</button>';
				html += '</div>';
				html += '<div class="shoper-ai-pane" data-pane="analysis"><textarea id="shoper-p-analysis" rows="8"></textarea></div>';
				html += '<div class="shoper-ai-pane" data-pane="review" style="display:none;"><textarea id="shoper-p-review" rows="8"></textarea></div>';
				html += '<div class="shoper-ai-pane" data-pane="audience" style="display:none;"><textarea id="shoper-p-audience" rows="5"></textarea></div>';
				html += '<p class="description">این متن از مشخصات واقعی ساخته می‌شود؛ نظر جعلی مشتری نوشته نمی‌شود. شما ناظرید و می‌توانید هر بخش را ویرایش کنید.</p>';
				html += '<p><button type="button" class="button" id="shoper-ai-rerun">بازنویسی دوباره (سرویس بعدی)</button></p>';
				html += '</div>';

				var seo = this.buildSeo(d);
				html += '<div class="shoper-step-body" data-step-body="review">';
				html += '<div class="shoper-supervisor">';
				html += '<strong>میز نظارت خواجوی</strong> — قبل از ساخت، عنوان، توضیحات کامل و سئو را تأیید کنید.';
				html += '</div>';
				html += '<div class="shoper-field-group"><label>توضیح کوتاه</label>';
				html += '<textarea id="shoper-p-short">' + this.esc(d.short_description || '') + '</textarea></div>';
				html += '<div class="shoper-field-group"><label>عنوان سئو (Meta Title)</label>';
				html += '<input type="text" id="shoper-p-seo-title" value="' + this.esc(seo.title) + '"></div>';
				html += '<div class="shoper-field-group"><label>توضیح متا (Meta Description)</label>';
				html += '<textarea id="shoper-p-seo-desc">' + this.esc(seo.description) + '</textarea></div>';
				html += '<div class="shoper-field-group"><label>کلمه کلیدی اصلی</label>';
				html += '<input type="text" id="shoper-p-focus" value="' + this.esc(seo.title) + '"></div>';
				html += '<div class="shoper-field-group"><label>برچسب‌ها (جدا با ویرگول)</label>';
				html += '<input type="text" id="shoper-p-tags" value="' + this.esc(seo.tags.join('، ')) + '"></div>';
				html += '<p class="description">اگر Yoast یا Rank Math نصب باشد، عنوان، توضیح متا و کلمه کلیدی همان‌جا هم نوشته می‌شود.</p>';
				html += '</div>';

				// نوار پیشرفت.
				html += '<div id="shoper-progress" class="shoper-progress" style="display:none;">';
				html += '<div class="shoper-progress-track"><div class="shoper-progress-bar" style="width:0%"></div></div>';
				html += '<div class="shoper-progress-label">آماده‌ی ساخت…</div>';
				html += '</div>';
			}

			// اطلاعات مخفی.
			html += '<input type="hidden" id="shoper-p-prk" value="' + this.esc(d.random_key) + '">';
			html += '<input type="hidden" id="shoper-p-searchid" value="' + this.esc(d.search_id || '') + '">';
			html += '<input type="hidden" id="shoper-p-moreinfo" value="' + this.esc(d.more_info_url || '') + '">';

			this.$preview.html(html);

			// اتصال نوار مراحل و شمارنده‌ها.
			if (isMain) {
				this.bindSteps();
				this.updateImgCount();
				this.$preview.on('change', '.shoper-img-check, .shoper-img-featured', function () {
					self.updateImgCount();
				});
				this.goStep('info');
				if (this.cfg('aiAuto', 'yes') !== 'no' && this.cfg('aiEnabled', 'yes') !== 'no') {
					this.queueEnhance(d, false);
				}
			}

			// در متاباکس ویرایش محصول، دکمه‌ی «پر کردن» را نشان بده.
			if (this.$fillRow.length) {
				this.$fillRow.show();
			}
			// در صفحه‌ی اصلی افزونه، دکمه‌ی ساخت را نشان بده.
			if (this.$createBtn.length) {
				this.$createBtn.show();
			}
		},

		/* ------------------------------------------------------------------
		 * نوار مراحل (stepper) + نوار پیشرفت + انتخاب تصاویر + سئو
		 * ------------------------------------------------------------------ */

		/**
		 * اتصال کلیک روی مراحل.
		 */
		bindSteps: function () {
			var self = this;
			this.$preview.off('click.steps').on('click.steps', '.shoper-step', function () {
				self.goStep($(this).data('step'));
			});
		},

		/**
		 * رفتن به یک مرحله و به‌روزرسانی وضعیت نوار.
		 *
		 * @param {string} name info | images | seo
		 */
		goStep: function (name) {
			var order = ['info', 'images', 'ai', 'review'];
			var idx = order.indexOf(name);
			if (idx < 0) idx = 0;
			this.$preview.find('.shoper-step').removeClass('is-active is-done');
			for (var i = 0; i < order.length; i++) {
				if (i === idx) {
					this.$preview.find('.shoper-step[data-step="' + order[i] + '"]').addClass('is-active');
				} else if (i < idx) {
					this.$preview.find('.shoper-step[data-step="' + order[i] + '"]').addClass('is-done');
				}
			}
			this.$preview.find('.shoper-step-body').hide().filter('[data-step-body="' + name + '"]').show();
		},

		/**
		 * به‌روزرسانی شمارنده‌ی تصاویر انتخاب‌شده.
		 */
		updateImgCount: function () {
			var kept = this.$preview.find('.shoper-img-check:checked').length;
			var total = this.$preview.find('.shoper-img-check').length;
			var $step = this.$preview.find('.shoper-step[data-step="images"]');
			$step.find('.shoper-step-count').remove();
			$step.append('<span class="shoper-step-count">' + kept + '/' + total + '</span>');
		},

		/**
		 * جمع‌آوری انتخاب تصاویر: ایندکس‌های نگه‌داشته‌شده + ایندکس تصویر اصلی.
		 *
		 * @return {Object}
		 */
		collectImgSelection: function () {
			var kept = [];
			var featured = 0;
			this.$preview.find('.shoper-img-check:checked').each(function () {
				kept.push(parseInt($(this).data('idx'), 10));
			});
			var $f = this.$preview.find('.shoper-img-featured:checked').first();
			if ($f.length) {
				featured = parseInt($f.data('idx'), 10);
			}
			return { selected: kept, featured: featured };
		},

		/**
		 * ساخت اطلاعات سئو (آینه‌ی Shoper_Product_Builder::build_seo).
		 *
		 * @param {Object} d داده‌ی محصول.
		 * @return {Object}
		 */
		buildSeo: function (d) {
			var title = d.name1 || '';
			var parts = [];
			if (d.name2) { parts.push(d.name2); }
			var ks = d.key_specs || {};
			var i = 0;
			for (var k in ks) {
				if (!ks.hasOwnProperty(k)) continue;
				if (i++ >= 5) break;
				parts.push(k + ': ' + ks[k]);
			}
			var desc = parts.join(' | ');
			if (desc.length > 155) { desc = desc.slice(0, 152) + '…'; }

			var tags = [];
			var seen = {};
			var cands = [];
			if (d.name1) { cands.push(d.name1); }
			if (d.name2) { cands.push(d.name2); }
			if (d.specs) {
				['برند', 'مدل', 'سازنده'].forEach(function (key) {
					if (d.specs[key]) { cands.push(d.specs[key]); }
				});
			}
			cands.forEach(function (c) {
				String(c).split(/[|\/،,]+/).forEach(function (t) {
					t = t.trim();
					if (t && !seen[t]) { seen[t] = true; tags.push(t); }
				});
			});
			if (tags.length > 12) { tags = tags.slice(0, 12); }

			return { title: title, description: desc, tags: tags };
		},

		/**
		 * نام پایه برای فایل تصویر (آینه‌ی base_filename).
		 *
		 * @param {string} title نام محصول.
		 * @return {string}
		 */
		fileBase: function (title) {
			// آینه‌ی sanitize_file_name وردپرس + base_filename در PHP.
			var base = String(title || '')
				.replace(/[?\[\]\/\\=<>:;,"'&$#*()~`!{}%+|]/g, '') // کاراکترهای غیرمجاز
				.replace(/\s+/g, '-')                               // فاصله → خط تیره
				.replace(/-+/g, '-')                                // حذف تیره‌های تکراری
				.replace(/^-+|-+$/g, '');
			base = base.slice(0, 80);
			return base || 'shoper-product';
		},

		/**
		 * به‌روزرسانی نوار پیشرفت.
		 *
		 * @param {string} label برچسب مرحله.
		 */
		showProgress: function (label) {
			var $p = this.$preview.find('#shoper-progress');
			if (!$p.length) { return; }
			$p.show();
			var $bar = $p.find('.shoper-progress-bar');
			var $lbl = $p.find('.shoper-progress-label');
			if (label === 'دریافت اطلاعات') { $bar.css('width', '40%'); }
			else if (label === 'دانلود تصاویر') { $bar.css('width', '75%'); }
			else if (label === 'سئو و برچسب') { $bar.css('width', '92%'); }
			else { $bar.css('width', '100%'); }
			$lbl.text(label);
		},

		create: function () {
			var self = this;
			var prk = $('#shoper-p-prk').val();
			if (!prk) {
				this.status('ابتدا یک محصول را انتخاب کنید.', 'error');
				return;
			}

			// مشخصات فنی انتخاب‌شده.
			var specs = [];
			$('.shoper-spec-check:checked').each(function () {
				specs.push($(this).val());
			});

			// انتخاب تصاویر.
			var imgSel = { selected: [], featured: 0 };
			if (typeof this.collectImgSelection === 'function') {
				imgSel = this.collectImgSelection();
			}

			// سئو.
			var seoTitle = $('#shoper-p-seo-title').val();
			var seoDesc  = $('#shoper-p-seo-desc').val();
			var tags     = $('#shoper-p-tags').val().split(/[،,]/).map(function (t) { return t.trim(); }).filter(Boolean);

			var payload = {
				prk: prk,
				search_id: $('#shoper-p-searchid').val(),
				more_info_url: $('#shoper-p-moreinfo').val(),
				name: $('#shoper-p-name').val(),
				description: $('#shoper-p-desc').val(),
				short_description: $('#shoper-p-short').val() || '',
				focus_keyword: $('#shoper-p-focus').val() || '',
				specs: JSON.stringify(specs),
				status: $('#shoper-create-status').val() || $('select#shoper-create-status').val() || 'draft',
				selected_images: JSON.stringify(imgSel.selected || []),
				featured_image: imgSel.featured || 0,
				seo_title: seoTitle,
				seo_desc: seoDesc,
				tags: JSON.stringify(tags),
				product_json: this.currentData ? JSON.stringify(this.currentData) : ''
			};

			this.status(ShoperData.i18n.creating, 'loading');
			this.$createBtn.prop('disabled', true);

			this.showProgress('دریافت اطلاعات');

			this.ajax('shoper_create', payload, function (data) {
				self.$createBtn.prop('disabled', false);
				self.clearStatus();

				self.showProgress('دانلود تصاویر');
				setTimeout(function () { self.showProgress('سئو و برچسب'); }, 250);
				setTimeout(function () { self.showProgress('انجام شد!'); }, 500);

				var html = '<div class="shoper-success-box">';
				html += '<p><strong>✓ محصول ساخته شد!</strong></p>';
				html += '<p>تعداد ویژگی‌های ثبت‌شده: ' + (data.specs_count || 0) + '<br>';
				html += 'تصاویر دانلودشده: ' + ((data.image_info && data.image_info.gallery_ids) ? data.image_info.gallery_ids.length + 1 : 0) + '<br>';
				if (data.filenames && data.filenames.length) {
					html += 'نام فایل تصاویر: <code>' + self.esc(data.filenames.join('، ')) + '</code><br>';
				}
				if (data.seo && data.seo.tags && data.seo.tags.length) {
					html += 'برچسب‌ها: <code>' + self.esc(data.seo.tags.join('، ')) + '</code>';
				}
				html += '</p>';
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
				self.$preview.find('#shoper-progress').hide();
			});
		},

		fillCurrent: function () {
			var self = this;
			var postId = this.$postId.val();
			var prk = $('#shoper-p-prk').val() || this.selectedPid;
			var searchId = $('#shoper-p-searchid').val() || '';
			var moreInfo = $('#shoper-p-moreinfo').val() || '';

			if (!postId) {
				this.status('برای پر کردن، ابتدا محصول را به‌صورت پیش‌نویس ذخیره کنید.', 'error');
				return;
			}
			if (!prk) {
				this.status('ابتدا یک محصول از نتایج جستجو انتخاب کنید.', 'error');
				return;
			}

			// انتخاب تصاویر و سئو (همان جریان صفحه‌ی اصلی).
			var imgSel = { selected: [], featured: 0 };
			if (typeof this.collectImgSelection === 'function') {
				imgSel = this.collectImgSelection();
			}
			var seoTitle = $('#shoper-p-seo-title').val();
			var seoDesc  = $('#shoper-p-seo-desc').val();
			var tags     = $('#shoper-p-tags').val().split(/[،,]/).map(function (t) { return t.trim(); }).filter(Boolean);

			this.status('در حال پر کردن محصول…', 'loading');
			this.$fillBtn.prop('disabled', true);
			this.showProgress('دریافت اطلاعات');

			this.ajax('shoper_fill', {
				post_id: postId,
				prk: prk,
				search_id: searchId,
				more_info_url: moreInfo,
				selected_images: JSON.stringify(imgSel.selected || []),
				featured_image: imgSel.featured || 0,
				seo_title: seoTitle,
				seo_desc: seoDesc,
				focus_keyword: $('#shoper-p-focus').val() || '',
				description: $('#shoper-p-desc').val() || '',
				short_description: $('#shoper-p-short').val() || '',
				tags: JSON.stringify(tags),
				product_json: this.currentData ? JSON.stringify(this.currentData) : ''
			}, function (data) {
				self.$fillBtn.prop('disabled', false);
				self.showProgress('انجام شد!');
				self.status(data.message || 'پر شد!', 'success');
				// رفرش صفحه برای دیدن تغییرات در ویرایشگر.
				if (data.reload) {
					setTimeout(function () { window.location.reload(); }, 1200);
				}
			}).fail(function () {
				self.$fillBtn.prop('disabled', false);
				self.$preview.find('#shoper-progress').hide();
			});
		},

		testConnection: function () {
			var self = this;
			this.$connResult.html('<span class="shoper-loading-inline"></span> در حال تست مرورگر و سرور...');
			this.browserFetch(this.torobSearchUrl('s25', 1)).done(function (raw) {
				if (raw && raw.results) {
					self.$connResult.html('<span style="color:#00a32a;">✓ اتصال از مرورگر شما برقرار است. همین حالا جستجو کنید.</span>');
					return;
				}
				self.ajax('shoper_test_connection', {}, function (data) {
					self.$connResult.html('<span style="color:#00a32a;">✓ ' + self.esc(data.message) + '</span>');
				}, function (info) {
					var msg = info.message || '';
					self.$connResult.html('<span style="color:#dba617;">⚠ سرور مسدود است (' + self.esc(msg) + '). اگر جستجوی بالا هم نتیجه نداد، رله ایران را تنظیم کنید.</span>');
				});
			});
		},

		/**
		 * عیب‌یابی کامل اتصال: اجرای همه‌ی بررسی‌ها و نمایش گزارش قابل کپی.
		 */
		diagnostics: function () {
			var self = this;
			if (!this.$diag.length) {
				this.status('عنصر گزارش عیب‌یابی یافت نشد.', 'error');
				return;
			}

			this.$diagBtn.prop('disabled', true);
			this.$diag.show().html('<div class="shoper-diag-loading"><span class="shoper-loading-inline"></span> در حال اجرای عیب‌یابی کامل… این کار چند ده ثانیه طول می‌کشد.</div>');

			this.ajax('shoper_diagnostics', {}, function (data) {
				self.browserFetch(self.torobSearchUrl('s25', 2)).done(function (raw) {
					self.$diagBtn.prop('disabled', false);
					self.mergeBrowserDiag(data, raw);
					self.renderDiagnostics(data);
				});
			}, function (info) {
				self.$diagBtn.prop('disabled', false);
				self.$diag.html('<div class="shoper-diag-error">خطا در اجرای عیب‌یابی: ' + self.esc(info.message) + '</div>');
			});
		},

		/**
		 * رندر گزارش عیب‌یابی + دکمه‌ی کپی.
		 *
		 * @param {Object} d گزارش.
		 */
		mergeBrowserDiag: function (d, raw) {
			d.checks = d.checks || [];
			var ok = !!(raw && raw.results && raw.results.length);
			var check = {
				id: 'browser',
				label: 'مرورگر مدیر (مسیر اصلی افزونه ۱.۳)',
				method: 'browser',
				status: ok ? 'ok' : 'fail',
				has_results: ok,
				results_count: ok ? raw.results.length : 0,
				note: ok
					? 'مرورگر شما به ترب دسترسی دارد. جستجو را از کادر بالا انجام دهید؛ نیازی به سبز شدن تست سرور نیست.'
					: 'مرورگر مستقیم JSON ترب را نخواند (معمولاً CORS). اگر درگاه پیش‌فرض در عیب‌یابی سرور موفق باشد، جستجو باید کار کند.'
			};
			d.checks.unshift(check);
			d.summary = d.summary || {};
			if (ok) {
				d.summary.verdict = 'ok';
				d.summary.message = 'سرور مسدود است اما مرورگر شما به ترب وصل شد. افزونه باید کار کند — در کادر جستجو نام محصول را بنویسید.';
			} else if (d.summary.verdict === 'blocked' || d.summary.verdict === 'fail') {
				d.summary.verdict = 'fail';
				d.summary.message = 'مرورگر مستقیم به ترب نرسید. اگر درگاه پیش‌فرض در همین گزارش موفق است، در کادر بالا جستجو کنید.';
			}
			if (d.text) {
				var line = '[ ' + (ok ? 'OK' : 'FAIL') + ' ] مرورگر مدیر — ' + check.note + '\n';
				d.text = d.text.replace('[نتیجه‌ی کلی]', line + '\n[نتیجه‌ی کلی]');
				d.text = d.text.replace(/\[نتیجه‌ی کلی\][^\n]*/, '[نتیجه‌ی کلی] ' + (d.summary.verdict || '').toUpperCase() + ' — ' + d.summary.message);
			}
		},

		renderDiagnostics: function (d) {
			var html = '';
			var verdict = (d.summary && d.summary.verdict) || 'unknown';
			var verdictLabel = { ok: 'افزونه می‌تواند کار کند', warn: 'پاسخ غیرمعتبر', fail: 'اتصال برقرار نشد', blocked: 'سرور مسدود است — از مرورگر استفاده کنید' }[verdict] || 'نامشخص';
			var verdictColor = { ok: '#00a32a', warn: '#dba617', fail: '#d63638', blocked: '#dba617' }[verdict] || '#555';

			html += '<div class="shoper-diag">';
			html += '<div class="shoper-diag-summary" style="border:1px solid ' + verdictColor + ';color:' + verdictColor + ';">';
			html += '<strong>' + this.esc(verdictLabel) + '</strong> — ' + this.esc((d.summary && d.summary.message) || '');
			html += ' <span style="font-size:11px;opacity:.8;">(' + this.esc(d.generated_at) + ')</span>';
			html += '</div>';

			// محیط.
			if (d.environment) {
				var e = d.environment;
				html += '<h4 class="shoper-diag-h">محیط</h4>';
				html += '<table class="shoper-diag-env">';
				html += this.diagRow('نسخه افزونه', e.plugin_version);
				html += this.diagRow('PHP', e.php_version);
				html += this.diagRow('وردپرس', e.wp_version);
				html += this.diagRow('ووکامرس', e.wc_version);
				html += this.diagRow('cURL', e.curl ? e.curl_version : 'موجود نیست');
				html += this.diagRow('allow_url_fopen', e.allow_url_fopen ? 'فعال' : 'غیرفعال');
				html += this.diagRow('OpenSSL', e.openssl);
				html += this.diagRow('منبع داده', e.data_source);
				html += this.diagRow('timeout / connect', e.timeout + ' / ' + e.connect_timeout);
				html += this.diagRow('پروکسی', e.proxy_configured ? e.proxy : 'تنظیم نشده');
				html += this.diagRow('لاگ اشکال‌زدایی', e.debug_enabled ? 'فعال' : 'غیرفعال');
				html += '</table>';
			}

			// بررسی‌ها.
			if (d.checks && d.checks.length) {
				html += '<h4 class="shoper-diag-h">بررسی‌های اتصال</h4>';
				for (var i = 0; i < d.checks.length; i++) {
					var c = d.checks[i];
					var st = c.status || 'unknown';
					var stLabel = { ok: 'موفق', warn: 'هشدار', fail: 'ناموفق', skip: 'رد شده' }[st] || st;
					var stColor = { ok: '#00a32a', warn: '#dba617', fail: '#d63638', skip: '#888' }[st] || '#555';
					html += '<div class="shoper-diag-check">';
					html += '<div class="shoper-diag-check-head"><span class="shoper-diag-badge" style="background:' + stColor + ';">' + stLabel + '</span> <strong>' + this.esc(c.label) + '</strong></div>';
					if (c.code !== undefined) html += '<div class="shoper-diag-line">HTTP status: <code>' + c.code + '</code></div>';
					if (c.content_type) html += '<div class="shoper-diag-line">Content-Type: <code>' + this.esc(c.content_type) + '</code></div>';
					if (c.duration !== undefined) html += '<div class="shoper-diag-line">زمان: ' + c.duration + 's</div>';
					if (c.curl_errno !== undefined) html += '<div class="shoper-diag-line">curl errno: ' + c.curl_errno + (c.curl_error ? ' — ' + this.esc(c.curl_error) : '') + '</div>';
					if (c.wp_error_code) html += '<div class="shoper-diag-line">WP_Error: ' + this.esc(c.wp_error_code) + (c.wp_error_message ? ' — ' + this.esc(c.wp_error_message) : '') + '</div>';
					if (c.body_length !== undefined) html += '<div class="shoper-diag-line">طول پاسخ: ' + c.body_length + ' بایت</div>';
					if (c.has_results !== undefined) html += '<div class="shoper-diag-line">کلید results: ' + (c.has_results ? 'دارد (' + (c.results_count || 0) + ' نتیجه)' : 'ندارد') + '</div>';
					if (c.detail) html += '<div class="shoper-diag-line">جزئیات: ' + this.esc(c.detail) + '</div>';
					if (c.note) html += '<div class="shoper-diag-note">' + this.esc(c.note) + '</div>';
					if (c.body_sample) html += '<details class="shoper-diag-sample"><summary>نمونه‌ی پاسخ</summary><pre>' + this.esc(c.body_sample) + '</pre></details>';
					html += '</div>';
				}
			}

			// گزارش متنی کامل + کپی.
			if (d.text) {
				html += '<div class="shoper-diag-copybar">';
				html += '<button type="button" class="button button-primary shoper-diag-copy" data-id="shoper-diag-text">📋 کپی گزارش کامل</button>';
				html += '<span class="shoper-diag-copy-ok" style="display:none;color:#00a32a;">کپی شد!</span>';
				html += '</div>';
				html += '<textarea id="shoper-diag-text" class="shoper-diag-text" readonly rows="14">' + this.esc(d.text) + '</textarea>';
			}

			html += '</div>';

			this.$diag.html(html);

			// کپی به کلیپ‌بورد.
			var $copy = this.$diag.find('.shoper-diag-copy');
			$copy.on('click', function () {
				var $ta = $('#shoper-diag-text');
				var copied = false;
				$ta[0].select();
				$ta[0].setSelectionRange(0, 99999999);
				try {
					copied = document.execCommand('copy');
				} catch (e) {
					copied = false;
				}
				if (!copied && navigator.clipboard) {
					navigator.clipboard.writeText($ta.val()).then(function () {
						self.$diag.find('.shoper-diag-copy-ok').show().fadeOut(2500);
					}).catch(function () {});
					return;
				}
				self.$diag.find('.shoper-diag-copy-ok').show().fadeOut(2500);
			});
		},

		/**
		 * ساخت یک ردیف جدول محیط.
		 *
		 * @param {string} k کلید.
		 * @param {string} v مقدار.
		 * @return {string}
		 */
		diagRow: function (k, v) {
			return '<tr><th>' + this.esc(k) + '</th><td>' + this.esc(v === undefined || v === null ? '—' : v) + '</td></tr>';
		},

		downloadRelay: function () {
			var token = 'shoper-' + Math.random().toString(36).slice(2, 10) + Math.random().toString(36).slice(2, 6);
			var php = [
				'<?php',
				'declare(strict_types=1);',
				"header('X-Content-Type-Options: nosniff');",
				"$TOKEN = '" + token + "';",
				"$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '*';",
				"header('Access-Control-Allow-Origin: ' . $origin);",
				"header('Access-Control-Allow-Methods: GET, OPTIONS');",
				"header('Access-Control-Allow-Headers: Accept, Content-Type');",
				"header('Vary: Origin');",
				"if ('OPTIONS' === strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) { http_response_code(204); exit; }",
				"if ('GET' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) { http_response_code(405); header('Content-Type: application/json; charset=utf-8'); echo '{\"error\":\"method\"}'; exit; }",
				"$tok = isset($_GET['token']) ? (string) $_GET['token'] : '';",
				"if ($TOKEN === '' || !hash_equals($TOKEN, $tok)) { http_response_code(403); echo '{\"error\":\"forbidden\"}'; exit; }",
				"$target = isset($_GET['url']) ? (string) $_GET['url'] : '';",
				"if ($target === '' || !preg_match('#^https://#i', $target)) { http_response_code(400); echo '{\"error\":\"url\"}'; exit; }",
				"$host = parse_url($target, PHP_URL_HOST);",
				"if (!is_string($host) || !preg_match('#(^|\\.)torob\\.(com|ir)$#i', $host)) { http_response_code(400); echo '{\"error\":\"host\"}'; exit; }",
				"$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';",
				"$ch = curl_init($target);",
				"curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_USERAGENT=>$ua,CURLOPT_ENCODING=>'gzip, deflate',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json','Referer: https://torob.com/','Origin: https://torob.com']]);",
				"$body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);",
				"if ($body === false) { http_response_code(502); echo '{\"error\":\"upstream\"}'; exit; }",
				"http_response_code($code > 0 ? $code : 200);",
				"header('Content-Type: ' . ($ctype ?: 'application/json; charset=utf-8'));",
				"echo $body;"
			].join('\n');
			var blob = new Blob([php], { type: 'application/x-httpd-php' });
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = 'shoper-relay.php';
			a.click();
			URL.revokeObjectURL(a.href);
			var hint = 'https://YOUR-IRAN-HOST/shoper-relay.php?token=' + token;
			var $field = $('#shoper-relay-url');
			if ($field.length && !$field.val()) {
				$field.val(hint);
			}
			alert('فایل رله دانلود شد. آن را روی هاست ایران آپلود کنید و در فیلد رله این آدرس را با دامنهٔ واقعی ذخیره کنید:\n' + hint);
		},


		stepOrder: function () {
			return ['info', 'images', 'ai', 'review'];
		},

		currentStep: function () {
			var $a = this.$preview.find('.shoper-step.is-active').first();
			return $a.length ? String($a.data('step') || 'info') : 'info';
		},

		stepDelta: function (delta) {
			var order = this.stepOrder();
			var idx = order.indexOf(this.currentStep());
			if (idx < 0) { idx = 0; }
			idx = Math.max(0, Math.min(order.length - 1, idx + delta));
			this.goStep(order[idx]);
			if (order[idx] === 'ai' && this.currentData && !this._enhancedOnce) {
				this.queueEnhance(this.currentData, false);
			}
		},

		setAiStatus: function (msg, type) {
			var $el = this.$preview.find('#shoper-ai-status');
			if (!$el.length) { return; }
			$el.removeClass('is-loading is-ok is-warn').addClass(type || '');
			$el.html(type === 'is-loading' ? '<span class="shoper-loading-inline"></span> ' + this.esc(msg) : this.esc(msg));
		},

		applyEnhance: function (enh) {
			if (!enh) { return; }
			this._enhancedOnce = true;
			this.lastEnhance = enh;
			if (enh.description_html) {
				$('#shoper-p-desc').val(enh.description_html);
			}
			if (enh.short_description) {
				$('#shoper-p-short').val(enh.short_description);
			}
			if (enh.analysis) { $('#shoper-p-analysis').val(enh.analysis); }
			if (enh.review) { $('#shoper-p-review').val(enh.review); }
			if (enh.audience) { $('#shoper-p-audience').val(enh.audience); }
			if (enh.seo_title) { $('#shoper-p-seo-title').val(enh.seo_title); }
			if (enh.seo_desc) { $('#shoper-p-seo-desc').val(enh.seo_desc); }
			if (enh.focus_keyword) { $('#shoper-p-focus').val(enh.focus_keyword); }
			if (enh.tags && enh.tags.length) { $('#shoper-p-tags').val(enh.tags.join('، ')); }
			var label = enh.provider_label || 'استودیوی نویسندگی خواجوی';
			var extra = enh.fallback_reason ? ' — ' + enh.fallback_reason : '';
			this.setAiStatus('آمادهٔ نظارت: ' + label + extra, 'is-ok');
		},

		queueEnhance: function (data, force) {
			var self = this;
			if (this.cfg('aiEnabled', 'yes') === 'no') { return; }
			if (this._enhancing && !force) { return; }
			this._enhancing = true;
			this.setAiStatus((typeof ShoperData !== 'undefined' && ShoperData.i18n && ShoperData.i18n.enhancing) ? ShoperData.i18n.enhancing : 'در حال بازنویسی…', 'is-loading');
			this.ajax('shoper_enhance', {
				product_json: JSON.stringify(data || this.currentData || {})
			}, function (resp) {
				self._enhancing = false;
				self.applyEnhance(resp);
			}, function (info) {
				self._enhancing = false;
				self.setAiStatus((info && info.message) ? info.message : 'بازنویسی ناموفق بود؛ متن منبع باقی ماند.', 'is-warn');
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
