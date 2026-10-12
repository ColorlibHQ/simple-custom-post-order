# Repository Guidelines

## Project Structure & Module Organization

`simple-custom-post-order.php` contains the `SCPO_Engine` class, WordPress hooks, query ordering, AJAX handlers, and install/uninstall logic. `settings.php` renders the settings page shell; settings registration lives in the engine. `class-simple-review.php` handles review notices independently.

`assets/` contains JavaScript and CSS sources alongside generated minified files. `languages/simple-custom-post-order.pot` is the translation template. `tests/` holds development-only regression, HTTP, and browser suites. `readme.txt` supplies WordPress.org documentation; `CHANGELOG.md` tracks releases.

## Build, Test, and Development Commands

Use Node.js 14+, PHP 7.4+, and WordPress 6.2+. Activate locally and enable types under Settings → SCPOrder.

- `npm test`: run isolated PHP tests.
- `npm run test:local`: build, install, test, and restore the configured development site.
- `npm ci`: install locked development dependencies.
- `npm run build`: minify JavaScript/CSS and validate translation domains/regenerate the POT.
- `npx grunt minjs`: rebuild assets after JavaScript or CSS changes; commit generated files with their sources.
- `npx grunt release-check`: verify versions, changelogs, and upgrade notices agree.
- `npm run zip`: validate, rebuild, and create `simple-custom-post-order.zip`.
- `wp --path=/path/to/site eval-file tests/regression.php`: run database-backed regression checks.

## Coding Style & Naming Conventions

Follow existing WordPress-style PHP and JavaScript: tab indentation in plugin sources, spaced parentheses, single-quoted strings where practical, and snake_case PHP methods. Preserve existing `scpo_`/`scporder_` identifiers and public hooks. Match surrounding style in build scripts. Keep PHP compatible with 7.4 and translate user-facing strings using `simple-custom-post-order`.

Edit asset sources, then regenerate `*.min.js`/`*.min.css`. No standalone formatter or lint configuration is provided; Grunt checks translation domains.

## Testing Guidelines

Tests use PHP assertions, shell HTTP checks, and Puppeteer Core with Chrome; no coverage threshold is configured. Add descriptive regression assertions and confirm bug tests fail against the previous release.

Install every implementation increment into `local-wp` and run regression, transaction, HTTP, and browser checks before proceeding. Use `npm run test:local` for automatic snapshot/restore; see `tests/README.md` for configuration. Always restore site data after browser tests.

## Commit & Pull Request Guidelines

History uses imperative subjects such as `Fix …`, `Add …`, and `Release 2.8.9 — …`. PRs should explain the trigger, resulting behavior, and validation; reference relevant issues and include screenshots for visible admin changes. Follow `.github/RELEASING.md` when preparing a release.

## Security & Compatibility

Preserve nonce checks, per-object authorization, prepared SQL, output escaping, and cache invalidation when modifying order writes. Keep public filters, actions, and stored option structures backward compatible.
