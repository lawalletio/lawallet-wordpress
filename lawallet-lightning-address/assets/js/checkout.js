(function () {
	'use strict';

	function ready(callback) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback);
			return;
		}
		callback();
	}

	function findTag(event, name) {
		if (!event || !Array.isArray(event.tags)) {
			return null;
		}
		return event.tags.find(function (tag) {
			return Array.isArray(tag) && tag[0] === name;
		});
	}

	ready(function () {
		var config = window.WCLLPayment;
		var root = document.querySelector('.wcll-payment');
		if (!config || !root) {
			return;
		}

		var qrTarget = root.querySelector('[data-wcll-qr]');
		var paidOverlay = root.querySelector('[data-wcll-paid-overlay]');
		var statusText = root.querySelector('[data-wcll-status-text]');
		var countdown = root.querySelector('[data-wcll-countdown]');
		var copyButton = root.querySelector('[data-wcll-copy]');
		var webLnButton = root.querySelector('[data-wcll-webln]');
		var invoiceField = root.querySelector('[data-wcll-invoice]');
		var pollTimer = null;
		var countdownTimer = null;
		var claimInFlight = false;
		var terminal = false;

		function setStatus(status) {
			root.classList.toggle('is-paid', status === 'paid');
			root.classList.toggle('is-expired', status === 'expired');
			if (paidOverlay) {
				paidOverlay.hidden = status !== 'paid';
			}
			if (statusText && config.i18n[status]) {
				statusText.textContent = config.i18n[status];
			}
		}

		function renderQr() {
			if (!qrTarget || typeof QRCode === 'undefined') {
				return;
			}
			qrTarget.textContent = '';
			new QRCode(qrTarget, {
				text: 'lightning:' + config.invoice,
				width: 260,
				height: 260,
				correctLevel: QRCode.CorrectLevel.M
			});
		}

		function stopTimers() {
			if (pollTimer) {
				window.clearInterval(pollTimer);
				pollTimer = null;
			}
			if (countdownTimer) {
				window.clearInterval(countdownTimer);
				countdownTimer = null;
			}
		}

		function normalizeUrl(url) {
			try {
				var parsed = new URL(url, window.location.href);
				parsed.hash = '';
				return parsed.href;
			} catch (error) {
				return url || '';
			}
		}

		function redirectToReturnUrl(url) {
			var target = url || config.returnUrl;
			if (!target || normalizeUrl(target) === normalizeUrl(window.location.href)) {
				return;
			}

			window.location.assign(target);
		}

		function claim(reason) {
			if (claimInFlight || terminal) {
				return Promise.resolve({ status: terminal ? 'paid' : 'pending' });
			}

			claimInFlight = true;
			setStatus(reason === 'nostr' || reason === 'webln' ? 'checking' : 'waiting');

			var body = new URLSearchParams();
			body.set('action', 'wcll_claim_payment');
			body.set('order_id', config.orderId);
			body.set('order_key', config.orderKey);
			body.set('nonce', config.nonce);

			return window.fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: body.toString()
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (payload) {
					var data = payload && payload.data ? payload.data : {};
					if (payload && payload.success && data.paid) {
						terminal = true;
						setStatus('paid');
						stopTimers();
						window.setTimeout(function () {
							redirectToReturnUrl(data.returnUrl);
						}, 1800);
						return;
					}

					if (payload && payload.success && data.expired) {
						terminal = true;
						setStatus('expired');
						stopTimers();
						return;
					}

					setStatus('waiting');
					return data;
				})
				.catch(function () {
					setStatus('waiting');
					return { status: 'pending' };
				})
				.finally(function () {
					claimInFlight = false;
				});
		}

		function hasWebLN() {
			return !!(window.webln && typeof window.webln.sendPayment === 'function');
		}

		function showWebLNButton() {
			if (!webLnButton || !hasWebLN()) {
				return false;
			}

			webLnButton.hidden = false;
			webLnButton.textContent = config.i18n.payWebln || 'Pay with WebLN';
			root.classList.add('has-webln');
			return true;
		}

		function setupWebLN() {
			if (!webLnButton) {
				return;
			}

			var attempts = 0;
			var detectionTimer = null;

			function detect() {
				attempts += 1;
				if (showWebLNButton() || attempts >= 20) {
					if (detectionTimer) {
						window.clearInterval(detectionTimer);
					}
				}
			}

			detect();
			if (webLnButton.hidden) {
				detectionTimer = window.setInterval(detect, 250);
			}

			webLnButton.addEventListener('click', function () {
				if (!hasWebLN() || terminal || webLnButton.disabled) {
					return;
				}

				webLnButton.disabled = true;
				webLnButton.textContent = config.i18n.webLnPaying || 'Opening WebLN';
				setStatus('checking');

				Promise.resolve()
					.then(function () {
						if (typeof window.webln.enable === 'function') {
							return window.webln.enable();
						}
						return null;
					})
					.then(function () {
						return window.webln.sendPayment(config.invoice);
					})
					.then(function () {
						webLnButton.textContent = config.i18n.webLnChecking || 'Checking payment';
						return claim('webln');
					})
					.catch(function () {
						if (!terminal) {
							webLnButton.disabled = false;
							webLnButton.textContent = config.i18n.payWebln || 'Pay with WebLN';
							setStatus('waiting');
						}
					});
			});
		}

		function startCountdown() {
			if (!countdown || !config.expiresAt) {
				return;
			}

			function tick() {
				var remaining = Math.max(0, (config.expiresAt * 1000) - Date.now());
				var seconds = Math.floor(remaining / 1000);
				var minutes = Math.floor(seconds / 60);
				var rest = seconds % 60;
				countdown.textContent = minutes + ':' + String(rest).padStart(2, '0');
				if (seconds <= 0 && !terminal) {
					claim('expired');
				}
			}

			tick();
			countdownTimer = window.setInterval(tick, 1000);
		}

		function watchNostr() {
			if (!config.nostrPubkey || !Array.isArray(config.nostrRelays) || !config.nostrRelays.length) {
				return;
			}

			config.nostrRelays.forEach(function (relay) {
				var socket;
				try {
					socket = new WebSocket(relay);
				} catch (error) {
					return;
				}

				socket.addEventListener('open', function () {
					var filter = {
						kinds: [9735],
						authors: [config.nostrPubkey],
						since: Math.floor(Date.now() / 1000) - 600
					};
					socket.send(JSON.stringify(['REQ', 'wcll-' + config.orderId, filter]));
				});

				socket.addEventListener('message', function (message) {
					var parsed;
					try {
						parsed = JSON.parse(message.data);
					} catch (error) {
						return;
					}

					if (!Array.isArray(parsed) || parsed[0] !== 'EVENT') {
						return;
					}

					var event = parsed[2];
					var bolt11 = findTag(event, 'bolt11');
					if (event && event.kind === 9735 && event.pubkey === config.nostrPubkey && bolt11 && bolt11[1] === config.invoice) {
						claim('nostr');
					}
				});
			});
		}

		if (copyButton && invoiceField) {
			copyButton.addEventListener('click', function () {
				var original = copyButton.textContent;
				navigator.clipboard.writeText(config.invoice).then(function () {
					copyButton.textContent = config.i18n.copied || 'Copied';
					window.setTimeout(function () {
						copyButton.textContent = original;
					}, 1400);
				});
			});
		}

		renderQr();
		if (config.isPaid) {
			terminal = true;
			setStatus('paid');
			window.setTimeout(function () {
				redirectToReturnUrl(config.returnUrl);
			}, 1800);
			return;
		}

		setStatus('waiting');
		setupWebLN();
		startCountdown();
		watchNostr();
		claim('load');
		pollTimer = window.setInterval(function () {
			claim('poll');
		}, 4000);
	});
})();
