# Releasing Simple Custom Post Order

The prepared release is **2.8.10**, dated **2026-10-12**. Git tags use plain
versions (`2.8.10`, without `v`), matching existing repository tags. If the
release date changes, update both changelogs before rebuilding.

## Validate the release

Keep the plugin header, `SCPORDER_VERSION`, `package.json`, both root versions
in `package-lock.json`, readme Stable tag, top changelog entries, and Upgrade
Notice synchronized. Regenerate the POT so its Project-Id-Version matches.
Keep historical version references intact. Only raise WordPress/PHP
compatibility claims after testing those versions.

```sh
npm ci
npm test
npm run test:local
node tests/browser-reliability.mjs
npx grunt release-check
git diff --check
unzip -l simple-custom-post-order.zip
```

Configure the local runner and Chrome dependency as described in
[`tests/README.md`](../tests/README.md). The runner builds and installs the ZIP
on `local-wp`, tests it, and verifies restored ordering/settings. Keep the test
logs. Confirm the PHP CI matrix passes on the release commit before publishing.

Review and commit all intended source, generated assets, POT, tests, and
documentation. The generated ZIP is ignored by Git. Confirm the working tree
is clean and use that exact tested ZIP for both distribution channels; do not
substitute GitHub's automatically generated source archive.

## WordPress.org SVN

Use a separate SVN working copy. Check that `2.8.10` has not already been
published. Follow WordPress.org's [tagging procedure](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/):
update `trunk`, copy it to `tags/2.8.10`, then commit both together.
The [Stable tag](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
must match the tag directory. Never rewrite an already published tag.

From the Git checkout, prepare the tested ZIP for copying:

```sh
SCPO_RELEASE_DIR=$(mktemp -d)
unzip -q simple-custom-post-order.zip -d "$SCPO_RELEASE_DIR"
svn checkout https://plugins.svn.wordpress.org/simple-custom-post-order /path/to/scpo-svn
rsync -av --delete --exclude=.svn/ "$SCPO_RELEASE_DIR/simple-custom-post-order/" /path/to/scpo-svn/trunk/
cd /path/to/scpo-svn
svn add --force trunk
svn status
svn diff
```

Run `svn delete` for any removed files listed with `!` in `svn status`.
Review all additions/removals before proceeding. The main plugin file must
sit directly in `trunk`; preserve the SVN root `assets/` directory. Repository
guides, tests, Node dependencies, and `.github/` do not belong in the upload.

Once the reviewed package is ready to publish:

```sh
svn copy trunk tags/2.8.10
svn status
svn commit trunk tags/2.8.10 -m "Release 2.8.10 — safer order saves and conflict recovery"
```

Complete release confirmation if WordPress.org requests it. Check the plugin
page and download the served ZIP to verify version and packaged file contents.

## GitHub

From the clean, tested release commit in the Git checkout:

```sh
git tag -a 2.8.10 -m "Release 2.8.10 — safer order saves and conflict recovery"
git push origin HEAD
git push origin 2.8.10
gh release create 2.8.10 simple-custom-post-order.zip \
  --repo ColorlibHQ/simple-custom-post-order \
  --verify-tag --draft \
  --title "2.8.10 — Safer order saves and conflict recovery" \
  --notes-file .github/release-notes/2.8.10.md
```

The [GitHub CLI release command](https://cli.github.com/manual/gh_release_create)
attaches the installable ZIP and uses the prepared notes. Review the draft,
confirm its tag points to the tested commit, and publish it on GitHub. Check
both channels show 2.8.10 and the InnoDB upgrade requirement.
