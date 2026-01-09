# Simple Custom Post Order - Modernization Plan

**Target Version:** 3.0.0
**PHP Support:** 7.4 - 8.4
**WordPress Support:** 6.2+
**Prepared by:** Senior Developer Review
**Date:** January 2026

---

## Executive Summary

This plan outlines the path to modernize Simple Custom Post Order for 2026 and beyond. The plugin has 300,000+ active installations and needs updates to address security vulnerabilities, PHP 8.4 compatibility, performance issues, and code quality while maintaining backward compatibility.

---

## Phase 1: Critical Security Fixes (Priority: URGENT)

**Target Version:** 2.6.0
**Breaking Changes:** None
**Estimated Scope:** Small

### 1.1 SQL Injection Vulnerabilities

#### Issue A: Unescaped variable in refresh() method
**File:** `simple-custom-post-order.php` lines 276-286
**Risk:** High

**Current Code:**
```php
$wpdb->query(
    "UPDATE $wpdb->posts as pt JOIN (
      SELECT ID, (@row_number:=@row_number + 1) AS `rank`
      FROM $wpdb->posts
      WHERE post_type = '$object' AND post_status IN ( 'publish', 'pending', 'draft', 'private', 'future' )
      ORDER BY menu_order ASC
    ) as pt2
    ON pt.id = pt2.id
    SET pt.menu_order = pt2.`rank`;"
);
```

**Fix:** Use `$wpdb->prepare()` with the identifier placeholder or sanitize the post type:
```php
$object = sanitize_key( $object );
$wpdb->query(
    $wpdb->prepare(
        "UPDATE $wpdb->posts as pt JOIN (
          SELECT ID, (@row_number:=@row_number + 1) AS `rank`
          FROM $wpdb->posts
          WHERE post_type = %s AND post_status IN ( 'publish', 'pending', 'draft', 'private', 'future' )
          ORDER BY menu_order ASC
        ) as pt2
        ON pt.id = pt2.id
        SET pt.menu_order = pt2.`rank`;",
        $object
    )
);
```

