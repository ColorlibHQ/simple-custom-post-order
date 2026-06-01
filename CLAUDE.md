# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Simple Custom Post Order is a WordPress plugin that enables drag-and-drop reordering of posts, pages, custom post types, and taxonomies in the WordPress admin. It uses WordPress's built-in `menu_order` field for posts and a custom `term_order` column added to the `wp_terms` table for taxonomies.

## Build Commands

```bash
# Minify JavaScript files
grunt minjs

# Generate/update translation files
grunt i18n

# Create distribution ZIP
grunt build-archive
```

## Architecture

The plugin is a single monolithic `SCPO_Engine` class (~1100 lines) that does everything: settings, query interception, AJAX, and asset loading. There is no autoloader or namespace — see `MODERNIZATION-PLAN.md` (gitignored, local-only) for the planned 3.0.0 split into `includes/class-scpo-*.php`.

### Core Files

- **simple-custom-post-order.php** — The `SCPO_Engine` class plus standalone `scporder_doing_ajax()`, `scporder_uninstall()`, and `scporder_uninstall_db()` functions. Instantiated immediately as the global `$scporder`.
- **settings.php** — Only the settings *page shell* (`do_settings_sections()`), the Reset Order form, and the Support section. The actual settings fields are registered and rendered via the **WordPress Settings API inside `SCPO_Engine`** (`register_settings()`, `render_post_types_field()`, `sanitize_options()`, etc.) — not here.
- **class-simple-review.php** — Self-instantiating `Simple_Review` class; WordPress.org rating nag, fully independent of `SCPO_Engine`. Uses the legacy `epsilon_simple_review` AJAX action / `simple-rate-time` option.
- **assets/scporder.js** — jQuery UI Sortable for **both** posts/pages (`table.posts/pages #the-list`) **and** taxonomies (`table.tags #the-list`). This is the only sorting script that ships.
- **assets/taxonomy_order.js** — ⚠️ **Dead code.** Not enqueued anywhere (references undefined `adminpage`/`get_inline_boxes`). Taxonomy sorting lives in `scporder.js`. Don't edit this file expecting an effect.

### How ordering actually works (the non-obvious core)

Saving order is the easy half (AJAX writes `menu_order`/`term_order`). The harder half is making *reads* respect that order, done by intercepting WordPress queries:

- `pre_get_posts` → `scporder_pre_get_posts()` forces `orderby=menu_order, order=ASC` for enabled post types (admin list screens and front-end, skipping search and explicit `orderby`).
- `get_previous_post_where` / `get_previous_post_sort` / `get_next_post_where` / `get_next_post_sort` → rewrite adjacent-post navigation SQL to walk `menu_order` instead of `post_date`.
- `get_terms_orderby` → swaps term ordering to `t.term_order`; `wp_get_object_terms` + `get_terms` → re-sort returned terms via `usort()`/`taxcmp()` (spaceship on `term_order`).

`refresh()` runs on every non-AJAX `admin_init` and re-normalizes `menu_order`/`term_order` into a gapless 1..N sequence for enabled types (skips when already contiguous). This is also why a freshly enabled type gets seeded order in `sanitize_options()` (pages → alphabetical, other posts → by date, terms → by name).

### Asset loading gate

`load_script_css()` only enqueues the sorter on specific admin list screens, gated by `_check_load_script_css()` (checks enabled `objects`/`tags` against `$_GET['post_type']`/`taxonomy`/request URI; bails on edit/new/`orderby` views). It enqueues **`scporder.min.js`**, not the source — so **you must run `grunt minjs` after editing `assets/scporder.js`** or changes won't load. Data is passed in via `wp_localize_script` as `scporder_vars` (`ajax_url`, `nonce`).

### Data Storage

- **Posts/Pages**: native `menu_order` column in `wp_posts`.
- **Taxonomies**: custom `term_order` column added to `wp_terms` via `ALTER TABLE` on install (`scporder_install()`), dropped on uninstall (`scporder_uninstall_db()`, multisite-aware). Presence is detected with `DESCRIBE`.
- **Settings**: `scporder_options` option — an array with keys `objects` (post type slugs), `tags` (taxonomy slugs), and `show_advanced_view` (`'1'`/`''`). Plus flag options `scporder_install` and `scporder_notice`.

### AJAX endpoints, nonces, capabilities

| Action | Nonce (action/field) | Capability | Purpose |
|--------|----------------------|------------|---------|
| `update-menu-order` | `scporder_nonce_action` / `nonce` | `edit_posts` | Save post order |
| `update-menu-order-tags` | `scporder_nonce_action` / `nonce` | `edit_posts` | Save term order |
| `scpo_reset_order` | `scpo-reset-order` / `scpo_security` | `manage_options` | Reset types to default + remove from `objects` |
| `scporder_dismiss_notices` | `scporder_dismiss_notice` / `scporder_nonce` | (admin notice) | Dismiss setup nag |

All handlers use `check_ajax_referer()`, capability checks, `$wpdb->prepare()`, and respond via `wp_send_json_success()`/`wp_send_json_error()`. Reorder handlers preserve the existing *set* of `menu_order`/`term_order` values and reassign them positionally (so the existing values are kept, only the row→value mapping changes).

### Extensibility (preserve these — backward-compat contract)

- Filter `scpo_post_types_args` (`$args, $options`) — modify which post types appear in settings. The plugin's own `scpo_filter_post_types()` uses it to drop `show_in_menu` when `show_advanced_view` is on.
- Actions `scp_update_menu_order` / `scp_update_menu_order_tags` — fire after a successful reorder.
- Global `$scporder` and the `scporder_options` structure are part of the public contract.

## Requirements

- WordPress 6.2+
- PHP 7.4+ (target compatibility 7.4–8.4)
