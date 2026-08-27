# Changelog

All notable changes to this project are documented here. Format: Keep a Changelog; versioning: SemVer.

## [Unreleased]

### Added
- Phase 0: plugin bootstrap, SPL autoloader, `Brand` white-label config, `ct_estimate_lead` post type, PHPCS/PHPUnit tooling, CI and release workflows.
- Phase 1: declarative questionnaire, versioned rate card with defaults/history/rollback, pure pricing engine with step-by-step breakdown, team composer, qualification score, admin Sandbox and Rate card pages, 167 unit tests (engine at 100% lines).
- Phase 2: preview/submit/token REST endpoints, schema-driven input sanitizer, transient rate limiter (IP + session), honeypot with signed time-on-form token, reveal policy (open/band/gated), wizard frontend (shortcode, server-rendered fieldsets, vanilla JS, tokens.css).
- Phase 3: lead repository with immutable snapshot, share tokens and standalone `/estimate/{token}` page with print CSS, HTML + text emails, signed n8n webhook with cron dispatch and retries, leads list columns/filters/quick status, lead metaboxes, tabbed settings.
- Phase 4: AI layer — provider interface, OpenRouter and Null providers, prompt builder + injection guard, response validator, 30-day cache, budget guard, circuit breaker, deterministic fallback narrative, `/narrative` endpoint, sandbox AI diagnostics.
- Phase 5: privacy exporter/eraser, retention cron, policy text, demo seeder (admin + WP-CLI), uninstall, Playground blueprint.