#### Issue B: Broken IN clause in scpo_ajax_reset_order()
**File:** `simple-custom-post-order.php` lines 745-762
**Risk:** High (SQL doesn't execute correctly)

**Current Code:**
```php
$in_list = '(';
foreach ( $items as $item ) {
    if ( $count != 0 ) {
        $in_list .= ',';
    }
    $in_list .= $wpdb->prepare( '%s', $item );
    $count++;
}
$in_list .= ')';

$prep_posts_query = $wpdb->prepare(
    "UPDATE $wpdb->posts SET `menu_order` = 0 WHERE `post_type` IN %s",
    $in_list
);
```

**Fix:** Build placeholders array properly:
```php
$items = array_map( 'sanitize_key', $items );
$placeholders = implode( ', ', array_fill( 0, count( $items ), '%s' ) );
$query = $wpdb->prepare(
    "UPDATE $wpdb->posts SET `menu_order` = 0 WHERE `post_type` IN ($placeholders)",
    $items
);
$result = $wpdb->query( $query );
```

### 1.2 Input Sanitization

#### Issue: Unsanitized POST arrays stored to options
**File:** `simple-custom-post-order.php` lines 444-446

**Current Code:**
```php
$input_options['objects'] = isset( $_POST['objects'] ) ? $_POST['objects'] : '';
$input_options['tags']    = isset( $_POST['tags'] ) ? $_POST['tags'] : '';
```

**Fix:**
```php
$input_options['objects'] = isset( $_POST['objects'] ) && is_array( $_POST['objects'] )
    ? array_map( 'sanitize_key', $_POST['objects'] )
    : array();
$input_options['tags'] = isset( $_POST['tags'] ) && is_array( $_POST['tags'] )
    ? array_map( 'sanitize_key', $_POST['tags'] )
    : array();
$input_options['show_advanced_view'] = isset( $_POST['show_advanced_view'] )
    ? '1'
    : '';
```

### 1.3 XSS Prevention in settings.php

**Multiple locations need escaping:**

| Line | Current | Fixed |
|------|---------|-------|
| 108 | `_e(` | `esc_html_e(` |
| 111 | `$_GET['msg'] == 'update'` | `isset($_GET['msg']) && $_GET['msg'] === 'update'` |
| 112 | `_e(` | `esc_html_e(` |
| 129 | `_e(` | `esc_html_e(` |
| 148 | `_e(` | `esc_html_e(` |
| 169 | `echo $post_type->name` | `echo esc_attr( $post_type->name )` |
| 191 | `echo $post_type->label` | `echo esc_html( $post_type->label )` |
| 207 | `_e(` | `esc_html_e(` |
| 226 | `_e(` | `esc_html_e(` |
| 244 | `echo $taxonomy->name` | `echo esc_attr( $taxonomy->name )` |
| 266 | `echo $taxonomy->label` | `echo esc_html( $taxonomy->label )` |
| 280 | `_e(` | `esc_html_e(` |
| 299 | `echo __(...` | `echo esc_html__(...` |
| 301-303 | `_e(` | `esc_html_e(` |
| 312 | `_e(` | `esc_html_e(` |
| 317 | Hardcoded English | Add to translation |
| 322 | `_e(` | `esc_html_e(` |
| 333 | `echo $post_type->name` | `echo esc_attr( $post_type->name )` |
| 347 | `echo $post_type->label` | `echo esc_html( $post_type->label )` |

### 1.4 Restore Selective Cache Flushing

**File:** `simple-custom-post-order.php` lines 374, 425

**Current Code (regression from 2.5.10):**
```php
wp_cache_flush();
```

**Fix (restore 2.5.10 behavior):**
```php
if ( wp_cache_supports( 'flush_group' ) ) {
    wp_cache_flush_group( 'posts' );
    wp_cache_flush_group( 'terms' );
} else {
    wp_cache_flush();
}
```

---

## Phase 2: PHP 8.4 Compatibility (Priority: HIGH)

**Target Version:** 2.7.0
**Breaking Changes:** Minimum PHP 7.4
**Estimated Scope:** Medium

### 2.1 PHP 8.x Deprecations and Changes

#### Constructor Method Names (PHP 8.0+)
The current code uses `function __construct()` which is correct. No changes needed.

#### Null Safety and Type Coercion
**Issue:** Passing `null` to string functions is deprecated in PHP 8.1+

**Check these locations:**
```php
// Line 208 - strstr() with potentially null $_SERVER value
strstr( $_SERVER['REQUEST_URI'], 'action=edit' )

// Fix:
strstr( $_SERVER['REQUEST_URI'] ?? '', 'action=edit' )
```

#### Dynamic Properties (PHP 8.2+)
The `SCPO_Engine` and `Simple_Review` classes don't declare properties explicitly. While WordPress plugins are exempt from the deprecation (WordPress adds `#[AllowDynamicProperties]`), it's best practice to declare properties.

**Simple_Review class - add property declarations:**
```php
class Simple_Review {
    private ?int $value = null;
    private array $messages = [];
    private string $link = 'https://wordpress.org/plugins/simple-custom-post-order/#reviews';
    private string $slug = 'simple-custom-post-order';
```

#### Implicit Float to Int Conversion (PHP 8.1+)
Review all array index operations and ensure explicit casting where needed.

### 2.2 Add Type Declarations

Add parameter and return type declarations for PHP 7.4+ compatibility:

```php
public function get_scporder_options_objects(): array {
    $scporder_options = get_option( 'scporder_options', [] );
    return isset( $scporder_options['objects'] ) && is_array( $scporder_options['objects'] )
        ? $scporder_options['objects']
        : [];
}

public function get_scporder_options_tags(): array {
    $scporder_options = get_option( 'scporder_options', [] );
    return isset( $scporder_options['tags'] ) && is_array( $scporder_options['tags'] )
        ? $scporder_options['tags']
        : [];
}

public function taxcmp( object $a, object $b ): int {
    return $a->term_order <=> $b->term_order;  // Spaceship operator
}
```

### 2.3 Modern PHP Syntax Updates

#### Use Null Coalescing Operator
```php
// Before
$scporder_options = get_option( 'scporder_options' ) ? get_option( 'scporder_options' ) : array();

// After
$scporder_options = get_option( 'scporder_options' ) ?: [];
```

#### Use Spaceship Operator for Comparisons
```php
// Before
public function taxcmp( $a, $b ) {
    if ( $a->term_order == $b->term_order ) {
        return 0;
    }
    return ( $a->term_order < $b->term_order ) ? -1 : 1;
}

// After
public function taxcmp( object $a, object $b ): int {
    return $a->term_order <=> $b->term_order;
}
```

#### Use Short Array Syntax
```php
// Before
array( 'key' => 'value' )

// After
[ 'key' => 'value' ]
```

### 2.4 Update Plugin Header

```php
/**
 * Requires PHP: 7.4
 */
```

---

## Phase 3: Code Architecture Refactoring (Priority: MEDIUM)

**Target Version:** 3.0.0
**Breaking Changes:** Internal only (APIs preserved)
**Estimated Scope:** Large

### 3.1 New File Structure

```
simple-custom-post-order/
├── simple-custom-post-order.php    # Bootstrap only
├── includes/
│   ├── class-scpo-plugin.php       # Main plugin class
│   ├── class-scpo-installer.php    # Activation/deactivation
│   ├── class-scpo-admin.php        # Admin UI & settings
│   ├── class-scpo-ajax.php         # AJAX handlers
│   ├── class-scpo-query.php        # Query modifications
│   ├── class-scpo-assets.php       # Script/style loading
│   └── class-scpo-review.php       # Review notice (renamed)
├── admin/
│   └── views/
│       └── settings-page.php       # Settings template
├── assets/
│   ├── js/
│   │   ├── scporder.js
│   │   └── scporder.min.js
│   └── css/
│       └── admin.css               # Extract inline styles
├── languages/
│   └── simple-custom-post-order.pot
├── composer.json                   # NEW
├── phpcs.xml                       # NEW
├── phpstan.neon                    # NEW
└── README.md
```

### 3.2 Add Namespace

```php
<?php
namespace Colorlib\SimpleCustomPostOrder;

// All classes under this namespace
class Plugin { ... }
class Admin { ... }
class Ajax { ... }
```

### 3.3 Implement Autoloading with Composer

**New composer.json:**
```json
{
    "name": "colorlib/simple-custom-post-order",
    "description": "Order posts using drag and drop",
    "type": "wordpress-plugin",
    "license": "GPL-3.0-or-later",
    "require": {
        "php": ">=7.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.0",
        "wp-coding-standards/wpcs": "^3.0",
        "phpstan/phpstan": "^1.0",
        "szepeviktor/phpstan-wordpress": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Colorlib\\SimpleCustomPostOrder\\": "includes/"
        }
    },
    "scripts": {
        "phpcs": "phpcs",
        "phpstan": "phpstan analyse",
        "test": "phpunit"
    }
}
```

### 3.4 Main Plugin Bootstrap

**simple-custom-post-order.php (simplified):**
```php
<?php
/**
 * Plugin Name: Simple Custom Post Order
 * Version: 3.0.0
 * Requires PHP: 7.4
 * ...
 */

defined( 'ABSPATH' ) || exit;

define( 'SCPORDER_VERSION', '3.0.0' );
define( 'SCPORDER_FILE', __FILE__ );
define( 'SCPORDER_PATH', plugin_dir_path( __FILE__ ) );
define( 'SCPORDER_URL', plugins_url( '', __FILE__ ) );

// Composer autoloader (with fallback for non-Composer installs)
if ( file_exists( SCPORDER_PATH . 'vendor/autoload.php' ) ) {
    require_once SCPORDER_PATH . 'vendor/autoload.php';
} else {
    // Manual autoloader fallback
    spl_autoload_register( function( $class ) {
        $prefix = 'Colorlib\\SimpleCustomPostOrder\\';
        $base_dir = SCPORDER_PATH . 'includes/';

        if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
            return;
        }

        $relative_class = substr( $class, strlen( $prefix ) );
        $file = $base_dir . 'class-scpo-' . strtolower( str_replace( '\\', '-', $relative_class ) ) . '.php';

        if ( file_exists( $file ) ) {
            require $file;
        }
    });
}

// Initialize plugin
function scporder_init(): Colorlib\SimpleCustomPostOrder\Plugin {
    static $plugin = null;

    if ( $plugin === null ) {
        $plugin = new Colorlib\SimpleCustomPostOrder\Plugin();
    }

    return $plugin;
}

// Backward compatibility - keep global for extensions
$GLOBALS['scporder'] = scporder_init();

// Activation/Deactivation hooks
register_activation_hook( __FILE__, [ Colorlib\SimpleCustomPostOrder\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Colorlib\SimpleCustomPostOrder\Installer::class, 'deactivate' ] );
register_uninstall_hook( __FILE__, [ Colorlib\SimpleCustomPostOrder\Installer::class, 'uninstall' ] );
```

### 3.5 Class Separation Example

**includes/class-scpo-ajax.php:**
```php
<?php
namespace Colorlib\SimpleCustomPostOrder;

class Ajax {

    public function __construct() {
        add_action( 'wp_ajax_update-menu-order', [ $this, 'update_menu_order' ] );
        add_action( 'wp_ajax_update-menu-order-tags', [ $this, 'update_menu_order_tags' ] );
        add_action( 'wp_ajax_scpo_reset_order', [ $this, 'reset_order' ] );
        add_action( 'wp_ajax_scporder_dismiss_notices', [ $this, 'dismiss_notices' ] );
    }

    public function update_menu_order(): void {
        check_ajax_referer( 'scporder_nonce_action', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'simple-custom-post-order' ) ], 403 );
        }

        // ... implementation

        wp_send_json_success( [ 'message' => __( 'Order updated.', 'simple-custom-post-order' ) ] );
    }

    // ... other methods
}
```

---

## Phase 4: Performance Optimizations (Priority: MEDIUM)

**Target Version:** 3.0.0
**Breaking Changes:** None
**Estimated Scope:** Medium

### 4.1 Options Caching

**Current Problem:** `get_option()` called multiple times per request.

**Solution:** Cache options in class property:
```php
class Plugin {
    private ?array $options = null;

    public function get_options(): array {
        if ( $this->options === null ) {
            $this->options = get_option( 'scporder_options', [] );
        }
        return $this->options;
    }

    public function get_enabled_post_types(): array {
        $options = $this->get_options();
        return $options['objects'] ?? [];
    }

    public function get_enabled_taxonomies(): array {
        $options = $this->get_options();
        return $options['tags'] ?? [];
    }

    // Clear cache when options updated
    public function clear_options_cache(): void {
        $this->options = null;
    }
}
```

### 4.2 Lazy Load refresh() Method

**Current Problem:** `refresh()` runs on every `admin_init`, executing expensive queries.

**Solution:** Only run when needed:
```php
public function maybe_refresh(): void {
    // Skip if not on a relevant admin page
    if ( ! $this->is_sortable_list_page() ) {
        return;
    }

    // Skip if we've already refreshed this request
    if ( did_action( 'scpo_refresh_complete' ) ) {
        return;
    }

    $this->refresh();
    do_action( 'scpo_refresh_complete' );
}

private function is_sortable_list_page(): bool {
    global $pagenow;

    if ( ! in_array( $pagenow, [ 'edit.php', 'edit-tags.php' ], true ) ) {
        return false;
    }

    // Additional checks for post type/taxonomy
    // ...

    return true;
}
```

### 4.3 Batch Database Updates

**Current Problem:** Terms updated one-by-one in a loop.

**Current Code:**
```php
foreach ( $results as $key => $result ) {
    $wpdb->update( $wpdb->terms, [ 'term_order' => $key + 1 ], [ 'term_id' => $result->term_id ] );
}
```

**Solution:** Use CASE WHEN for batch update:
```php
private function batch_update_term_order( array $term_ids ): void {
    global $wpdb;

    if ( empty( $term_ids ) ) {
        return;
    }

    $cases = [];
    $ids = [];

    foreach ( $term_ids as $order => $term_id ) {
        $term_id = absint( $term_id );
        $order = $order + 1;
        $cases[] = $wpdb->prepare( "WHEN %d THEN %d", $term_id, $order );
        $ids[] = $term_id;
    }

    $cases_sql = implode( ' ', $cases );
    $ids_sql = implode( ', ', array_map( 'absint', $ids ) );

    $wpdb->query(
        "UPDATE {$wpdb->terms}
         SET term_order = CASE term_id {$cases_sql} END
         WHERE term_id IN ({$ids_sql})"
    );
}
```

### 4.4 Defer Script Loading

Add `defer` attribute to non-critical scripts:
```php
public function enqueue_scripts(): void {
    wp_enqueue_script(
        'scporderjs',
        SCPORDER_URL . '/assets/js/scporder.min.js',
        [ 'jquery', 'jquery-ui-sortable' ],
        SCPORDER_VERSION,
        [
            'in_footer' => true,
            'strategy'  => 'defer',  // WordPress 6.3+
        ]
    );
}
```

---

## Phase 5: Code Quality & Developer Experience (Priority: LOW)

**Target Version:** 3.0.0
**Breaking Changes:** None
**Estimated Scope:** Medium

### 5.1 Add PHP CodeSniffer Configuration

**phpcs.xml:**
```xml
<?xml version="1.0"?>
<ruleset name="Simple Custom Post Order">
    <description>Coding standards for SCPO</description>

    <file>.</file>

    <exclude-pattern>/vendor/*</exclude-pattern>
    <exclude-pattern>/node_modules/*</exclude-pattern>
    <exclude-pattern>/build/*</exclude-pattern>

    <arg name="extensions" value="php"/>
    <arg name="colors"/>
    <arg value="sp"/>

    <rule ref="WordPress">
        <exclude name="WordPress.Files.FileName.InvalidClassFileName"/>
    </rule>

    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array">
                <element value="simple-custom-post-order"/>
            </property>
        </properties>
    </rule>

    <config name="minimum_supported_wp_version" value="6.2"/>
    <config name="testVersion" value="7.4-"/>
</ruleset>
```

### 5.2 Add PHPStan Configuration

**phpstan.neon:**
```neon
includes:
    - vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
    level: 6
    paths:
        - simple-custom-post-order.php
        - includes/
    excludePaths:
        - vendor/
    bootstrapFiles:
        - vendor/php-stubs/wordpress-stubs/wordpress-stubs.php
```

### 5.3 Remove Dead Code

**class-simple-review.php lines 20-22:**
```php
// REMOVE - $args is never defined
if ( isset( $args['messages'] ) ) {
    $this->messages = wp_parse_args( $args['messages'], $this->messages );
}
```

**taxonomy_order.js - entire file:**
This file references a non-existent AJAX action `get_inline_boxes`. Either fix or remove.

### 5.4 Standardize Code Comments

Remove French comments and TODO markers:
```php
// Line 230: //TODO corrigé
// Line 245: //TODO corrigé
// Line 268: // à corriger
// Line 302: //Passage en requette préparée
// etc.
```

### 5.5 Improve AJAX Responses

**Current:** Echo strings directly
```php
echo 'Items have been reset';
```

**Improved:** Use WordPress JSON functions
```php
wp_send_json_success( [
    'message' => __( 'Items have been reset.', 'simple-custom-post-order' )
] );
```

### 5.6 Add Filter for Search Exclusion

Make the search exclusion filterable:
```php
public function scporder_pre_get_posts( WP_Query $wp_query ): void {
    // Allow filtering the search check
    $skip_search = apply_filters( 'scpo_skip_search_ordering', is_search(), $wp_query );

    if ( $skip_search ) {
        return;
    }

    // ... rest of method
}
```

---

## Phase 6: JavaScript Modernization (Priority: LOW)

**Target Version:** 3.1.0
**Breaking Changes:** None
**Estimated Scope:** Medium

### 6.1 Fix Variable Hoisting Issue

**Current Problem:** `fixHelper` used before defined.

**Fix:**
```javascript
(function ($) {
    // Define helper first
    var fixHelper = function (e, ui) {
        ui.children().children().each(function () {
            $(this).width($(this).width());
        });
        return ui;
    };

    // Then use it
    $('table.posts #the-list, table.pages #the-list').sortable({
        'items': 'tr',
        'axis': 'y',
        'helper': fixHelper,
        // ...
    });
})(jQuery);
```

### 6.2 Add Error Handling to AJAX

```javascript
$.post(scporder_vars.ajax_url, {
    action: 'update-menu-order',
    order: $('#the-list').sortable('serialize'),
    nonce: scporder_vars.nonce
})
.done(function(response) {
    if (response.success) {
        // Optional: Show success indicator
    }
})
.fail(function(xhr, status, error) {
    console.error('SCPO: Failed to save order', error);
    // Optional: Show error to user, revert order
});
```

### 6.3 Extract Inline CSS

Move inline styles from PHP to a separate CSS file:
```
assets/css/admin.css
```

### 6.4 Future: Consider Vanilla JS

For WordPress 6.5+, consider removing jQuery dependency for new installations. Provide both versions:
- `scporder.js` - jQuery version (default)
- `scporder-vanilla.js` - No dependencies

---

## Phase 7: Testing Infrastructure (Priority: LOW)

**Target Version:** 3.0.0
**Breaking Changes:** None
**Estimated Scope:** Medium

### 7.1 Add PHPUnit Tests

**tests/bootstrap.php:**
```php
<?php
$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function() {
    require dirname( __DIR__ ) . '/simple-custom-post-order.php';
});

require $_tests_dir . '/includes/bootstrap.php';
```

**tests/test-query.php:**
```php
<?php
class Test_Query extends WP_UnitTestCase {

    public function test_search_results_not_affected() {
        // Enable ordering for posts
        update_option( 'scporder_options', [ 'objects' => [ 'post' ] ] );

        // Create test posts
        $this->factory->post->create_many( 5 );

        // Perform search
        $query = new WP_Query( [ 's' => 'test' ] );

        // Assert orderby is NOT menu_order
        $this->assertNotEquals( 'menu_order', $query->get( 'orderby' ) );
    }

    public function test_post_list_ordered_by_menu_order() {
        update_option( 'scporder_options', [ 'objects' => [ 'post' ] ] );

        $query = new WP_Query( [ 'post_type' => 'post' ] );

        $this->assertEquals( 'menu_order', $query->get( 'orderby' ) );
        $this->assertEquals( 'ASC', $query->get( 'order' ) );
    }
}
```

### 7.2 Add GitHub Actions CI

**.github/workflows/ci.yml:**
```yaml
name: CI

on:
  push:
    branches: [ master, develop ]
  pull_request:
    branches: [ master ]

jobs:
  phpcs:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer, cs2pr
      - run: composer install
      - run: composer phpcs -- -q --report=checkstyle | cs2pr

  phpstan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: composer install
      - run: composer phpstan

  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4']
        wp: ['6.2', '6.4', 'latest']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
      - name: Install WP Tests
        run: bash bin/install-wp-tests.sh wordpress_test root '' localhost ${{ matrix.wp }}
      - run: composer install
      - run: composer test
```

---

## Migration & Backward Compatibility

### Preserved APIs

The following will remain unchanged for backward compatibility:

| API | Type | Notes |
|-----|------|-------|
| `$scporder` global | Variable | Kept for extensions |
| `scpo_post_types_args` filter | Hook | Unchanged |
| `scp_update_menu_order` action | Hook | Unchanged |
| `scp_update_menu_order_tags` action | Hook | Unchanged |
| `scporder_options` option | Data | Structure unchanged |

### New APIs (3.0.0+)

| API | Type | Purpose |
|-----|------|---------|
| `scporder_init()` | Function | Get plugin instance |
| `scpo_skip_search_ordering` | Filter | Control search behavior |
| `scpo_refresh_complete` | Action | After order normalization |
| `Colorlib\SimpleCustomPostOrder\Plugin` | Class | Main plugin class |

### Deprecation Strategy

1. **Version 2.6.0**: Add `_deprecated_function()` notices for any changes
2. **Version 3.0.0**: Remove deprecated code, bump minimum PHP to 7.4
3. **Version 3.x**: Remove deprecated hooks after 1 year

---

## Version Roadmap

| Version | Focus | PHP | WordPress | Timeline |
|---------|-------|-----|-----------|----------|
| 2.6.0 | Security fixes | 7.2.5+ | 6.2+ | Immediate |
| 2.7.0 | PHP 8.4 compatibility | 7.4+ | 6.2+ | 2-4 weeks |
| 3.0.0 | Architecture refactor | 7.4+ | 6.2+ | 1-2 months |
| 3.1.0 | JS modernization | 7.4+ | 6.4+ | 3+ months |

---

## Checklist

### Pre-Release 2.6.0
- [ ] Fix SQL injection in `refresh()`
- [ ] Fix SQL injection in `scpo_ajax_reset_order()`
- [ ] Sanitize POST arrays in `update_options()`
- [ ] Add escaping to all output in `settings.php`
- [ ] Restore selective cache flushing
- [ ] Update `Tested up to` header
- [ ] Test on PHP 7.2, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4
- [ ] Test on WordPress 6.2, 6.4, 6.7

### Pre-Release 2.7.0
- [ ] Add type declarations
- [ ] Fix null coalescing for PHP 8.x
- [ ] Update minimum PHP to 7.4 in plugin header
- [ ] Remove redundant `function_exists()` checks
- [ ] Run PHPStan level 5

### Pre-Release 3.0.0
- [ ] Implement new file structure
- [ ] Add namespace
- [ ] Add Composer autoloading
- [ ] Split monolithic class
- [ ] Add PHPUnit tests
- [ ] Add GitHub Actions CI
- [ ] Update documentation
- [ ] Update CLAUDE.md

---

## Resources

- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- [PHP 8.4 Migration Guide](https://www.php.net/manual/en/migration84.php)
- [WordPress Plugin Security](https://developer.wordpress.org/plugins/security/)
- [PHPStan for WordPress](https://github.com/szepeviktor/phpstan-wordpress)
