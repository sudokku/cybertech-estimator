# Cybertech Project Estimator — Build Plan

Source brief: `~/Desktop/cybertech-estimator-build-prompt.md` (v. 2026-08-27).
Target: local WP at `~/Local Sites/cybertech-cta-demo` → http://cybertech-cta-demo.local/
(WP 7.1, PHP 8.2.29 + Xdebug, nginx :10003, MySQL :10004 via socket, Mailpit :10000, admin `root`/`1234`).

## 0. Environment facts (verified)

| Item | Value |
|---|---|
| Site URL | http://cybertech-cta-demo.local/ (200 OK), permalinks `/%postname%/` |
| WP | 7.1 (brief targets 6.4+; we declare `Requires at least: 6.4`, `Tested up to: 7.1`) |
| PHP (site) | Local lightning PHP 8.2.29, Xdebug present (`xdebug.mode=off`, enable per-run for coverage) |
| PHP (host) | 8.4.1 (herd-lite) — used for phpcs only |
| DB | `local` / root / root, socket `~/Library/Application Support/Local/run/_JiKY9VLl/mysql/mysqld.sock` (TCP 127.0.0.1:10004 refuses — must use socket) |
| Mail | Mailpit UI http://127.0.0.1:10000 — all wp_mail output is inspectable here |
| Tooling missing | wp-cli (→ `brew install wp-cli` + a `bin/wp` wrapper that uses Local's php + php.ini so it hits the right socket) |
| Elementor | free 4.2.3 (requires WP 6.8, tested 7.1) + Hello Elementor 3.5.1 installable from wp.org for widget verification |
| Playwright MCP + dev-browser | available for E2E/visual checks |

## 1. Repo layout & git

```
~/Developer/cybertech-estimator/          ← git repo root == the plugin (as in the brief §3)
├── cybertech-estimator.php … src/ assets/ templates/ languages/ tests/
├── demo/                                 ← NOT shipped in the plugin zip (.distignore)
│   ├── theme/cybertech-demo/             ← classic theme reproducing cybertech.ro UI/UX
│   └── seed/                             ← wp-cli script: pages, menu, settings, demo leads
├── bin/wp, bin/test, bin/lint            ← dev wrappers (Local php + ini)
└── docs/PLAN.md, docs/DECISIONS.md       ← this plan + the running decision log
```

- Plugin is **symlinked** into `wp-content/plugins/cybertech-estimator`; theme symlinked into `wp-content/themes/cybertech-demo`. (WP supports symlinked plugins; fallback = rsync if `plugin_dir_url()` misbehaves under nginx.)
- Commit at every phase boundary (brief §16) plus smaller commits inside phases; conventional messages `phase1: pricing engine + tests`.
- Distribution: `bin/build-zip` → `dist/cybertech-estimator.zip` (excludes demo/, tests/, bin/, docs/, composer dev files). `blueprint.json` installs the zip from the GitHub release URL.

## 2. Decisions & push-backs on the brief

Numbered so you can veto individually.

**D1 — AI narration only after consent (post-submit), never at preview.**
Brief §12 says the narrative streams in after the numeric result; §6 keys the cache on the answers. Two problems if done at preview time: (a) the free-text field is personal data leaving the EU to a paid US API *before* the consent checkbox is ticked — for a GDPR-sensitive audience that's a defect; (b) it spends API budget on anonymous non-leads. Proposal: `POST /submit` creates the lead and returns the numeric result + fallback narrative instantly; the wizard then calls `POST /narrative` (with the lead's share token) and swaps in the AI text when it arrives. Same UX, cleaner story. Sandbox can call the LLM at will (admin-only).

**D2 — No true streaming.** `wp_remote_post` is blocking; "streams in" = one async follow-up request with a skeleton placeholder. Nothing on the page is ever blocked by it.

**D3 — Step order depends on reveal mode.**
- `open`: answers → result (figures + timeline) → contact/consent → full result + narrative + share link
- `band`: same, but band + timeline instead of figures. Figures never shown to the visitor anywhere (result, share page, confirmation email). Sales email has them.
- `gated`: answers → blurred placeholder card + contact overlay → submit → full result. The `/preview` response in gated mode carries only `{weeks, band, hours_band}`… actually **nothing numeric**: `{ready:true}`. Figures only ever leave the server in the `/submit` response.

