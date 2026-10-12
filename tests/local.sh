#!/usr/bin/env bash
# Build, install, and exercise a local development site; restore ordering afterward.
set -euo pipefail
: "${SCPO_WP:?Set SCPO_WP to an executable WP-CLI wrapper for your local site}"
: "${SCPO_SITE:?Set SCPO_SITE to your local site URL}"
: "${SCPO_MU_DIR:?Set SCPO_MU_DIR to the local site mu-plugins directory}"
REPO_DIR=$(cd "$(dirname "$0")/.." && pwd)
cd "$REPO_DIR"
TEST_ARTIFACTS=$(mktemp -d "${TMPDIR:-/tmp}/scpo-local.XXXXXX")
export SCPO_SNAPSHOT="$TEST_ARTIFACTS/snapshot.json"
export SCPO_COOKIE="$TEST_ARTIFACTS/admin.cookie"
printf 'Local test logs: %s\n' "$TEST_ARTIFACTS"
npm run zip > "$TEST_ARTIFACTS/build.log" 2>&1
"$SCPO_WP" eval-file tests/snapshot.php > "$TEST_ARTIFACTS/snapshot.log" 2>&1
restore() {
    test_status=$?
    trap - EXIT
    if ! "$SCPO_WP" eval-file tests/restore.php > "$TEST_ARTIFACTS/restore.log" 2>&1; then
        printf 'Restoration failed; inspect %s/restore.log\n' "$TEST_ARTIFACTS" >&2
        exit 1
    fi
    printf 'Site ordering/settings restored. Logs: %s\n' "$TEST_ARTIFACTS"
    exit "$test_status"
}
trap restore EXIT
"$SCPO_WP" plugin install "$REPO_DIR/simple-custom-post-order.zip" --force --activate > "$TEST_ARTIFACTS/install.log" 2>&1
"$SCPO_WP" eval-file tests/make-cookie.php | tail -1 > "$SCPO_COOKIE"
failed=0
for suite in regression transactions; do
    if ! "$SCPO_WP" eval-file "tests/$suite.php" > "$TEST_ARTIFACTS/$suite.log" 2>&1; then failed=1; fi
    tail -3 "$TEST_ARTIFACTS/$suite.log"
done
if ! bash tests/http.sh > "$TEST_ARTIFACTS/http.log" 2>&1; then failed=1; fi
tail -3 "$TEST_ARTIFACTS/http.log"
if ! node tests/e2e.mjs > "$TEST_ARTIFACTS/e2e.log" 2>&1; then failed=1; fi
tail -4 "$TEST_ARTIFACTS/e2e.log"
exit "$failed"
