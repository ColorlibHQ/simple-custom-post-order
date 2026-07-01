# Changelog

All notable changes to **Simple Custom Post Order** are documented here. The full,
WordPress.org-formatted history lives in [`readme.txt`](readme.txt); this file mirrors
recent releases in [Keep a Changelog](https://keepachangelog.com/) style and follows
[Semantic Versioning](https://semver.org/).

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
