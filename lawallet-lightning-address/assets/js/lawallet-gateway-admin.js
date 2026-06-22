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
