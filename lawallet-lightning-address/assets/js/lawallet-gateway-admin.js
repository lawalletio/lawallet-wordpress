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

	// "Switch to NWC Proxy" alert: shown only in Lightning Address mode when the
	// address resolves (LUD-16) but confirms neither LUD-21 nor NIP-57.
	var modeSel = document.getElementById('woocommerce_wcll_gateway_settlement_method');
	var alertEl = document.querySelector('[data-wcll-receiver-alert]');
	var needsProxy = false;

	function updateAlert() {
		if (!alertEl) {
			return;
		}
		var laMode = !modeSel || modeSel.value === 'lightning_address';
		alertEl.hidden = !(laMode && needsProxy);
	}

	if (modeSel) {
		modeSel.addEventListener('change', updateAlert);
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
					needsProxy = !!(data.lud16 && data.lud16.ok)
						&& !(data.lud21 && data.lud21.ok)
						&& !(data.nip57 && data.nip57.ok);
					updateAlert();
				} else {
					setAll('error', data.message || '');
					needsProxy = false;
					updateAlert();
				}
			})
			.catch(function () {
				if (currentRequest === requestId) {
					setAll('error', '');
					needsProxy = false;
					updateAlert();
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
			needsProxy = false;
			updateAlert();
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

		// After a "Save & open NWC Proxy" save, land on the requested tab.
		var openTab = '';
		try {
			openTab = window.sessionStorage.getItem('wcllOpenTab') || '';
		} catch (e) {} // eslint-disable-line no-empty
		if (openTab && root.querySelector('[data-wcll-panel="' + openTab + '"]')) {
			try {
				window.sessionStorage.removeItem('wcllOpenTab');
			} catch (e) {} // eslint-disable-line no-empty
			activate(openTab);
		}

		// "Save & open NWC Proxy": persist settings (so the proxy tab reflects them),
		// then open that tab on reload.
		var gotoNwc = root.querySelector('[data-wcll-goto-nwc]');
		if (gotoNwc) {
			gotoNwc.addEventListener('click', function () {
				try {
					window.sessionStorage.setItem('wcllOpenTab', 'nwc');
				} catch (e) {} // eslint-disable-line no-empty
				var save = document.querySelector('button[name="save"], .woocommerce-save-button, input[name="save"]');
				if (save) {
					save.click();
				} else {
					var form = root.closest('form');
					if (form) {
						form.submit();
					}
				}
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

// Receiver mode drives the whole admin UI: which Receiver-tab fields show, the
// terminal-NWC balance panel, the compatible-wallets list, and whether the
// proxy-only "NWC Wallet" tab is available at all.
(function () {
	function init() {
		var method = document.getElementById('woocommerce_wcll_gateway_settlement_method');
		var mode = document.getElementById('woocommerce_wcll_gateway_nwc_mode');
		if (!method) {
			return;
		}

		function rowOf(id) {
			var el = document.getElementById(id);
			return el ? el.closest('tr') : null;
		}
		function show(el, on) {
			if (el) {
				el.style.display = on ? '' : 'none';
			}
		}

		var wallets = document.querySelector('.wcll-wallets');
		var receiverPanel = document.querySelector('[data-wcll-receiver-panel]');
		var nwcTab = document.querySelector('[data-wcll-tab="nwc"]');
		var nwcPanel = document.querySelector('[data-wcll-panel="nwc"]');

		function apply() {
			var m = method.value;
			var isProxy = m === 'nwc_proxy';
			var isTerminal = m === 'nwc';
			var usesAddress = m === 'lightning_address' || isProxy;
			var disposable = !mode || mode.value === 'disposable';

			// Receiver tab fields.
			show(rowOf('woocommerce_wcll_gateway_lightning_address'), usesAddress);
			show(rowOf('woocommerce_wcll_gateway_nwc_receiver_connection'), isTerminal);
			if (receiverPanel) {
				receiverPanel.hidden = !isTerminal;
			}
			if (wallets) {
				wallets.style.display = usesAddress ? '' : 'none';
			}
			show(document.querySelector('[data-wcll-receiver-proxy-note]'), isProxy);

			// Proxy-only "NWC Wallet" tab: hide its nav (and panel) outside proxy mode.
			show(nwcTab, isProxy);
			if (!isProxy && nwcPanel) {
				nwcPanel.classList.remove('is-active');
				if (nwcTab) {
					nwcTab.classList.remove('nav-tab-active');
				}
			}

			// Proxy wallet sub-fields (only meaningful while the NWC tab is shown).
			show(rowOf('woocommerce_wcll_gateway_nwc_lncurl_url'), isProxy && disposable);
			show(rowOf('woocommerce_wcll_gateway_nwc_connection'), isProxy && !disposable);
			show(rowOf('woocommerce_wcll_gateway_nwc_mode'), isProxy);
			show(document.querySelector('[data-wcll-nwc-disposable-info]'), isProxy && disposable);
		}

		method.addEventListener('change', apply);
		if (mode) {
			mode.addEventListener('change', apply);
		}
		apply();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

// Terminal-NWC live balance in the Receiver tab.
(function (config) {
	function init() {
		var nwc = config && config.nwc;
		var balanceEl = document.querySelector('[data-wcll-receiver-balance]');
		if (!nwc || !balanceEl) {
			return;
		}
		var i18n = nwc.i18n || {};

		function refresh() {
			balanceEl.textContent = i18n.loading || '…';
			var body = new URLSearchParams();
			body.set('action', 'wcll_nwc_receiver_balance');
			body.set('nonce', nwc.nonce);
			window.fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			}).then(function (r) {
				return r.json();
			}).then(function (res) {
				var d = (res && res.data) || {};
				balanceEl.textContent = (res && res.success && d.ok)
					? (Number(d.sats).toLocaleString() + ' ' + (i18n.sats || 'sats'))
					: (i18n.unavailable || 'Balance unavailable');
			}).catch(function () {
				balanceEl.textContent = i18n.unavailable || 'Balance unavailable';
			});
		}

		var refreshBtn = document.querySelector('[data-wcll-receiver-refresh]');
		if (refreshBtn) {
			refreshBtn.addEventListener('click', refresh);
		}
		refresh();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window.WCLLGatewayAdmin || {});

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

		// Disposable wallets burn ~1 sat/hour of lncurl upkeep, so the balance (in
		// sats) is the remaining lifetime in hours from the moment it was read.
		var isDisposable = nwc.mode === 'disposable';
		var lifetimeEl = root.querySelector('[data-wcll-nwc-lifetime]');
		var countdownEl = root.querySelector('[data-wcll-nwc-countdown]');
		var deathTs = null;

		function pad2(n) {
			return (n < 10 ? '0' : '') + n;
		}

		function fmtCountdown(ms) {
			if (ms <= 0) {
				return i18n.walletEmpty || 'now (empty)';
			}
			var s = Math.floor(ms / 1000);
			var d = Math.floor(s / 86400);
			s -= d * 86400;
			var h = Math.floor(s / 3600);
			s -= h * 3600;
			var m = Math.floor(s / 60);
			s -= m * 60;
			var parts = [];
			if (d) {
				parts.push(d + 'd');
			}
			if (h || d) {
				parts.push(pad2(h) + 'h');
			}
			parts.push(pad2(m) + 'm');
			parts.push(pad2(s) + 's');
			return parts.join(' ');
		}

		function renderCountdown() {
			if (!lifetimeEl || !countdownEl) {
				return;
			}
			if (deathTs === null) {
				lifetimeEl.hidden = true;
				return;
			}
			lifetimeEl.hidden = false;
			countdownEl.textContent = fmtCountdown(deathTs - Date.now());
		}

		function setBalance(ok, sats) {
			if (balanceEl) {
				balanceEl.textContent = ok
					? (Number(sats).toLocaleString() + ' ' + (i18n.sats || 'sats'))
					: (i18n.unavailable || 'Balance unavailable');
			}
			deathTs = (isDisposable && ok) ? (Date.now() + Number(sats) * 3600 * 1000) : null;
			renderCountdown();
		}

		function refreshBalance() {
			if (!balanceEl) {
				return;
			}
			ajax('wcll_nwc_balance', {}).then(function (res) {
				var d = (res && res.data) || {};
				setBalance(!!(res && res.success && d.ok), d.sats || 0);
			}).catch(function () {
				setBalance(false, 0);
			});
		}

		if (isDisposable) {
			window.setInterval(renderCountdown, 1000);
		}

		root.querySelectorAll('[data-wcll-nwc-tab]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var which = btn.getAttribute('data-wcll-nwc-tab');
				// Reset both sections so reopening one always starts from scratch.
				resetSection('receive');
				resetSection('withdraw');
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

		// Create a fresh disposable wallet on demand (disposable mode only). The
		// control lives below the lncurl field, outside this panel root, so query
		// the document for it.
		var createRow = document.querySelector('[data-wcll-nwc-create-row]');
		var createBtn = document.querySelector('[data-wcll-nwc-create]');
		var createFeedback = document.querySelector('[data-wcll-nwc-create-feedback]');
		if (isDisposable && createRow) {
			createRow.hidden = false;
		}
		function createSay(message, isError) {
			if (createFeedback) {
				createFeedback.textContent = message || '';
				createFeedback.classList.toggle('is-error', !!isError);
			}
		}
		if (createBtn) {
			createBtn.addEventListener('click', function () {
				if (!window.confirm(i18n.createConfirm || 'Create a new disposable wallet? The current one will be archived.')) {
					return;
				}
				createBtn.disabled = true;
				createSay(i18n.creating || 'Creating…');
				ajax('wcll_nwc_create', {}).then(function (res) {
					createBtn.disabled = false;
					var d = (res && res.data) || {};
					if (res && res.success) {
						createSay(i18n.created || 'New disposable wallet created.');
						setBalance(!!d.ok, d.sats || 0);
					} else {
						createSay(d.message || i18n.unavailable || 'Could not create the wallet.', true);
					}
				}).catch(function () {
					createBtn.disabled = false;
					createSay(i18n.unavailable || 'Could not create the wallet.', true);
				});
			});
		}

		// Receive-invoice payment detection: poll lookup_invoice for the generated
		// top-up invoice, re-checking immediately on any wallet notification. On
		// settlement, announce it and close the invoice section.
		var receiveBox = root.querySelector('[data-wcll-nwc-invoice]');
		var receiveForm = root.querySelector('[data-wcll-nwc-form="receive"]');
		var receiveText = root.querySelector('[data-wcll-nwc-invoice-text]');
		var pendingHash = null;
		var pendingPubkey = '';
		var receivePoll = null;
		var receiveTicks = 0;
		var receiveInFlight = false;
		var receiveErrors = 0;

		function stopReceiveWatch() {
			pendingHash = null;
			pendingPubkey = '';
			receiveTicks = 0;
			receiveErrors = 0;
			if (receivePoll) {
				window.clearInterval(receivePoll);
				receivePoll = null;
			}
		}

		function receiveCheckFailed() {
			receiveErrors += 1;
			if (receiveErrors >= 3) {
				stopReceiveWatch();
				say(i18n.unavailable || 'Could not check the payment.', true);
			}
		}

		function checkReceiveInvoice() {
			if (!pendingHash || receiveInFlight) {
				return;
			}
			receiveInFlight = true;
			ajax('wcll_nwc_invoice_status', { payment_hash: pendingHash, wallet_pubkey: pendingPubkey }).then(function (res) {
				receiveInFlight = false;
				var d = (res && res.data) || {};
				if (res && res.success) {
					receiveErrors = 0;
					if (d.settled) {
						stopReceiveWatch();
						if (receiveText) {
							receiveText.value = '';
						}
						if (receiveBox) {
							receiveBox.hidden = true;
						}
						if (receiveForm) {
							receiveForm.hidden = true;
						}
						say(i18n.received || 'Payment received.');
						refreshBalance();
					}
				} else {
					receiveCheckFailed();
				}
			}).catch(function () {
				receiveInFlight = false;
				receiveCheckFailed();
			});
		}

		function startReceiveWatch(hash, pubkey) {
			stopReceiveWatch();
			if (!hash) {
				return;
			}
			pendingHash = hash;
			pendingPubkey = pubkey || '';
			receivePoll = window.setInterval(function () {
				receiveTicks += 1;
				if (receiveTicks > 240) { // ~20 min at 5s; the invoice has expired by then.
					stopReceiveWatch();
					return;
				}
				checkReceiveInvoice();
			}, 5000);
		}

		// Return a form section to its initial state, so toggling it open never
		// shows a stale invoice or a half-filled form.
		function resetSection(which) {
			if (which === 'receive') {
				stopReceiveWatch();
				if (receiveText) {
					receiveText.value = '';
				}
				if (receiveBox) {
					receiveBox.hidden = true;
				}
				var rAmount = root.querySelector('[data-wcll-nwc-amount="receive"]');
				if (rAmount) {
					rAmount.value = '';
				}
			} else if (which === 'withdraw') {
				var dest = root.querySelector('[data-wcll-nwc-destination]');
				if (dest) {
					dest.value = '';
				}
				var wAmount = root.querySelector('[data-wcll-nwc-amount="withdraw"]');
				if (wAmount) {
					wAmount.value = '';
				}
			}
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
						if (receiveText) {
							receiveText.value = d.invoice;
						}
						if (receiveBox) {
							receiveBox.hidden = false;
						}
						say(i18n.waitingPayment || 'Waiting for payment…');
						startReceiveWatch(d.payment_hash || null, d.wallet_pubkey || '');
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

		// Connection-string reveal. The secret is fetched on demand and wiped from
		// the DOM when hidden, so it never sits in the page source.
		var showConnBtn = root.querySelector('[data-wcll-nwc-show-connection]');
		var connBox = root.querySelector('[data-wcll-nwc-connection-box]');
		var connText = root.querySelector('[data-wcll-nwc-connection-text]');
		if (showConnBtn && connBox && connText) {
			showConnBtn.addEventListener('click', function () {
				if (!connBox.hidden) {
					connBox.hidden = true;
					connText.value = '';
					showConnBtn.textContent = i18n.connShow || 'Show connection string';
					say('');
					return;
				}
				showConnBtn.disabled = true;
				say(i18n.txLoading || 'Loading…');
				ajax('wcll_nwc_connection', {}).then(function (res) {
					showConnBtn.disabled = false;
					var d = (res && res.data) || {};
					if (res && res.success && d.uri) {
						connText.value = d.uri;
						connBox.hidden = false;
						showConnBtn.textContent = i18n.connHide || 'Hide connection string';
						say('');
					} else {
						say(d.message || i18n.connError || 'Could not load the connection string.', true);
					}
				}).catch(function () {
					showConnBtn.disabled = false;
					say(i18n.connError || 'Could not load the connection string.', true);
				});
			});
		}

		var connCopyBtn = root.querySelector('[data-wcll-nwc-connection-copy]');
		if (connCopyBtn && connText) {
			connCopyBtn.addEventListener('click', function () {
				if (connText.value && navigator.clipboard) {
					navigator.clipboard.writeText(connText.value).then(function () {
						var original = connCopyBtn.textContent;
						connCopyBtn.textContent = i18n.copied || 'Copied';
						window.setTimeout(function () {
							connCopyBtn.textContent = original;
						}, 1400);
					});
				}
			});
		}

		// A withdraw is confirmed by whichever arrives first: the wallet's NIP-47
		// payment_sent notification (via watchNotifications) or the pay AJAX
		// response. Confirming once clears the form, announces it, and refreshes the
		// balance — so a slow or lost pay response is still reflected when the
		// subscription reports the send.
		var withdrawing = false;
		function confirmWithdrawSent() {
			if (!withdrawing) {
				return;
			}
			withdrawing = false;
			say(i18n.sent);
			var dest = root.querySelector('[data-wcll-nwc-destination]');
			var wAmt = root.querySelector('[data-wcll-nwc-amount="withdraw"]');
			if (dest) {
				dest.value = '';
			}
			if (wAmt) {
				wAmt.value = '';
			}
			refreshBalance();
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
				withdrawing = true;
				say(i18n.sending);
				ajax('wcll_nwc_withdraw', { destination: destination, amount: amount > 0 ? amount : 0 }).then(function (res) {
					sendBtn.disabled = false;
					var d = (res && res.data) || {};
					if (res && res.success) {
						confirmWithdrawSent();
					} else if (withdrawing) {
						// Only surface the error if a notification hasn't already
						// confirmed the send (a lost-but-settled pay response).
						withdrawing = false;
						say(d.message || i18n.unavailable, true);
					}
				}).catch(function () {
					sendBtn.disabled = false;
					if (withdrawing) {
						withdrawing = false;
						say(i18n.unavailable, true);
					}
				});
			});
		}

		// Pressing Enter in a wallet input runs its action (Generate/Send) instead of
		// submitting the surrounding WooCommerce settings form.
		function enterRuns(input, buttonSelector) {
			if (!input) {
				return;
			}
			input.addEventListener('keydown', function (event) {
				if (event.key !== 'Enter') {
					return;
				}
				event.preventDefault();
				var btn = root.querySelector(buttonSelector);
				if (btn && !btn.disabled) {
					btn.click();
				}
			});
		}
		enterRuns(root.querySelector('[data-wcll-nwc-amount="receive"]'), '[data-wcll-nwc-generate]');
		enterRuns(root.querySelector('[data-wcll-nwc-destination]'), '[data-wcll-nwc-send]');
		enterRuns(root.querySelector('[data-wcll-nwc-amount="withdraw"]'), '[data-wcll-nwc-send]');

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
						checkReceiveInvoice();
						confirmWithdrawSent();
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

// Show a "Regenerate NWC connection" button when the lncurl service URL changes,
// and mint a fresh disposable wallet from the new service on click.
(function (config) {
	function init() {
		var nwc = config && config.nwc;
		var field = document.getElementById('woocommerce_wcll_gateway_nwc_lncurl_url');
		var row = document.querySelector('[data-wcll-nwc-regenerate-row]');
		var btn = document.querySelector('[data-wcll-nwc-regenerate]');
		var feedback = document.querySelector('[data-wcll-nwc-regenerate-feedback]');
		if (!nwc || !field || !row || !btn) {
			return;
		}

		var i18n = nwc.i18n || {};
		var saved = field.value.trim();

		function say(message, isError) {
			if (feedback) {
				feedback.textContent = message || '';
				feedback.classList.toggle('is-error', !!isError);
			}
		}

		field.addEventListener('input', function () {
			var value = field.value.trim();
			row.hidden = value === '' || value === saved;
			say('');
		});

		btn.addEventListener('click', function () {
			var url = field.value.trim();
			if (!url) {
				return;
			}
			btn.disabled = true;
			say(i18n.regenerating || 'Regenerating…');

			var body = new URLSearchParams();
			body.set('action', 'wcll_nwc_regenerate');
			body.set('nonce', nwc.nonce);
			body.set('lncurl_url', url);

			window.fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			}).then(function (response) {
				return response.json();
			}).then(function (res) {
				btn.disabled = false;
				var d = (res && res.data) || {};
				if (res && res.success) {
					saved = url;
					row.hidden = true;
					say(i18n.regenerated || 'Connection regenerated.');
					var balanceEl = document.querySelector('[data-wcll-nwc-balance]');
					if (balanceEl) {
						balanceEl.textContent = (d.ok ? Number(d.sats).toLocaleString() : '0') + ' ' + (i18n.sats || 'sats');
					}
				} else {
					say(d.message || i18n.unavailable, true);
				}
			}).catch(function () {
				btn.disabled = false;
				say(i18n.unavailable, true);
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window.WCLLGatewayAdmin || {});

// Proxy transactions: "Show all" modal with AJAX pagination, plus copy-proof.
(function (config) {
	function init() {
		var nwc = config && config.nwc;
		var i18n = (nwc && nwc.i18n) || {};

		// Copy a proof preimage from any row (inline list or modal), delegated.
		document.addEventListener('click', function (event) {
			var btn = event.target && event.target.closest ? event.target.closest('[data-wcll-tx-copy]') : null;
			if (!btn) {
				return;
			}
			var value = btn.getAttribute('data-wcll-tx-copy');
			if (value && navigator.clipboard) {
				navigator.clipboard.writeText(value).then(function () {
					var original = btn.textContent;
					btn.textContent = i18n.copied || 'Copied';
					window.setTimeout(function () {
						btn.textContent = original;
					}, 1200);
				});
			}
		});

		var modal = document.querySelector('[data-wcll-tx-modal]');
		var opener = document.querySelector('[data-wcll-tx-open]');
		if (!nwc || !modal || !opener) {
			return;
		}

		var body = modal.querySelector('[data-wcll-tx-body]');
		var pageinfo = modal.querySelector('[data-wcll-tx-pageinfo]');
		var prev = modal.querySelector('[data-wcll-tx-prev]');
		var next = modal.querySelector('[data-wcll-tx-next]');
		var page = 1;
		var pages = 1;
		var loading = false;

		function updatePager() {
			if (pageinfo) {
				pageinfo.textContent = (i18n.txPage || 'Page %1$s of %2$s').replace('%1$s', page).replace('%2$s', pages);
			}
			if (prev) {
				prev.disabled = page <= 1;
			}
			if (next) {
				next.disabled = page >= pages;
			}
		}

		function load(target) {
			if (loading) {
				return;
			}
			loading = true;
			if (pageinfo) {
				pageinfo.textContent = i18n.txLoading || '…';
			}
			var b = new URLSearchParams();
			b.set('action', 'wcll_nwc_transactions');
			b.set('nonce', nwc.nonce);
			b.set('page', target);
			window.fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: b.toString()
			}).then(function (response) {
				return response.json();
			}).then(function (res) {
				loading = false;
				var d = (res && res.data) || {};
				if (res && res.success) {
					page = d.page || target;
					pages = d.pages || 1;
					if (body) {
						body.innerHTML = d.rows || '';
					}
					updatePager();
				}
			}).catch(function () {
				loading = false;
			});
		}

		function open() {
			modal.hidden = false;
			document.body.classList.add('wcll-tx-open');
			load(1);
		}
		function close() {
			modal.hidden = true;
			document.body.classList.remove('wcll-tx-open');
		}

		opener.addEventListener('click', function (event) {
			event.preventDefault();
			open();
		});
		modal.querySelectorAll('[data-wcll-tx-close]').forEach(function (el) {
			el.addEventListener('click', function (event) {
				event.preventDefault();
				close();
			});
		});
		if (prev) {
			prev.addEventListener('click', function () {
				if (page > 1) {
					load(page - 1);
				}
			});
		}
		if (next) {
			next.addEventListener('click', function () {
				if (page < pages) {
					load(page + 1);
				}
			});
		}
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && !modal.hidden) {
				close();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window.WCLLGatewayAdmin || {});

// Transaction detail modal: "Show" opens the received + sent invoice details.
(function (config) {
	function init() {
		var nwc = config && config.nwc;
		var modal = document.querySelector('[data-wcll-txd-modal]');
		if (!nwc || !modal) {
			return;
		}
		var body = modal.querySelector('[data-wcll-txd-body]');
		var i18n = nwc.i18n || {};
		var loading = false;

		function open() {
			modal.hidden = false;
			document.body.classList.add('wcll-tx-open');
		}
		function close() {
			modal.hidden = true;
			document.body.classList.remove('wcll-tx-open');
		}

		document.addEventListener('click', function (event) {
			var btn = event.target && event.target.closest ? event.target.closest('[data-wcll-tx-show]') : null;
			if (!btn) {
				return;
			}
			event.preventDefault();
			var orderId = btn.getAttribute('data-wcll-tx-show');
			if (!orderId || loading) {
				return;
			}
			loading = true;
			if (body) {
				body.textContent = i18n.txLoading || '…';
			}
			open();
			var b = new URLSearchParams();
			b.set('action', 'wcll_nwc_transaction');
			b.set('nonce', nwc.nonce);
			b.set('order_id', orderId);
			window.fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: b.toString()
			}).then(function (response) {
				return response.json();
			}).then(function (res) {
				loading = false;
				var d = (res && res.data) || {};
				if (res && res.success && body) {
					body.innerHTML = d.html || '';
				} else if (body) {
					body.textContent = d.message || '';
				}
			}).catch(function () {
				loading = false;
				if (body) {
					body.textContent = '';
				}
			});
		});

		modal.querySelectorAll('[data-wcll-txd-close]').forEach(function (el) {
			el.addEventListener('click', function (event) {
				event.preventDefault();
				close();
			});
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && !modal.hidden) {
				close();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window.WCLLGatewayAdmin || {});
