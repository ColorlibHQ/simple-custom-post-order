# Simple Custom Post Order - Modernization Plan

**Target Version:** 3.0.0
**PHP Support:** 7.4 - 8.4
**WordPress Support:** 6.2+
**Prepared by:** Senior Developer Review
**Date:** January 2026
**Last Updated:** January 2026 (after v2.6.0 implementation)

---

## Executive Summary

This plan outlines the path to modernize Simple Custom Post Order for 2026 and beyond. The plugin has 300,000+ active installations and needs updates to address security vulnerabilities, PHP 8.4 compatibility, performance issues, and code quality while maintaining backward compatibility.

---

## Completed Work (Version 2.6.0)

The following items from the original plan have been **completed** and released in version 2.6.0:

### Phase 1: Critical Security Fixes - COMPLETED

- [x] **1.1 SQL Injection Vulnerabilities** - Fixed with `$wpdb->prepare()` and `sanitize_key()`
- [x] **1.2 Input Sanitization** - POST arrays properly sanitized with `array_map('sanitize_key', ...)`
- [x] **1.3 XSS Prevention** - All output properly escaped with `esc_html_e()`, `esc_attr()`, `esc_url()`, `esc_js()`
- [x] **1.4 Cache Invalidation** - Replaced `wp_cache_flush()` with targeted `clean_post_cache()` / `clean_term_cache()`

### Phase 2: PHP 8.4 Compatibility - COMPLETED

- [x] **2.1 Null Safety** - Added null coalescing for `$_SERVER` values and `get_current_screen()`
- [x] **2.2 Type Declarations** - Added return types (`: void`, `: array`, `: bool`, `: int`, `: string`) to all methods
- [x] **2.3 Modern PHP Syntax** - Short arrays `[]`, spaceship operator `<=>`, strict comparisons `===`
- [x] **2.4 Plugin Header** - Updated to `Requires PHP: 7.4`
- [x] **Property Declarations** - Added explicit property declarations in `Simple_Review` class

### Additional Completed Work (Beyond Original Plan)

- [x] **Settings Page Rewrite** - Complete rewrite using WordPress Settings API
  - Replaced custom `epsilon-toggle` CSS with standard WordPress checkboxes
  - Removed 100+ lines of custom inline CSS
  - Proper `settings_fields()`, `do_settings_sections()`, `register_setting()`
  - Added sanitization callback for automatic input validation
  - Improved accessibility with ARIA roles and screen reader text
- [x] **AJAX Response Standardization** - All AJAX handlers now use `wp_send_json_success()` / `wp_send_json_error()`
- [x] **Dead Code Removal** - Removed French comments, TODO markers, unused variables
- [x] **TypeError Fix** - Fixed `scpo_filter_post_types()` to handle `false` from `get_option()`

---

## Remaining Work

### Phase 3: Code Architecture Refactoring (Priority: MEDIUM)

**Target Version:** 3.0.0
**Status:** Not Started

This phase involves splitting the monolithic 900-line `SCPO_Engine` class into separate concerns:

#### 3.1 New File Structure
```
simple-custom-post-order/
├── simple-custom-post-order.php    # Bootstrap only
├── includes/
│   ├── class-scpo-plugin.php       # Main plugin class
│   ├── class-scpo-installer.php    # Activation/deactivation
│   ├── class-scpo-admin.php        # Admin UI & settings
│   ├── class-scpo-ajax.php         # AJAX handlers
│   ├── class-scpo-query.php        # Query modifications
│   └── class-scpo-review.php       # Review notice
├── assets/
│   ├── js/
│   │   ├── scporder.js
│   │   └── scporder.min.js
│   └── css/
│       └── admin.css
├── languages/
└── composer.json
```

#### 3.2 Add Namespace
```php
namespace Colorlib\SimpleCustomPostOrder;
```

#### 3.3 Composer Autoloading
Add PSR-4 autoloading with fallback for non-Composer installs.

---

### Phase 4: Performance Optimizations (Priority: MEDIUM)

**Target Version:** 3.0.0
**Status:** Partially Complete

- [x] **4.1 Targeted Cache Invalidation** - Completed in 2.6.0
- [ ] **4.2 Options Caching** - Cache `get_option()` calls in class property
- [ ] **4.3 Lazy Load refresh()** - Only run on relevant admin pages
- [ ] **4.4 Batch Database Updates** - Use `CASE WHEN` for bulk term updates
- [ ] **4.5 Defer Script Loading** - Add `defer` strategy for WordPress 6.3+

---

### Phase 5: Code Quality & Developer Experience (Priority: LOW)

**Target Version:** 3.0.0
**Status:** Partially Complete

