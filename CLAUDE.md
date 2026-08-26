# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Simple Custom Post Order is a WordPress plugin that enables drag-and-drop reordering of posts, pages, custom post types, and taxonomies in the WordPress admin. It uses WordPress's built-in `menu_order` field for posts and a custom `term_order` column added to the `wp_terms` table for taxonomies.

## Build Commands

```bash
# Minify admin assets — JS (uglify) + CSS (cssmin)
grunt minjs

# Generate/update translation files (checktextdomain + makepot)
grunt i18n

# Create distribution ZIP
grunt build-archive
```

`grunt minjs` is only needed when something under `assets/` changes — it rebuilds
`*.min.js`/`*.min.css` from source and is a no-op otherwise. `grunt i18n` writes
`languages/simple-custom-post-order.pot`; **before 2.8.7 the Gruntfile's
`potFilename` said `.po`**, so every run regenerated a mislabelled template and
never touched the real `.pot`, which sat at v2.7.0 (62 strings) while the plugin
reached 2.8.6 — every string added by the 2.8.x Order column, role settings and
error messages was missing from it. The leftover `languages/*.po` (a stale 2019
v2.4.7 copy of that template) and `*.mo` (a compiled catalogue containing only its
own header, zero translations) are vestigial; wordpress.org builds translations on
translate.wordpress.org by parsing the source, not from these files.

Nothing reads `package.json`'s `version` — the Gruntfile only uses `pkg.name`, for
the zip filename — so bumping it is for consistency, not for the build.

## Architecture

The plugin is a single monolithic `SCPO_Engine` class (~1700 lines) that does everything: settings, query interception, AJAX, and asset loading. There is no autoloader or namespace — a 3.0.0 split into `includes/class-scpo-*.php` is planned (described in `MODERNIZATION-PLAN.md`, which is gitignored, so it may not be present in a fresh checkout).

### Core Files

- **simple-custom-post-order.php** — The `SCPO_Engine` class plus standalone `scporder_doing_ajax()`, `scporder_uninstall()`, and `scporder_uninstall_db()` functions. Instantiated immediately as the global `$scporder`.
- **settings.php** — Only the settings *page shell* (`do_settings_sections()`), the Reset Order form, and the Support section. The actual settings fields are registered and rendered via the **WordPress Settings API inside `SCPO_Engine`** (`register_settings()`, `render_post_types_field()`, `sanitize_options()`, etc.) — not here.
- **class-simple-review.php** — Self-instantiating `Simple_Review` class; WordPress.org rating nag, fully independent of `SCPO_Engine`. Uses the legacy `epsilon_simple_review` AJAX action / `simple-rate-time` option. **It did not run at all between the `init` move and 2.8.7**: `load_dependencies()` fires on `init`/10 and includes this file, whose constructor then registered another `init`/10 callback — and `WP_Hook::apply_filters()` walks a priority's callbacks with a by-value `foreach`, so a callback added to the priority currently executing is never seen (reported by @jamieburchell). The constructor now runs `init()` directly when `did_action( 'init' )`, so the class is safe to include at any point. Three things were hardened at the same time, because simply switching the notice back on would have regressed live sites: it is vanilla JS instead of jQuery (it used to `wp_enqueue_script( 'jquery' )` on every admin page and print an un-ordered inline jQuery block); translated strings no longer go through `sprintf()`; and `value()` reschedules a `simple-rate-time` more than 90 days in the past to `+1 week` rather than firing instantly on every upgraded install at once — future values (a previous *Don't show again*) are never touched.
- **assets/scporder-sortablejs.js** — ⭐ **The default sorter** (since 2.7.0). Vanilla JS layer on top of SortableJS (`assets/vendor/Sortable.min.js`). Drives posts/pages (`table.posts/pages #the-list`) and taxonomies (`table.tags #the-list`): whole-row mouse/touch drag, keyboard + screen-reader reordering (focus-revealed grip handle), save toast, single-flight save queue with retry, same-origin AJAX, and nonce auto-refresh. Enqueued as `scporder-sortablejs.min.js` (source only under `SCRIPT_DEBUG`).
- **assets/scporder.css** — Admin styles for the SortableJS path (grip handle + its show/hover/focus states, save toast, drag classes, reduced-motion). Enqueued as `scporder.min.css`.
- **assets/scporder.js** — Legacy **jQuery UI Sortable** path, retained as an opt-out fallback (Settings → engine "Classic", or `scpo_use_sortablejs` filter → `false`). Enqueued as `scporder.min.js`. Fire-and-forget AJAX, no UI feedback. (The old dead `taxonomy_order.js` was removed in 2.7.0.)
- **assets/scporder-order-column.js** — Drives the optional numeric **"Order" column** (2.8.0, off by default). A small standalone vanilla script (no SortableJS/jQuery dep) that lives only on `edit.php` for enabled non-hierarchical types; typing an absolute 1-based position into the `.scpo-order-input` field POSTs `scpo_set_position`. Localized as `scpoOrderCol` (`ajax_url`, `nonce` on `scporder_nonce_action`, and the `error`/`expired`/`network` strings). Enqueued as `scporder-order-column.min.js`; its tiny inline style is printed by `print_order_column_style()`. Built by the same `grunt minjs`. **Since 2.8.6 its error handling mirrors the sorter's** — tolerant `JSON.parse` (stray output from other plugins no longer reads as failure for a save that succeeded), `-1` stale-nonce detection → `scpo_refresh_nonce` → one transparent retry, one 800ms network retry, and the server's own `data.message` surfaced instead of one generic alert. Before 2.8.6 it had none of that, so an expired nonce, a permissions problem, and a foreign PHP notice were indistinguishable — all three read as "Couldn't update the order — please try again" (reported by @nlarenas).

