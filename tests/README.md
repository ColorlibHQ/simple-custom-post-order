# Regression tests

Development-only; excluded from the plugin zip. They run against a real WordPress
dev site with this plugin active and **Posts, Pages and Tags** enabled in
Settings → SCPOrder (plus a few dozen posts, a page tree and 40+ tags, so that
pagination is exercised).

| Suite | What it covers | Writes to the site? |
|-------|----------------|---------------------|
| `regression.php` | Query ordering, term ordering, prev/next, seeding, the AJAX handlers (called in-process), tree numbering, cache invalidation, reset, uninstall-adjacent guards | No — every write is inside a transaction that is rolled back |
| `http.sh` | Which admin screens load the sorter, tag-list pagination, list orders, settings page | No |
| `e2e.mjs` | Real drags and keyboard moves in headless Chrome: saving, page trees, Quick Edit, stray PHP output, expired nonce, Retry, tags, Classic engine, settings round-trip | **Yes** — snapshot first |

```bash
export SCPO_WP="wp --path=/path/to/site"        # any wp-cli invocation
export SCPO_SITE="http://example.local"
export SCPO_LOG="/path/to/site/wp-content/debug.log"
export SCPO_MU_DIR="/path/to/site/wp-content/mu-plugins"

$SCPO_WP eval-file tests/regression.php
$SCPO_WP eval-file tests/make-cookie.php > tests/.cookie
bash tests/http.sh

$SCPO_WP eval-file tests/snapshot.php
npm i --no-save puppeteer-core                   # uses your installed Chrome (CHROME=…)
node tests/e2e.mjs
$SCPO_WP eval-file tests/restore.php
```

`regression.php` prints `RESULT: N passed, M failed`; run it against the previous
release as well, to confirm a new test really catches the bug it was written for.
