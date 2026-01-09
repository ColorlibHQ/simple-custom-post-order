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

### Core Files

- **simple-custom-post-order.php** - Main plugin file containing the `SCPO_Engine` class (monolithic, ~900 lines) that handles all plugin functionality
- **settings.php** - Admin settings page UI for enabling/disabling sorting on specific post types and taxonomies
- **class-simple-review.php** - Review notification system for prompting WordPress.org ratings
- **assets/scporder.js** - jQuery UI Sortable implementation for post/page drag-drop
- **assets/taxonomy_order.js** - jQuery UI Sortable implementation for taxonomy term drag-drop

### Data Storage

- **Posts/Pages**: Uses WordPress's native `menu_order` column in `wp_posts`
- **Taxonomies**: Uses `term_order` column added to `wp_terms` during plugin activation
- **Settings**: Stored in `wp_options` as `scporder_options` array

### Key WordPress Hooks

The plugin modifies queries via:
- `pre_get_posts` - Adds `menu_order` ordering for enabled post types
- `get_terms_orderby` - Modifies taxonomy term ordering
- `get_previous_post_where` / `get_next_post_where` - Fixes post navigation

AJAX endpoints (wp_ajax_*):
- `update-menu-order` - Save post order changes
- `update-menu-order-tags` - Save taxonomy order changes
- `scpo_reset_order` - Reset to default ordering

### Security Patterns

All AJAX handlers use:
- Nonce verification via `check_ajax_referer()`
- Capability checks (`edit_posts` for sorting, `manage_options` for settings)
- Prepared SQL statements via `$wpdb->prepare()`

### Extensibility

- Filter `scpo_post_types_args` - Modify which post types appear in settings

## Requirements

- WordPress 6.2+
- PHP 7.2.5+