### How ordering actually works (the non-obvious core)

Saving order is the easy half (AJAX writes `menu_order`/`term_order`). The harder half is making *reads* respect that order, done by intercepting WordPress queries:

- `pre_get_posts` → `scporder_pre_get_posts()` forces `orderby=menu_order, order=ASC` for enabled post types (admin list screens and front-end, skipping search and explicit `orderby`).
- `get_previous_post_where` / `get_previous_post_sort` / `get_next_post_where` / `get_next_post_sort` → rewrite adjacent-post navigation SQL to walk `menu_order` instead of `post_date`.
- `get_terms_orderby` → swaps term ordering to `t.term_order`; `wp_get_object_terms` + `get_terms` → re-sort returned terms via `usort()`/`taxcmp()` (spaceship on `term_order`).

`refresh()` re-normalizes `menu_order`/`term_order` into a gapless 1..N sequence for enabled types (skips a type when already contiguous, via a `COUNT`/`COUNT(DISTINCT)`/`MAX`/`MIN` pre-check in `is_already_sequential()` — **all** of `MAX === COUNT`, `MIN === 1` and `COUNT(DISTINCT) === COUNT` must hold. This guard has been wrong twice: the original MAX-only test passed `{-1,2,3,4,5}` as clean (fixed 2.8.5 by adding `MIN === 1`), and the MAX+MIN test still passed any duplicate with a compensating gap, e.g. `{1,2,2,4,5}` — so duplicates were never repaired, and since the drag handler re-deals the *existing* set of values, two tied rows made dragging them a literal no-op (fixed 2.8.7 by adding the DISTINCT test; reported by @literayz)). It runs on non-AJAX `admin_init` **but only on the sortable list screens** — gated behind `_check_load_script_css()` since 2.8.4. Before 2.8.4 it ran on *every* admin page and renumbered dirty types one `$wpdb->update()` per row, which on large sites added thousands of queries and multi-second TTFB to unrelated admin screens (Plugins/Dashboard/Tools/Settings) — reported by @crossy. The gate is safe because nothing needs gapless numbering to be *correct* (drag saves preserve the value set; the Order column and new-item placement compute from live rows; all reads use `ORDER BY menu_order`/`term_order`, which sort fine with gaps) — normalization only needs to happen right before the ordered list is rendered. The actual renumber is now a single **batched `CASE` UPDATE** (chunked at 1000 rows) via the `renumber_rows()` helper, not a per-row loop. This is also why a freshly enabled type gets seeded order in `sanitize_options()` (pages → alphabetical, other posts → by date, terms → by name) — that seeding goes through `renumber_rows()` too, so it is batched and cache-safe rather than the old per-row `$wpdb->update()` loop.

