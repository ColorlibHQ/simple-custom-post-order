=== Simple Custom Post Order ===
Contributors: silkalns
Tags: post order, custom post order, sort posts, reorder posts, drag drop order
Requires at least: 6.2
Requires PHP: 7.4
Tested up to: 7.0
Stable tag: 2.8.3
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Easily reorder posts, pages, custom post types, and taxonomies with intuitive drag-and-drop sorting in the WordPress admin.

== Description ==

**Simple Custom Post Order** is the easiest way to manually sort your WordPress content using drag-and-drop functionality. Whether you're managing blog posts, portfolio items, WooCommerce products, team members, testimonials, or any custom post type, this plugin gives you complete control over your content order.

= Why Choose Simple Custom Post Order? =

* **Zero Configuration** - Works instantly after activation
* **Drag & Drop Interface** - Intuitive sorting directly in your admin post lists
* **Universal Compatibility** - Works with any post type and taxonomy
* **Lightweight & Fast** - No bloat, minimal impact on site performance
* **Modern & Dependency-Free** - Vanilla JavaScript drag-and-drop (no jQuery UI) with touch and full keyboard support
* **Reliable Saving** - Robust AJAX saving that works behind reverse proxies, custom ports, and HTTPS setups
* **Developer Friendly** - Clean code with action hooks for customization

= Key Features =

**Sort Any Content Type**

* **Posts** - Reorder your blog posts in any sequence you prefer
* **Pages** - Organize pages beyond alphabetical or date order
* **Custom Post Types** - Perfect for portfolios, team members, testimonials, products, events, FAQs, services, and more
* **WooCommerce Products** - Manually sort products in your shop
* **Taxonomies** - Reorder categories, tags, and custom taxonomies

**Intuitive Admin Interface**

* Smooth drag-and-drop sorting directly in the WordPress post list table
* Touch-friendly — press, hold, and drag to reorder on phones and tablets
* Fully keyboard accessible — tab to a row, then use Space and the arrow keys to reorder, with screen-reader announcements
* Clear save feedback — a "Saving… / Order saved" status appears as you sort
* Optional drag handle — show a grip icon on hover, or keep rows draggable from anywhere
* Changes saved automatically via AJAX - no page refresh needed
* Works seamlessly with the default WordPress admin experience

**Smart Query Integration**

* Automatically applies custom order to front-end queries
* Respects custom `orderby` parameters when explicitly set
* Does not interfere with search results (maintains relevance sorting)
* Compatible with `get_posts()`, `WP_Query`, and standard loops

**Reset & Restore**

* One-click reset to restore default ordering
* Reset individual post types without affecting others
* Useful when you need to start fresh with your content organization

= Perfect For =

* **Bloggers** - Feature important posts at the top of your blog
* **Business Websites** - Showcase key services or team members first
* **Portfolio Sites** - Display your best work in a specific order
* **Online Stores** - Highlight featured or seasonal products
* **Membership Sites** - Organize course content or resources
* **News Sites** - Pin important stories or announcements
* **Event Websites** - Arrange events in your preferred sequence
* **Documentation Sites** - Structure help articles logically

= Use Cases =

**Portfolio Management**
Arrange your portfolio items to showcase your best work first, group similar projects together, or create a visual narrative of your creative journey.

**Team Page Organization**
Display team members by hierarchy, department, or seniority rather than by when they were added to the system.

**Product Highlighting**
Feature seasonal items, bestsellers, or new arrivals at the top of your WooCommerce shop without relying solely on sorting by date or price.

**Content Curation**
Create curated reading lists by manually ordering posts in the exact sequence you want readers to discover them.

**FAQ Organization**
Sort frequently asked questions by importance or topic, ensuring the most relevant questions appear first.

**Testimonial Display**
Show your most compelling testimonials first to maximize their impact on potential customers.

= Developer Features =

**Action Hooks**

* `scp_update_menu_order` - Fires after post order is updated
* `scp_update_menu_order_tags` - Fires after taxonomy term order is updated

**Filter Hooks**

* `scpo_post_types_args` - Modify which post types appear in settings
* `scpo_use_sortablejs` - Force the drag engine in code (overrides the setting); return `true` for the modern SortableJS engine or `false` for the classic jQuery UI sorter
* `scpo_capability` - Change the capability required to reorder (default `edit_posts`)

**Drag & Drop Engine**
Choose between the modern, dependency-free SortableJS engine (default) and the classic jQuery UI sorter under Settings > SCPOrder. The classic engine remains fully supported as a fallback.

**Advanced View Mode**
Enable the advanced view in settings to see all registered post types, including those normally hidden from the admin menu.

