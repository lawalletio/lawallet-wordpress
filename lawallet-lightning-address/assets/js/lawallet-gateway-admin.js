(function (config) {
	if (!config || !config.fieldId) {
		return;
	}

	var input = document.getElementById(config.fieldId);
	if (!input) {
		return;
	}

	var i18n = config.i18n || {};
	var ICONS = {
		pending: 'dashicons-minus',
		loading: 'dashicons-update',
		ok: 'dashicons-yes-alt',
		error: 'dashicons-dismiss'
	};

	function makeBadge(key, label) {
		var badge = document.createElement('span');
		badge.className = 'wcll-la-check is-pending';
		badge.setAttribute('data-wcll-check', key);

		var icon = document.createElement('span');
		icon.className = 'wcll-la-check-icon dashicons ' + ICONS.pending;

		var text = document.createElement('span');
		text.className = 'wcll-la-check-label';
		text.textContent = label;

		badge.appendChild(icon);
		badge.appendChild(text);
		return badge;
	}

	var badges = {
		lud16: makeBadge('lud16', i18n.lud16Label || 'LUD-16'),
		lud21: makeBadge('lud21', i18n.lud21Label || 'LUD-21'),
		nip57: makeBadge('nip57', i18n.nip57Label || 'NIP-57')
	};

	var wrap = document.createElement('div');
	wrap.className = 'wcll-la-checks';
	wrap.appendChild(badges.lud16);
	wrap.appendChild(badges.lud21);
	wrap.appendChild(badges.nip57);

	if (input.parentNode) {
		if (input.nextSibling) {
			input.parentNode.insertBefore(wrap, input.nextSibling);
		} else {
			input.parentNode.appendChild(wrap);
		}
	}

	function setBadge(key, state, message) {
		var badge = badges[key];
		if (!badge) {
			return;
		}
		badge.className = 'wcll-la-check is-' + state;
		badge.title = message || '';
		var icon = badge.querySelector('.wcll-la-check-icon');
		if (icon) {
			icon.className = 'wcll-la-check-icon dashicons ' + (ICONS[state] || ICONS.pending);
		}
	}

	function setAll(state, message) {
		setBadge('lud16', state, message);
		setBadge('lud21', state, message);
		setBadge('nip57', state, message);
	}

	function applyResult(key, result) {
		if (!result) {
			setBadge(key, 'error', '');
			return;
		}
		setBadge(key, result.ok ? 'ok' : 'error', result.message || '');
	}

	var timer = null;
	var requestId = 0;

	function check(address) {
		var currentRequest = ++requestId;
		var body = new URLSearchParams();
		body.set('action', 'wcll_check_lightning_address');
		body.set('nonce', config.nonce);
		body.set('address', address);
		setAll('loading', i18n.checking || '');

		window.fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (currentRequest !== requestId) {
					return;
				}
				var data = payload && payload.data ? payload.data : {};
				if (payload && payload.success) {
					applyResult('lud16', data.lud16);
					applyResult('lud21', data.lud21);
					applyResult('nip57', data.nip57);
				} else {
					setAll('error', data.message || '');
				}
			})
			.catch(function () {
				if (currentRequest === requestId) {
					setAll('error', '');
				}
			});
	}

	function isValid(address) {
		return /^[^@\s]+@[^@\s]+$/.test(address);
	}

	function schedule() {
		window.clearTimeout(timer);
		requestId += 1;
		var value = input.value.trim();
		if (!isValid(value)) {
			setAll('pending', i18n.pending || '');
			return;
		}
		setAll('loading', i18n.checking || '');
		timer = window.setTimeout(function () {
			check(value);
		}, 600);
	}

	input.addEventListener('input', schedule);

	var initial = input.value.trim();
	if (isValid(initial)) {
		check(initial);
	} else {
		setAll('pending', i18n.pending || '');
	}
})(window.WCLLGatewayAdmin || {});