**Seeding must never overwrite an existing order** (2.8.8). `sanitize_options()` runs for **every enabled type on every settings save**, not just newly enabled ones, and it used to order by `post_title`/`post_date` alone — so ticking Pages (or merely re-saving the settings screen) alphabetised the site, discarding WordPress's own Page Attributes → Order values and anything a previously-removed sorting plugin had left behind (reported by @martinsauter, diagnosed by @jamieburchell). It now seeds from scratch **only when there is nothing to preserve** — `distinct_cnt === 1`, i.e. every row of the type sharing one value, which is what an untouched site looks like. Otherwise it follows the existing sequence. Crucially the tie-break is `ID ASC`, **the same as `refresh()`**: both paths renumber the same rows and a site hits whichever runs first, so a title-based tie-break here made a tied pair settle one way after a settings save and the other way after simply opening the list. Note 2.8.7's `COUNT(DISTINCT)` addition briefly widened which sites hit the destructive seeding (a `{1,2,2,4}` type was skipped in 2.8.6, reseeded in 2.8.7) — moot now that seeding is non-destructive.

**Hierarchical drag carries the subtree** (2.8.8). WordPress renders a page tree as one flat run of `<tr>`s, each tagged with a `level-N` class, so SortableJS moved only the dragged row and its children visibly stayed put until reload (reported by @jamieburchell). The *saved* result was always correct — the list table re-nests children under their parent on the next render and `post_parent` is never touched — so this was cosmetic, not corruption. `descendantsOf()`/`reattach()`/`afterSubtree()` in `assets/scporder-sortablejs.js` now move a row's whole subtree on mouse drag, keyboard move and keyboard cancel; `rowLevel()` returns `null` on flat lists, making all of it inert for posts/CPTs/non-hierarchical taxonomies. **This is not #58** — constraining a drag to its own level is still 2.9.0.

**Which rows count as orderable** (2.8.7): every order query — the `refresh()` probe and renumber, the `sanitize_options()` seeding, new-item placement, and `scpo_set_position` — takes its post-status set from `order_post_statuses()`, i.e. `get_post_stati( [ 'show_in_admin_all_list' => true ] )`. That is exactly the set a list table's "All" view shows, so the rows the plugin numbers match the rows the user can see and drag. These queries previously hard-coded `('publish','pending','draft','private','future')`, which silently excluded any status registered by another plugin (editorial workflows, "archived", …): such a row still rendered and was still draggable, but was skipped by the renumber, so it kept its original `menu_order` (usually `0`) while its neighbours were numbered from `1` — a primary source of the permanent duplicates above. On a stock site `show_in_admin_all_list` resolves to those same five statuses, so the change is purely additive. The statuses are bound through `$wpdb->prepare()`; only the generated `%s` placeholder list (`order_post_statuses_placeholders()`) is interpolated.

### Object-cache invalidation after raw order writes (PR #154)

Every order write in this plugin is a raw `$wpdb` query, so **none** of them pass through `clean_post_cache()`/`clean_term_cache()` on their own. Under a persistent object cache (Redis, Memcached) that leaves the written rows serving their pre-write value: stale/duplicate numbers in the Order column, and — because `taxcmp()` re-sorts terms in PHP on the **cached** `$term->term_order` — genuinely wrong term order on the *front end* too (term ordering is not "DB-only", unlike post ordering which really does sort in SQL).

`invalidate_order_cache( $table, $ids, $taxonomy = '' )` is the single choke point; `renumber_rows()` calls it automatically, and the two drag handlers call it directly. It picks a strategy by size: at or below the threshold (default 500, filter `scpo_cache_flush_group_threshold`) it invalidates row by row; above it, and only when the backend reports `wp_cache_supports( 'flush_group' )`, it does one `wp_cache_flush_group()`. Deliberately *not* a plain "always flush the group": that would discard every cached post on the site on each small drag save. Backends without group-flush support always take the per-row path, so the fix holds everywhere instead of silently no-op'ing.

For terms it must know the taxonomy — `clean_term_cache()` interprets a bare ID list as **term_taxonomy_ids**, not term_ids, so the old `clean_term_cache( $term_id )` calls busted the wrong rows anywhere the two have diverged. Callers that know the taxonomy pass it; the drag handler doesn't, so the helper resolves it per term.

### Asset loading gate

`load_script_css()` only enqueues the sorter on specific admin list screens, gated by `_check_load_script_css()` (checks enabled `objects`/`tags` against `$_GET['post_type']`/`taxonomy`/request URI; bails on edit/new/`orderby` views). It chooses the engine — SortableJS by default, or the jQuery path when the `engine` option is `'classic'` — overridable via the `scpo_use_sortablejs` filter (the filter wins over the stored option). It enqueues **minified** assets (`*.min.js`/`*.min.css`), falling back to source only when `SCRIPT_DEBUG` is on — so **run `grunt minjs` after editing `assets/scporder-sortablejs.js` or `assets/scporder.css`** or changes won't load.