= Supported Post Types =

Simple Custom Post Order works with:

* WordPress Posts
* WordPress Pages
* WooCommerce Products
* Easy Digital Downloads Products
* Portfolio items (Jetpack, custom)
* Team member post types
* Testimonial post types
* Event post types (The Events Calendar, etc.)
* FAQ post types
* Any custom post type with `show_ui` enabled

= Supported Taxonomies =

* Categories
* Tags
* WooCommerce Product Categories
* WooCommerce Product Tags
* Custom taxonomies with `show_ui` enabled

= How It Works =

1. **Install & Activate** - Install the plugin from WordPress.org or upload manually
2. **Configure** - Go to Settings > SCPOrder and select which post types and taxonomies to enable
3. **Reorder** - Visit any enabled post type list and drag items to reorder
4. **Done** - Your custom order is automatically applied everywhere on your site

The plugin uses WordPress's native `menu_order` field for posts and pages, and adds a `term_order` column for taxonomy terms. This approach ensures compatibility and data persistence.

= Performance Optimized =

* Targeted cache invalidation (only clears cache for modified items)
* Efficient database queries using prepared statements
* Scripts loaded only on relevant admin pages
* No front-end performance impact

= Security First =

* All database queries use prepared statements
* AJAX requests protected with nonce verification
* Capability checks ensure only authorized users can reorder
* Input sanitization on all user data

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Go to Plugins > Add New
3. Search for "Simple Custom Post Order"
4. Click "Install Now" and then "Activate"
5. Go to Settings > SCPOrder to configure

= Manual Installation =

1. Download the plugin ZIP file
2. Log in to your WordPress admin panel
3. Go to Plugins > Add New > Upload Plugin
4. Choose the downloaded ZIP file and click "Install Now"
5. Activate the plugin
6. Go to Settings > SCPOrder to configure

= Configuration =

1. Navigate to Settings > SCPOrder
2. Check the boxes next to post types you want to enable sorting for
3. Check the boxes next to taxonomies you want to enable sorting for
4. Click "Update" to save your settings
5. Visit any enabled post type list to start reordering with drag-and-drop

== Frequently Asked Questions ==

= Does this plugin work with custom post types? =

Yes! Simple Custom Post Order works with any custom post type that has `show_ui` enabled. This includes post types from popular plugins like WooCommerce, Easy Digital Downloads, Jetpack, and any custom post types you've created.

= Will the custom order appear on my website's front-end? =

Yes, the custom order is automatically applied to all front-end queries for enabled post types. Your posts, pages, and custom content will display in your chosen order throughout your site.

= Does it work with page builders like Elementor or Divi? =

Yes, as long as the page builder uses standard WordPress queries, your custom order will be respected. Most major page builders are compatible.

= Can I reset the order back to default? =

Yes! Go to Settings > SCPOrder and use the "Reset Order" section at the bottom of the page. Select the post types you want to reset and click "Reset order". This will clear the custom ordering for selected post types.

= Does it affect search results? =

No, the plugin automatically preserves WordPress's relevance-based sorting for search results. Your search functionality will work exactly as expected.

= Is it compatible with WooCommerce? =

Yes, you can use Simple Custom Post Order to manually sort WooCommerce products, product categories, and product tags.

= Will it slow down my website? =

No, the plugin is highly optimized. It only loads scripts on relevant admin pages and uses efficient database queries. There is no impact on front-end performance.

= Can multiple users reorder at the same time? =

While technically possible, we recommend coordinating reordering activities to avoid conflicts. The last save wins if two users modify the same list simultaneously.

= Does it work with multisite? =

Yes, Simple Custom Post Order is compatible with WordPress multisite installations. Each site can have its own ordering configuration.

= How do I reorder items? =

Simply go to the post list for any enabled post type (Posts, Pages, Products, etc.), click and hold on a row, then drag it to the desired position. The new order is saved automatically.

= Does drag-and-drop work on touch devices? =

Yes. On phones and tablets, press and hold a row for a moment, then drag it to reorder. Normal taps and vertical scrolling continue to work as expected.

= Can I reorder using only the keyboard? =

Yes. Tab to a row's drag handle, press Space (or Enter) to pick it up, use the Up/Down arrow keys (or Home/End) to move it, then press Space to drop or Escape to cancel. Each step is announced for screen-reader users.

= Can I switch back to the classic jQuery sorting? =

Yes. Go to Settings > SCPOrder and set the "Drag & Drop Engine" to "Classic". Developers can also force it in code with the `scpo_use_sortablejs` filter (return `false` for the classic engine). The modern engine is the default and recommended for most sites.

