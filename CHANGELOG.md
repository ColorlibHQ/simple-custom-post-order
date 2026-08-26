# Changelog

All notable changes to **Simple Custom Post Order** are documented here. The full,
WordPress.org-formatted history lives in [`readme.txt`](readme.txt); this file mirrors
recent releases in [Keep a Changelog](https://keepachangelog.com/) style and follows
[Semantic Versioning](https://semver.org/).

## [2.8.8] - 2026-08-26

### Fixed
- **Enabling a post type destroyed the order it already had.** `sanitize_options()` seeded a newly enabled type by `post_title ASC` (pages) or `post_date DESC` (everything else), ignoring `menu_order` entirely — so ticking "Pages" in Settings silently alphabetised the whole site. For pages that is WordPress's *own* Page Attributes → Order field, and for a site arriving from another sorting plugin it is the order that plugin left behind. Worse, the loop runs for **every enabled type on every settings save**, not just newly enabled ones, so any later visit to the settings screen could re-flatten the order again. Seeding now preserves an existing sequence and only falls back to title/date when there is genuinely nothing to preserve — that is, when every row of the type shares a single `menu_order` value, which is what an untouched site looks like. Reported by [@martinsauter](https://wordpress.org/support/users/martinsauter/) ("destroying any existing order") and diagnosed by [@jamieburchell](https://wordpress.org/support/users/jamieburchell/), who arrived from another sort plugin whose values started at `0`.
- **The two renumber paths broke ties differently.** `refresh()` orders on `menu_order ASC, ID ASC`; the seeding above used a title/date tie-break. Both renumber the same rows, and a site hits whichever runs first, so two rows sharing a `menu_order` could come out in one order after a settings save and the other after simply viewing the list — an order that appears to change on its own. Both now break ties on ID.
- **2.8.7 regression, same area.** Adding `COUNT(DISTINCT)` to `is_already_sequential()` in 2.8.7 was correct for repairing duplicates, but it also widened the set of sites the destructive seeding fired on: a type whose values were, say, `{1,2,2,4}` was skipped in 2.8.6 and reseeded in 2.8.7. With seeding no longer destructive this is moot in either direction, but it is worth recording that 2.8.7 briefly made the older bug easier to hit.
- **Dragging a parent in a page tree left its children behind** until the page was reloaded (reported by [@jamieburchell](https://wordpress.org/support/users/jamieburchell/)). WordPress renders a hierarchical list as one flat run of `<tr>`s, so moving a parent moved only its own row. What was *saved* was always correct — the list table re-nests children under their parent on the next render and `post_parent` is never touched — but the screen disagreed with it in the meantime, which read as a broken drag. The sorter now carries a row's whole subtree with it, on mouse drag, on keyboard move and on keyboard cancel, using the `level-N` class WordPress puts on every row. Flat lists (posts, custom post types, non-hierarchical taxonomies) are unaffected. **This is not full hierarchical ordering** — constraining drags to siblings remains #58, planned for 2.9.0.

## [2.8.7] - 2026-08-26

### Fixed
- **Drag-and-drop silently doing nothing when rows share an order number.** Reordering reuses the *existing* set of `menu_order`/`term_order` values from the dragged rows and re-deals them positionally, which is what keeps other pages of a paginated list untouched. That only works while the values are distinct: where two rows shared a number, sorting and re-dealing the same multiset wrote back exactly the value each row already had — the save reported success and the order never moved, and because the two rows were still tied, which one came first stayed a coin toss on the front end. The reused set is now forced to strictly increase, so every position gets its own value. Reported by [@literayz](https://wordpress.org/support/users/literayz/).
- **Duplicate order numbers were never repaired.** `is_already_sequential()` — the pre-check that decides whether `refresh()` needs to renumber — compared `COUNT`/`MAX`/`MIN` only, so any set with a duplicate *and* a compensating gap (e.g. `{1, 2, 2, 4, 5}`: COUNT 5, MAX 5, MIN 1) read as a clean 1..N sequence and was skipped forever. The probe now also compares `COUNT(DISTINCT …)`, so duplicates are detected and normalized on the next list-screen render. This is the third correction to this guard; see 2.8.5 for the `MIN === 1` one.
- **Rows in a custom post status were excluded from ordering.** Every order query hard-coded the five built-in statuses (`publish`, `pending`, `draft`, `private`, `future`). A row in a status registered by another plugin — editorial workflows, "archived", and similar — was therefore left out of both the probe and the renumber, while still rendering in the list table and still being draggable. It kept whatever `menu_order` it had (usually `0`) as its neighbours were renumbered from `1`, which is one of the main ways the duplicates above were created. The status set now comes from `get_post_stati( [ 'show_in_admin_all_list' => true ] )` — exactly the statuses a list table's "All" view shows, so the rows the plugin numbers match the rows the user can see and drag. On a stock site this resolves to the same five statuses, so the change is purely additive.
- **Options left behind on uninstall.** Only `scporder_install` was deleted, so `scporder_notice`, `scporder_options` and `simple-rate-time` survived an uninstall and were silently restored on a later reinstall. All four are now removed (per-site on multisite, as before). Reported by [@jamieburchell](https://wordpress.org/support/users/jamieburchell/).
- **The bundled review notice never ran.** `SCPO_Engine::load_dependencies()` runs on `init` at priority 10 and includes `class-simple-review.php`, whose constructor registered *another* `init`/10 callback. `WP_Hook::apply_filters()` iterates a priority's callbacks with a by-value `foreach`, so a callback added to the priority already being walked is never seen, and `Simple_Review::init()` had been dead since the include was moved to `init`. It now runs immediately when `init` has already fired, so the class no longer depends on when it is included. Also spotted by [@jamieburchell](https://wordpress.org/support/users/jamieburchell/).

### Changed
- **`Tested up to` is now `7.1`, set in a proper release.** The 2.8.6 header was edited in place in the already-published SVN tag, which changed the files wordpress.org serves for a version that was already installed on live sites. Wordfence compares installed files against the copy wordpress.org holds for that version, so every site that had updated to 2.8.6 before the edit started reporting `simple-custom-post-order.php` and `readme.txt` as **Modified Plugin File**. Nothing was compromised — the sole difference was the one header line. Reported by [@timwakeling-1](https://wordpress.org/support/users/timwakeling-1/) (thread: "Wordfence warnings being generated due to file differences"). Published tags must never be edited after release; compatibility bumps now ship as a version like any other change.
- **The review notice no longer depends on jQuery.** It enqueued jQuery on every admin page and printed an inline jQuery block that was not dependency-ordered, so any site that defers or removes jQuery in wp-admin would have thrown `jQuery is not defined` on every screen once the notice started rendering again. It is now vanilla JS, posts to a root-relative same-origin admin-ajax path (matching the reorder endpoints), and delegates its dismiss handler so core's injected `.notice-dismiss` button is caught regardless of script order.
- **The review notice will not reappear all at once on existing sites.** A stored `simple-rate-time` more than 90 days in the past can only have come from the period when the notice was not rendering at all — any install that actually saw it pushed the value forward. Re-enabling would have fired the notice on the very next admin page load for all of those at once, so a long-elapsed timer is given a fresh week instead. Values in the future are never touched: a site that clicked *Don't show again* stays dismissed.
- The review notice's message is no longer passed through `sprintf()`. The string carries no placeholder, and running a translated string through `sprintf()` corrupts any locale whose text contains a literal `%`.
- `Simple_Review` now returns early for users without `manage_options` before touching options, and its AJAX handler verifies the capability as well as the nonce.

## [2.8.6] - 2026-07-27

### Fixed
- **"Couldn't update the order — please try again" from the numeric Order column.** The column script had none of the resilience the drag-and-drop sorter has had since 2.7.0, and collapsed every possible failure into one alert. Three distinct causes produced that identical message:
  - **Expired nonce.** `check_ajax_referer()` answers `-1`/403, which the script treated as a flat failure with no recovery. A list screen left open past the nonce window — or any site whose security plugin shortens `nonce_life` — failed every Order-column save until the page was manually reloaded, while drag-and-drop on the same screen kept working. The script now detects the `-1` body, fetches a fresh nonce from `scpo_refresh_nonce`, and retries once, transparently.
  - **Stray output from another plugin.** The response was parsed with `res.json()`, so a notice or deprecation printed before the JSON made the parse reject — reporting failure for a write that had already succeeded server-side. Parsing is now tolerant, matching the sorter's `JSON.parse`-in-try/catch, and recovers the payload from anywhere in the body.
  - **Transient network errors,** which now retry once after 800ms before reporting.
- **An Order box rendered on rows the user cannot reorder.** `scpo_set_position` has required `edit_post` on the specific row since 2.8.3, but the column is registered off the broad reorder capability, so the two could disagree — most often on a custom post type registered with its own `capability_type` and no `map_meta_cap`, where even administrators fail the meta-cap check. The result was an editable control that rejected every save. `render_order_column()` now checks `current_user_can( 'edit_post', $post_id )` and renders the position as read-only text where the save could not succeed.

### Changed
- The Order column surfaces the server's own message (`Permission denied.`, `Invalid item.`) and distinguishes an expired session and a connection failure, instead of showing one generic string for every cause. Two new localized strings, `expired` and `network`, join `error` in `scpoOrderCol`.

## [2.8.5] - 2026-07-27

### Fixed
- **Stale order values under a persistent object cache.** Every order write in the plugin is a raw `$wpdb` query, so none of them passed through `clean_post_cache()`/`clean_term_cache()`. With Redis/Memcached in play, the written rows kept serving their pre-write `menu_order`/`term_order` — showing as stale or duplicated numbers in the admin Order column until the cache was flushed by hand. Two paths were entirely uncovered: the gapless renumber (`renumber_rows()`) and the sibling shift in new-item placement. Order writes now funnel through a single `invalidate_order_cache()` helper. Reported by [@raveendrawpc](https://github.com/raveendrawpc) (#154).
- **Wrong taxonomy term order on the front end,** from the same root cause. Post ordering really is applied in SQL (`ORDER BY menu_order`), so it reads the live value — but term ordering is re-sorted in PHP by `taxcmp()` on `$term->term_order`, and those `WP_Term` objects come from the object cache. A stale entry therefore produced genuinely wrong term order for visitors, not merely wrong-looking numbers in wp-admin.
- **Term reorders busting the wrong cache entries.** `clean_term_cache()` interprets a bare ID list as **term_taxonomy_ids**, not term_ids, so the existing `clean_term_cache( $term_id )` calls in the drag handler cleared the wrong rows anywhere the two identifiers have diverged. Callers that know the taxonomy now pass it; the drag handler's IDs are resolved per term.
- **A blind spot in the "already gapless?" skip guard.** The pre-check compared `MAX` against `COUNT` only, so a set like `{-1, 2, 3, 4, 5}` read as clean and skipped renumbering. It now also requires `MIN === 1` (`is_already_sequential()`).

### Changed
- **New-item placement is a single-row write.** With `new_post_position` set to `'top'` (the default), publishing ran `UPDATE … SET menu_order = menu_order + 1` across the entire post type — an O(N) write on *every* publish, and, being raw SQL, one that left all N shifted rows stale in the object cache. Top placement now writes `MIN(menu_order) - 1` and bottom writes `MAX(menu_order) + 1`, so both touch exactly one row and the existing `clean_post_cache( $post_id )` covers them. Ordering is unchanged: gaps and negatives sort correctly, and `refresh()` normalizes back to `1..N` before the list screen renders. `menu_order = 0` is never written, as that is the handler's "not yet placed" sentinel.
- Seeding a newly enabled post type or taxonomy in `sanitize_options()` now goes through `renumber_rows()` — one batched `CASE` UPDATE plus cache invalidation, instead of one `$wpdb->update()` per row with none.

### Added
- Filter `scpo_cache_flush_group_threshold` (`int`, default `500`; args `$threshold, $table`) — the row count above which `invalidate_order_cache()` prefers a single `wp_cache_flush_group()` to per-row invalidation. Below the threshold it invalidates row by row, so an ordinary drag save no longer discards every cached post on the site; above it, and only where the backend reports `wp_cache_supports( 'flush_group' )`, it takes the O(1) group flush. Backends without group-flush support always use the per-row path, so the fix applies everywhere rather than silently doing nothing.

## [2.8.4] - 2026-07-14

### Fixed
- **Very slow admin on sites with many posts.** The order-normalization routine `refresh()` was hooked on `admin_init` with no screen check, so it ran on *every* admin page — Dashboard, Plugins, Tools, Settings — not just the sortable list screens. On each run it issued a `COUNT`/`MAX` per enabled type and, whenever a type's order wasn't already gapless, renumbered the whole type **one `UPDATE` per row**. On a site with ~3,000 posts across several enabled types that meant thousands of queries and multi-second TTFB on pages that never display a sortable list (one report measured ~9.5s and 14,000+ queries on `plugins.php`). `refresh()` now runs only on the post/taxonomy list screens where the manual order is actually shown (gated by the same `_check_load_script_css()` check the sorter uses), and off-list admin pages do zero order work. Reported by [@crossy](https://wordpress.org/support/users/crossy/).

### Changed
- The order renumber now writes a gapless `1..N` sequence in a **single chunked `CASE` `UPDATE`** (batched at 1,000 rows, `max_allowed_packet`-safe, fully `$wpdb->prepare()`-bound) via a new internal `renumber_rows()` helper, instead of one `UPDATE` per row. A dirty list of 2,500 items now normalizes in ~5 queries instead of ~2,500. No change to ordering behavior — every read already tolerates gaps (`ORDER BY menu_order`/`term_order`), so the numbering is only tidied right before the ordered list is rendered.

## [2.8.3] - 2026-07-01

### Security
- **Per-object authorization on the reorder AJAX endpoints.** The three reorder writes (`update_menu_order`, `update_menu_order_tags`, `scpo_ajax_set_position`) previously gated only on a nonce plus the broad reorder capability (`scporder_user_can_reorder()`, default `edit_posts`) with no check that the submitted IDs were ones the user may actually edit. Any signed-in user able to reach the endpoints could forge arbitrary post/term IDs and reshuffle their stored order — including posts, pages, or terms outside their own edit permissions (a broken-object-authorization / IDOR pattern; impact bounded to ordering integrity, no content disclosure or editing). Each submitted ID is now validated — the object must exist, belong to an enabled sortable type, and pass `current_user_can( 'edit_post', $id )` (posts) or the taxonomy's `manage_terms` capability (terms) — before its order is written; the drag handlers reject the whole batch (`403`) on any unauthorized ID. No behavior change for the usual reorder users (administrators/editors). Reported by the WordPress.org Plugin Review Team's automated scan.

### Fixed
- Manual post order is no longer ignored on the admin Posts list after using the **"All dates"** or **category** dropdown filters. Those filters submit an empty search field (`s=`) alongside the search box, and WordPress flags any query with the `s` var *present* as a search (`is_search()` keys off `isset()`, not a non-empty value) — so the plugin was skipping its custom order whenever a filter was applied. The order now applies while filtering; genuine searches (a non-empty term in admin, `is_search()` on the front end) are still left untouched. Props [@r-a-y](https://github.com/r-a-y) (#153).

## [2.8.2] - 2026-06-26

### Fixed
- Newly created posts/items no longer default to the **bottom** of the manual order. The 2.8.0 new-item-placement feature shipped with a `bottom` default, which silently reversed long-standing behavior — pre-2.8.0, a new post's `menu_order = 0` sorted first and `refresh()` renumbered it to `1`, so new items landed at the **top**. On every site upgrading from before 2.8.0 the option key is absent, and the getter resolved absent → `bottom`, surfacing as "the latest post is stuck at the bottom of the admin list." The default is back to **top**; an explicit `bottom` choice saved in Settings is still honored. Reported by @ffusion and @deisedesign.

## [2.8.1] - 2026-06-22

### Added
- **Reversible previous/next links** — new **`scpo_reverse_adjacent_posts`** filter to flip the direction of the previous/next post-navigation links for manually-ordered posts and custom post types. The 2.7.2 fix (#146) made "previous" the item *before* the current one in the arranged order and "next" the item *after* — correct for sequential content, but the opposite of what sites built around WordPress's native chronological convention expect. Return `true` from the filter to restore the pre-2.7.2 direction without editing your theme's template tags. Reported by @sarahmelyne.

## [2.8.0] - 2026-06-17

### Added
- **New-item placement** — choose whether newly created posts/pages/items are added to the **bottom** (default) or **top** of the manual order (Settings → SCPOrder → Advanced). Props [@mplusb](https://github.com/mplusb) (#45).
- **Optional "Order" column** — an editable position number column on enabled **non-hierarchical** post-type lists. Type an exact position to move an item — including **jumping it across paginated pages** — backed by a new `scpo_set_position` AJAX endpoint. Off by default; toggle via Settings and hide/show via Screen Options. (Hierarchical types like Pages get dedicated tree ordering in a later release — #58.) Props [@mplusb](https://github.com/mplusb) (#76, #89, #136).
- **Role-based reordering** — restrict drag-and-drop to selected roles in Settings, plus a new **`scpo_capability`** filter for developers (default `edit_posts`). Props [@mplusb](https://github.com/mplusb) (#95, #133).

## [2.7.3] - 2026-06-04

### Fixed
- Quick Edit / Bulk Edit fields (`<input>`, `<select>`) were not clickable with the left mouse button on post/page list screens when the **Modern (SortableJS)** engine was active. SortableJS excluded the inline-edit rows from dragging via `filter`, but its default `preventOnFilter: true` still called `preventDefault()` on the mousedown, cancelling native focus and dropdown-open (right-click was unaffected because SortableJS bails on non-left buttons first). Set `preventOnFilter: false` so filtered rows stay undraggable while their fields remain fully interactive. The Classic (jQuery UI) engine was never affected. Reported by @stilografico and @tedmw.

## [2.7.2] - 2026-06-03

### Fixed
- Previous/next post navigation (`get_previous_post()` / `get_next_post()` and the `*_post_link` template tags) returned the wrong adjacent post — often reversed — for manually-ordered posts and CPTs. The plugin rewrote only part of WordPress's adjacent-post `WHERE` clause (leaving the `post_date`/`ID` tiebreaker intact) and used the wrong direction. The clause is now fully rewritten to walk `menu_order`, with previous/next matching the manual order. Props [@beatricelucaci](https://github.com/beatricelucaci) (#146).

## [2.7.1] - 2026-06-02

### Fixed
- Post order could be scrambled on **MariaDB / MySQL 8** when `menu_order` was re-normalized after gaps appeared (e.g. after deleting an item). The gap-compacting step relied on a MySQL user-variable ranking (`@row_number`) whose evaluation order is undefined on those databases. Re-numbering is now done deterministically in PHP. Props [@alexgw](https://github.com/alexgw) & [@sebastiencyr](https://github.com/sebastiencyr) (#147, #119).
- `get_terms()` / `wp_get_object_terms()` calls that request `orderby=include` are now honored instead of being overridden by the custom term order. Props [@glebkema](https://github.com/glebkema) (#67, #66).

### Changed
- Custom term ordering now applies when *any* queried taxonomy is sortable (previously only the first taxonomy in a multi-taxonomy query was checked) and keeps the caller's `orderby` as a fallback tiebreaker. Props [@goaround](https://github.com/goaround) (#104).

## [2.7.0] - 2026-06-02

### Added
- **Modern SortableJS drag-and-drop engine**, now the default — dependency-free vanilla JavaScript, no jQuery UI.
- **Touch support** — press-and-hold to drag on phones and tablets (taps and vertical scrolling still work).
- **Full keyboard accessibility** — Tab to a row, Space to grab, arrow keys / Home / End to move, Space to drop, Escape to cancel; every step announced via an ARIA live region.
- **Visible save feedback** — a "Saving… / Order saved" toast while reordering.
- **"Drag & Drop Engine" setting** (Settings → SCPOrder) — choose Modern (SortableJS) or Classic (jQuery UI).
- **"Drag handle" setting** — optionally show a grip icon on row hover; hidden by default and never affects accessibility.
- **`scpo_use_sortablejs` filter** — force the engine in code (overrides the setting).
- **`scpo_refresh_nonce` AJAX endpoint** — backs transparent nonce refresh.
- Honors the `prefers-reduced-motion` user setting.

### Fixed
- **Reordering silently failing to save** in some environments — the AJAX request is now always same-origin (root-relative), fixing reverse proxies, load balancers, non-standard ports, and HTTP/HTTPS or domain mismatches.
- **Expired security nonce** now auto-refreshes and retries the save, so long-open edit screens (or sites with a shortened `nonce_life`) keep saving without a reload.

### Changed
- Rapid successive drags are coalesced into a single request (the final order always wins), with one automatic retry on transient network errors.
- Admin assets are minified, with unminified sources loaded automatically under `SCRIPT_DEBUG`; `grunt minjs` now builds CSS as well as JS.
- The classic jQuery UI Sortable path is retained as an opt-out fallback.

### Removed
- Unused/dead `assets/taxonomy_order.js`.

### Compatibility
- Fully backward compatible. Existing settings, hooks (`scp_update_menu_order`, `scp_update_menu_order_tags`, `scpo_post_types_args`), the global `$scporder`, and the `scporder_options` structure are unchanged. Two optional keys were added with safe defaults: `engine` (`sortable`) and `show_handle` (`1`).

## [2.6.1] - 2026-06-01
- Confirmed compatible with WordPress 7.0; maintenance release with no functional changes.

## [2.6.0] - 2026-01-09
- Settings page rewritten on the WordPress Settings API; security hardening (SQL injection, XSS); PHP 8.4 compatibility; targeted cache invalidation; "Settings" plugin action link.

---

Older releases (2.5.x and earlier): see the Changelog section of [`readme.txt`](readme.txt).