Data is passed via `wp_localize_script` as `scporder_vars`: `ajax_url` (a **root-relative, same-origin** admin-ajax path from `get_ajax_url()` — never an absolute `admin_url()`, so saves stay same-origin behind proxies / non-standard ports / scheme mismatches), `nonce`, `showHandle` (`'1'`/`''`), and `i18n` (toast + a11y strings).

### Data Storage

- **Posts/Pages**: native `menu_order` column in `wp_posts`.
- **Taxonomies**: custom `term_order` column added to `wp_terms` via `ALTER TABLE` on install (`scporder_install()`), dropped on uninstall (`scporder_uninstall_db()`, multisite-aware). Presence is detected with `DESCRIBE`.
- **Uninstall**: `scporder_uninstall_db()` drops the `term_order` column and deletes **all four** options the plugin writes — `scporder_install`, `scporder_notice`, `scporder_options`, `simple-rate-time`. Before 2.8.7 only `scporder_install` went, so the rest survived an uninstall and were silently reused on reinstall (reported by @jamieburchell).
- **Settings**: `scporder_options` option — an array with keys `objects` (post type slugs), `tags` (taxonomy slugs), `show_advanced_view` (`'1'`/`''`), `engine` (`'sortable'` default / `'classic'`), `show_handle` (`'1'` default / `'0'`), and these 2.8.0 additions: `new_post_position` (`'top'` default / `'bottom'` — where freshly created items land, #45; default was `'bottom'` in 2.8.0–2.8.1 but that silently flipped the long-standing top placement — reported by @ffusion and @deisedesign — and was reverted to `'top'` in 2.8.2), `order_column` (`'1'`/`'0'` default — the optional numeric Order column, #76/#89), and `allowed_roles` (array of role slugs; empty = anyone holding the reorder capability, the default). Plus flag options `scporder_install` and `scporder_notice`.

### AJAX endpoints, nonces, capabilities

| Action | Nonce (action/field) | Capability | Purpose |
|--------|----------------------|------------|---------|
| `update-menu-order` | `scporder_nonce_action` / `nonce` | `scporder_user_can_reorder()` † + per-object ‡ | Save post order (drag) |
| `update-menu-order-tags` | `scporder_nonce_action` / `nonce` | `scporder_user_can_reorder()` † + per-object ‡ | Save term order (drag) |
| `scpo_set_position` | `scporder_nonce_action` / `nonce` | `scporder_user_can_reorder()` † + per-object ‡ | Move one post to an **absolute** 1-based position (the numeric Order column). Position is across the whole type, independent of list pagination; hierarchical types are rejected. |
| `scpo_reset_order` | `scpo-reset-order` / `scpo_security` | `manage_options` | Reset types to default + remove from `objects` |
| `scporder_dismiss_notices` | `scporder_dismiss_notice` / `scporder_nonce` | (admin notice) | Dismiss setup nag |
| `scpo_refresh_nonce` | none — intentional (see below) | `scporder_user_can_reorder()` † | Mint a fresh reorder nonce so the SortableJS client can transparently retry a save after the page nonce expires |

† **`scporder_user_can_reorder()`** gates *access* to the reorder writes (2.8.0, role-based reordering): the user must hold the `scpo_capability` capability (filterable, default `edit_posts`) **and**, if `allowed_roles` is non-empty, hold one of those roles.

‡ **Per-object authorization** (2.8.3, security hardening): the access gate alone is not enough — a broad `edit_posts`-style gate does not prove the caller may edit the *specific* IDs they submit. Every submitted ID is now validated before its order is written, via `scporder_user_can_edit_post()` (post must exist, be an enabled sortable type, and pass `current_user_can( 'edit_post', $id )`) or `scporder_user_can_edit_term()` (term must exist, be an enabled sortable taxonomy, and pass the taxonomy's `manage_terms` cap). `scpo_set_position` adds an inline `current_user_can( 'edit_post', $post_id )` check on top of its existing enabled-type/hierarchical guard. The drag handlers **reject the whole batch** (403) if any ID fails. For the usual reorder users (admins/editors) this is a no-op; it only blocks forged requests reordering objects the user can't edit (IDOR).

All handlers use `check_ajax_referer()`, capability checks, `$wpdb->prepare()`, and respond via `wp_send_json_success()`/`wp_send_json_error()` — **except `scpo_refresh_nonce`, which deliberately does *not* verify a nonce** (the stale nonce is the very reason it's called). That's safe because it is an authenticated (`wp_ajax_`, non-`nopriv`) action gated on `scporder_user_can_reorder()`, and admin-ajax sends no CORS headers, so the issued nonce can't be read cross-origin — the same model WordPress core's Heartbeat uses to refresh nonces. Reorder handlers preserve the existing *set* of `menu_order`/`term_order` values and reassign them positionally (so the existing values are kept, only the row→value mapping changes) — which is what leaves rows on other pages of a paginated list alone, but only works while the values are distinct. Since 2.8.7 the reused set is forced to strictly increase before it is dealt back out, so a page containing duplicates can no longer write back exactly what each row already had.

### Extensibility (preserve these — backward-compat contract)

- Filter `scpo_post_types_args` (`$args, $options`) — modify which post types appear in settings. The plugin's own `scpo_filter_post_types()` uses it to drop `show_in_menu` when `show_advanced_view` is on.
- Filter `scpo_use_sortablejs` (`bool`, default from the `engine` option) — force the drag engine in code; `true` = SortableJS, `false` = jQuery UI. Overrides the user's setting.
- Filter `scpo_capability` (`string`, default `'edit_posts'`, 2.8.0) — the capability required to reorder, consulted by `scporder_user_can_reorder()`. Use it to relax/tighten access in code (the per-role UI setting is `allowed_roles`).
- Filter `scpo_reverse_adjacent_posts` (`bool`, default `false`, 2.8.1) — reverse the direction of the previous/next post-navigation links. Default (`false`) is the #146 behavior (previous = the item *before* in the manual order, next = the item *after*); return `true` to restore the pre-2.7.2 direction. Consulted by `scporder_adjacent_reversed()`, which gates the operator/sort in all four `get_{previous,next}_post_{where,sort}` methods. "Previous/next" under manual ordering is genuinely ambiguous, so this is a per-site escape hatch rather than a globally-correct default.
- Filter `scpo_cache_flush_group_threshold` (`int`, default `500`; args `$threshold, $table`) — row count above which `invalidate_order_cache()` prefers a single `wp_cache_flush_group()` over per-row invalidation. Raise it to never flush the group, lower it to always prefer the flush.
- Actions `scp_update_menu_order` / `scp_update_menu_order_tags` — fire after a successful reorder (drag *or* the numeric Order column, which fires `scp_update_menu_order`).
- Global `$scporder` and the `scporder_options` structure are part of the public contract (the `engine`/`show_handle` keys were added in 2.7.0, and `new_post_position`/`order_column`/`allowed_roles` in 2.8.0, all with backward-compatible defaults).

### New-item placement (2.8.0, #45)

When `new_post_position` is `'top'` (the default), newly created items of enabled non-hierarchical types are pushed to the front of their type's order; `'bottom'` appends them instead. This is a separate concern from drag/column reordering — see the `#45: placement of newly created items` block in the engine.

Both directions are a **single-row write**: `'top'` writes `MIN(menu_order) - 1`, `'bottom'` writes `MAX(menu_order) + 1`. `'top'` originally ran `UPDATE … SET menu_order = menu_order + 1` across the whole post type, which cost an O(N) write on *every* publish and left all N shifted rows stale in a persistent object cache (PR #154). Placing outside the current range gets identical ordering from one row, so `clean_post_cache( $post_id )` alone is sufficient. Gaps and negative values are fine — every read sorts on the raw value, and `refresh()` normalizes back to 1..N before the list screen renders. The one value never written is `0`, which is this handler's "not yet placed" sentinel (it hooks `save_post`, so writing `0` would re-place the post on every subsequent save).

**Default history (2.8.2):** 2.8.0 introduced this feature defaulting to `'bottom'`, on the assumption that appending was "native." That was wrong: pre-2.8.0, a new post got `menu_order = 0`, which sorts first and `refresh()` renumbers to `1` — so new posts had always landed at the **top**. The `'bottom'` default silently flipped that for every upgraded site (the option key is absent on upgrade, and `get_new_post_position()` resolved absent → `'bottom'`), surfacing as "new/latest posts stuck at the bottom of the admin list." 2.8.2 reverts the default to `'top'`; an explicit `'bottom'` choice saved in Settings is still honored.

## Requirements

- WordPress 6.2+
- PHP 7.4+ (target compatibility 7.4–8.4)
