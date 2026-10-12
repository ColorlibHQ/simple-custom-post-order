# Regression tests

After every implementation increment, install and test it on `local-wp`.
`npm run test:local` builds the zip, installs/activates it, runs the WordPress,
transaction, HTTP, and full Chrome suites, and restores ordering/settings even
when a suite fails. Restoration verifies post/term identifiers, order values,
and plugin options against the snapshot, failing if fixtures remain.
It leaves the new plugin build installed. Set `SCPO_WP` to
a single executable wrapper that supplies your PHP binary, WP-CLI, site path,
and local database socket; set `SCPO_SITE`, `SCPO_MU_DIR`, and `SCPO_LOG` as below.
Set `SCPO_PUPPETEER` if Puppeteer Core is installed outside this checkout.
Run this only against a development site. Logs and snapshots go to a unique
temporary directory printed by the runner.


Run `npm test` (or `php tests/unit.php`) for isolated failure-injection tests;
these need PHP 7.4+ but no WordPress installation or database. They load the
actual plugin with doubles for WordPress/database boundaries and cover failed
reads/writes, partial batches, reset failures, and the query opt-out. Set
`SCPO_PLUGIN_FILE=/path/to/older/simple-custom-post-order.php` to check an older
release. These tests supplement the real-site suites below.

`node tests/browser-reliability.mjs` checks error messages, retry recovery, and
HTTP failures in Chrome against both source and minified assets. It needs
`puppeteer-core` and Chrome, but no WordPress site or cookies. Set
`SCPO_PUPPETEER` to an absolute module entry path if installed outside this repo.

All suites return a nonzero exit status on failed assertions. Always restore the
snapshot after browser tests, including when they fail.

Development-only; excluded from the plugin zip. They run against a real WordPress
dev site with this plugin active and **Posts, Pages and Tags** enabled in
Settings → SCPOrder (plus a few dozen posts, a page tree and 40+ tags, so that
pagination is exercised).

| Suite | What it covers | Writes to the site? |
|-------|----------------|---------------------|
| `transactions.php` | Real InnoDB commit, rollback, savepoint, conflict, and unsupported-engine checks | Creates/drops its own scratch table; does not change content |
| `regression.php` | Query ordering, term ordering, prev/next, seeding, the AJAX handlers (called in-process), tree numbering, cache invalidation, reset, uninstall-adjacent guards | No — every write is inside a transaction that is rolled back |
| `http.sh` | Which admin screens load the sorter, tag-list pagination, list orders, settings page | No |
| `e2e.mjs` | Real drags and keyboard moves in headless Chrome: saving, page trees, Quick Edit, stray PHP output, expired nonce, Retry, tags, Classic engine, settings round-trip | **Yes** — snapshot first |

```bash
export SCPO_WP="/path/to/wp-local"             # executable wrapper, including site path
export SCPO_SITE="http://example.local"
export SCPO_LOG="/path/to/site/wp-content/debug.log"
export SCPO_MU_DIR="/path/to/site/wp-content/mu-plugins"

"$SCPO_WP" eval-file tests/regression.php
"$SCPO_WP" eval-file tests/make-cookie.php | tail -1 > tests/.cookie
bash tests/http.sh

"$SCPO_WP" eval-file tests/snapshot.php
npm i --no-save puppeteer-core                   # uses your installed Chrome (CHROME=…)
node tests/e2e.mjs
"$SCPO_WP" eval-file tests/restore.php
```

`regression.php` prints `RESULT: N passed, M failed`; run it against the previous
release as well, to confirm a new test really catches the bug it was written for.
