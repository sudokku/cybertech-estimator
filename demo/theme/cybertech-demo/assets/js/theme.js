/**
 * Cybertech Demo — header, mobile menu, scroll-spy, back-to-top, counters,
 * and the 2D-canvas dot-wave behind the hero.
 */
(function () {
	'use strict';

	var doc = document;
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* ---------- sticky header ---------- */
	var header = doc.querySelector('[data-sticky-header]');
	var backToTop = doc.querySelector('[data-back-to-top]');

	function onScroll() {
		var y = window.scrollY || window.pageYOffset;
		if (header) {
			header.classList.toggle('is-sticky', y > 200);
		}
		if (backToTop) {
			backToTop.hidden = y < 600;
		}
	}
	window.addEventListener('scroll', onScroll, { passive: true });
	onScroll();

	if (backToTop) {
		backToTop.addEventListener('click', function (e) {
			e.preventDefault();
			window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
		});
	}

	/* ---------- mobile menu ---------- */
	var openBtn = doc.querySelector('[data-nav-open]');
	var nav = doc.querySelector('[data-site-nav]');
	var closers = doc.querySelectorAll('[data-nav-close]');
	var backdrop = doc.querySelector('.nav-backdrop');

	function setMenu(open) {
		doc.body.classList.toggle('menu-open', open);
		if (openBtn) {
			openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
		}
		if (backdrop) {
			backdrop.hidden = !open;
		}
		if (open && nav) {
			var first = nav.querySelector('a, button');
			if (first) {
				first.focus();
			}
		} else if (!open && openBtn) {
			openBtn.focus();
		}
	}

	if (openBtn && nav) {
		openBtn.addEventListener('click', function () {
			setMenu(!doc.body.classList.contains('menu-open'));
		});
		Array.prototype.forEach.call(closers, function (el) {
			el.addEventListener('click', function () { setMenu(false); });
		});
		nav.addEventListener('click', function (e) {
			if (e.target.closest && e.target.closest('.nav-menu a')) {
				setMenu(false);
			}
		});
		doc.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && doc.body.classList.contains('menu-open')) {
				setMenu(false);
			}
		});
	}

	/* ---------- scroll-spy on the one-page nav ---------- */
	var links = doc.querySelectorAll('.nav-menu a[href*="#"]');
	var targets = [];
	Array.prototype.forEach.call(links, function (a) {
		var hash = a.getAttribute('href').split('#')[1];
		var el = hash ? doc.getElementById(hash) : null;
		if (el) {
			targets.push({ link: a, el: el });
		}
	});

	if (targets.length && 'IntersectionObserver' in window) {
		var spy = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) {
					return;
				}
				targets.forEach(function (t) {
					t.link.parentNode.classList.toggle('is-active', t.el === entry.target);
				});
			});
		}, { rootMargin: '-40% 0px -55% 0px' });
		targets.forEach(function (t) { spy.observe(t.el); });
	}

	/* ---------- counters ---------- */
	var counters = doc.querySelectorAll('[data-counter]');
	if (counters.length && !reduceMotion && 'IntersectionObserver' in window) {
		var counted = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) {
					return;
				}
				var el = entry.target;
				var end = parseInt(el.getAttribute('data-counter'), 10);
				var suffix = el.getAttribute('data-suffix') || '';
				var start = performance.now();
				(function tick(now) {
					var p = Math.min(1, (now - start) / 1400);
					var eased = 1 - Math.pow(1 - p, 3);
					el.textContent = Math.round(end * eased) + suffix;
					if (p < 1) {
						requestAnimationFrame(tick);
					}
				})(start);
				counted.unobserve(el);
			});
		}, { threshold: 0.6 });
		Array.prototype.forEach.call(counters, function (el) { counted.observe(el); });
	}

	/* ---------- hero dot-wave (2D canvas, perspective grid, mouse parallax) ---------- */
	function dotWave(canvas) {
		var ctx = canvas.getContext('2d');
		if (!ctx) {
			return;
		}
		var COLS = 90;         // recomputed from width in resize()
		var SCALE = 1;
		var ROWS = 44;
		var K = 7;            // perspective strength
		var w = 0, h = 0, dpr = 1, t = 0;
		var mouse = 0, mouseTarget = 0;
		var raf = 0;

		function resize() {
			dpr = Math.min(window.devicePixelRatio || 1, 2);
			w = canvas.clientWidth;
			h = canvas.clientHeight;
			canvas.width = Math.round(w * dpr);
			canvas.height = Math.round(h * dpr);
			ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
			COLS = Math.max(36, Math.min(90, Math.round(w / 16)));
			SCALE = Math.max(0.45, Math.min(1, w / 1200));
		}

		function lerp(a, b, p) { return a + (b - a) * p; }

		function frame() {
			ctx.clearRect(0, 0, w, h);
			mouse += (mouseTarget - mouse) * 0.05;
			var horizon = h * 0.4;
			var camY = h * 0.95;
			var halfW = w * 1.7;
			var amp = h * 0.028;
			for (var r = 0; r < ROWS; r++) {
				var z = r / (ROWS - 1);                   // 0 far, 1 near
				var s = 1 / (1 + (1 - z) * K);             // perspective scale
				for (var c = 0; c < COLS; c++) {
					var x = c / (COLS - 1) - 0.5;          // -0.5 .. 0.5
					// three overlapping ripple fields -> irregular "sea" rather than one arc
					var wave = Math.sin(x * 26 + z * 9 + t * 1.3) * amp
						+ Math.sin(x * 13 - z * 18 - t * 0.9) * amp * 0.8
						+ Math.cos(z * 24 + x * 5 - t * 0.6) * amp * 0.6;
					var sx = w / 2 + (x * halfW + mouse * 90) * s;
					var sy = horizon + (camY + wave) * s;
					if (sx < -20 || sx > w + 20 || sy > h + 40) {
						continue;
					}
					var crest = (wave / (amp * 2.4) + 1) / 2;   // 0 trough .. 1 crest
					var jitter = ((c * 7919 + r * 104729) % 1000) / 1000; // stable per-dot noise
					if (crest < 0.3 && jitter > 0.45) {
						continue;                              // sparser troughs, like their particle sea
					}
					var rad = (0.5 + 14 * SCALE * Math.pow(s, 1.5)) * (0.45 + crest * 0.8) * (0.7 + jitter * 0.7);
					// far rows navy, near rows electric blue; crests brighter
					var hue = lerp(224, 214, s);
					var light = lerp(12, 46, Math.pow(s, 0.8)) + crest * 12;
					ctx.fillStyle = 'hsla(' + hue + ',95%,' + light + '%,' + lerp(0.5, 0.95, s) + ')';
					ctx.beginPath();
					ctx.arc(sx, sy, rad, 0, Math.PI * 2);
					ctx.fill();
				}
			}
		}

		function loop() {
			t += 0.014;
			frame();
			raf = requestAnimationFrame(loop);
		}

		resize();
		window.addEventListener('resize', function () { resize(); if (reduceMotion) { frame(); } });

		if (reduceMotion) {
			frame();
			return;
		}

		window.addEventListener('mousemove', function (e) {
			mouseTarget = (e.clientX / window.innerWidth - 0.5) * 2; // -1 .. 1
		}, { passive: true });

		// pause when the hero is off screen
		if ('IntersectionObserver' in window) {
			new IntersectionObserver(function (entries) {
				if (entries[0].isIntersecting) {
					if (!raf) { loop(); }
				} else {
					cancelAnimationFrame(raf);
					raf = 0;
				}
			}).observe(canvas);
		} else {
			loop();
		}
	}

	Array.prototype.forEach.call(doc.querySelectorAll('[data-dot-wave]'), dotWave);
})();
