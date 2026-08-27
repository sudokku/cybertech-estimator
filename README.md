# Cybertech Project Estimator

A WordPress plugin that replaces a `mailto:` "Estimate my project" link with a guided estimator: a five-step questionnaire priced by an editable rate card, an optional AI-written narrative that never touches a number, lead capture with an immutable snapshot of what was quoted, and a shareable, printable estimate page.

Built as a pitch piece for [Cybertech](https://cybertech.ro) (ALANTIS WEB STUDIO S.R.L.). Zero runtime dependencies, PHP 8.1+, WordPress 6.4+ (tested on 7.1).

- [Install](#install)
- [How it works](#how-it-works)
- [The pricing formula](#the-pricing-formula)
- [Reveal modes](#reveal-modes)
- [The AI layer (and why it can never change a number)](#the-ai-layer)
- [Leads, snapshots and the share page](#leads-snapshots-and-the-share-page)
- [n8n webhook](#n8n-webhook)
- [Privacy / GDPR](#privacy--gdpr)
- [White-labelling](#white-labelling)
- [Elementor, shortcode, WP-CLI](#elementor-shortcode-wp-cli)
- [Security notes](#security-notes)
- [Development](#development)
- [Roadmap](#roadmap)

## Install

**From a release:** download `cybertech-estimator.zip` from the [latest release](https://github.com/sudokku/cybertech-estimator/releases/latest) → Plugins → Add New → Upload Plugin → Activate.

**Try it in the browser:** open the WordPress Playground blueprint — `https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/sudokku/cybertech-estimator/main/blueprint.json` — it boots a seeded demo (10 leads, the estimator page, admin `admin`/`password`).

**Then:**

1. Put `[cybertech_estimator]` on a page (or drop the *Project Estimator* Elementor widget). Link your CTA to that page.
2. Estimator → **Rate card**: check the coefficients (defaults are sensible for a small senior agency at a €45/h blended rate).
3. Estimator → **Settings → General**: pick a reveal mode (default `gated`).
4. Estimator → **Settings → Notifications**: sales email. **Integrations**: n8n webhook URL + secret. **AI**: optional — paste an OpenRouter key, refresh the model list, pick a model, tick "Enable AI narration".
5. Estimator → **Sandbox**: click through a project and watch every step of the calculation.

No API key, no cron, no external service is required for a complete estimate. Everything AI-related is a garnish.

## How it works

```
visitor answers ──► POST /preview ──► PricingEngine (PHP) ──► RevealPolicy ──► live range in the wizard
                                                                                (nothing numeric in gated mode)
contact + consent ► POST /submit  ──► PricingEngine ──► Lead + snapshot ──► emails, webhook (cron), share link
                    POST /narrative ► NarrativeService: cache → guards → LLM → validator → (fallback) ──► prose
```

`src/Engine/PricingEngine.php` is a pure class: `new PricingEngine( RateCard, array $answers )->estimate()` returns an `EstimateResult` and never calls WordPress. That is what lets `tests/Unit/PricingEngineTest.php` cover it 100% with hand-derived expectations.

## The pricing formula

Everything below is data in the rate card (`Estimator → Rate card`, stored in the `ct_est_rate_card` option, versioned on every save with a 10-deep history, diff and rollback). There are no numeric literals in the engine.

| Step | Operation | Rate-card source |
|---|---|---|
| 1 | `hours = base_hours[service_line]` | `service_lines.*.base_hours` |
| 2 | `+ add_hours` factors selected by the answers, ascending `order` (then id). `per_unit` factors multiply by the numeric answer (templates, screens, workflows, integrations…) | `factors.*` |
| 3 | `× multiplier` factors, same ordering (e.g. Magento ×1.8, multilingual ×1.25, both mobile platforms ×1.3) | `factors.*` |
| 4 | `× urgency` (flexible 0.95 · normal 1 · urgent 1.25 · ASAP 1.5) | `urgency.*` |
| 5 | `× (1 + contingency)` (10%) | `contingency` |
| 6 | `max(hours, min_hours[service_line])` | `service_lines.*.min_hours` |
| 7 | Team allocation from the service line's hours band → share-weighted hourly rate `Σ share × role_rate` (falls back to `blended_rate`) → `price = hours × rate` | `team_bands.*`, `role_rates`, `blended_rate` |
| 8 | `+ add_price` factors (none by default; the type exists for fixed-fee items) | `factors.*` |
| 9 | Range `[price × (1 − spread), price × (1 + spread)]`, each rounded to the nearest €250 below €10k and €500 above | `range_spread`, `rounding` |
| 10 | `weeks = max(min_weeks, ceil(hours / weekly_capacity))` (30 productive team-hours/week, min 2) | `weekly_capacity`, `min_weeks` |
| 11 | Engagement band from the point price (Small < €10k · Mid-size < €40k · Enterprise) | `reveal_bands` |
| 12 | Qualification score 0–100, admin-only: budget band vs range (40) · urgency (15) · scope size (20) · left notes (10) · maintenance (10) · hosting with us (5) | `qualification.*`, `budget_bands` |

Every step appends a row to the **Breakdown** (`label, input, operation, before, after, source`). In the Sandbox each row links to the rate-card field that produced it. The same breakdown is stored on every lead and mailed to sales.

Worked example (defaults): Web · WordPress · Magento · 5 templates · multilingual · 2 integrations · migration · urgent · hosting by Cybertech → 80 + 30 + 24 + 24 + 16 = 174 h → ×1.8 → ×1.25 → ×1.25 → ×1.1 = 538.3 h → effective rate €46.71 → €25,144 → **€20,000 – €30,000, 18 weeks**, Mid-size.

## Reveal modes

`Settings → General` (or `mode="…"` on the shortcode/widget). A business decision the agency owns:

- **open** — the range and timeline update live while answering; contact details are asked after the result.
- **band** — only the engagement band ("Mid-size engagement") and the timeline are shown; figures are never shown to the visitor anywhere (result, share page, confirmation email). Sales gets them.
- **gated** (default) — the result is rendered blurred behind a contact form. **The blur is cosmetic; the `/preview` response contains no figures at all in gated mode** — open devtools and check. Figures only leave the server in the `/submit` response, after consent.

## The AI layer

**The LLM never produces a number that matters.** Pricing, hours and weeks are computed in PHP first; the model receives the human-readable answers, the hours, the week count and the team composition — **never a currency figure, the rate card or the qualification score** — and returns prose only, as strict JSON (`headline, summary, phases[], assumptions[], risks[]`).

Narration runs **after** the visitor submits contact details and consent (`POST /narrative`), not at preview time: the free-text field is personal data, and it should not travel to a third-party API before consent. It is asynchronous — the numeric result and a built-in narrative render first, the AI text swaps in when it arrives. There is no visible failure state.

Guards, in order: kill switch → provider configured → circuit breaker (5 consecutive failures open it for 15 min) → monthly budget → 30-day cache keyed on `sha1(answers + rate-card version + model + locale)` → the call (8 s timeout) → `ResponseValidator`: strips code fences, checks keys/types/lengths, rejects any currency symbol or money-shaped number, requires the phases' weeks to sum to the computed weeks (±1), strips HTML. Any failure → `FallbackNarrative` (a deterministic PHP template that satisfies the same contract). The free text goes through `PromptGuard` (role markers, `<|…|>`, "ignore previous instructions"… are stripped and logged) and is wrapped in a delimited block with instructions above and below.

**Providers.** `OpenRouterProvider` ships (strict `json_schema` output with `provider.require_parameters: true`, `:floor` routing toggle, `max_price` ceiling, `usage.include` for exact cost). `NullProvider` = fallback only. Add OpenAI or Gemini directly by implementing `ProviderInterface` and hooking `ct_est_ai_providers`.

**Models.** No slug is hardcoded. Settings → AI → *Refresh model list* pulls `GET /api/v1/models` with per-model pricing; if the field is empty the first `:free` model is suggested. Free models are rate-limited by OpenRouter (roughly 20 requests/min, 200/day) — fine for a demo; use a paid slug in production.

**Per-lead cost.** A narration prompt is ~700 input tokens and ≤700 output tokens (`max_tokens`, adjustable). On a typical small paid model (≈$0.10 / $0.40 per 1M tokens) that is **≈ $0.0004 per lead**; identical answer sets are cached and cost nothing. The Settings → AI strip shows this month's spend against the budget (`monthly_budget_cents`, default $5.00): at 80% an admin notice, at 100% the fallback takes over and the admin is emailed. The Sandbox shows the exact prompt, raw response, validation verdict, latency, tokens and cost for any answer set, with a "force fallback" toggle.

## Leads, snapshots and the share page

Leads are a private post type (`ct_estimate_lead`, Estimator → Leads): columns for contact, service line, range, weeks, colour-coded score, pipeline status (inline dropdown: New → Contacted → Qualified → Proposal sent → Won → Lost), AI status and share link; filters and sorting.

**The snapshot is non-negotiable.** On creation the lead stores the raw answers, the resolved labels, **the full rate card as it existed at that moment**, its version, the complete breakdown, the result, the narrative and which provider/model produced it. Change the rate card in March and the January lead still renders exactly what was quoted; the lead screen shows "Rate card v7 (superseded — current is v9)".

**Share page.** Every lead gets `/estimate/{32-char token}/` — a standalone, theme-less, responsive page that prints/saves-to-PDF cleanly (`share-print.css`; no PDF library needed), sends `X-Robots-Tag: noindex, nofollow`, expires (`share_days`, default 90) and can be disabled per lead. Expired or disabled links get a polite page with a contact CTA, not a 404. In B2B the person filling the form is rarely the person who signs — this is the link that gets forwarded to the CFO.

**Emails.** Sales notification (full breakdown table, score, answers, share link, reply-to the lead) and an optional confirmation to the lead with the share link. HTML with plain-text alternatives (`templates/email/`).

## n8n webhook

`Settings → Integrations`: URL + shared secret, "Send test payload" button (shows the exact request and the response). Dispatched via WP-Cron so the visitor never waits; 3 retries on exponential backoff (1 min, 5 min, 15 min); every attempt is logged on the lead with a "Resend" button.

Headers: `Content-Type: application/json`, `X-CT-Event: estimate.created`, `X-CT-Timestamp: <unix seconds>`, `X-CT-Signature: sha256=<hex HMAC-SHA256 of the raw body with the secret>`. The timestamp is repeated inside the signed body (`timestamp`), so a replay cannot alter it.

Payload:

```json
{
  "event": "estimate.created",
  "lead_id": 42,
  "created_at": "2026-08-27T15:08:10+00:00",
  "timestamp": 1787843290,
  "status": "new",
  "contact": { "name": "…", "email": "…", "company": "…", "phone": "…" },
  "service": { "line": "mobile", "label": "Mobile application" },
  "estimate": {
    "currency": "EUR", "price_low": 22500, "price_high": 34000,
    "hours": 618.4, "weeks": 21, "band": "mid", "band_label": "Mid-size engagement",
    "team": [ { "role": "pm", "label": "Project manager", "hours": 74.2 }, … ]
  },
  "qualification": { "score": 100, "parts": { "budget": 40, "urgency": 15, "scope": 20, "notes": 10, "maintenance": 10, "hosting": 5 } },
  "answers": { "service_line": "mobile", "mobile_framework": "flutter", … },
  "labels": { "mobile_framework": { "label": "Framework", "value": "Flutter" }, … },
  "notes": "…",
  "reveal_mode": "gated",
  "share_url": "https://example.com/estimate/UUfk…5k/",
  "admin_url": "https://example.com/wp-admin/post.php?post=42&action=edit",
  "rate_card_version": 7,
  "ai": { "status": "fallback", "model": "" }
}
```

Verify in an n8n **Code** node (place it right after the Webhook node, with "Raw Body" enabled on the webhook):

```js
const crypto = require('crypto');
const secret = 'YOUR_SHARED_SECRET';
const headers = $input.first().json.headers;
const rawBody = $input.first().binary?.data
  ? Buffer.from($input.first().binary.data.data, 'base64').toString('utf8')
  : JSON.stringify($input.first().json.body);

const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
const given = headers['x-ct-signature'] || '';
const fresh = Math.abs(Date.now() / 1000 - Number(headers['x-ct-timestamp'])) < 300;

if (given.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(given), Buffer.from(expected)) || !fresh) {
  throw new Error('Invalid estimator signature or stale timestamp');
}
return [{ json: JSON.parse(rawBody) }];
```

## Privacy / GDPR

- Required consent checkbox; the consent **text and its version** are stored with the timestamp on every lead.
- Personal-data **exporter and eraser** are registered with core (Tools → Export / Erase Personal Data). Erasure anonymises: personal fields, the free text and the narrative are removed, the share link disabled; the anonymous estimate stays for statistics.
- **Retention**: a daily cron anonymises leads older than `retention_days` (default 365).
- IP addresses are not stored unless `store_ip` is on, and then only as `wp_hash( ip )`.
- No third-party CAPTCHA — deliberately, so no visitor data goes to a bot-detection vendor. Bot defence is a honeypot field plus a signed time-on-form token (submissions under 3 s are rejected).
- No Google Fonts are loaded by the plugin; it inherits the theme's fonts.
- A suggested privacy-policy paragraph is registered (Settings → Privacy → Policy Guide).
- The AI provider receives answers, hours, weeks, team and the free text — never contact details — and only after consent.

## White-labelling

Every brand string, colour, logo and contact address lives in **one file**: [`src/Brand.php`](src/Brand.php) (or override at runtime with the `ct_est_brand` filter). Design tokens are CSS custom properties in [`assets/css/tokens.css`](assets/css/tokens.css) (`--ct-*`). Nothing else references the brand by name.

## Elementor, shortcode, WP-CLI

- Shortcode: `[cybertech_estimator service="web" mode="open" title="Estimate my project"]` (`service` pre-selects and skips step 1; `mode` overrides the setting).
- Elementor widget "Project Estimator" (category *Cybertech*) with title, service pre-filter, reveal-mode override and accent colour controls — registered only when Elementor is loaded.
- `wp ct-estimator seed` / `wp ct-estimator unseed` — 10 realistic demo leads spread over six weeks (also buttons under Settings → Diagnostics).

## Security notes

- Public endpoints use a named permission callback that runs the rate limiter (defaults 60 previews, 3 submissions, 6 narrations per hour per hashed IP **and** per session cookie) → HTTP 429 with a friendly message. Admin endpoints check `manage_options`.
- Input is validated against the questionnaire schema: unknown ids and out-of-range options are rejected, numbers clamped, free text stripped and capped at 1000 characters.
- Output is escaped at the point of output, including cached AI text.
- Share tokens are 32 random alphanumerics (`wp_generate_password`).

## Development

```bash
composer install       # dev tools only: phpcs (WordPress-Extra + Docs + PHPCompatibility), phpunit
bin/lint               # phpcs
bin/test               # phpunit (add --coverage-text; uses Local's PHP/Xdebug when present)
bin/build-zip          # dist/cybertech-estimator.zip
bin/wp …               # WP-CLI against the Local demo site
```

CI runs lint + tests on PHP 8.1 and 8.3; pushing a `v*` tag builds the zip and attaches it to a GitHub release.

Layout: `src/Engine` (pure pricing), `src/Ai`, `src/Security`, `src/Rest`, `src/Lead`, `src/Frontend`, `src/Admin`, `src/Integration`, `src/Privacy`, `templates/`, `assets/` (vanilla ES2020, plain CSS, no build step, no jQuery), `tests/Unit` (WordPress-free bootstrap with a handful of stubs), `demo/` (the demo theme reproducing cybertech.ro's UI; not shipped in the zip). Decisions are logged in [`docs/DECISIONS.md`](docs/DECISIONS.md), the build plan in [`docs/PLAN.md`](docs/PLAN.md).

## Roadmap

Written as a roadmap, not built:

- **Funnel analytics** — per-step drop-off, time per step, preview→submit conversion by service line and reveal mode.
- **CSV export** of leads with the breakdown flattened.
- **A/B testing of reveal modes** — assign a mode per session, report conversion.
- Direct OpenAI / Gemini providers via `ct_est_ai_providers`.
- Gutenberg block wrapping the shortcode.

## License

GPL-2.0-or-later. Author: Radu Chirilov — https://github.com/sudokku
