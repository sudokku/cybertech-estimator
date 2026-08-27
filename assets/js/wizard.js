/**
 * Cybertech Project Estimator — visitor wizard.
 *
 * Vanilla ES2020, no bundler, no jQuery. Progressive enhancement over the
 * server-rendered form: this file only shows one screen at a time,
 * validates, talks to the REST API and renders what the server returns.
 * Nothing is priced on the client.
 */
(function () {
	'use strict';

	const cfg = window.ctEstimator;
	if (!cfg || !cfg.steps) {
		return;
	}

	const S = cfg.strings || {};
	const REDUCED_MOTION = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* ---------- helpers ---------- */

	function sprintf(template, ...args) {
		let i = 0;
		return String(template || '').replace(/%(\d+\$)?[sd]/g, (match, pos) => {
			const index = pos ? parseInt(pos, 10) - 1 : i++;
			return args[index] === undefined ? '' : String(args[index]);
		});
	}

	function randomId(length) {
		const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		const bytes = new Uint8Array(length);
		if (window.crypto && window.crypto.getRandomValues) {
			window.crypto.getRandomValues(bytes);
		} else {
			for (let i = 0; i < length; i++) {
				bytes[i] = Math.floor(Math.random() * 256);
			}
		}
		let out = '';
		for (let i = 0; i < length; i++) {
			out += alphabet[bytes[i] % alphabet.length];
		}
		return out;
	}

	function ensureSessionCookie() {
		const name = cfg.cookieName || 'ct_est_sid';
		const has = document.cookie.split(';').some((c) => c.trim().startsWith(name + '='));
		if (has) {
			return;
		}
		const secure = window.location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = name + '=' + randomId(32) + '; path=/; max-age=' + 60 * 60 * 24 + '; SameSite=Lax' + secure;
	}

	async function request(url, options) {
		const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
		if (cfg.loggedIn && cfg.nonce) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}
		const response = await fetch(url, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers,
			body: options.body ? JSON.stringify(options.body) : undefined,
		});
		let json = null;
		try {
			json = await response.json();
		} catch (e) {
			json = null;
		}
		return { status: response.status, ok: response.ok, json };
	}

	function isVisible(question, answers) {
		const cond = question.show_if || {};
		return Object.keys(cond).every((dep) => {
			const allowed = Array.isArray(cond[dep]) ? cond[dep] : [cond[dep]];
			return allowed.includes(answers[dep]);
		});
	}

	/* ---------- wizard ---------- */

	class Wizard {
		constructor(root) {
			this.root = root;
			this.form = root.querySelector('[data-ct-form]');
			if (!this.form) {
				return;
			}
			this.mode = root.getAttribute('data-ct-mode') || cfg.mode || 'gated';
			this.prefilter = root.getAttribute('data-ct-service') || cfg.servicePrefilter || '';
			this.live = root.querySelector('[data-ct-live]');
			this.formError = root.querySelector('[data-ct-form-error]');
			this.progress = root.querySelector('[data-ct-progress]');
			this.progressText = root.querySelector('[data-ct-progress-text]');
			this.progressBar = root.querySelector('[data-ct-progress-bar]');
			this.progressFill = root.querySelector('[data-ct-progress-fill]');
			this.nav = root.querySelector('[data-ct-nav]');
			this.btnBack = root.querySelector('[data-ct-back]');
			this.btnNext = root.querySelector('[data-ct-next]');
			this.btnSubmit = root.querySelector('[data-ct-submit]');
			this.honeypot = root.querySelector('[data-ct-honeypot]');
			this.tokenInput = root.querySelector('[data-ct-token]');

			this.questions = {};
			this.stepQuestions = {};
			cfg.steps.forEach((step) => {
				this.stepQuestions[step.id] = step.questions.map((q) => q.id);
				step.questions.forEach((q) => {
					this.questions[q.id] = q;
				});
			});

			this.screens = Array.from(root.querySelectorAll('[data-ct-screen]')).map((el, index) => ({
				el,
				index,
				type: el.getAttribute('data-ct-screen'),
				stepId: el.getAttribute('data-ct-step') || (el.getAttribute('data-ct-screen') === 'gate' ? 'contact' : ''),
				heading: el.querySelector('[data-ct-heading]'),
			}));

			this.index = -1;
			this.done = false;
			this.inflight = false;
			this.previewTimer = 0;
			this.previewKey = '';
			this.previewPromise = null;
			this.lastPreview = null;
			this.previewBlocked = false;

			this.init();
		}

		init() {
			ensureSessionCookie();
			this.refreshToken();
			this.applyVisibility();
			this.bind();

			const first = this.firstIndex();
			const state = window.history.state;
			const wanted = state && typeof state.ctEst === 'number' ? state.ctEst : first;
			this.goTo(Math.min(wanted, first), { push: false, replace: true, focus: false });
			if (this.prefilter) {
				this.schedulePreview(true);
			}
		}

		/* --- bootstrap --- */

		async refreshToken() {
			try {
				const res = await request(cfg.endpoints.token, { method: 'GET' });
				if (res.ok && res.json && res.json.token && this.tokenInput) {
					this.tokenInput.value = res.json.token;
				}
			} catch (e) {
				// Keep the server-rendered token.
			}
		}

		bind() {
			this.form.addEventListener('submit', (event) => {
				event.preventDefault();
				if (this.isContactScreen(this.current())) {
					this.submit();
				} else {
					this.next();
				}
			});

			this.form.addEventListener('keydown', (event) => {
				if (event.key !== 'Enter') {
					return;
				}
				const target = event.target;
				if (!(target instanceof HTMLInputElement)) {
					return;
				}
				// Enter in a radio/checkbox/text input advances instead of submitting early.
				event.preventDefault();
				if (this.isContactScreen(this.current())) {
					this.submit();
				} else {
					this.next();
				}
			});

			this.form.addEventListener('change', (event) => this.onChange(event));
			this.form.addEventListener('input', (event) => {
				const target = event.target;
				if (target instanceof HTMLTextAreaElement) {
					this.updateCounter(target);
				}
				const q = target.closest && target.closest('[data-ct-question]');
				if (q && q.classList.contains('is-invalid')) {
					this.clearError(q.getAttribute('data-ct-question'));
				}
			});

			this.btnBack.addEventListener('click', () => this.back());
			this.btnNext.addEventListener('click', () => this.next());

			const copy = this.root.querySelector('[data-ct-copy]');
			if (copy) {
				copy.addEventListener('click', () => this.copyShareUrl());
			}

			window.addEventListener('popstate', (event) => {
				const state = event.state;
				if (!state || typeof state.ctEst !== 'number') {
					return;
				}
				if (this.done) {
					this.goTo(this.finalIndex(), { push: false, replace: true });
					return;
				}
				this.goTo(Math.min(state.ctEst, this.maxReachable()), { push: false });
			});

			this.root.querySelectorAll('textarea').forEach((t) => this.updateCounter(t));
		}

		onChange(event) {
			const target = event.target;
			const wrapper = target.closest && target.closest('[data-ct-question]');
			if (!wrapper) {
				return;
			}
			const id = wrapper.getAttribute('data-ct-question');
			this.clearError(id);
			this.applyVisibility();
			const question = this.questions[id];
			if (question && !question.contact) {
				this.schedulePreview();
			}
		}

		/* --- answers & visibility --- */

		control(id) {
			return this.form.querySelector('[data-ct-question="' + id + '"]');
		}

		readAnswers() {
			const pricing = {};
			const contact = {};
			Object.values(this.questions).forEach((q) => {
				const wrapper = this.control(q.id);
				if (!wrapper) {
					return;
				}
				let value;
				switch (q.type) {
					case 'single': {
						const checked = wrapper.querySelector('input[type="radio"]:checked');
						value = checked ? checked.value : undefined;
						break;
					}
					case 'multi': {
						const list = Array.from(wrapper.querySelectorAll('input[type="checkbox"]:checked')).map((i) => i.value);
						value = list.length ? list : undefined;
						break;
					}
					case 'number': {
						const input = wrapper.querySelector('input');
						value = input && input.value.trim() !== '' && !Number.isNaN(Number(input.value)) ? Number(input.value) : undefined;
						break;
					}
					case 'checkbox': {
						const input = wrapper.querySelector('input');
						value = input && input.checked ? true : undefined;
						break;
					}
					default: {
						const input = wrapper.querySelector('input, textarea');
						value = input && input.value.trim() !== '' ? input.value.trim() : undefined;
					}
				}
				if (value === undefined) {
					return;
				}
				if (q.contact) {
					contact[q.id] = value;
				} else {
					pricing[q.id] = value;
				}
			});
			return { pricing, contact };
		}

		visiblePricing() {
			const { pricing } = this.readAnswers();
			const out = {};
			Object.keys(pricing).forEach((id) => {
				if (isVisible(this.questions[id], pricing)) {
					out[id] = pricing[id];
				}
			});
			return out;
		}

		applyVisibility() {
			const { pricing } = this.readAnswers();
			Object.values(this.questions).forEach((q) => {
				const wrapper = this.control(q.id);
				if (!wrapper) {
					return;
				}
				const visible = isVisible(q, pricing);
				wrapper.hidden = !visible;
				wrapper.querySelectorAll('input, textarea').forEach((el) => {
					el.disabled = !visible;
				});
			});
		}

		visibleQuestionsOf(stepId) {
			const { pricing } = this.readAnswers();
			return (this.stepQuestions[stepId] || []).filter((id) => isVisible(this.questions[id], pricing));
		}

		/* --- screens --- */

		current() {
			return this.screens[this.index];
		}

		isContactScreen(screen) {
			return !!screen && (screen.type === 'gate' || (screen.type === 'step' && screen.stepId === 'contact'));
		}

		isSkipped(screen) {
			if (screen.type !== 'step') {
				return false;
			}
			if (screen.stepId === 'service' && this.prefilter) {
				return true;
			}
			return this.visibleQuestionsOf(screen.stepId).length === 0;
		}

		firstIndex() {
			for (let i = 0; i < this.screens.length; i++) {
				if (!this.isSkipped(this.screens[i])) {
					return i;
				}
			}
			return 0;
		}

		finalIndex() {
			const final = this.screens.find((s) => s.type === 'final');
			return final ? final.index : this.screens.length - 1;
		}

		maxReachable() {
			return this.finalIndex() - 1;
		}

		countable() {
			// Progress total: a step only stops counting when the prefilter removes it,
			// not while its branch questions are still waiting for an answer.
			return this.screens.filter((s) => s.type !== 'final' && !(s.type === 'step' && s.stepId === 'service' && this.prefilter));
		}

		goTo(target, options = {}) {
			const direction = target >= this.index ? 1 : -1;
			let i = Math.max(0, Math.min(target, this.screens.length - 1));
			while (i >= 0 && i < this.screens.length && this.isSkipped(this.screens[i])) {
				i += direction;
			}
			if (i < 0 || i >= this.screens.length) {
				return;
			}

			this.index = i;
			const screen = this.screens[i];
			this.screens.forEach((s) => {
				const active = s === screen;
				s.el.hidden = !active;
				s.el.classList.toggle('is-active', active);
			});

			this.hideFormError();
			this.updateNav(screen);
			this.updateProgress(screen);

			// Keep whatever other scripts stored in history.state (the WordPress
			// Interactivity runtime reloads the page on popstate if its session id
			// is missing from the entry).
			const entry = Object.assign({}, window.history.state || {}, { ctEst: i });
			if (options.replace) {
				window.history.replaceState(entry, '');
			} else if (options.push !== false) {
				window.history.pushState(entry, '');
			}

			if (screen.type === 'result') {
				this.renderResultScreen(screen);
			}

			if (options.focus !== false) {
				this.focusHeading(screen);
			}
		}

		focusHeading(screen) {
			const heading = screen.heading;
			if (!heading) {
				return;
			}
			heading.focus({ preventScroll: true });
			const top = this.root.getBoundingClientRect().top;
			if (top < 0 || top > window.innerHeight * 0.6) {
				this.root.scrollIntoView({ block: 'start', behavior: REDUCED_MOTION ? 'auto' : 'smooth' });
			}
		}

		updateNav(screen) {
			if (screen.type === 'final') {
				this.nav.hidden = true;
				return;
			}
			this.nav.hidden = false;
			const isFirst = screen.index === this.firstIndex();
			this.btnBack.hidden = isFirst;
			const contact = this.isContactScreen(screen);
			this.btnNext.hidden = contact;
			this.btnSubmit.hidden = !contact;
			this.btnNext.textContent = screen.type === 'result' ? S.continue || 'Continue' : S.next || 'Next';
		}

		updateProgress(screen) {
			if (!this.progress) {
				return;
			}
			if (screen.type === 'final') {
				this.progress.hidden = true;
				return;
			}
			this.progress.hidden = false;
			const list = this.countable();
			const position = list.indexOf(screen) + 1;
			const total = list.length;
			const pct = total ? Math.round((position / total) * 100) : 0;
			this.progressText.textContent = sprintf(S.stepOf || 'Step %1$s of %2$s', position, total);
			this.progressBar.setAttribute('aria-valuenow', String(pct));
			this.progressFill.style.width = pct + '%';
		}

		next() {
			const screen = this.current();
			if (!screen) {
				return;
			}
			if (screen.type === 'step' && !this.validateStep(screen.stepId)) {
				return;
			}
			if (screen.type === 'step') {
				this.schedulePreview(true);
			}
			this.goTo(this.index + 1);
		}

		back() {
			if (this.index <= this.firstIndex()) {
				return;
			}
			this.goTo(this.index - 1);
		}

		/* --- validation --- */

		validateStep(stepId) {
			let firstInvalid = null;
			this.visibleQuestionsOf(stepId).forEach((id) => {
				const message = this.validateQuestion(id);
				if (message) {
					this.showError(id, message);
					if (!firstInvalid) {
						firstInvalid = id;
					}
				} else {
					this.clearError(id);
				}
			});
			if (firstInvalid) {
				this.focusQuestion(firstInvalid);
				return false;
			}
			return true;
		}

		validateQuestion(id) {
			const q = this.questions[id];
			const wrapper = this.control(id);
			if (!q || !wrapper) {
				return '';
			}
			switch (q.type) {
				case 'single': {
					if (q.required && !wrapper.querySelector('input:checked')) {
						return S.errorChoose;
					}
					return '';
				}
				case 'multi': {
					if (q.required && !wrapper.querySelector('input:checked')) {
						return S.errorChooseAny;
					}
					return '';
				}
				case 'number': {
					const raw = wrapper.querySelector('input').value.trim();
					if (raw === '') {
						return q.required ? S.errorRequired : '';
					}
					const n = Number(raw);
					if (!Number.isFinite(n) || !Number.isInteger(n)) {
						return S.errorNumber;
					}
					if (typeof q.min === 'number' && n < q.min) {
						return sprintf(S.errorMin, q.min);
					}
					if (typeof q.max === 'number' && n > q.max) {
						return sprintf(S.errorMax, q.max);
					}
					return '';
				}
				case 'email': {
					const raw = wrapper.querySelector('input').value.trim();
					if (raw === '') {
						return q.required ? S.errorRequired : '';
					}
					if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(raw)) {
						return S.errorEmail;
					}
					return '';
				}
				case 'checkbox': {
					if (q.required && !wrapper.querySelector('input').checked) {
						return S.errorConsent;
					}
					return '';
				}
				default: {
					const raw = wrapper.querySelector('input, textarea').value.trim();
					if (raw === '') {
						return q.required ? S.errorRequired : '';
					}
					if (typeof q.max === 'number' && raw.length > q.max) {
						return sprintf(S.errorMaxLength, q.max);
					}
					return '';
				}
			}
		}

		validateContact() {
			let firstInvalid = null;
			Object.values(this.questions)
				.filter((q) => q.contact)
				.forEach((q) => {
					const message = this.validateQuestion(q.id);
					if (message) {
						this.showError(q.id, message);
						if (!firstInvalid) {
							firstInvalid = q.id;
						}
					} else {
						this.clearError(q.id);
					}
				});
			if (firstInvalid) {
				this.focusQuestion(firstInvalid);
				return false;
			}
			return true;
		}

		showError(id, message) {
			const wrapper = this.control(id);
			if (!wrapper) {
				return;
			}
			const box = wrapper.querySelector('[data-ct-error]');
			if (box) {
				box.textContent = message;
				box.hidden = false;
			}
			wrapper.classList.add('is-invalid');
			wrapper.querySelectorAll('input, textarea').forEach((el) => el.setAttribute('aria-invalid', 'true'));
		}

		clearError(id) {
			const wrapper = this.control(id);
			if (!wrapper) {
				return;
			}
			const box = wrapper.querySelector('[data-ct-error]');
			if (box) {
				box.textContent = '';
				box.hidden = true;
			}
			wrapper.classList.remove('is-invalid');
			wrapper.querySelectorAll('input, textarea').forEach((el) => el.removeAttribute('aria-invalid'));
		}

		applyServerErrors(errors) {
			if (!errors || typeof errors !== 'object') {
				return null;
			}
			let first = null;
			Object.keys(errors).forEach((id) => {
				if (this.questions[id]) {
					this.showError(id, String(errors[id]));
					if (!first) {
						first = id;
					}
				}
			});
			return first;
		}

		focusQuestion(id) {
			const wrapper = this.control(id);
			if (!wrapper) {
				return;
			}
			const target = wrapper.querySelector('input:not([disabled]), textarea:not([disabled])');
			if (target) {
				target.focus();
			}
		}

		updateCounter(textarea) {
			const wrapper = textarea.closest('[data-ct-question]');
			const counter = wrapper && wrapper.querySelector('[data-ct-count]');
			if (!counter) {
				return;
			}
			const max = parseInt(textarea.getAttribute('maxlength') || '1000', 10);
			counter.textContent = sprintf(S.charCount || '%1$s / %2$s', textarea.value.length, max);
		}

		/* --- messages --- */

		showFormError(message, withContact) {
			if (!this.formError) {
				return;
			}
			this.formError.textContent = message + ' ';
			if (withContact && cfg.brand && cfg.brand.contact_url) {
				const link = document.createElement('a');
				link.href = cfg.brand.contact_url;
				link.textContent = S.contactUs || 'Contact us';
				this.formError.appendChild(link);
			}
			this.formError.hidden = false;
		}

		hideFormError() {
			if (this.formError) {
				this.formError.hidden = true;
				this.formError.textContent = '';
			}
		}

		/* --- preview --- */

		schedulePreview(immediate) {
			// Gated mode: the preview carries no figures, so only validate on advance.
			if (this.mode === 'gated' && !immediate) {
				this.renderLive(null);
				return;
			}
			window.clearTimeout(this.previewTimer);
			const delay = immediate ? 0 : Number(cfg.previewDebounce) || 350;
			this.previewTimer = window.setTimeout(() => this.runPreview(), delay);
		}

		runPreview() {
			const answers = this.visiblePricing();
			if (!answers.service_line || this.previewBlocked || this.done) {
				return Promise.resolve(this.lastPreview);
			}
			const key = JSON.stringify(answers);
			if (key === this.previewKey && this.previewPromise) {
				return this.previewPromise;
			}
			this.previewKey = key;
			if (this.live && !this.lastPreview) {
				this.live.textContent = this.mode === 'gated' ? '' : S.calculating || '';
			}
			this.previewPromise = request(cfg.endpoints.preview, {
				method: 'POST',
				body: { answers, mode: this.mode },
			})
				.then((res) => {
					if (key !== this.previewKey) {
						return this.lastPreview;
					}
					if (res.status === 429) {
						this.previewBlocked = true;
						this.lastPreview = null;
						if (this.live) {
							this.live.textContent = S.previewPaused || '';
						}
						return null;
					}
					if (!res.ok || !res.json) {
						if (res.json && res.json.data && res.json.data.errors) {
							this.applyServerErrors(res.json.data.errors);
						}
						return this.lastPreview;
					}
					this.lastPreview = res.json;
					if (res.json.errors) {
						this.applyServerErrors(res.json.errors);
					}
					this.renderLive(res.json);
					return res.json;
				})
				.catch(() => {
					if (this.live && !this.lastPreview) {
						this.live.textContent = '';
					}
					return this.lastPreview;
				});
			return this.previewPromise;
		}

		summaryText(payload) {
			if (!payload) {
				return '';
			}
			if (this.mode === 'open' && payload.range_text) {
				return payload.range_text + ' · ' + (payload.weeks_text || '');
			}
			if (this.mode === 'band' && payload.band_label) {
				return payload.band_label + ' · ' + (payload.weeks_text || '');
			}
			return '';
		}

		renderLive(payload) {
			if (!this.live) {
				return;
			}
			if (this.mode === 'gated') {
				const { pricing } = this.readAnswers();
				this.live.textContent = pricing.service_line ? S.keepGoing || '' : '';
				return;
			}
			const summary = this.summaryText(payload);
			this.live.textContent = summary ? sprintf(S.currentEstimate || '%s', summary) : '';
		}

		/* --- result screens --- */

		async renderResultScreen(screen) {
			const status = screen.el.querySelector('[data-ct-result-status]');
			const body = screen.el.querySelector('[data-ct-result-body]');
			if (this.lastPreview && JSON.stringify(this.visiblePricing()) === this.previewKey) {
				this.fillResult(screen.el, this.lastPreview);
				return;
			}
			if (status) {
				status.hidden = false;
				status.classList.remove('is-error');
				status.textContent = S.calculating || '';
			}
			if (body) {
				body.hidden = true;
			}
			const payload = await this.runPreview();
			if (this.current() !== screen) {
				return;
			}
			if (payload && payload.ready) {
				this.fillResult(screen.el, payload);
			} else if (status) {
				status.hidden = false;
				status.classList.add('is-error');
				status.textContent = this.previewBlocked ? S.errorRateLimited || '' : S.errorNetwork || '';
			}
		}

		fillResult(el, payload) {
			const status = el.querySelector('[data-ct-result-status]');
			const body = el.querySelector('[data-ct-result-body]');
			if (status) {
				status.hidden = true;
			}
			if (body) {
				body.hidden = false;
			}
			const set = (selector, value) => {
				const node = el.querySelector(selector);
				if (node) {
					node.textContent = value || '';
				}
			};
			set('[data-ct-range]', payload.range_text);
			set('[data-ct-band]', payload.band_label);
			set('[data-ct-weeks]', payload.weeks_text);
			set('[data-ct-hours]', payload.hours !== undefined ? sprintf(S.hours || '%d h', payload.hours) : '');

			const team = el.querySelector('[data-ct-team]');
			if (team) {
				team.textContent = '';
				(payload.team || []).forEach((member) => {
					const li = document.createElement('li');
					li.className = 'ct-est__team-item';
					const role = document.createElement('span');
					role.className = 'ct-est__team-role';
					role.textContent = member.label;
					const hours = document.createElement('span');
					hours.className = 'ct-est__team-hours';
					hours.textContent = sprintf(S.hours || '%d h', member.hours);
					const bar = document.createElement('span');
					bar.className = 'ct-est__team-bar';
					bar.setAttribute('aria-hidden', 'true');
					bar.style.setProperty('--share', String(Math.max(0, Math.min(1, Number(member.share) || 0))));
					li.append(role, hours, bar);
					team.appendChild(li);
				});
			}
		}

		/* --- submit --- */

		async submit() {
			if (this.inflight || this.done) {
				return;
			}
			this.hideFormError();
			if (!this.validateContact()) {
				return;
			}
			const { pricing, contact } = this.readAnswers();
			const answers = Object.assign({}, this.visiblePricing(), contact);
			void pricing;

			const body = { answers, mode: this.mode, source_url: cfg.sourceUrl || window.location.href };
			body[cfg.honeypot.field] = this.honeypot ? this.honeypot.value : '';
			body[cfg.honeypot.token] = this.tokenInput ? this.tokenInput.value : '';

			this.setBusy(true);
			let res;
			try {
				res = await request(cfg.endpoints.submit, { method: 'POST', body });
			} catch (e) {
				this.setBusy(false);
				this.showFormError(S.errorNetwork || '', true);
				return;
			}
			this.setBusy(false);

			if (res.status === 201 && res.json) {
				this.renderFinal(res.json);
				return;
			}
			if (res.status === 429) {
				this.showFormError((res.json && res.json.message) || S.errorRateLimited || '', true);
				return;
			}
			if (res.status === 400 && res.json) {
				const first = this.applyServerErrors(res.json.data && res.json.data.errors);
				if (first) {
					this.showFormError(S.errorFix || res.json.message || '');
					this.focusQuestion(first);
				} else {
					this.showFormError(res.json.message || S.errorGeneric || '', true);
				}
				return;
			}
			this.showFormError((res.json && res.json.message) || S.errorGeneric || '', true);
		}

		setBusy(busy) {
			this.inflight = busy;
			this.btnSubmit.disabled = busy;
			this.btnSubmit.setAttribute('aria-busy', busy ? 'true' : 'false');
			if (busy) {
				this.btnSubmit.dataset.label = this.btnSubmit.textContent.trim();
				this.btnSubmit.textContent = S.submitting || '…';
			} else if (this.btnSubmit.dataset.label) {
				this.btnSubmit.textContent = this.btnSubmit.dataset.label;
			}
		}

		renderFinal(payload) {
			this.done = true;
			window.clearTimeout(this.previewTimer);
			const final = this.screens[this.finalIndex()];
			this.fillResult(final.el, payload);

			const share = final.el.querySelector('[data-ct-share-url]');
			if (share) {
				share.value = payload.share_url || '';
			}
			this.shareUrl = payload.share_url || '';

			if (this.live) {
				this.live.textContent = '';
			}

			this.loadNarrative(final.el, payload.token);

			this.goTo(final.index, { replace: true });
		}

		/* --- narrative (AI or fallback text, fetched after the lead exists) --- */

		async loadNarrative(finalEl, token) {
			const container = finalEl.querySelector('[data-ct-narrative]');
			if (!container) {
				return;
			}
			const skeleton = container.querySelector('.ct-est__skeleton');
			const fail = () => {
				// No error state: the narrative is a bonus, the estimate above is the product.
				container.hidden = true;
				container.setAttribute('aria-busy', 'false');
			};
			if (!token || !cfg.endpoints.narrative) {
				fail();
				return;
			}
			let res;
			try {
				res = await request(cfg.endpoints.narrative, { method: 'POST', body: { token } });
			} catch (e) {
				fail();
				return;
			}
			const n = res.ok && res.json && res.json.narrative;
			if (!n || typeof n !== 'object' || !n.headline) {
				fail();
				return;
			}
			const frag = document.createDocumentFragment();
			const el = (tag, className, text) => {
				const node = document.createElement(tag);
				if (className) {
					node.className = className;
				}
				if (text !== undefined) {
					node.textContent = String(text);
				}
				return node;
			};
			frag.appendChild(el('h3', 'ct-est__subtitle ct-est__narrative-headline', n.headline));
			if (n.summary) {
				frag.appendChild(el('p', 'ct-est__narrative-summary', n.summary));
			}
			const phases = Array.isArray(n.phases) ? n.phases.filter((p) => p && p.name) : [];
			if (phases.length) {
				frag.appendChild(el('h4', 'ct-est__narrative-label', S.narrativePhases || ''));
				const ol = el('ol', 'ct-est__phases');
				phases.forEach((phase) => {
					const li = el('li', 'ct-est__phase');
					const head = el('div', 'ct-est__phase-head');
					head.appendChild(el('span', 'ct-est__phase-name', phase.name));
					if (phase.weeks !== undefined && phase.weeks !== null && phase.weeks !== '') {
						head.appendChild(el('span', 'ct-est__phase-weeks', sprintf(S.weeksShort || '%s wk', phase.weeks)));
					}
					li.appendChild(head);
					if (phase.description) {
						li.appendChild(el('p', 'ct-est__phase-desc', phase.description));
					}
					const roles = Array.isArray(phase.roles) ? phase.roles.filter((r) => typeof r === 'string' && r) : [];
					if (roles.length) {
						li.appendChild(el('p', 'ct-est__phase-roles', roles.join(' · ')));
					}
					ol.appendChild(li);
				});
				frag.appendChild(ol);
			}
			const lists = el('div', 'ct-est__narrative-lists');
			[
				['assumptions', S.narrativeAssume],
				['risks', S.narrativeRisks],
			].forEach(([key, label]) => {
				const items = Array.isArray(n[key]) ? n[key].filter((i) => typeof i === 'string' && i) : [];
				if (!items.length) {
					return;
				}
				const block = el('div', 'ct-est__narrative-list');
				block.appendChild(el('h4', 'ct-est__narrative-label', label || ''));
				const ul = el('ul');
				items.forEach((item) => ul.appendChild(el('li', '', item)));
				block.appendChild(ul);
				lists.appendChild(block);
			});
			if (lists.childElementCount) {
				frag.appendChild(lists);
			}
			// Replace the whole slot content (static heading + skeleton) with the narrative.
			container.textContent = '';
			container.appendChild(frag);
			void skeleton;
			container.setAttribute('aria-busy', 'false');
		}

		async copyShareUrl() {
			const status = this.root.querySelector('[data-ct-copy-status]');
			const input = this.root.querySelector('[data-ct-share-url]');
			const url = this.shareUrl || (input && input.value) || '';
			if (!url) {
				return;
			}
			try {
				if (navigator.clipboard && navigator.clipboard.writeText) {
					await navigator.clipboard.writeText(url);
				} else {
					input.focus();
					input.select();
					if (!document.execCommand('copy')) {
						throw new Error('copy failed');
					}
				}
				if (status) {
					status.textContent = S.copied || '';
				}
			} catch (e) {
				if (input) {
					input.focus();
					input.select();
				}
				if (status) {
					status.textContent = S.copyFailed || '';
				}
			}
		}
	}

	document.querySelectorAll('[data-ct-estimator]').forEach((root) => {
		root.classList.add('ct-est--js');
		new Wizard(root);
	});
})();
