# Decision log

Dated, append-only. Numbers reference `docs/PLAN.md` §2 where applicable.

- 2026-08-27 — Plan accepted by Radu. D1 (AI narrative only after consent/submit), D9 (hand-coded demo theme; Elementor installed only to verify the widget), D12 (demo site `en_US`; ro_RO shipped but not blocking) confirmed.
- 2026-08-27 — Author: Radu Chirilov, https://github.com/sudokku. Public repo `sudokku/cybertech-estimator`; every git tag produces a GitHub release with a built zip (`.github/workflows/release.yml`).
- 2026-08-27 — API keys are entered in the admin (Settings → AI), stored in `ct_est_settings`. No wp-config constants.
- 2026-08-27 — Default reveal mode: `gated`. `band` mode hides figures from the visitor everywhere (result, share page, confirmation email); only the sales email carries them.
- 2026-08-27 — Default rate card as proposed in PLAN §3 (all values editable in admin).
- 2026-08-27 — Repo lives at `~/Developer/cybertech-estimator`, symlinked into the Local site's `wp-content/plugins/`. `demo/` (theme + seed) is in the repo but excluded from the zip via `.distignore`.
- 2026-08-27 — License GPL-2.0-or-later (WordPress convention; required for any wp.org listing).
- 2026-08-27 — Option names: `ct_est_settings` (Settings API array), `ct_est_rate_card`, `ct_est_rate_card_history`, `ct_est_version`, `ct_est_log`; transients prefixed `ct_est_`.
- 2026-08-27 — Default preview rate limit raised 10 → 60/hour (frontend agent finding: open mode fires a preview per answer change; 10 blocked a normal visitor). Submit stays 3/h, narrative 6/h.
- 2026-08-27 — `RateCard.php` was split: `RateCard` (pure value object + validate) and `RateCardRepository` (load/save/history/rollback in wp_options) so the engine stays WordPress-free.
- 2026-08-27 — Provider interface methods are snake_case (`list_models`, `is_configured`) to satisfy WPCS; the brief's camelCase names were kept in spirit only.
- 2026-08-27 — README documents the formula table, reveal modes, AI cost model, webhook payload + n8n verification snippet, GDPR, white-label point and roadmap.
- 2026-08-27 — Demo theme uses the CUI shown on the live site footer (RO39659270) rather than the brief's RO39558270; flagged for Radu to confirm.
- 2026-08-27 — Demo theme deviations from cybertech.ro: estimator CTA added to the nav and hero; service cards deep-link to the estimator with a service pre-filter; no photos/third-party logos (placeholders and text badges); CSS-only dark-band textures.
- 2026-08-27 — Emails are sent synchronously on submit (reliable on demo sites without real cron); only the webhook is cron-dispatched.
- 2026-08-27 — OpenRouter free-model findings (live batch, 10 runs): (1) most free models have no `structured_outputs` support, so strict `json_schema` + `require_parameters` routing fails with "No endpoints found" — the provider now downgrades once to prompt-only JSON; (2) free models are reasoning models that spend the whole token budget thinking and return empty content — the provider sends `reasoning: {enabled: false}` when the model advertises the parameter (and `exclude` + 3× budget where reasoning is mandatory); (3) small models slip on brackets/trailing commas — the validator repairs conservatively (warning `json_repaired`); (4) `max_tokens` default raised 700 → 900 after a verbose model truncated at 700; (5) thousands-separated counts ("12,000-SKU") are no longer treated as money. Recommended free slug today: `nvidia/nemotron-3-super-120b-a12b:free` (structured outputs, strict mode); `cohere/north-mini-code:free` works in prompt-only mode.
