# Cybertech Project Estimator

A WordPress plugin that replaces a `mailto:` "Estimate my project" link with a guided estimator: a five-step questionnaire priced by an editable rate card, an optional AI-written narrative that never touches a number, lead capture with an immutable snapshot of what was quoted, and a shareable, printable estimate page.

> Status: in development — see [`docs/PLAN.md`](docs/PLAN.md) for the build plan and [`docs/DECISIONS.md`](docs/DECISIONS.md) for the decision log.

## Install (development)

```bash
git clone https://github.com/sudokku/cybertech-estimator.git
cd cybertech-estimator
composer install            # dev tools only (phpcs, phpunit); the plugin itself has zero runtime deps
bin/lint                    # phpcs, WordPress-Extra
bin/test                    # phpunit
bin/build-zip               # dist/cybertech-estimator.zip — installable from Plugins → Add New → Upload
```

Symlink or copy the folder into `wp-content/plugins/cybertech-estimator` and activate. Requires PHP 8.1+ and WordPress 6.4+.

## Install (from a release)

Download `cybertech-estimator.zip` from the [latest release](https://github.com/sudokku/cybertech-estimator/releases/latest) and upload it under Plugins → Add New → Upload Plugin.

## White-labelling

Every brand string, colour, logo and contact address lives in one file: [`src/Brand.php`](src/Brand.php) (or override at runtime with the `ct_est_brand` filter). Nothing else in the plugin references the brand by name.

_(Sections on the pricing formula, reveal modes, the AI layer, the webhook payload and per-lead cost are added as each phase lands.)_