= My reordering wasn't saving on a previous version — is that fixed? =

Yes. As of 2.7.0 the save request is always sent same-origin, which fixes silent save failures behind reverse proxies, load balancers, non-standard ports, and HTTP/HTTPS or domain mismatches. Expired security tokens now also refresh and retry automatically.

= Can I programmatically override the custom order? =

Yes, if you explicitly set `orderby` and `order` parameters in your custom queries, those will take precedence over the plugin's custom order.

== Screenshots ==

1. Drag-and-drop reordering of posts in the admin list table
2. Drag-and-drop reordering of custom post types
3. Plugin settings page - select post types and taxonomies to enable
4. Reset order functionality for specific post types

== Changelog ==

= 2.8.3 - 2026-07-01 =

**Security**

* Hardened the drag-and-drop reorder AJAX endpoints with per-object permission checks. Previously any signed-in user who could reach the reorder actions (by default anyone able to edit posts) could submit arbitrary post or term IDs and change their stored order — including posts, pages, or terms they are not allowed to edit. Each submitted item is now verified to belong to an enabled sortable type **and** to be editable by the current user before its order is changed. Impact was limited to ordering only (no content was exposed or edited). Reported by the WordPress.org Plugin Review Team's automated scan.

**Bug fixes**

* Fixed the manual post order being ignored on the admin Posts list after using the "All dates" or category dropdown filters. Those filters submit an empty search field, which WordPress counts as a search, and the plugin was skipping its custom order for any search. The manual order now applies while filtering; genuine searches are still left untouched. Props @r-a-y (#153).

= 2.8.2 - 2026-06-26 =

**Bug fixes**

* Fixed newly created posts and items landing at the **bottom** of the manual order on sites upgraded from before 2.8.0 — which made the latest post appear at the bottom of admin lists instead of the top. The 2.8.0 "new-item placement" feature shipped defaulting to *bottom*, which silently reversed the long-standing behavior (new items went to the **top**). The default is now *top* again. If you prefer new items at the bottom, choose it under Settings → Sortable Post Types → "New items". Sites that already selected *bottom* keep their choice. Reported by @ffusion and @deisedesign.

= 2.8.1 - 2026-06-22 =

**Improvements**

* Added a `scpo_reverse_adjacent_posts` filter to reverse the direction of the previous/next post navigation links for manually-ordered posts and custom post types. The 2.7.2 fix (#146) made "previous" the item before the current one in the arranged order and "next" the item after — correct for sequential content, but the opposite of what sites built around WordPress's native chronological convention expect. Return `true` from the filter to restore the pre-2.7.2 direction without editing your theme's template tags. Reported by @sarahmelyne.

= 2.8.0 - 2026-06-17 =

**New features**

* **Choose where new items are placed** — a setting to add newly created posts/pages/items to the bottom (default) or top of the manual order. Props @mplusb (#45).
* **Optional “Order” number column** — turn on an editable position column on enabled (non-hierarchical) post-type lists to type an item's exact order, including jumping it across paginated pages. Hidden by default and toggleable via Screen Options. (Hierarchical types like Pages are handled separately in a future release.) Props @mplusb (#76, #89, #136).
* **Role-based reordering** — restrict drag-and-drop reordering to specific roles in Settings > SCPOrder, with a new `scpo_capability` filter for developers. Props @mplusb (#95, #133).

= 2.7.3 - 2026-06-04 =

**Bug fixes**

* Fixed Quick Edit and Bulk Edit fields (text inputs, dropdowns) not responding to a left mouse click on post/page list screens when the Modern (SortableJS) sorting engine was active. The drag engine was excluding those inline-edit rows from sorting but still suppressing the browser's default click behaviour on them; native field interaction is now preserved. Right-click was unaffected, and the Classic engine was never affected. Reported by @stilografico and @tedmw.

= 2.7.2 - 2026-06-03 =

**Bug fixes**

* Fixed previous/next post navigation links pointing to the wrong adjacent post (often reversed) for manually-ordered posts and custom post types. The adjacent-post queries now walk `menu_order` correctly and account for the date/ID tiebreaker WordPress adds to the query. Props @beatricelucaci (#146).

= 2.7.1 - 2026-06-02 =

**Bug fixes**

* Fixed post order being scrambled on MariaDB / MySQL 8 when `menu_order` is re-normalized after gaps appear (e.g. after deleting an item). The gap-compacting step relied on a MySQL user-variable ranking whose evaluation order is undefined on those databases; re-numbering is now done deterministically in PHP. Props @alexgw and @sebastiencyr (#147 / #119).
* Term queries that request a specific order with `orderby=include` are now respected instead of being overridden by the custom term order. Props @glebkema (#67 / #66).

**Improvements**

* Custom term ordering now applies when any queried taxonomy is sortable (previously only the first taxonomy in a multi-taxonomy query was checked) and keeps the caller's `orderby` as a fallback tiebreaker. Props @goaround (#104).

= 2.7.0 - 2026-06-02 =

**Modern Drag-and-Drop Engine (SortableJS)**

* Reordering now uses a fast, dependency-free vanilla JavaScript engine (no jQuery UI)
* Smoother drag animation and a cleaner floating row while sorting
* Touch support — press, hold, and drag to reorder on phones and tablets
* Full keyboard accessibility — tab to a row, press Space to grab, arrow keys (and Home/End) to move, Space to drop, Escape to cancel
* Screen-reader announcements for every grab, move, and drop via an ARIA live region
* Visible save feedback — a "Saving… / Order saved" status appears as you reorder

**Reliability**

* Fixed reordering silently failing to save in some environments — the AJAX request is now always same-origin (root-relative), so saving works correctly behind reverse proxies, load balancers, non-standard ports, and HTTP/HTTPS or domain mismatches
* Expired security nonce now refreshes automatically and retries the save, so long-open edit screens (or sites with a shortened nonce lifetime) keep saving without a reload
* Rapid successive drags are coalesced into a single request (the final order always wins), with an automatic retry on transient network errors

**New Settings (Settings > SCPOrder)**

* Drag & Drop Engine — choose "Modern" (SortableJS, default) or "Classic" (the legacy jQuery UI sorter)
* Drag handle — optionally show a grip icon on row hover; hidden by default, and never affects accessibility (keyboard users can always reveal it)

**Accessibility**

* Respects the `prefers-reduced-motion` setting
* Proper ARIA labels, roles, and focus handling on the drag handle

**Performance & Build**

* Ships minified admin assets; unminified sources load automatically when `SCRIPT_DEBUG` is enabled
* Removed unused/dead JavaScript

**Developer**

* New filter `scpo_use_sortablejs` — force the drag engine on/off in code (overrides the setting)
* The classic jQuery UI Sortable path remains available as an opt-out fallback
* Fully backward compatible — existing settings, hooks (`scp_update_menu_order`, `scp_update_menu_order_tags`, `scpo_post_types_args`), the global `$scporder`, and the `scporder_options` structure are unchanged (two optional keys added: `engine`, `show_handle`)

= 2.6.1 - 2026-06-01 =

**Compatibility**

* Tested and confirmed compatible with WordPress 7.0
* Updated "Tested up to" version to WordPress 7.0
* Verified PHP 8.3 and 8.4 compatibility (no deprecated APIs in use)
* Maintenance release — no functional changes

= 2.6.0 - 2026-01-09 =

**Settings Page Overhaul**

* Complete rewrite using WordPress Settings API for native admin experience
* Replaced custom toggle switches with standard WordPress checkboxes
* Removed 100+ lines of custom CSS - now uses native WordPress admin styles
* Improved accessibility with proper ARIA roles and screen reader text
* Settings now integrate seamlessly with WordPress core admin UI
* Added "Settings" link to plugin action links on the Plugins page

**Security Enhancements**

* Fixed potential SQL injection vulnerabilities with prepared statements
* Added proper output escaping (XSS prevention) throughout the plugin
* Improved input sanitization for all user data
* Added nonce escaping in JavaScript contexts

**Performance Improvements**

* Replaced blanket cache flushing with targeted cache invalidation
* Only affected posts/terms have their cache cleared after reordering
* Reduced unnecessary database operations

**PHP 8.4 Compatibility**

* Added type declarations to all class methods
* Fixed null safety issues for PHP 8.1+
* Added explicit property declarations
* Minimum PHP version now 7.4

**Code Quality**

* Converted to modern PHP syntax (short arrays, spaceship operator)
* Replaced loose comparisons with strict comparisons
* Removed legacy French comments and TODO markers
* Fixed code formatting issues
* Improved AJAX responses with proper JSON structure

**Bug Fixes**

* Fixed potential fatal error when `get_current_screen()` returns null
* Fixed reset order JavaScript to handle JSON responses correctly
* Fixed TypeError when plugin options are not yet set (fresh installs)

= 2.5.11 - 2025-06-23 =
* Deactivated custom sort order on search results pages

= 2.5.10 - 2024-12-04 =
* Fix Notice: _load_textdomain_just_in_time

= 2.5.9 - 2024-11-29 =
* Allow editors to change posts order

= 2.5.8 - 2024-10-10 =
* Security update

= 2.5.7 - 2023-09-20 =
* Security update fixing multiple issues
* Code cleanup for better performance

= 2.5.6 - 2021-05-27 =
* Changed: Revert to 2.5.4

= 2.5.5 - 2021-05-11 =
* Changed: Code cleaning
* Changed: Allow custom orderby in Block Preview

= 2.5.4 - 2021-03-05 =
* Changed: Improved performance

= 2.5.3 =
* Modified deprecated jQuery functions

= 2.5.2 =
* Modified deprecated jQuery function for WordPress 5.5 compatibility
* Fixed an issue where posts would be in reverse order after resetting

= 2.5.1 =
* Improved fix for post list table width when sorting is enabled
* Fixed admin AJAX overriding queries

= 2.5.0 =
* Fixed post list table width when sorting is enabled
* Review dismiss fix

= 2.4.9 =
* Fixed "Post order not saving"

= 2.4.8 =
* Added ability to reset order for post types

= 2.4.7 =
* Fixed undefined index when ordering terms
* Added filter for post types args shown in settings page
* Added extra option for advanced view of post types

= 2.4.6 =
* Removed dashboard news widget

= 2.4.5 =
* Added action hooks for update_menu_order_tags and update_menu_order
* Fixed issue with sorting
* Fixed edit page layout when no items found

= 2.4.4 =
* Fixed slow JavaScript in admin
* Fixed database error

= 2.4.3 =
* Minor UI update with toggles

= 2.4.2 =
* Fixed potential bug with other plugins
* Fixed table breaking on reordering when Yoast SEO installed

= 2.4.1 =
* Fixed translations

= 2.4.0 =
* Optimized database queries

= 2.3.9 =
* Added button to dismiss admin notice

= 2.3.8 =
* Fixed white screen issue

= 2.3.7 =
* Fixed white screen issue

= 2.3.6 =
* Bug fixes

= 2.3.5 =
* Bug fixes

= 2.3.4 =
* Removed deprecated function "screen_icon"

= 2.3.2 =
* Minor documentation and readme tweaks

= 2.3 =
* Fixed major bug on taxonomy and post order

= 2.2 =
* Fixed bug: Custom query order/orderby parameters now take precedence
* Improved parameter handling
* Removed taxonomy sort (re-added in later versions)

= 2.1 =
* Prevent breaking autocomplete

= 2.0 =
* Fixed undefined notice error in WordPress 3.7.1
* Taxonomy activate checkbox removed

= 1.5 =
* Bug fixes
* Added taxonomy sort
* Added taxonomy sort option in settings

= 1.0 =
* Initial release

== Upgrade Notice ==

= 2.8.0 =
New: choose where new items are placed, an optional editable "Order" number column (great for paginated lists), and role-based reordering control.

= 2.7.3 =
Fixes Quick Edit / Bulk Edit fields being unclickable on post list screens when the Modern sorting engine is active. Recommended for all users on the Modern engine.

= 2.7.2 =
Fixes previous/next post navigation links pointing to the wrong adjacent post for manually-ordered posts and custom post types.

= 2.7.1 =
Bug-fix release: prevents post order from being scrambled on MariaDB / MySQL 8 during order normalization, and honors `orderby=include` term queries. Recommended for all users.

= 2.7.0 =
New modern drag-and-drop: smoother sorting, touch support, full keyboard accessibility, and visible save feedback. Fixes saving in reverse-proxy / custom-port / HTTPS setups and auto-recovers expired security tokens. Fully backward compatible — the classic jQuery sorter remains available as a fallback.

= 2.6.1 =
Confirmed compatible with WordPress 7.0. Maintenance release with no functional changes.

= 2.6.0 =
Major update with redesigned settings page using WordPress Settings API, security fixes (SQL injection, XSS), PHP 8.4 compatibility, and performance improvements. Recommended for all users.

= 2.5.11 =
Search results now maintain default WordPress relevance sorting instead of custom order.

= 2.5.10 =
Fixes textdomain loading notice.

== Additional Information ==

= Support =

For support questions, please use the [WordPress.org support forum](https://wordpress.org/support/plugin/simple-custom-post-order/).

= Bug Reports =

Report bugs on our [GitHub repository](https://github.com/ColorlibHQ/simple-custom-post-order/issues).

= Contributing =

Contributions are welcome! Please submit pull requests to our [GitHub repository](https://github.com/ColorlibHQ/simple-custom-post-order).

= Credits =

This plugin is made with love by the team at Colorlib.
