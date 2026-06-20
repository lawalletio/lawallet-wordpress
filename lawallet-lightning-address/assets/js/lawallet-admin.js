(function (config) {
	document.querySelectorAll('[data-lawallet-submitting-text]').forEach(function (button) {
		if (!button.form) {
			return;
		}

		button.form.addEventListener('submit', function (event) {
			var submitter = event.submitter || button;
			if (submitter !== button || button.classList.contains('is-loading')) {
				return;
			}

			if (button.name) {
				var actionInput = document.createElement('input');
				actionInput.type = 'hidden';
				actionInput.name = button.name;
				actionInput.value = button.value || '1';
				button.form.appendChild(actionInput);
			}

			if (button.hasAttribute('data-lawallet-connect-button')) {
				var endpointInput = button.form.querySelector('[data-lawallet-endpoint-input]');
				if (endpointInput && endpointInput.name && !endpointInput.disabled) {
					var endpointValueInput = document.createElement('input');
					endpointValueInput.type = 'hidden';
					endpointValueInput.name = endpointInput.name;
					endpointValueInput.value = endpointInput.value;
					button.form.appendChild(endpointValueInput);
					endpointInput.disabled = true;
					endpointInput.setAttribute('aria-busy', 'true');
				}
			}

			button.classList.add('is-loading');
			button.setAttribute('aria-busy', 'true');
			button.textContent = button.getAttribute('data-lawallet-submitting-text') || button.textContent;
			button.disabled = true;
		});
	});

	var input = document.querySelector('[data-lawallet-endpoint-input]');
	var status = document.querySelector('[data-lawallet-endpoint-status]');
	var statusText = document.querySelector('[data-lawallet-endpoint-status-text]');
	var card = document.querySelector('[data-lawallet-instance-card]');
	var cover = document.querySelector('[data-lawallet-instance-cover]');
	var avatar = document.querySelector('[data-lawallet-instance-avatar]');
	var name = document.querySelector('[data-lawallet-instance-name]');
	var meta = document.querySelector('[data-lawallet-instance-meta]');
	var details = document.querySelector('[data-lawallet-instance-details]');
	var socials = document.querySelector('[data-lawallet-instance-socials]');
	var connectButton = document.querySelector('[data-lawallet-connect-button]');
	var domainWarning = document.querySelector('[data-lawallet-domain-warning]');
	var domainWarningText = document.querySelector('[data-lawallet-domain-warning-text]');
	if (!input || !status) {
		return;
	}

	var timer = null;
	var requestId = 0;
	var icon = status.querySelector('.dashicons');
	var icons = {
		pending: 'dashicons-minus',
		loading: 'dashicons-update',
		ready: 'dashicons-yes-alt',
		error: 'dashicons-warning'
	};

	function setState(state, message) {
		status.className = 'lawallet-endpoint-check is-' + state;
		status.title = message || config.i18n[state] || '';
		if (connectButton) {
			connectButton.disabled = state !== 'ready';
		}
		if (statusText) {
			statusText.textContent = status.title;
		}
		if (icon) {
			icon.className = 'dashicons ' + (icons[state] || icons.pending);
		}
	}

	function clearNode(node) {
		while (node && node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function setEmptyInstance() {
		if (card) {
			card.classList.add('is-empty');
		}
		if (cover) {
			cover.removeAttribute('style');
		}
		if (avatar) {
			avatar.removeAttribute('style');
			avatar.classList.add('is-empty');
			avatar.textContent = '';
		}
		if (name) {
			name.textContent = config.i18n.instanceEmptyTitle;
		}
		if (meta) {
			meta.textContent = config.i18n.instanceEmptyMeta;
		}
		clearNode(details);
		clearNode(socials);
		if (domainWarning) {
			domainWarning.hidden = true;
		}
	}

	function updateDomainWarning(domain) {
		if (!domainWarning) {
			return;
		}
		var site = String(config.siteDomain || '').toLowerCase().replace(/^www\./, '');
		var gateway = String(domain || '').toLowerCase().replace(/^www\./, '');
		if (gateway && site && gateway !== site) {
			if (domainWarningText) {
				domainWarningText.textContent = String(config.i18n.domainMismatch || '')
					.replace('%1$s', domain)
					.replace('%2$s', config.siteDomain);
			}
			domainWarning.hidden = false;
		} else {
			domainWarning.hidden = true;
		}
	}

	function renderInstance(instance) {
		if (!instance || (!instance.name && !instance.domain && !instance.endpoint)) {
			setEmptyInstance();
			return;
		}
		if (card) {
			card.classList.remove('is-empty');
		}
		if (cover) {
			cover.style.backgroundColor = instance.theme || '#111827';
			cover.style.backgroundImage = instance.cover ? "url('" + String(instance.cover).replace(/'/g, '%27') + "')" : '';
		}
		if (avatar) {
			if (instance.avatar) {
				avatar.style.backgroundImage = "url('" + String(instance.avatar).replace(/'/g, '%27') + "')";
				avatar.classList.remove('is-empty');
				avatar.textContent = '';
			} else {
				avatar.removeAttribute('style');
				avatar.classList.add('is-empty');
				avatar.textContent = instance.initials || '';
			}
		}
		if (name) {
			name.textContent = instance.name || instance.domain || config.i18n.instanceReadyTitle;
		}
		if (meta) {
			meta.textContent = [instance.domain, instance.endpoint].filter(Boolean).join(' · ');
		}
		updateDomainWarning(instance.domain);
		clearNode(details);
		(instance.details || []).forEach(function (detail) {
			var pill = document.createElement('span');
			var label = document.createElement('strong');
			var value = document.createElement('span');
			pill.className = 'lawallet-detail-pill';
			label.textContent = detail.label || '';
			value.textContent = detail.value || '';
			pill.appendChild(label);
			pill.appendChild(value);
			if (details) {
				details.appendChild(pill);
			}
		});
		clearNode(socials);
		(instance.socials || []).forEach(function (social) {
			var item = social.url ? document.createElement('a') : document.createElement('span');
			item.textContent = social.label || '';
			if (social.url) {
				item.href = social.url;
				item.target = '_blank';
				item.rel = 'noopener noreferrer';
			}
			if (socials) {
				socials.appendChild(item);
			}
		});
	}

	function checkEndpoint(endpoint) {
		var currentRequest = ++requestId;
		var body = new URLSearchParams();
		body.set('action', 'lawallet_check_gateway_endpoint');
		body.set('nonce', config.nonce);
		body.set('endpoint', endpoint);
		setState('loading', config.i18n.loading);

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
				if (currentRequest !== requestId) {
					return;
				}
				if (payload && payload.success && data.ok) {
					setState('ready', data.message || config.i18n.ready);
					renderInstance(data.instance);
					return;
				}
				setEmptyInstance();
				setState('error', data.message || config.i18n.error);
			})
			.catch(function () {
				if (currentRequest === requestId) {
					setEmptyInstance();
					setState('error', config.i18n.error);
				}
			});
	}

	input.addEventListener('input', function () {
		window.clearTimeout(timer);
		requestId += 1;
		setState('pending', config.i18n.pending);
		if (!input.value.trim()) {
			setEmptyInstance();
			return;
		}
		timer = window.setTimeout(function () {
			checkEndpoint(input.value.trim());
		}, 600);
	});

	if (input.value.trim()) {
		checkEndpoint(input.value.trim());
	} else {
		setEmptyInstance();
	}
})(window.WCLLDiscovery || {});