**D4 — Calculation-order fix.** Brief step 7 (hours→price weighted by role allocation) needs the team composition from step 11. Engine computes allocation right after the min-hours clamp (6→"6b team allocation"→7), and step 11 just reports it. Breakdown logs it in that order.

**D5 — JS delivery.** "ES2020 modules" + WP 6.4 + `wp_localize_script` don't combine: `wp_enqueue_script_module()` can't be localised before WP 6.7. Ship `wizard.js` as one modern ES2020 file (no bundler, no `import`), enqueued with `strategy => 'defer'`, config injected with `wp_add_inline_script( wp_json_encode(...) )` (not `wp_localize_script`, which stringifies every value). Translations for JS go in that same config object (server-side `__()`), so no `.json` translation build step is needed. `wp_set_script_translations` dropped as redundant.

**D6 — Rate-card factor model gets one extension: `per_unit`.** Number questions (page templates, screens, workflows, integrations) need "×N hours per unit". Factor types stay `multiplier | add_hours | add_price`; a `per_unit: true` flag multiplies `value` by the numeric answer. Everything still editable in the rate card.

**D7 — Team bands are per service line.** A UI/UX engagement is ~60% designer; a web build ~10%. One global band table would price design work at SSE rates. `team_bands[service_line][] = {max_hours, roles{%}}`.

**D8 — Default model slug is empty on install.** Brief forbids hardcoding a slug. On the AI tab, "Refresh model list" populates the datalist; if the field is empty we auto-suggest the first `:free` slug returned. Until a slug and key exist → `NullProvider` → fallback. The demo seeder marks its leads as `fallback`.

**D9 — Demo site = hand-coded classic theme, Elementor installed only to prove the widget.**
Rebuilding cybertech.ro *inside* Elementor (writing `_elementor_data` JSON by script) is slow, fragile, and unverifiable by agents. A small classic theme (`demo/theme/cybertech-demo`, ~6 templates, one CSS file sharing `tokens.css`) reproduces the header/hero/services/clients/process/footer layout with their tokens, and swaps the header `mailto:` CTA for the estimator page. Elementor free + Hello theme get installed on the same site to verify the widget registers, renders and its controls work — that is what matters on *their* site. Their imagery is not copied; layout, type, colour, spacing and copy structure are. Client logos rendered as text badges (no third-party logo redistribution).

**D10 — Testing tiers.**
- PHPUnit (no WP test suite): pure engine + validators + guard. Bootstrap stubs the 4–5 WP functions those classes touch (`__`, `esc_html`, `wp_strip_all_tags`, `wp_hash`) — keeps tests instant and Composer-free at runtime. Coverage via Local's Xdebug.
- Integration: wp-cli `eval-file` smoke scripts (activate, seed, REST calls with `wp rest`/curl).
- E2E: Playwright MCP against the demo site (wizard walk-through in all three modes, gated-mode network assertion, 360px, keyboard-only, share page print CSS).
- Static: phpcs (WordPress-Extra + PHPCompatibility 8.1+) + `php -l` across Local's 8.2.

**D11 — Budget guard spend tracking.** OpenRouter's response `usage` gives tokens; we also request `usage: {include: true}` which returns `cost` in USD. Store cents as integer; the monthly counter is an option keyed by `Y-m`. Model price list cached from the models endpoint (24h transient) for the fallback estimate.

**D12 — Locale.** cybertech.ro is English-only (no switcher, no RO copy). Demo site stays `en_US` to match; the complete `ro_RO` translation is verified by switching Settings → Language and walking the wizard + share page in RO (screenshots in both). Wizard language follows the site locale; the LLM is told the locale and writes prose in it.

## 3. Proposed default rate card (brief §16: propose, don't invent silently)

