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
		var openWalletLink = root.querySelector('.wcll-payment__open');
		var actionButtons = root.querySelectorAll('.wcll-payment__action');
		var invoiceField = root.querySelector('[data-wcll-invoice]');
		var pollTimer = null;
		var countdownTimer = null;
		var claimInFlight = false;
		var pendingSignal = false;
		var terminal = false;
		var recreating = false;
		var sockets = [];

		function setActionsDisabled(disabled) {
			actionButtons.forEach(function (button) {
				if (disabled) {
					button.setAttribute('aria-disabled', 'true');
					button.classList.add('is-disabled');
					if (button.tagName.toLowerCase() === 'a') {
						if (button.hasAttribute('href')) {
							button.dataset.wcllHref = button.getAttribute('href');
						}
						button.removeAttribute('href');
					} else {
						button.disabled = true;
					}
					return;
				}

				button.removeAttribute('aria-disabled');
				button.classList.remove('is-disabled');
				if (button.tagName.toLowerCase() === 'a') {
					if (button.dataset.wcllHref) {
						button.setAttribute('href', button.dataset.wcllHref);
					}
				} else {
					button.disabled = false;
				}
			});
		}

		function setStatus(status) {
			root.classList.toggle('is-paid', status === 'paid');
			root.classList.toggle('is-expired', status === 'expired');
			setActionsDisabled(status === 'paid' || status === 'expired');
			if (status === 'paid' || status === 'expired') {
				actionButtons.forEach(function (button) {
					button.classList.remove('is-loading');
				});
			}
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
			var isSettlementSignal = reason === 'nostr' || reason === 'nwc' || reason === 'webln';
			if (terminal) {
				return Promise.resolve({ status: 'paid' });
			}
			if (claimInFlight) {
				// A lookup is already running. Polls are best-effort (the next poll
				// is seconds away), but a settlement *signal* (NWC/nostr/WebLN
				// notification) must never be dropped: queue a re-check to run as
				// soon as the in-flight lookup resolves, so the payment that the
				// signal announced is confirmed on a fresh backend lookup.
				if (isSettlementSignal) {
					pendingSignal = true;
				}
				return Promise.resolve({ status: 'pending' });
			}

			claimInFlight = true;
			setStatus(isSettlementSignal ? 'checking' : 'waiting');

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
						handleExpiry();
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
					// Run the re-check queued by a settlement signal that arrived
					// while this lookup was in flight (drops to a single follow-up).
					if (pendingSignal && !terminal) {
						pendingSignal = false;
						claim('nwc');
					}
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
			webLnButton.classList.remove('is-loading');
			webLnButton.textContent = config.i18n.payWebln || 'Pay with WebLN';
			if (openWalletLink) {
				openWalletLink.hidden = true;
			}
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
				webLnButton.classList.add('is-loading');
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
							webLnButton.classList.remove('is-loading');
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
				if (seconds <= 0 && !terminal && !recreating) {
					handleExpiry();
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

				sockets.push(socket);

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

		function watchNwc() {
			if (!config.nwcWalletPubkey || !Array.isArray(config.nwcRelays) || !config.nwcRelays.length) {
				return;
			}

			config.nwcRelays.forEach(function (relay) {
				var socket;
				try {
					socket = new WebSocket(relay);
				} catch (error) {
					return;
				}

				sockets.push(socket);

				socket.addEventListener('open', function () {
					var filter = {
						kinds: [23196, 23197],
						authors: [config.nwcWalletPubkey],
						since: Math.floor(Date.now() / 1000) - 600
					};
					if (config.nwcClientPubkey) {
						filter['#p'] = [config.nwcClientPubkey];
					}
					socket.send(JSON.stringify(['REQ', 'wcll-nwc-' + config.orderId, filter]));
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

					// Trigger-only: any matching wallet notification prompts a backend
					// claim, which confirms settlement with lookup_invoice. The payload
					// is encrypted and is never decrypted in the browser.
					var event = parsed[2];
					if (event && (event.kind === 23196 || event.kind === 23197) && event.pubkey === config.nwcWalletPubkey) {
						claim('nwc');
					}
				});
			});
		}

		function closeSockets() {
			sockets.forEach(function (socket) {
				try {
					socket.close();
				} catch (error) {
					// Already closing/closed.
				}
			});
			sockets = [];
		}

		// Swap the page over to a freshly issued invoice: new QR, invoice text,
		// countdown and re-subscribed settlement watchers, then resume polling.
		function applyNewInvoice(data) {
			config.invoice = data.invoice;
			config.expiresAt = data.expiresAt;
			config.nostrPubkey = data.nostrPubkey || '';
			config.nostrRelays = Array.isArray(data.nostrRelays) ? data.nostrRelays : [];
			config.nwcWalletPubkey = data.nwcWalletPubkey || '';
			config.nwcClientPubkey = data.nwcClientPubkey || '';
			config.nwcRelays = Array.isArray(data.nwcRelays) ? data.nwcRelays : [];
			if (data.returnUrl) {
				config.returnUrl = data.returnUrl;
			}

			terminal = false;
			claimInFlight = false;
			pendingSignal = false;

			renderQr();
			if (invoiceField) {
				invoiceField.value = config.invoice;
			}

			setStatus('waiting');
			if (openWalletLink) {
				var lightningHref = 'lightning:' + config.invoice;
				openWalletLink.setAttribute('href', lightningHref);
				openWalletLink.dataset.wcllHref = lightningHref;
			}

			closeSockets();
			watchNostr();
			watchNwc();

			stopTimers();
			startCountdown();
			pollTimer = window.setInterval(function () {
				claim('poll');
			}, 4000);
			claim('load');
		}

		// Ask the backend to re-issue the invoice. It verifies the expired invoice
		// was not actually paid before minting a new one.
		function recreate() {
			var body = new URLSearchParams();
			body.set('action', 'wcll_recreate_invoice');
			body.set('order_id', config.orderId);
			body.set('order_key', config.orderKey);
			body.set('nonce', config.nonce);

			window.fetch(config.ajaxUrl, {
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
					if (payload && payload.success && data.invoice) {
						applyNewInvoice(data);
						return;
					}
					// Could not re-issue: show the expired state.
					terminal = true;
					setStatus('expired');
				})
				.catch(function () {
					terminal = true;
					setStatus('expired');
				})
				.finally(function () {
					recreating = false;
				});
		}

		// Invoice expired (countdown reached zero, or the backend reported it):
		// verify settlement and re-issue a fresh invoice instead of giving up.
		function handleExpiry() {
			if (recreating || terminal) {
				return;
			}
			recreating = true;
			stopTimers();
			setStatus('checking');
			recreate();
		}

		if (copyButton && invoiceField) {
			copyButton.addEventListener('click', function () {
				if (terminal || copyButton.disabled) {
					return;
				}

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
		watchNwc();
		claim('load');
		pollTimer = window.setInterval(function () {
			claim('poll');
		}, 4000);
	});
})();
