=== Simple Custom Post Order ===
Contributors: silkalns
Tags: custom post order, post order, js post order, page order, posts order, category order, sort posts, sort pages, sort custom posts
Requires at least: 4.6
Requires PHP: 5.6
Tested up to: 5.3
Stable tag: 2.4.8
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Order posts(posts, any custom post types) using a Drag and Drop Sortable JavaScript. Configuration is unnecessary.

== Description ==

Order posts(posts, any custom post types) using a Drag and Drop Sortable JavaScript. Configuration is unnecessary. You can do directly on default WordPress administration.
Excluding custom query which uses order or orderby parameters, in get_posts or query_posts and so on.

This plugins is now supported and maintained by <a href="https://colorlib.com/wp/" target="_blank">Colorlib</a>.

== Installation ==

1. Upload `plugin-name.php` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently asked questions ==

= A question that someone might have =

An answer to that question.

== Screenshots ==

1. Order Custom Posts
2. Order Posts
3. Settings

== Changelog ==

= Version 2.4.8 =
* Add ability to reset order to post types

= Version 2.4.7 =
* Fix undefined index when ordering terms
* Added filter for post types args shown in settings page
* Added extra option for advanced view of post types

= Version 2.4.6 =
* Removed dashboard news widget


= Version 2.4.5 =
* Added 2 action hooks that trigger at `update_menu_order_tags` and `update_menu_order` ( https://github
* Fix issue with sorting (https://github.com/ColorlibHQ/simple-custom-post-order/issues/49)
* Fix edit page layout when no item found

= Version 2.4.4 =
* Fix for slow javscript in admin( https://github.com/ColorlibHQ/simple-custom-post-order/issues/46 )
* Fix database error( https://github.com/ColorlibHQ/simple-custom-post-order/issues/36 )

= Version 2.4.3 =
* Minor UI update added toggles

= Version 2.4.2 =
* Fixed potential bug with other plugins
* Fixed table breaking on re-ordering when Yoast SEO installed

= Version 2.4.1 =
* Fixed translations

= Version 2.4.0 =
* Optimized our db queries ( https://wordpress.org/support/topic/update-optimization/ )

= Version 2.3.9 =
* Added button to dismiss the admin notice

= Version 2.3.8 =
* Fixed white screen ( https://wordpress.org/support/topic/white-screen-after-upgrade-to-2-3-6/ )

= Version 2.3.7 =
* Fixed white screen ( https://wordpress.org/support/topic/white-screen-after-upgrade-to-2-3-6/ )

= Version 2.3.6 =
* Fixed https://github.com/ColorlibHQ/simple-custom-post-order/issues/3

= Version 2.3.5 =
* Fixed https://github.com/ColorlibHQ/simple-custom-post-order/issues/12

= Version 2.3.4 =
* Removed deprecated function "screen_icon"

= Version 2.3.2 (17-03-2017) =
* Minor documentation and readme tweaks

= Version 2.3 (24-03-2014) =
* Fixed major bug on taxonomy and post order

= Version 2.2 (02-07-2014) =
* Fixed bug: Custom Query which uses 'order' or 'orderby' parameters is preferred
* It does not depend on the designation manner of arguments( Parameters ). ( $args = 'orderby=&order=' or $args = array( 'orderby' => '', 'order' => '' ) )
* Previous Versions Issues were Improved.
* Removed Taxonomy Sort( Will add in next Version :) )

= Version 2.1 (31-12-2013) =
* Prevent Breaking autocomplete

= Version 2.0 (22-11-2013) =
* Fixed Undefined Notice Error in wp version 3.7.1
* Taxonomy Activate Checkbox removed.

= Version 1.5 (25-07-2013) =
*  Fix : fix errors
*  Added Taxonomy Sort
*  Added Taxonomy Sort option In setting Page

= Version 1.0 (20-07-2013) =
*  Initial release.