Given in the brief: blended €45/h; role rates pm 40 / sse 55 / be 55 / devops 50 / qa 35 / fe_junior 28 / design 40; urgency 0.95 / 1.0 / 1.25 / 1.5; spread ±20%; capacity 30 h/week; contingency 10%; web base 80h / min 40h; Magento ×1.8.

Proposed (all editable in admin; reasoning = "small agency, blended €45/h, cross-platform-first"):

| Service line | base_hours | min_hours | why |
|---|---|---|---|
| web | 80 | 40 | given |
| mobile | 160 | 80 | one cross-platform app shell + store submission ≈ 4 team-weeks |
| design | 60 | 24 | discovery + core screens |
| ai | 60 | 24 | one n8n workflow + one integration + handover |

| Factor (id) | applies | type | value | note |
|---|---|---|---|---|
| web_platform_{wordpress,drupal,joomla,django,custom} | web | add_hours | 0 / 40 / 24 / 60 / 120 | WP is the baseline; custom = own backend |
| web_ecommerce_{none,woocommerce,prestashop} | web | add_hours | 0 / 40 / 60 | catalogue + checkout + payments |
| web_ecommerce_magento | web | multiplier | 1.8 | given |
| web_templates | web | add_hours, per_unit | 6 | per unique page template (1–40) |
| web_multilingual | web | multiplier | 1.25 | content ops + i18n QA |
| web_integrations | web | add_hours, per_unit | 12 | per third-party API (0–10) |
| web_migration | web | add_hours | 24 | content migration + redirects |
| mobile_framework_{flutter,react_native,ionic} | mobile | multiplier | 1.0 / 1.0 / 0.9 | choice barely moves cost — deliberately visible |
| mobile_platforms_{ios,android} / both | mobile | add_hours / multiplier | 0 / 0 / ×1.3 | dual store QA + submission |
| mobile_offline | mobile | add_hours | 40 | sync + conflict handling |
| mobile_auth | mobile | add_hours | 24 | |
| mobile_payments | mobile | add_hours | 32 | PSP + PCI + store rules |
| mobile_push | mobile | add_hours | 16 | |
| mobile_backend_{existing,needed,none} | mobile | add_hours | 16 / 80 / 0 | |
| design_deliverable_{research,wireframes,hifi,prototype,design_system} | design | add_hours | 24 / 16 / 24 / 16 / 40 | multi-select, additive |
| design_screens | design | add_hours, per_unit | 3 | per screen (1–100) |
| design_brand | design | add_hours | 40 | identity work |
| design_testing_rounds | design | add_hours, per_unit | 12 | per usability round (0–5) |
| ai_workflows | ai | add_hours, per_unit | 16 | per n8n workflow (1–20) |
| ai_provider_{openai,gemini,open_weight,undecided} | ai | add_hours | 0 / 0 / 24 / 8 | open-weight = hosting + evals; undecided = discovery |
| ai_voice_vapi | ai | add_hours | 40 | |
| ai_systems | ai | add_hours, per_unit | 12 | per system (0–10) |
| ai_data_{small,medium,large} | ai | multiplier | 1.0 / 1.15 / 1.35 | |
| ai_hitl | ai | add_hours | 16 | review UI + audit trail |
| ctx_hosting_{client,cybertech,undecided} | all | add_hours | 0 / 16 / 8 | DevOps setup |
| ctx_maintenance | all | (none) | — | qualification + narrative only; retainer is priced separately |

Team bands (`% of hours`, per service line, by total hours ≤120 / ≤400 / >400):
- web: pm 10/12/15 · sse 40/30/25 · be 0/20/25 · devops 5/6/8 · qa 15/15/15 · fe_junior 20/12/7 · design 10/5/5
- mobile: pm 10/12/15 · sse 45/35/30 · be 15/20/20 · devops 5/6/8 · qa 15/17/17 · fe_junior 5/5/5 · design 5/5/5
- design: pm 10/10/10 · design 70/65/60 · sse 5/10/15 · qa 5/5/5 · fe_junior 10/10/10 (be/devops 0)
- ai: pm 12/12/15 · sse 40/35/30 · be 25/30/30 · devops 8/8/10 · qa 10/10/10 · fe_junior 0 · design 5/5/5