- [x] **5.1 Remove Dead Code** - Completed in 2.6.0
- [x] **5.2 Standardize Comments** - French comments removed
- [x] **5.3 AJAX Responses** - Now using `wp_send_json_success()`
- [ ] **5.4 PHP CodeSniffer** - Add `phpcs.xml` configuration
- [ ] **5.5 PHPStan** - Add `phpstan.neon` configuration
- [ ] **5.6 Search Exclusion Filter** - Make search check filterable with `scpo_skip_search_ordering`

---

### Phase 6: JavaScript Modernization (Priority: LOW)

**Target Version:** 3.1.0
**Status:** Not Started

- [ ] **6.1 Fix Variable Hoisting** - Define `fixHelper` before use
- [ ] **6.2 AJAX Error Handling** - Add `.fail()` handlers with user feedback
- [ ] **6.3 Extract Inline CSS** - Move remaining inline styles to CSS file
- [ ] **6.4 Consider Vanilla JS** - Future option to remove jQuery dependency

---

### Phase 7: Testing Infrastructure (Priority: LOW)

**Target Version:** 3.0.0
**Status:** Not Started

- [ ] **7.1 PHPUnit Tests** - Add test suite for query modifications
- [ ] **7.2 GitHub Actions CI** - Add automated testing workflow
- [ ] **7.3 Multi-version Testing** - Test PHP 7.4-8.4, WP 6.2-6.9

---

## New Items to Consider

### Settings Page Enhancements (Priority: LOW)

Now that we're using Settings API, consider:

- [x] **Add Settings Link** - Add "Settings" link to plugin row on plugins page
- [ ] **Admin Success Notice** - Show WordPress admin notice after saving (Settings API provides this automatically)
- [ ] **Reset Confirmation** - Add JavaScript confirmation dialog before reset

### Review Notice Modernization (Priority: LOW)

The `Simple_Review` class still has inline CSS/JS:

- [ ] **Extract Review CSS** - Move review notice styles to admin.css
- [ ] **Extract Review JS** - Move review JS to a separate file or combine with admin JS

### Documentation (Priority: MEDIUM)

- [ ] **Update CLAUDE.md** - Reflect new Settings API architecture
- [ ] **Add Inline Documentation** - PHPDoc blocks for all public methods
- [ ] **Update Screenshots** - New settings page screenshots for WordPress.org

---

## Version Roadmap (Updated)

| Version | Focus | PHP | WordPress | Status |
|---------|-------|-----|-----------|--------|
| 2.6.0 | Security, PHP 8.4, Settings API | 7.4+ | 6.2+ | **RELEASED** |
| 2.7.0 | Performance optimizations | 7.4+ | 6.2+ | Planned |
| 3.0.0 | Architecture refactor | 7.4+ | 6.2+ | Planned |
| 3.1.0 | JS modernization, tests | 7.4+ | 6.4+ | Planned |

---

## Updated Checklist

### Released - Version 2.6.0
- [x] Fix SQL injection in `refresh()`
- [x] Fix SQL injection in `scpo_ajax_reset_order()`
- [x] Sanitize POST arrays
- [x] Add escaping to all output
- [x] Targeted cache invalidation
- [x] Add type declarations
- [x] Fix null safety for PHP 8.x
- [x] Update minimum PHP to 7.4
- [x] Rewrite settings page with Settings API
- [x] Remove custom toggle CSS
- [x] Fix TypeError for fresh installs

### Pre-Release 2.7.0
- [ ] Cache options in class property
- [ ] Lazy load `refresh()` method
- [ ] Add batch database updates for terms
- [ ] Add script defer strategy
- [ ] Add settings link to plugins page

### Pre-Release 3.0.0
- [ ] Implement new file structure
- [ ] Add namespace
- [ ] Add Composer autoloading
- [ ] Split monolithic class
- [ ] Add PHPUnit tests
- [ ] Add GitHub Actions CI
- [ ] Add PHPCS configuration
- [ ] Add PHPStan configuration
- [ ] Update CLAUDE.md

---

## Backward Compatibility

All existing APIs remain unchanged:

| API | Type | Status |
|-----|------|--------|
| `$scporder` global | Variable | Preserved |
| `scpo_post_types_args` filter | Hook | Preserved |
| `scp_update_menu_order` action | Hook | Preserved |
| `scp_update_menu_order_tags` action | Hook | Preserved |
| `scporder_options` option | Data | Structure unchanged |

---

## Resources

- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- [WordPress Settings API](https://developer.wordpress.org/plugins/settings/settings-api/)
- [PHP 8.4 Migration Guide](https://www.php.net/manual/en/migration84.php)
- [WordPress Plugin Security](https://developer.wordpress.org/plugins/security/)
