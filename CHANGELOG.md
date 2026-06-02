# Changelog

All notable changes to **Simple Custom Post Order** are documented here. The full,
WordPress.org-formatted history lives in [`readme.txt`](readme.txt); this file mirrors
recent releases in [Keep a Changelog](https://keepachangelog.com/) style and follows
[Semantic Versioning](https://semver.org/).

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
