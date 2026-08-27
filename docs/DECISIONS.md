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
