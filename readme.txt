=== Cybertech Project Estimator ===
Contributors: sudokku
Tags: estimator, quote, calculator, leads, ai
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A guided project estimator: questionnaire → rate-card pricing engine → lead with snapshot → shareable estimate page. Optional AI narrative that never touches a number.

== Description ==

Replace your "Estimate my project" mailto link with a five-step wizard that prices Web, Mobile, UI/UX and AI Automation projects from an editable rate card, captures qualified leads with an immutable snapshot of what was quoted, and produces a printable shareable estimate page.

* Every coefficient editable in the admin; every calculation shows its step-by-step breakdown.
* Three reveal modes: open, band (no figures), gated (figures never leave the server before contact details).
* Optional AI narration via OpenRouter with strict JSON output, validation, cache, budget guard and circuit breaker — a deterministic fallback is always ready.
* Sales email, lead confirmation, signed n8n webhook.
* GDPR: consent versioning, core exporter/eraser, retention cron, no third-party CAPTCHA, no Google Fonts.
* Shortcode `[cybertech_estimator]` and an Elementor widget.
* Zero runtime dependencies, no build step, no jQuery.

== Installation ==

1. Upload the zip under Plugins → Add New → Upload Plugin and activate.
2. Add `[cybertech_estimator]` to a page (or use the Elementor widget).
3. Review Estimator → Rate card and Settings.

== Frequently Asked Questions ==

= Does it need an AI key? =
No. Without a key the visitor still gets a complete estimate with a built-in narrative.

= Can visitors see the numbers in devtools in gated mode? =
No. In gated mode the preview endpoint returns no figures; they are only returned after contact details are submitted.

== Changelog ==

= 0.1.0 =
* Initial release.