Reveal `band` thresholds (on the range midpoint): Small < €10k · Mid-size €10k–€40k · Enterprise ≥ €40k.

Budget bands (qualification only): `<5k · 5–15k · 15–40k · 40–100k · >100k · undisclosed`.

Qualification score (0–100, admin-only):
- budget vs range (40): band ≥ range-high 40 · overlaps 30 · below low by <50% 15 · far below 0 · undisclosed 20
- urgency (15): urgent 15 · normal 12 · asap 10 · flexible 8 (ASAP + tiny budget is a tyre-kicker pattern)
- scope (20): <80h 8 · 80–300 15 · 300–800 20 · >800 15
- free text ≥40 chars (10) · maintenance interest (10) · hosting by Cybertech (5)
- colours: ≥70 green · 40–69 amber · <40 red

## 4. Phases, agents, gates

Each phase: I write the spec/skeleton, spawn coding agents on disjoint directories, then a reviewer agent + my own verification, then commit. "Stop and summarise" per brief §13 → I report after each phase; if you're away I continue (you said one-shot).

| Phase | Deliverable | Agents | Gate (must pass before commit) |
|---|---|---|---|
| 0 Scaffold | repo, bootstrap, autoloader, constants, Activator, Brand.php, Lead CPT, phpcs.xml, composer dev, bin/ wrappers, wp-cli, symlinks, README skeleton | me | plugin activates on WP 7.1 with `WP_DEBUG=true`, zero notices; phpcs clean |
| 1 Engine | Questionnaire, RateCard(+Defaults, versioning, history), PricingEngine, Breakdown, TeamComposer, EstimateResult; PHPUnit; Sandbox page (results + breakdown w/ source links + JSON) | 1 engine coder, 1 test writer (adversarial: writes tests from the brief's formula, not from the code), 1 sandbox UI coder | phpunit green, PricingEngine 100% lines, hand-checked worked example matches §5 order |
| 2 Wizard | REST preview/submit, InputSanitizer, RateLimiter, Honeypot, WizardRenderer + templates, wizard.js, tokens/wizard CSS, 3 reveal modes, shortcode | 1 REST/security coder, 1 frontend coder, 1 Playwright tester | E2E all modes; gated: figures absent in network; 429 verified; a11y keyboard-only; 360px |
| 3 Leads | LeadRepository, Snapshot, ShareToken, share page + print CSS, emails (Mailpit-verified), WebhookDispatcher (HMAC, cron, retries, test button), admin columns/metaboxes/pipeline quick-edit | 2 coders (lead+share / integrations+admin) | rate-card change ≠ old lead; share link noindex/expiry; webhook signature verified with a local receiver script |
| 4 AI | Provider interface, OpenRouter, Null, registry filter, PromptBuilder, PromptGuard, ResponseValidator, NarrativeService, cache, BudgetGuard, CircuitBreaker, Fallback; Sandbox AI panel | 1 coder, 1 adversarial reviewer (injection + malformed responses), tests | full estimate with no key / invalid key; injection logged; validator unit tests |
| 5 Platform | i18n + ro_RO (.pot/.po/.mo), privacy exporter/eraser/retention/policy text, Elementor widget, uninstall.php, DemoSeeder (+ WP-CLI), blueprint.json | 1 translator agent (RO native-quality pass), 1 coder | export/erase through Tools; no English leak in RO; widget renders in Elementor editor |
| 6 Demo site + polish | `cybertech-demo` theme, seed script (pages, menu, estimator page as the CTA), a11y + mobile pass, README/CHANGELOG/readme.txt, screenshots, phpcs final | 1 theme coder (from research notes + screenshots), 1 visual QA agent comparing against cybertech.ro screenshots | side-by-side screenshots; definition-of-done checklist §14 all ticked |

MVP cut if time is short: Phases 0–3 + FallbackNarrative = a complete, sellable estimator with no AI. Phase 4 is additive by design.

## 5. Demo site reproduction (from research, `tmp/research/cybertech-site-notes.md`)

cybertech.ro = WordPress + Elementor on the **Trydo** theme (Redux options), one-page home with anchor nav. Only `/services/` and `/portfolio/` are real pages.

Tokens (computed from the live site → go into `tokens.css` and the theme):
```css
--ct-color-primary:#1C67FA; --ct-color-primary-light:#4ECEEE;
--ct-gradient:linear-gradient(145deg,#1C67FA,#4ECEEE);
--ct-color-bg:#FFFFFF; --ct-color-bg-dark:#191919; --ct-color-hero:#000; --ct-color-header-sticky:#1F1F25;
--ct-color-ink:#1F1F25; --ct-color-body:#717173; --ct-color-nav-muted:#C6C9D8; --ct-color-border:#E9E9E9; --ct-color-surface:#F8F8F8;
--ct-font-heading:"Montserrat"(600/800); --ct-font-body:"Poppins"(400–800);  /* Google Fonts, same as their Elementor kit */
h1: 800 80/80 Montserrat uppercase ls 4px (mobile 30/35) · section h2: 600 35px Poppins · subtitle 500 25px Poppins in primary
container 1024px · section padding 80px · header 128px transparent → 68px fixed #1F1F25 · logo 28px high (white PNG only)
button: 2px outline currentColor, radius 0, 10px 25px, 600 15px Poppins ls 2px; hover → #1C67FA border/colour + lift -5px + blue shadow · card radius 5px
```

`cybertech-demo` theme (classic, `demo/theme/`) — pages & sections to reproduce:
1. **Home**: black hero (100vh) with a lightweight canvas dot-wave (our own ~80-line 2D-canvas version of their three.js particle field; respects reduced motion) + "27 YEARS OF EXPERTISE" / "NAVIGATING THE DIGITAL OCEAN SINCE 1999"; Alida strip; dark Services band with silk-wave background + 2×2 cards (their 4 service lines, blue icons); Our Clients (hatched dividers Enterprise / Startups / NGO's, logo wall as text badges); About (33/66 with counters 27 / 500+ / 80%); Team heading + placeholder photo; **Contact band** with "Dive in With Us" + the **Estimate my project** button now opening the estimator instead of a mailto; charcoal footer with ALANTIS WEB STUDIO S.R.L. legal line + RO legal links.
2. **Services**: wave hero "OUR SERVICES EXPLAINED", 4 stacked service sections, contact band.
3. **Estimate my project** (`/estimate/`): the wizard, full-width on a light section under a short dark hero. Also linked from header nav as a primary button (the only addition to their nav — it's the point of the pitch).
4. **Share page** `/estimate/{token}` is theme-less by spec, but styled with the same tokens (dark hero strip, white body, print CSS).
Copy is reproduced from their site (typos fixed, "24/27 years" normalised to 27). Their photos are not copied — placeholders in their colour palette. Logo: their white wordmark PNG in `Brand.php` + theme header (dark header keeps it legible, as on their site).

Elementor verification lane: install Elementor 4.2.3 + Hello Elementor on the same site, create one Elementor page "Estimator (Elementor)" with the widget, screenshot editor + frontend, then switch the active theme back to `cybertech-demo`.

## 6. Open questions (answers → docs/DECISIONS.md)
Q1 header FILL INs: Author name / Author URI / Plugin URI (GitHub) — and should I create the GitHub repo (public, needed for the Playground blueprint zip URL)?
Q2 OpenRouter key: paste into Settings → AI yourself later, or define `CT_EST_OPENROUTER_API_KEY` in wp-config (plugin reads the constant, never stores it)? Without a key I still verify the fallback path fully.
Q3 Default reveal mode for the demo: `gated` (recommended — strongest lead-gen demo and exercises the hardest path) vs `open`.
Q4 `band` mode semantics: figures hidden from the visitor everywhere (share page + confirmation email too), sales email only. OK?
Q5 D1 (narrative only after consent/submit) — OK?
Q6 Rate-card defaults in §3 — accept as proposed, or edit?
Q7 Demo site: hand-coded theme + Elementor only for widget proof (D9) — OK? Or do you want the demo pages built as Elementor pages?
Q8 Repo location `~/Developer/cybertech-estimator` with `demo/` inside (monorepo) — OK?