// Tabbed settings: show the panel that matches the clicked tab.
(function () {
	function init() {
		var root = document.querySelector('.wcll-settings');
		if (!root) {
			return;
		}

		var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-wcll-tab]'));
		var panels = Array.prototype.slice.call(root.querySelectorAll('[data-wcll-panel]'));
		if (!tabs.length) {
			return;
		}

		function activate(id) {
			var matched = false;
			tabs.forEach(function (tab) {
				tab.classList.toggle('nav-tab-active', tab.getAttribute('data-wcll-tab') === id);
			});
			panels.forEach(function (panel) {
				var on = panel.getAttribute('data-wcll-panel') === id;
				panel.classList.toggle('is-active', on);
				matched = matched || on;
			});
			if (matched) {
				try {
					window.history.replaceState(null, '', '#wcll-' + id);
				} catch (e) {} // eslint-disable-line no-empty
			}
		}

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function (event) {
				event.preventDefault();
				activate(tab.getAttribute('data-wcll-tab'));
			});
		});

		var hash = (window.location.hash || '').replace('#wcll-', '');
		if (hash && root.querySelector('[data-wcll-panel="' + hash + '"]')) {
			activate(hash);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

// NWC tab: reveal only the fields relevant to the selected mode / enabled state.
(function () {
	function init() {
		var enabled = document.getElementById('woocommerce_wcll_gateway_nwc_proxy_enabled');
		var mode = document.getElementById('woocommerce_wcll_gateway_nwc_mode');
		if (!mode) {
			return;
		}

		function rowOf(id) {
			var el = document.getElementById(id);
			return el ? el.closest('tr') : null;
		}

		function apply() {
			var on = !enabled || enabled.checked;
			var disposable = mode.value === 'disposable';
			var rules = [
				['woocommerce_wcll_gateway_nwc_mode', on],
				['woocommerce_wcll_gateway_nwc_lncurl_url', on && disposable],
				['woocommerce_wcll_gateway_nwc_auto_replace', on && disposable],
				['woocommerce_wcll_gateway_nwc_connection', on && !disposable]
			];
			rules.forEach(function (rule) {
				var row = rowOf(rule[0]);
				if (row) {
					row.style.display = rule[1] ? '' : 'none';
				}
			});
		}

		mode.addEventListener('change', apply);
		if (enabled) {
			enabled.addEventListener('change', apply);
		}
		apply();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

// NWC proxy wallet panel: live balance + receive + withdraw (all signing is
// server-side; the browser only subscribes to NIP-47 notifications).
(function (config) {
	function init() {
		var nwc = config && config.nwc;
		var root = document.querySelector('[data-wcll-nwc-wallet]');
		if (!root || !nwc || !nwc.configured) {
			return;
		}

		var i18n = nwc.i18n || {};
		var balanceEl = root.querySelector('[data-wcll-nwc-balance]');
		var feedback = root.querySelector('[data-wcll-nwc-feedback]');

		function ajax(action, data) {
			var body = new URLSearchParams();
			body.set('action', action);
			body.set('nonce', nwc.nonce);
			Object.keys(data || {}).forEach(function (key) {
				body.set(key, data[key]);
			});
			return window.fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			}).then(function (response) {
				return response.json();
			});
		}

		function say(message, isError) {
			if (feedback) {
				feedback.textContent = message || '';
				feedback.classList.toggle('is-error', !!isError);
			}
		}

		function refreshBalance() {
			if (!balanceEl) {
				return;
			}
			ajax('wcll_nwc_balance', {}).then(function (res) {
				var d = (res && res.data) || {};
				balanceEl.textContent = (res && res.success && d.ok)
					? (Number(d.sats).toLocaleString() + ' ' + (i18n.sats || 'sats'))
					: (i18n.unavailable || 'Balance unavailable');
			}).catch(function () {
				balanceEl.textContent = i18n.unavailable || 'Balance unavailable';
			});
		}

		root.querySelectorAll('[data-wcll-nwc-tab]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var which = btn.getAttribute('data-wcll-nwc-tab');
				root.querySelectorAll('[data-wcll-nwc-form]').forEach(function (form) {
					var match = form.getAttribute('data-wcll-nwc-form') === which;
					form.hidden = match ? !form.hidden : true;
				});
				say('');
			});
		});

		var refreshBtn = root.querySelector('[data-wcll-nwc-refresh]');
		if (refreshBtn) {
			refreshBtn.addEventListener('click', function () {
				if (balanceEl) {
					balanceEl.textContent = i18n.loading || '…';
				}
				refreshBalance();
			});
		}

		var generateBtn = root.querySelector('[data-wcll-nwc-generate]');
		if (generateBtn) {
			generateBtn.addEventListener('click', function () {
				var input = root.querySelector('[data-wcll-nwc-amount="receive"]');
				var amount = input ? parseInt(input.value, 10) : 0;
				if (!amount || amount < 1) {
					say(i18n.amountRequired, true);
					return;
				}
				generateBtn.disabled = true;
				say(i18n.generating);
				ajax('wcll_nwc_receive', { amount: amount }).then(function (res) {
					generateBtn.disabled = false;
					var d = (res && res.data) || {};
					if (res && res.success && d.invoice) {
						var box = root.querySelector('[data-wcll-nwc-invoice]');
						var text = root.querySelector('[data-wcll-nwc-invoice-text]');
						if (text) {
							text.value = d.invoice;
						}
						if (box) {
							box.hidden = false;
						}
						say('');
					} else {
						say(d.message || i18n.unavailable, true);
					}
				}).catch(function () {
					generateBtn.disabled = false;
					say(i18n.unavailable, true);
				});
			});
		}

		var copyBtn = root.querySelector('[data-wcll-nwc-copy]');
		if (copyBtn) {
			copyBtn.addEventListener('click', function () {
				var text = root.querySelector('[data-wcll-nwc-invoice-text]');
				if (text && navigator.clipboard) {
					navigator.clipboard.writeText(text.value).then(function () {
						var original = copyBtn.textContent;
						copyBtn.textContent = i18n.copied || 'Copied';
						window.setTimeout(function () {
							copyBtn.textContent = original;
						}, 1400);
					});
				}
			});
		}

		var sendBtn = root.querySelector('[data-wcll-nwc-send]');
		if (sendBtn) {
			sendBtn.addEventListener('click', function () {
				var destInput = root.querySelector('[data-wcll-nwc-destination]');
				var amountInput = root.querySelector('[data-wcll-nwc-amount="withdraw"]');
				var destination = destInput ? destInput.value.trim() : '';
				var amount = amountInput ? parseInt(amountInput.value, 10) : 0;
				if (!destination) {
					say(i18n.destRequired, true);
					return;
				}
				sendBtn.disabled = true;
				say(i18n.sending);
				ajax('wcll_nwc_withdraw', { destination: destination, amount: amount > 0 ? amount : 0 }).then(function (res) {
					sendBtn.disabled = false;
					var d = (res && res.data) || {};
					if (res && res.success) {
						say(i18n.sent);
						if (destInput) {
							destInput.value = '';
						}
						if (amountInput) {
							amountInput.value = '';
						}
						refreshBalance();
					} else {
						say(d.message || i18n.unavailable, true);
					}
				}).catch(function () {
					sendBtn.disabled = false;
					say(i18n.unavailable, true);
				});
			});
		}

		// Live: subscribe to NIP-47 notifications and refresh the balance on any.
		function watchNotifications() {
			if (!nwc.walletPubkey || !Array.isArray(nwc.relays) || !nwc.relays.length) {
				return;
			}
			nwc.relays.forEach(function (relay) {
				var socket;
				try {
					socket = new WebSocket(relay);
				} catch (e) {
					return;
				}
				socket.addEventListener('open', function () {
					var filter = {
						kinds: [23196, 23197],
						authors: [nwc.walletPubkey],
						since: Math.floor(Date.now() / 1000) - 60
					};
					if (nwc.clientPubkey) {
						filter['#p'] = [nwc.clientPubkey];
					}
					socket.send(JSON.stringify(['REQ', 'wcll-nwc-admin', filter]));
				});
				socket.addEventListener('message', function (message) {
					var parsed;
					try {
						parsed = JSON.parse(message.data);
					} catch (e) {
						return;
					}
					if (!Array.isArray(parsed) || parsed[0] !== 'EVENT') {
						return;
					}
					var ev = parsed[2];
					if (ev && (ev.kind === 23196 || ev.kind === 23197) && ev.pubkey === nwc.walletPubkey) {
						refreshBalance();
					}
				});
			});
		}

		refreshBalance();
		watchNotifications();
		window.setInterval(refreshBalance, 30000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window.WCLLGatewayAdmin || {});

// Keep the "Use NWC Proxy" checkbox (Lightning Address tab) in sync with the
// real nwc_proxy_enabled checkbox (NWC tab) — one underlying setting.
(function () {
	function init() {
		var mirror = document.querySelector('[data-wcll-nwc-mirror]');
		var real = document.getElementById('woocommerce_wcll_gateway_nwc_proxy_enabled');
		if (!mirror || !real) {
			return;
		}

		mirror.checked = real.checked;

		mirror.addEventListener('change', function () {
			real.checked = mirror.checked;
			real.dispatchEvent(new Event('change', { bubbles: true }));
		});
		real.addEventListener('change', function () {
			mirror.checked = real.checked;
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
