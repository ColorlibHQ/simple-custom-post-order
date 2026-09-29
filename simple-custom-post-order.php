<?php
/**
 * Plugin Name: Simple Custom Post Order
 * Plugin URI: https://wordpress.org/plugins/simple-custom-post-order/
 * Description: Order posts, pages, custom post types, and taxonomies with a simple drag and drop interface.
 * Version: 2.8.9
 * Author: Colorlib
 * Author URI: https://colorlib.com/
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPLv3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: simple-custom-post-order
 * Domain Path: /languages
 *
 * Copyright 2013-2017 Sameer Humagain im@hsameer.com.np
 * Copyright 2017-2026 Colorlib support@colorlib.com
 *
 * SVN commit with ownership change: https://plugins.trac.wordpress.org/changeset/1590135/simple-custom-post-order
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCPORDER_URL', plugins_url( '', __FILE__ ) );
define( 'SCPORDER_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCPORDER_VERSION', '2.8.9' );

$scporder = new SCPO_Engine();

class SCPO_Engine {

	function __construct() {
		if ( 2 !== (int) get_option( 'scporder_install' ) ) {
			$this->scporder_install();
		}

		add_action( 'init', array( $this, 'load_dependencies' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		add_action( 'admin_init', array( $this, 'refresh' ) );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'load_script_css' ) );

		add_action( 'wp_ajax_update-menu-order', array( $this, 'update_menu_order' ) );
		add_action( 'wp_ajax_update-menu-order-tags', array( $this, 'update_menu_order_tags' ) );
		add_action( 'wp_ajax_scpo_refresh_nonce', array( $this, 'refresh_nonce' ) );

		add_action( 'pre_get_posts', array( $this, 'scporder_pre_get_posts' ) );

		// The where/sort filters receive the post being navigated from (5th / 2nd
		// argument), which is not always the global $post.
		add_filter( 'get_previous_post_where', array( $this, 'scporder_previous_post_where' ), 10, 5 );
		add_filter( 'get_previous_post_sort', array( $this, 'scporder_previous_post_sort' ), 10, 2 );
		add_filter( 'get_next_post_where', array( $this, 'scporder_next_post_where' ), 10, 5 );
		add_filter( 'get_next_post_sort', array( $this, 'scporder_next_post_sort' ), 10, 2 );

		add_filter( 'get_terms_orderby', array( $this, 'scporder_get_terms_orderby' ), 10, 3 );
		// `wp_get_object_terms` passes $args as the 4th arg, `get_terms` as the 3rd,
		// so each hook gets its own thin wrapper that hands $args to the sorter.
		add_filter( 'wp_get_object_terms', array( $this, 'scporder_get_object_terms' ), 10, 4 );
		add_filter( 'get_terms', array( $this, 'scporder_get_terms' ), 10, 3 );
		// get_the_terms() serves the per-post `{$taxonomy}_relationships` cache,
		// which clean_term_cache() does not clear, so under a persistent object
		// cache it kept returning terms in their pre-drag order.
		add_filter( 'get_the_terms', array( $this, 'scporder_get_the_terms' ), 10, 1 );

		add_action( 'admin_notices', array( $this, 'scporder_notice_not_checked' ) );
		add_action( 'wp_ajax_scporder_dismiss_notices', array( $this, 'dismiss_notices' ) );

		add_filter( 'scpo_post_types_args', array( $this, 'scpo_filter_post_types' ), 10, 2 );

		// Seed the order of newly enabled types once the option is written, rather
		// than from inside the sanitize callback (see seed_newly_enabled()).
		add_action( 'add_option_scporder_options', array( $this, 'seed_on_add_option' ), 10, 2 );
		add_action( 'update_option_scporder_options', array( $this, 'seed_newly_enabled' ), 10, 2 );

		add_action( 'wp_ajax_scpo_reset_order', array( $this, 'scpo_ajax_reset_order' ) );

		// 2.8.0: new-item placement (#45) + optional numeric Order column (#76/#89).
		add_action( 'save_post', array( $this, 'scporder_place_new_post' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'setup_order_column' ) );
		add_action( 'wp_ajax_scpo_set_position', array( $this, 'scpo_ajax_set_position' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
	}

	public function load_dependencies(): void {
		// include_once: the file instantiates SCPO_Review_Notice at the bottom, so a
		// second include would redeclare the class and fatal.
		include_once SCPORDER_DIR . 'class-simple-review.php';
	}

	/**
	 * Filter post types based on options.
	 *
	 * @param array       $args    Post type query args.
	 * @param array|false $options Plugin options or false if not set.
	 * @return array
	 */
	public function scpo_filter_post_types( array $args, $options ): array {
		if ( is_array( $options ) && isset( $options['show_advanced_view'] ) && '1' === $options['show_advanced_view'] ) {
			unset( $args['show_in_menu'] );
		}

		return $args;
	}

	/**
	 * No longer hooked: plugins hosted on wordpress.org have their translations
	 * loaded automatically (since WP 4.6). Kept so external callers don't fatal.
	 *
	 * @deprecated 2.8.9
	 * @return void
	 */
	public function load_scpo_textdomain(): void {
	}

	public function dismiss_notices(): void {
		if ( ! check_ajax_referer( 'scporder_dismiss_notice', 'scporder_nonce', false ) ) {
			wp_die( 'nok', '', 403 );
		}

		// The notice is site-wide, so only someone who can act on it (open the
		// settings page) may dismiss it for everybody.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'nok', '', 403 );
		}

		update_option( 'scporder_notice', '1' );

		wp_die( 'ok' );
	}

	public function scporder_notice_not_checked(): void {
		// Only shown to users who can reach the settings page it points to.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// "Nothing is enabled yet" — a site that sorts only taxonomies is set up.
		if ( ! empty( $this->get_scporder_options_objects() ) || ! empty( $this->get_scporder_options_tags() ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( null === $screen || 'settings_page_scporder-settings' === $screen->id ) {
			return;
		}

		$dismessed = get_option( 'scporder_notice', false );

		if ( $dismessed ) {
			return;
		}

		?>
		<div class="notice scpo-notice" id="scpo-notice">
			<img src="<?php echo esc_url( plugins_url( 'assets/logo.jpg', __FILE__ ) ); ?>" width="80" alt="">

			<h2><?php esc_html_e( 'Simple Custom Post Order', 'simple-custom-post-order' ); ?></h2>

			<p><?php esc_html_e( 'Thank you for installing our awesome plugin, in order to enable it you need to go to the settings page and select which custom post or taxonomy you want to order.', 'simple-custom-post-order' ); ?></p>

			<p><a href="<?php echo esc_url( admin_url( 'options-general.php?page=scporder-settings' ) ); ?>" class="button button-primary button-hero"><?php esc_html_e( 'Get started !', 'simple-custom-post-order' ); ?></a></p>
			<button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'simple-custom-post-order' ); ?></span></button>
		</div>

		<style>
			.scpo-notice {
				background: #e9eff3;
				border: 10px solid #fff;
				color: #608299;
				padding: 30px;
				text-align: center;
				position: relative;
			}
		</style>
		<script>
			( function () {
				var notice = document.getElementById( 'scpo-notice' );
				if ( ! notice ) {
					return;
				}
				// Vanilla and root-relative on purpose: no dependency on jQuery being
				// loaded in time, and the request stays same-origin behind proxies,
				// non-standard ports and http/https mismatches.
				notice.addEventListener( 'click', function ( evt ) {
					if ( ! evt.target.closest( '.notice-dismiss' ) ) {
						return;
					}
					evt.preventDefault();
					notice.style.display = 'none';
					if ( ! window.fetch ) {
						return;
					}
					window.fetch( <?php echo wp_json_encode( $this->get_ajax_url() ); ?>, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: 'action=scporder_dismiss_notices&scporder_nonce=' + encodeURIComponent( <?php echo wp_json_encode( wp_create_nonce( 'scporder_dismiss_notice' ) ); ?> )
					} ).catch( function () {} );
				} );
			} )();
		</script>
		<?php
	}

	/**
	 * Add the `term_order` column to the terms table.
	 *
	 * `scporder_install` is only recorded once the column is confirmed to exist.
	 * It used to be set unconditionally, so a host that refused the ALTER (no
	 * ALTER privilege, read-only replica) was marked installed without the
	 * column, and every term query then failed with "Unknown column
	 * t.term_order" once a taxonomy was enabled. A failed attempt is retried at
	 * most once a day instead of on every request.
	 *
	 * @return void
	 */
	public function scporder_install(): void {
		global $wpdb;

		if ( get_transient( 'scporder_install_failed' ) ) {
			return;
		}

		$previously_installed = (bool) get_option( 'scporder_install' );

		if ( ! $this->term_order_column_exists() ) {
			$wpdb->query( "ALTER TABLE $wpdb->terms ADD `term_order` INT( 4 ) NULL DEFAULT '0'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

			if ( ! $this->term_order_column_exists() ) {
				// Also clears a flag an older version set without the column, so
				// term queries stop referencing it (term_ordering_available()).
				delete_option( 'scporder_install' );
				set_transient( 'scporder_install_failed', 1, DAY_IN_SECONDS );
				return;
			}
		} elseif ( ! $previously_installed && false === get_option( 'scporder_term_order_owner', false ) ) {
			// A fresh install found the column already there — another ordering
			// plugin (or an older install of this one that lost its options)
			// created it. Remember that so uninstall does not drop a column
			// something else may rely on. Upgraded installs created it themselves
			// and keep the pre-2.8.9 behaviour of dropping it.
			update_option( 'scporder_term_order_owner', 'foreign', false );
		}

		// 2 = column verified. Installs from before 2.8.9 carry 1, which was set
		// even when the ALTER failed; the constructor re-runs this once for them.
		update_option( 'scporder_install', 2 );
	}

	/**
	 * Whether wp_terms has the `term_order` column.
	 *
	 * @return bool
	 */
	private function term_order_column_exists(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $wpdb->terms LIKE %s", 'term_order' ) );
	}

	/**
	 * Whether term ordering can safely be applied to queries. False while the
	 * column is missing (install failed), so term queries keep working unsorted
	 * instead of failing in SQL.
	 *
	 * @return bool
	 */
	private function term_ordering_available(): bool {
		return (bool) get_option( 'scporder_install' );
	}

	public function admin_menu(): void {
		add_options_page(
			__( 'Simple Custom Post Order', 'simple-custom-post-order' ),
			__( 'SCPOrder', 'simple-custom-post-order' ),
			'manage_options',
			'scporder-settings',
			[ $this, 'admin_page' ]
		);
	}

	public function admin_page(): void {
		require SCPORDER_DIR . 'settings.php';
	}

	/**
	 * Add Settings link to plugin action links on Plugins page.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=scporder-settings' ) ),
			esc_html__( 'Settings', 'simple-custom-post-order' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Check if sortable scripts should be loaded on current page.
	 *
	 * @return bool
	 */
	public function _check_load_script_css(): bool {
		return null !== $this->current_sortable_screen();
	}

	/**
	 * The enabled post type or taxonomy whose list is being viewed — but only
	 * when that list is displayed in its manual order.
	 *
	 * A drag save hands the visible rows' existing order values back out in the
	 * order the rows are *displayed*. On a list sorted by anything else, a
	 * single drag therefore rewrote the whole page into that other order. That
	 * used to happen on:
	 *  - Drafts / Pending, which core sorts by last-modified date;
	 *  - an admin search, which is ordered by relevance;
	 *  - `?order=desc` without an orderby, which shows the order reversed.
	 * Those views now get neither the sorter nor the refresh() pass.
	 *
	 * Also limited to the real list screens (edit.php / edit-tags.php): a URL
	 * test used to match term.php?taxonomy=… and plugin pages registered under
	 * edit.php?post_type=…&page=…, where neither is needed.
	 *
	 * @return array{0:string,1:string}|null [ 'post', $post_type ] or [ 'term', $taxonomy ].
	 */
	private function current_sortable_screen(): ?array {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen routing, no state change.
		$get = static function ( string $key ): string {
			return ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) ? trim( wp_unslash( $_GET[ $key ] ) ) : '';
		};

		if ( isset( $_GET['page'] ) || isset( $_GET['orderby'] ) ) {
			return null;
		}
		// phpcs:enable

		$order = strtolower( $get( 'order' ) );
		if ( '' !== $order && 'asc' !== $order ) {
			return null;
		}

		if ( '' !== $get( 's' ) ) {
			return null;
		}

		if ( 'edit.php' === $pagenow ) {
			$type = '' !== $get( 'post_type' ) ? sanitize_key( $get( 'post_type' ) ) : 'post';
			if ( ! in_array( $type, $this->get_scporder_options_objects(), true ) ) {
				return null;
			}
			if ( in_array( $get( 'post_status' ), [ 'draft', 'pending' ], true ) ) {
				return null;
			}
			return [ 'post', $type ];
		}

		if ( 'edit-tags.php' === $pagenow ) {
			// Not sanitize_key(): taxonomy names are not lowercased on registration.
			$taxonomy = $get( 'taxonomy' );
			if ( '' === $taxonomy || ! in_array( $taxonomy, $this->get_scporder_options_tags(), true ) ) {
				return null;
			}
			return [ 'term', $taxonomy ];
		}

		return null;
	}

	/**
	 * Load sortable scripts and styles.
	 *
	 * @return void
	 */
	public function load_script_css(): void {
		if ( ! $this->_check_load_script_css() ) {
			return;
		}

		// Don't load the sorter for users who aren't allowed to reorder — avoids a
		// drag that would just fail on save (#95).
		if ( ! $this->scporder_user_can_reorder() ) {
			return;
		}

		/**
		 * Which drag-and-drop engine to load. The user's choice in
		 * Settings → SCPOrder ("Drag & Drop Engine") provides the default; the
		 * `scpo_use_sortablejs` filter overrides it, so developers can force one
		 * engine per-site / per-network regardless of the stored setting.
		 *
		 * SortableJS (default): native touch, smoother animation, no jQuery UI,
		 * visible save feedback, keyboard + screen-reader support. The classic
		 * jQuery UI path remains as an opt-out fallback.
		 *
		 * @param bool $use_sortablejs Default derived from the saved engine option.
		 */
		$options        = get_option( 'scporder_options', [] );
		$engine_default = ! ( isset( $options['engine'] ) && 'classic' === $options['engine'] );
		$use_sortablejs = (bool) apply_filters( 'scpo_use_sortablejs', $engine_default );

		// Serve minified assets; fall back to readable source under SCRIPT_DEBUG.
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		if ( $use_sortablejs ) {
			wp_enqueue_script( 'scpo-sortablejs', SCPORDER_URL . '/assets/vendor/Sortable.min.js', [], '1.15.7', true );
			wp_enqueue_script( 'scporderjs', SCPORDER_URL . "/assets/scporder-sortablejs{$suffix}.js", [ 'scpo-sortablejs' ], SCPORDER_VERSION, true );
			wp_enqueue_style( 'scpo-admin', SCPORDER_URL . "/assets/scporder{$suffix}.css", [], SCPORDER_VERSION );
		} else {
			wp_enqueue_script( 'jquery' );
			wp_enqueue_script( 'jquery-ui-sortable' );
			wp_enqueue_script( 'scporderjs', SCPORDER_URL . "/assets/scporder{$suffix}.js", [ 'jquery' ], SCPORDER_VERSION, true );
			add_action( 'admin_print_styles', [ $this, 'print_scpo_style' ] );
		}

		// Localized for both paths (the jQuery version simply ignores i18n).
		wp_localize_script( 'scporderjs', 'scporder_vars', [
			'ajax_url' => $this->get_ajax_url(),
			'nonce'    => wp_create_nonce( 'scporder_nonce_action' ),
			'showHandle' => ( ! isset( $options['show_handle'] ) || '0' !== $options['show_handle'] ) ? '1' : '',
			'i18n'     => [
				'saving'       => __( 'Saving order…', 'simple-custom-post-order' ),
				'saved'        => __( 'Order saved', 'simple-custom-post-order' ),
				'error'        => __( 'Couldn’t save — please try again', 'simple-custom-post-order' ),
				/* translators: %1$s: item title. */
				'reorderLabel' => __( 'Reorder: %1$s', 'simple-custom-post-order' ),
				/* translators: 1: item title, 2: current row number, 3: total rows. */
				'grabbed'      => __( 'Grabbed %1$s. Row %2$d of %3$d. Use the arrow keys to move, Space to drop, Escape to cancel.', 'simple-custom-post-order' ),
				/* translators: 1: item title, 2: current row number, 3: total rows. */
				'moved'        => __( '%1$s. Row %2$d of %3$d.', 'simple-custom-post-order' ),
				/* translators: 1: item title, 2: final row number, 3: total rows. */
				'dropped'      => __( '%1$s dropped. Row %2$d of %3$d.', 'simple-custom-post-order' ),
				/* translators: 1: item title, 2: restored row number, 3: total rows. */
				'cancelled'    => __( 'Reorder cancelled. %1$s returned to row %2$d of %3$d.', 'simple-custom-post-order' ),
				'instructions' => __( 'Press Space or Enter to grab. Use the arrow keys, Home and End to move, Space or Enter to drop, Escape to cancel.', 'simple-custom-post-order' ),
				/* translators: %1$s: item title. */
				'atTop'        => __( '%1$s can’t move up any further.', 'simple-custom-post-order' ),
				/* translators: %1$s: item title. */
				'atBottom'     => __( '%1$s can’t move down any further.', 'simple-custom-post-order' ),
				'retry'        => __( 'Retry', 'simple-custom-post-order' ),
				'dismiss'      => __( 'Dismiss', 'simple-custom-post-order' ),
			],
		] );
	}

	/**
	 * Root-relative admin-ajax URL for the reorder request.
	 *
	 * An absolute admin_url() is built from the `siteurl` option, which is not
	 * guaranteed to match the origin the admin is actually being viewed from
	 * (non-standard ports, reverse proxies / load balancers, http↔https
	 * mismatches, IP-vs-domain access, staging mirrors). When it doesn't match,
	 * the browser treats the save as cross-origin: the auth cookie is withheld
	 * and the response is blocked, so the reorder silently never persists.
	 *
	 * A root-relative path ("/wp-admin/admin-ajax.php") is always resolved by
	 * the browser against the current page's origin, so the request is
	 * guaranteed same-origin on every install layout (including subdirectory
	 * and multisite, whose admin path is preserved here). Falls back to the
	 * absolute URL only if the path can't be parsed.
	 *
	 * @return string
	 */
	private function get_ajax_url(): string {
		$url   = admin_url( 'admin-ajax.php' );
		$path  = wp_parse_url( $url, PHP_URL_PATH );
		$query = wp_parse_url( $url, PHP_URL_QUERY );

		if ( ! is_string( $path ) || '' === $path ) {
			return $url;
		}

		return $query ? $path . '?' . $query : $path;
	}

	/**
	 * Re-normalize menu_order / term_order into a gapless 1..N sequence for
	 * enabled types, preserving the saved relative order.
	 *
	 * Only runs on the admin list screens where that order is actually
	 * read/displayed — the same screens the sorter loads on (`_check_load_script_css()`).
	 * It used to run unconditionally on every `admin_init`, so on large sites it
	 * added a COUNT-per-type plus (when the order wasn't already gapless) a full
	 * table renumber to *every* admin page — plugins.php, Dashboard, Tools,
	 * Settings — producing thousands of queries and multi-second TTFB on screens
	 * that never show the order (reported on wordpress.org). Nothing needs the
	 * numbering to be gapless to be correct: drag saves preserve the existing set
	 * of values, the numeric Order column and new-item placement compute from the
	 * live rows, and every read (`pre_get_posts`, adjacent-post nav, term sorting)
	 * uses `ORDER BY … / … <>` which sorts correctly with gaps. So the tidy-up is
	 * only needed right before the ordered list is rendered.
	 *
	 * @return void
	 */
	public function refresh(): void {

		if ( scporder_doing_ajax() ) {
			return;
		}

		// Only the type or taxonomy being listed. Every other enabled one is
		// normalised when its own list is opened, so probing them all here was
		// wasted queries on each list-screen load.
		$screen = $this->current_sortable_screen();
		if ( null === $screen ) {
			return;
		}

		if ( 'post' === $screen[0] ) {
			$this->normalize_post_type( $screen[1] );
		} elseif ( $this->term_ordering_available() ) {
			$this->normalize_taxonomy( $screen[1] );
		}
	}

	/**
	 * Renumber one post type's menu_order into a gapless 1..N sequence,
	 * preserving the saved relative order. No-op when it already is one.
	 *
	 * Rows are read in order and written back rather than renumbered with a
	 * MySQL user variable (@row_number) in one UPDATE: that "rank inside a
	 * derived table" trick has undefined evaluation order on MariaDB / MySQL 8
	 * and could scramble the saved order (PR #147 / issue #119).
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	private function normalize_post_type( string $post_type ): void {
		global $wpdb;

		// A tree can be gapless yet not in tree order, which the COUNT/MIN/MAX
		// probe cannot see, so hierarchical types always go through the renumber —
		// it only writes rows whose number actually changes.
		if ( is_post_type_hierarchical( $post_type ) ) {
			$this->renumber_post_type( $post_type );
			return;
		}

		$statuses     = $this->order_post_statuses();
		$placeholders = $this->order_post_statuses_placeholders();

		$probe = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated %s list; the statuses are bound here.
			$wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COUNT(DISTINCT menu_order) AS distinct_cnt, MAX(menu_order) AS max, MIN(menu_order) AS min
				FROM $wpdb->posts
				WHERE post_type = %s AND post_status IN ($placeholders)",
				array_merge( [ $post_type ], $statuses )
			)
		);

		if ( $this->is_already_sequential( $probe ) ) {
			return;
		}

		$this->renumber_post_type( $post_type );
	}

	/**
	 * Rewrite a post type's menu_order as 1..N in its current order, ties broken
	 * by post_order_by(). Shared by refresh() and the seeding of newly enabled
	 * types — the two must agree, or a tied pair settles one way after a
	 * settings save and the other way after the list is opened.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	private function renumber_post_type( string $post_type ): void {
		global $wpdb;

		$statuses     = $this->order_post_statuses();
		$placeholders = $this->order_post_statuses_placeholders();
		$order_by     = $this->post_order_by( $post_type );

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated %s list plus a hard-coded ORDER BY literal; values bound here.
			$wpdb->prepare(
				"SELECT ID, menu_order, post_parent FROM $wpdb->posts
				WHERE post_type = %s AND post_status IN ($placeholders)
				ORDER BY $order_by",
				array_merge( [ $post_type ], $statuses )
			),
			ARRAY_N
		);

		$ordered_ids = is_post_type_hierarchical( $post_type )
			? $this->tree_order( $rows )
			: array_column( $rows, 0 );

		$this->renumber_rows( $wpdb->posts, 'ID', 'menu_order', $ordered_ids, '', array_column( $rows, 1, 0 ) );
	}

	/**
	 * Flatten a hierarchical type into the order its admin list shows it:
	 * depth-first, each parent followed by its subtree, siblings in their
	 * existing order.
	 *
	 * Numbering a tree in one global sequence instead (the pre-2.8.9 behaviour)
	 * interleaved the children of different parents, so the rows on one page of
	 * a paginated page list did not hold a contiguous run of numbers. A drag
	 * save re-deals the page's own numbers, and therefore pushed untouched pages
	 * at the page boundary onto the next list page — on any save, even one that
	 * moved nothing. In the list table's own order every list page of the "All"
	 * view is a contiguous run (the ancestors WordPress repeats at the top of a
	 * page precede it), so a save only ever reorders the rows on screen.
	 * Sibling order, which is all the tree displays, is unchanged.
	 *
	 * @param array $rows Rows of [ ID, menu_order, post_parent ], siblings already in order.
	 * @return int[]
	 */
	private function tree_order( array $rows ): array {
		$children = [];
		foreach ( $rows as $row ) {
			$children[ (int) $row[2] ][] = (int) $row[0];
		}

		$ordered = [];
		$seen    = [];
		$walk    = function ( array $roots ) use ( &$ordered, &$seen, $children ) {
			$stack = array_reverse( $roots );
			while ( $stack ) {
				$id = array_pop( $stack );
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$ordered[]   = $id;
				foreach ( array_reverse( $children[ $id ] ?? [] ) as $child ) {
					$stack[] = $child;
				}
			}
		};

		$walk( $children[0] ?? [] );

		// Everything not reached from a top-level page — orphans whose parent is
		// not in the set (trashed, or a status that isn't ordered), and their own
		// descendants — is listed the way WP_Posts_List_Table::_display_rows_hierarchical()
		// lists it: after every top-level tree, flat, one group per parent in the
		// order the groups first appear.
		$in_tree = $seen;
		foreach ( $children as $parent => $kids ) {
			if ( 0 === $parent || isset( $in_tree[ $parent ] ) ) {
				continue;
			}
			foreach ( $kids as $id ) {
				if ( ! isset( $seen[ $id ] ) ) {
					$seen[ $id ] = true;
					$ordered[]   = $id;
				}
			}
		}

		// Anything unreachable (a parent loop in corrupt data) keeps its place at the end.
		foreach ( $rows as $row ) {
			if ( ! isset( $seen[ (int) $row[0] ] ) ) {
				$ordered[] = (int) $row[0];
			}
		}

		return $ordered;
	}

	/**
	 * Taxonomy counterpart of normalize_post_type().
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	private function normalize_taxonomy( string $taxonomy ): void {
		global $wpdb;

		$probe = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COUNT(DISTINCT term_order) AS distinct_cnt, MAX(term_order) AS max, MIN(term_order) AS min
				FROM $wpdb->terms AS terms
				INNER JOIN $wpdb->term_taxonomy AS term_taxonomy ON ( terms.term_id = term_taxonomy.term_id )
				WHERE term_taxonomy.taxonomy = %s",
				$taxonomy
			)
		);

		if ( $this->is_already_sequential( $probe ) ) {
			return;
		}

		$this->renumber_taxonomy( $taxonomy );
	}

	/**
	 * Taxonomy counterpart of renumber_post_type(). Ties are broken by name, the
	 * order core lists terms in, then term_id.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	private function renumber_taxonomy( string $taxonomy ): void {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT terms.term_id, terms.term_order
				FROM $wpdb->terms AS terms
				INNER JOIN $wpdb->term_taxonomy AS term_taxonomy ON ( terms.term_id = term_taxonomy.term_id )
				WHERE term_taxonomy.taxonomy = %s
				ORDER BY terms.term_order ASC, terms.name ASC, terms.term_id ASC",
				$taxonomy
			),
			ARRAY_N
		);

		$this->renumber_rows( $wpdb->terms, 'term_id', 'term_order', array_column( $rows, 0 ), $taxonomy, array_column( $rows, 1, 0 ) );
	}

	/**
	 * ORDER BY clause giving a post type's rows in their manual order, with ties
	 * broken the way WordPress itself showed them before the type was sorted:
	 * hierarchical types by title (core's `menu_order title` for page lists and
	 * wp_list_pages()), everything else newest first (the default list order).
	 *
	 * Ties used to be broken by ID alone. On a site whose pages share a Page
	 * Attributes order value, enabling Pages therefore reshuffled those pages
	 * from alphabetical into creation order — a visible change to an order the
	 * user never touched.
	 *
	 * @param string $post_type Post type.
	 * @return string One of two hard-coded literals.
	 */
	private function post_order_by( string $post_type ): string {
		return is_post_type_hierarchical( $post_type )
			? 'menu_order ASC, post_title ASC, ID ASC'
			: 'menu_order ASC, post_date DESC, ID DESC';
	}

	/**
	 * Whether an order column is already a gapless 1..N sequence, judged from the
	 * COUNT/DISTINCT/MAX/MIN probe its callers run before deciding to renumber.
	 *
	 * All three of `MAX === COUNT`, `MIN === 1` and `COUNT(DISTINCT) === COUNT`
	 * have to hold.
	 *
	 * History of this check, because each miss left real rows unrepaired:
	 *  - MAX alone: a set like {-1, 2, 3, 4, 5} (COUNT 5, MAX 5) read as clean.
	 *    Reachable once top placement started writing below the current minimum,
	 *    and left a negative number showing in the Order column. Fixed in 2.8.5
	 *    by also requiring MIN === 1.
	 *  - MAX + MIN: a set with a duplicate and a compensating gap, e.g.
	 *    {1, 2, 2, 4, 5}, still passes — COUNT 5, MAX 5, MIN 1. Duplicates arise
	 *    from imports, direct SQL, and (until 2.8.7) any row in a post status
	 *    this plugin's renumber query did not select. They were never repaired,
	 *    and because the drag handler reassigns the *existing* set of values
	 *    positionally, two rows sharing a number made dragging them a literal
	 *    no-op — the reorder saved successfully and changed nothing (reported by
	 *    @literayz). Fixed in 2.8.7 by requiring the values to be distinct.
	 *
	 * Ordering itself is never affected by gaps — reads sort on the raw value —
	 * but duplicates genuinely are: two rows with the same number sort
	 * unpredictably against each other.
	 *
	 * @param object|null $probe Row exposing cnt / distinct_cnt / max / min.
	 * @return bool True when there is nothing to renumber.
	 */
	private function is_already_sequential( $probe ): bool {
		if ( ! is_object( $probe ) ) {
			return true;
		}

		$count = (int) $probe->cnt;

		if ( 0 === $count ) {
			return true;
		}

		// COUNT(DISTINCT col) skips NULLs, so an all-NULL term_order column
		// (the column is `INT NULL DEFAULT 0`) reports 0 here and is renumbered.
		if ( isset( $probe->distinct_cnt ) && (int) $probe->distinct_cnt !== $count ) {
			return false;
		}

		return $count === (int) $probe->max && 1 === (int) $probe->min;
	}

	/**
	 * Post statuses whose rows take part in ordering.
	 *
	 * These are exactly the statuses WordPress shows in a list table's "All"
	 * view, so the set of rows this plugin renumbers matches the set of rows the
	 * user can actually see and drag.
	 *
	 * Until 2.8.7 the five built-in statuses were hard-coded. Any row in a
	 * custom status registered by another plugin (editorial workflows, "archived",
	 * WooCommerce-style statuses) was therefore excluded from both the
	 * COUNT/MAX/MIN probe and the renumber, yet still rendered in the list — so
	 * it kept whatever `menu_order` it had (usually 0) while its neighbours were
	 * renumbered from 1, producing permanent duplicates that no amount of
	 * dragging could resolve. `show_in_admin_all_list` resolves to the same five
	 * statuses on a stock site, so this is purely additive.
	 *
	 * @return string[]
	 */
	private function order_post_statuses(): array {
		$statuses = get_post_stati( [ 'show_in_admin_all_list' => true ] );

		if ( empty( $statuses ) ) {
			return [ 'publish', 'pending', 'draft', 'private', 'future' ];
		}

		return array_values( $statuses );
	}

	/**
	 * `%s` placeholder list for order_post_statuses(), for use inside an IN ().
	 *
	 * The statuses themselves are still bound through $wpdb->prepare() by the
	 * caller — only the placeholder string is interpolated.
	 *
	 * @return string
	 */
	private function order_post_statuses_placeholders(): string {
		return implode( ', ', array_fill( 0, count( $this->order_post_statuses() ), '%s' ) );
	}

	/**
	 * Write a gapless 1..N sequence into an order column for the given rows, in
	 * the fewest queries possible.
	 *
	 * Replaces the previous one-UPDATE-per-row loop, which issued N write queries
	 * per dirty type and — combined with refresh() running on every admin page —
	 * produced the thousands-of-queries / multi-second TTFB reported on large
	 * sites. Rows are renumbered with a single `CASE` statement, chunked so the
	 * generated SQL stays well under `max_allowed_packet` on any host. Every ID
	 * and position is bound through `$wpdb->prepare()`; only the internal table /
	 * column identifiers (all `$wpdb->*` constants, never user input) are
	 * interpolated.
	 *
	 * @param string $table        Table name ( $wpdb->posts or $wpdb->terms ).
	 * @param string $id_column    Primary-key column ( 'ID' or 'term_id' ).
	 * @param string $order_column Order column to rewrite ( 'menu_order' or 'term_order' ).
	 * @param array  $ordered_ids  Row IDs already in the desired order.
	 * @param string $taxonomy     Taxonomy the IDs belong to, when renumbering terms.
	 *                             Required for correct cache invalidation (see
	 *                             invalidate_order_cache()); ignored for posts.
	 * @param array  $current      Optional map of ID => current order value. Rows
	 *                             already holding their new value are then neither
	 *                             written nor invalidated.
	 * @return void
	 */
	private function renumber_rows( string $table, string $id_column, string $order_column, array $ordered_ids, string $taxonomy = '', array $current = [] ): void {
		$values   = [];
		$position = 1;
		foreach ( $ordered_ids as $id ) {
			$values[ (int) $id ] = $position++;
		}

		$this->write_order_values( $table, $id_column, $order_column, $values, $taxonomy, $current );
	}

	/**
	 * Write explicit order values for a set of rows, batched into chunked
	 * `CASE` UPDATEs, then invalidate the object cache for the rows written.
	 *
	 * @param string $table        Table name ( $wpdb->posts or $wpdb->terms ).
	 * @param string $id_column    Primary-key column ( 'ID' or 'term_id' ).
	 * @param string $order_column Order column ( 'menu_order' or 'term_order' ).
	 * @param array  $values       Map of ID => new order value.
	 * @param string $taxonomy     Taxonomy, when writing terms (see invalidate_order_cache()).
	 * @param array  $current      Optional map of ID => current value; unchanged rows are skipped.
	 * @return int[] IDs actually written.
	 */
	private function write_order_values( string $table, string $id_column, string $order_column, array $values, string $taxonomy = '', array $current = [] ): array {
		global $wpdb;

		$changed = [];
		foreach ( $values as $id => $value ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			if ( array_key_exists( $id, $current ) && null !== $current[ $id ] && (int) $current[ $id ] === (int) $value ) {
				continue;
			}
			$changed[ $id ] = (int) $value;
		}

		if ( empty( $changed ) ) {
			return [];
		}

		foreach ( array_chunk( $changed, 1000, true ) as $chunk ) {
			$cases = '';
			$args  = [];
			$in    = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			foreach ( $chunk as $id => $value ) {
				$cases .= ' WHEN %d THEN %d';
				$args[] = $id;
				$args[] = $value;
			}
			// Identifiers are internal ( $wpdb->posts/terms, fixed column names );
			// all values are bound via prepare(). The WHERE limits the update to the
			// listed IDs, so every row matches a WHEN — ELSE just guards against ever
			// writing NULL.
			$sql = "UPDATE $table SET $order_column = CASE $id_column{$cases} ELSE $order_column END WHERE $id_column IN ($in)";
			$wpdb->query( $wpdb->prepare( $sql, array_merge( $args, array_keys( $chunk ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$ids = array_keys( $changed );
		$this->invalidate_order_cache( $table, $ids, $taxonomy );

		return $ids;
	}

	/**
	 * Drop the object-cache entries for rows whose order column was written with
	 * a raw query.
	 *
	 * Raw `$wpdb` writes never pass through clean_post_cache()/clean_term_cache(),
	 * so with a persistent object cache (Redis, Memcached) the affected rows keep
	 * serving their pre-write `menu_order`/`term_order`. Symptoms: stale or
	 * duplicated values in the numeric Order column, and — because taxcmp()
	 * re-sorts terms in PHP on the *cached* `$term->term_order` — genuinely wrong
	 * term order on the front end too. Reported in PR #154.
	 *
	 * Strategy depends on how many rows changed. Small writes (the overwhelmingly
	 * common case) go through clean_post_cache()/clean_term_cache() row by row,
	 * so third-party code hooked on those actions still hears about them. Past
	 * the threshold that gets expensive — clean_post_cache() loads each post
	 * first, one query per row — so a bulk renumber instead deletes the rows'
	 * cache entries in one wp_cache_delete_multiple() call and bumps the group's
	 * `last_changed` once, which is everything a changed order value can have
	 * left stale (the row object and the query caches keyed on last_changed).
	 *
	 * Until 2.8.9 the bulk path was wp_cache_flush_group(), which discarded
	 * every cached post or term on the site; renumbering a large type after
	 * each new item was published made that a routine event.
	 *
	 * @param string $table    Table that was written ( $wpdb->posts or $wpdb->terms ).
	 * @param array  $ids      IDs whose order column changed.
	 * @param string $taxonomy Taxonomy the IDs belong to, when invalidating terms.
	 *                         clean_term_cache() treats a bare ID list as
	 *                         term_taxonomy_ids when no taxonomy is passed, which
	 *                         busts the wrong rows wherever term_id and
	 *                         term_taxonomy_id have diverged.
	 * @return void
	 */
	private function invalidate_order_cache( string $table, array $ids, string $taxonomy = '' ): void {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$is_posts = ( $wpdb->posts === $table );
		if ( ! $is_posts && $wpdb->terms !== $table ) {
			return;
		}

		/**
		 * Row count above which rows are invalidated in bulk instead of through
		 * clean_post_cache()/clean_term_cache() one at a time. (The name predates
		 * 2.8.9, when the bulk path was a whole-group flush.)
		 *
		 * @param int    $threshold Number of rows. Default 500.
		 * @param string $table     Table being invalidated.
		 */
		$threshold = (int) apply_filters( 'scpo_cache_flush_group_threshold', 500, $table );

		if ( count( $ids ) > $threshold ) {
			if ( $is_posts ) {
				wp_cache_delete_multiple( $ids, 'posts' );
				wp_cache_set_posts_last_changed();
			} else {
				wp_cache_delete_multiple( $ids, 'terms' );
				if ( function_exists( 'wp_cache_set_terms_last_changed' ) ) {
					wp_cache_set_terms_last_changed();
				} else {
					wp_cache_set( 'last_changed', microtime(), 'terms' ); // WP < 6.3.
				}
			}
			return;
		}

		if ( $is_posts ) {
			foreach ( $ids as $id ) {
				clean_post_cache( $id );
			}
			return;
		}

		if ( '' !== $taxonomy ) {
			clean_term_cache( $ids, $taxonomy );
			return;
		}

		// No taxonomy supplied — resolve it per term rather than letting
		// clean_term_cache() reinterpret the IDs as term_taxonomy_ids.
		$by_taxonomy = [];
		foreach ( $ids as $id ) {
			$term = get_term( $id );
			if ( $term instanceof WP_Term ) {
				$by_taxonomy[ $term->taxonomy ][] = $id;
			}
		}
		foreach ( $by_taxonomy as $term_taxonomy => $term_ids ) {
			clean_term_cache( $term_ids, $term_taxonomy );
		}
	}

	/**
	 * Save post order after a drag (AJAX).
	 *
	 * @return void
	 */
	public function update_menu_order(): void {
		$this->save_dragged_order( 'post' );
	}

	/**
	 * Save term order after a drag (AJAX).
	 *
	 * @return void
	 */
	public function update_menu_order_tags(): void {
		$this->save_dragged_order( 'term' );
	}

	/**
	 * Shared drag-save handler for posts and terms.
	 *
	 * Reordering reuses the *existing* set of order values of the submitted rows
	 * and reassigns them positionally, which keeps rows on other pages of a
	 * paginated list untouched.
	 *
	 * @param string $kind 'post' or 'term'.
	 * @return void
	 */
	private function save_dragged_order( string $kind ): void {
		global $wpdb;

		check_ajax_referer( 'scporder_nonce_action', 'nonce' );

		if ( ! $this->scporder_user_can_reorder() ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'simple-custom-post-order' ) ], 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed strictly into positive integers by parse_order_ids().
		$order = ( isset( $_POST['order'] ) && is_string( $_POST['order'] ) ) ? wp_unslash( $_POST['order'] ) : '';
		$ids   = $this->parse_order_ids( $order );

		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid data.', 'simple-custom-post-order' ) ] );
		}

		$is_posts = ( 'post' === $kind );

		// Object-level authorization (defense against forged IDs / IDOR).
		// scporder_user_can_reorder() only gates *access* to this endpoint; it
		// does not prove the caller may edit the specific rows they submitted.
		// Every ID must belong to an enabled sortable type the current user can
		// actually edit (posts) or manage (terms), or the whole batch is refused.
		if ( $is_posts ) {
			_prime_post_caches( $ids, false, false );
			$objects = $this->get_scporder_options_objects();
			foreach ( $ids as $id ) {
				if ( ! $this->scporder_user_can_edit_post( $id, $objects ) ) {
					wp_send_json_error( [ 'message' => __( 'Permission denied.', 'simple-custom-post-order' ) ], 403 );
				}
			}
			$table     = $wpdb->posts;
			$id_column = 'ID';
			$column    = 'menu_order';
		} else {
			_prime_term_caches( $ids, false );
			$tags = $this->get_scporder_options_tags();
			foreach ( $ids as $id ) {
				if ( ! $this->scporder_user_can_edit_term( $id, $tags ) ) {
					wp_send_json_error( [ 'message' => __( 'Permission denied.', 'simple-custom-post-order' ) ], 403 );
				}
			}
			$table     = $wpdb->terms;
			$id_column = 'term_id';
			$column    = 'term_order';
		}

		// Current values, in one query.
		$in      = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$current = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal identifiers and a generated %d list; IDs bound here.
			$wpdb->prepare( "SELECT $id_column, $column FROM $table WHERE $id_column IN ($in)", $ids ),
			ARRAY_N
		);
		$current = array_column( $current, 1, 0 );

		$values = array_map( 'intval', array_values( $current ) );
		sort( $values );

		// Re-dealing the existing set only works while the values are distinct:
		// if two of the dragged rows share a number, sorting and re-dealing the
		// same multiset writes back exactly what each row already had, so the
		// save succeeded and the order never moved — the "menu order is not
		// updating when I drag and drop" report (@literayz). Force the reused set
		// to strictly increase so every position gets its own value. refresh()
		// normalises the whole type back to a gapless 1..N the next time the list
		// screen renders, so any number pushed past a neighbour outside this page
		// is short-lived.
		$previous = null;
		foreach ( $values as $i => $value ) {
			if ( null !== $previous && $value <= $previous ) {
				$value        = $previous + 1;
				$values[ $i ] = $value;
			}
			$previous = $value;
		}

		$new = [];
		foreach ( $ids as $position => $id ) {
			if ( isset( $values[ $position ] ) ) {
				$new[ $id ] = $values[ $position ];
			}
		}

		// One batched write for the rows whose value actually changed, with the
		// object-cache invalidation that raw writes need (see
		// invalidate_order_cache(); for terms it resolves each term's taxonomy).
		$this->write_order_values( $table, $id_column, $column, $new, '', $current );

		if ( $is_posts ) {
			do_action( 'scp_update_menu_order' );
		} else {
			do_action( 'scp_update_menu_order_tags' );
		}

		wp_send_json_success( [ 'message' => __( 'Order updated.', 'simple-custom-post-order' ) ] );
	}

	/**
	 * Parse the sorter's `key[]=id&key[]=id…` payload into row IDs, in order.
	 *
	 * Parsed by hand rather than with parse_str(), which stops at
	 * `max_input_vars` (1000 by default) — a long page tree or a host with a
	 * lower limit silently saved a truncated list and printed a PHP warning into
	 * the JSON response. Non-numeric values (e.g. the `bulk[]=edit` the legacy
	 * engine serialised from an open Bulk Edit row) are skipped instead of
	 * failing the batch, and repeated IDs (an open Quick Edit row duplicates its
	 * post's ID) keep their first position only.
	 *
	 * @param string $order Serialized order.
	 * @return int[]
	 */
	private function parse_order_ids( string $order ): array {
		$ids = [];

		foreach ( explode( '&', $order ) as $pair ) {
			$parts = explode( '=', $pair, 2 );
			if ( 2 !== count( $parts ) || '[]' !== substr( rawurldecode( $parts[0] ), -2 ) ) {
				continue;
			}

			$value = rawurldecode( $parts[1] );
			if ( '' === $value || ! ctype_digit( $value ) ) {
				continue;
			}

			$id = (int) $value;
			if ( $id > 0 && ! isset( $ids[ $id ] ) ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Issue a fresh reorder nonce.
	 *
	 * A nonce embedded at page load expires (12–24h by default, often far less
	 * when a security plugin shortens nonce_life). When that happens a reorder
	 * save is rejected with "-1" and the client calls this endpoint to obtain a
	 * fresh nonce and transparently retry — so a long-open edit screen still
	 * saves without the user reloading.
	 *
	 * This handler intentionally does NOT verify a nonce (the stale nonce is the
	 * very reason it's called). It is safe because it is an authenticated action
	 * (wp_ajax_, not nopriv) gated on the same scporder_user_can_reorder() check
	 * as the reorder endpoints, and admin-ajax only sends CORS headers to the
	 * site's own allowed origins (send_origin_headers()), so the issued nonce
	 * cannot be read by a foreign origin. This mirrors how core's Heartbeat API
	 * refreshes nonces.
	 *
	 * @return void
	 */
	public function refresh_nonce(): void {
		if ( ! $this->scporder_user_can_reorder() ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'simple-custom-post-order' ) ], 403 );
		}

		wp_send_json_success( [ 'nonce' => wp_create_nonce( 'scporder_nonce_action' ) ] );
	}


	/**
	 * Register plugin settings using WordPress Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'scporder_settings',
			'scporder_options',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_options' ],
				'default'           => [
					'objects'            => [],
					'tags'               => [],
					'show_advanced_view' => '',
					'engine'             => 'sortable',
					'show_handle'        => '1',
					'new_post_position'  => 'top',
					'allowed_roles'      => [],
					'order_column'       => '',
				],
			]
		);

		// Post Types Section
		add_settings_section(
			'scporder_post_types_section',
			__( 'Sortable Post Types', 'simple-custom-post-order' ),
			[ $this, 'render_post_types_section' ],
			'scporder-settings'
		);

		add_settings_field(
			'scporder_objects',
			__( 'Enable sorting for:', 'simple-custom-post-order' ),
			[ $this, 'render_post_types_field' ],
			'scporder-settings',
			'scporder_post_types_section'
		);

		// Taxonomies Section
		add_settings_section(
			'scporder_taxonomies_section',
			__( 'Sortable Taxonomies', 'simple-custom-post-order' ),
			[ $this, 'render_taxonomies_section' ],
			'scporder-settings'
		);

		add_settings_field(
			'scporder_tags',
			__( 'Enable sorting for:', 'simple-custom-post-order' ),
			[ $this, 'render_taxonomies_field' ],
			'scporder-settings',
			'scporder_taxonomies_section'
		);

		// Drag & Drop Engine Section
		add_settings_section(
			'scporder_engine_section',
			__( 'Drag & Drop Engine', 'simple-custom-post-order' ),
			[ $this, 'render_engine_section' ],
			'scporder-settings'
		);

		add_settings_field(
			'scporder_engine',
			__( 'Sorting engine', 'simple-custom-post-order' ),
			[ $this, 'render_engine_field' ],
			'scporder-settings',
			'scporder_engine_section'
		);

		add_settings_field(
			'scporder_show_handle',
			__( 'Drag handle', 'simple-custom-post-order' ),
			[ $this, 'render_handle_field' ],
			'scporder-settings',
			'scporder_engine_section'
		);

		// Advanced Section
		add_settings_section(
			'scporder_advanced_section',
			__( 'Advanced Options', 'simple-custom-post-order' ),
			[ $this, 'render_advanced_section' ],
			'scporder-settings'
		);

		add_settings_field(
			'scporder_advanced_view',
			__( 'Advanced View', 'simple-custom-post-order' ),
			[ $this, 'render_advanced_view_field' ],
			'scporder-settings',
			'scporder_advanced_section'
		);

		add_settings_field(
			'scporder_new_post_position',
			__( 'New items', 'simple-custom-post-order' ),
			[ $this, 'render_new_post_position_field' ],
			'scporder-settings',
			'scporder_advanced_section'
		);

		add_settings_field(
			'scporder_order_column',
			__( 'Order column', 'simple-custom-post-order' ),
			[ $this, 'render_order_column_field' ],
			'scporder-settings',
			'scporder_advanced_section'
		);

		add_settings_field(
			'scporder_allowed_roles',
			__( 'Who can reorder', 'simple-custom-post-order' ),
			[ $this, 'render_allowed_roles_field' ],
			'scporder-settings',
			'scporder_advanced_section'
		);
	}

	/**
	 * Sanitize and validate options before saving.
	 *
	 * Deliberately free of side effects. It used to seed the order of every
	 * enabled type from in here, but a sanitize callback runs on *every*
	 * update_option() call — including the Reset Order handler's and any
	 * wp-cli/REST write — and twice when the option is first added, so e.g.
	 * resetting one post type renumbered all the others. Seeding now happens
	 * once the value is stored, and only for newly enabled types
	 * (seed_newly_enabled()).
	 *
	 * @param mixed $input The input array to sanitize.
	 * @return array Sanitized options.
	 */
	public function sanitize_options( $input ): array {
		$input = is_array( $input ) ? $input : [];

		$sanitized = [
			'objects'            => [],
			'tags'               => [],
			'show_advanced_view' => '',
			'engine'             => 'sortable',
			'show_handle'        => '1',
			'new_post_position'  => 'top',
			'allowed_roles'      => [],
			'order_column'       => '',
		];

		// Only names that are actually registered, matched exactly. sanitize_key()
		// used to lowercase them, which silently dropped any taxonomy registered
		// with capitals ("Genre" was stored as "genre" and never matched again).
		if ( isset( $input['objects'] ) && is_array( $input['objects'] ) ) {
			$sanitized['objects'] = array_values( array_unique( array_intersect( array_filter( $input['objects'], 'is_string' ), array_keys( get_post_types() ) ) ) );
		}

		if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
			$sanitized['tags'] = array_values( array_unique( array_intersect( array_filter( $input['tags'], 'is_string' ), array_keys( get_taxonomies() ) ) ) );
		}

		// Sanitize advanced view option
		if ( ! empty( $input['show_advanced_view'] ) ) {
			$sanitized['show_advanced_view'] = '1';
		}

		// Sanitize drag-and-drop engine choice (anything but 'classic' is the default).
		$sanitized['engine'] = ( isset( $input['engine'] ) && 'classic' === $input['engine'] ) ? 'classic' : 'sortable';

		// Show-drag-handle toggle, which defaults to on. From the settings form an
		// unchecked box is simply absent, so absent means "hide" there. Any other
		// writer (Reset Order, wp-cli, an install whose options predate 2.7.0)
		// just didn't pass the key, and that must not switch the handle off.
		if ( ! empty( $input['_scpo_form'] ) ) {
			$sanitized['show_handle'] = ! empty( $input['show_handle'] ) ? '1' : '0';
		} else {
			$sanitized['show_handle'] = ( isset( $input['show_handle'] ) && '0' === (string) $input['show_handle'] ) ? '0' : '1';
		}

		// Where newly created items are placed in the order.
		$sanitized['new_post_position'] = ( isset( $input['new_post_position'] ) && 'bottom' === $input['new_post_position'] ) ? 'bottom' : 'top';

		// Optional numeric "Order" column (off by default).
		$sanitized['order_column'] = ! empty( $input['order_column'] ) ? '1' : '0';

		// Roles allowed to reorder. Empty array = fall back to the capability check.
		$sanitized['allowed_roles'] = [];
		if ( isset( $input['allowed_roles'] ) && is_array( $input['allowed_roles'] ) && function_exists( 'wp_roles' ) ) {
			$valid_roles                = array_keys( wp_roles()->get_names() );
			$sanitized['allowed_roles'] = array_values( array_intersect( $valid_roles, array_map( 'sanitize_key', array_filter( $input['allowed_roles'], 'is_string' ) ) ) );
		}

		return $sanitized;
	}

	/**
	 * `add_option_scporder_options` callback — the first save enables types.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Stored value.
	 * @return void
	 */
	public function seed_on_add_option( $option, $value ): void {
		$this->seed_newly_enabled( [], $value );
	}

	/**
	 * Give newly enabled post types and taxonomies an initial order.
	 *
	 * Runs once the option is stored, for the types that were not enabled
	 * before, so re-saving the settings (or any other write of the option)
	 * never renumbers a type that is already being sorted.
	 *
	 * Seeding must not destroy an order the site already has. Ordering on
	 * title/date alone used to alphabetise every page the moment Pages was
	 * ticked, discarding WordPress's own Page Attributes → Order values and
	 * whatever a previously removed sorting plugin left behind (reported by
	 * @martinsauter, diagnosed by @jamieburchell). The existing order value is
	 * therefore always the primary key; title/date only break ties, exactly as
	 * WordPress listed tied rows before the type was sorted (post_order_by()).
	 * On an untouched site every row shares one value, so that reduces to
	 * alphabetical pages and newest-first everything else.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $value     New option value.
	 * @return void
	 */
	public function seed_newly_enabled( $old_value, $value ): void {
		$old_value = is_array( $old_value ) ? $old_value : [];
		$value     = is_array( $value ) ? $value : [];

		$old_objects = ( isset( $old_value['objects'] ) && is_array( $old_value['objects'] ) ) ? $old_value['objects'] : [];
		$old_tags    = ( isset( $old_value['tags'] ) && is_array( $old_value['tags'] ) ) ? $old_value['tags'] : [];
		$objects     = ( isset( $value['objects'] ) && is_array( $value['objects'] ) ) ? $value['objects'] : [];
		$tags        = ( isset( $value['tags'] ) && is_array( $value['tags'] ) ) ? $value['tags'] : [];

		foreach ( array_diff( $objects, $old_objects ) as $post_type ) {
			$this->normalize_post_type( (string) $post_type );
		}

		if ( ! $this->term_ordering_available() ) {
			return;
		}

		foreach ( array_diff( $tags, $old_tags ) as $taxonomy ) {
			$this->normalize_taxonomy( (string) $taxonomy );
		}
	}

	/**
	 * Render post types section description.
	 *
	 * @return void
	 */
	public function render_post_types_section(): void {
		echo '<p>' . esc_html__( 'Select which post types should have drag-and-drop sorting enabled.', 'simple-custom-post-order' ) . '</p>';
	}

	/**
	 * Render post types checkboxes.
	 *
	 * @return void
	 */
	public function render_post_types_field(): void {
		$options        = get_option( 'scporder_options', [] );
		$saved_objects  = isset( $options['objects'] ) && is_array( $options['objects'] ) ? $options['objects'] : [];
		$post_types_args = apply_filters(
			'scpo_post_types_args',
			[
				'show_ui'      => true,
				'show_in_menu' => true,
			],
			$options
		);
		$post_types = get_post_types( $post_types_args, 'objects' );

		// Tells sanitize_options() this save came from the form, where an
		// unchecked checkbox is absent rather than false.
		echo '<input type="hidden" name="scporder_options[_scpo_form]" value="1" />';
		echo '<fieldset>';
		echo '<legend class="screen-reader-text"><span>' . esc_html__( 'Post Types', 'simple-custom-post-order' ) . '</span></legend>';

		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}

			$checked = in_array( $post_type->name, $saved_objects, true );
			printf(
				'<label><input type="checkbox" name="scporder_options[objects][]" value="%s" %s /> %s</label><br />',
				esc_attr( $post_type->name ),
				checked( $checked, true, false ),
				esc_html( $post_type->label )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Render taxonomies section description.
	 *
	 * @return void
	 */
	public function render_taxonomies_section(): void {
		echo '<p>' . esc_html__( 'Select which taxonomies should have drag-and-drop sorting enabled.', 'simple-custom-post-order' ) . '</p>';
	}

	/**
	 * Render taxonomies checkboxes.
	 *
	 * @return void
	 */
	public function render_taxonomies_field(): void {
		$options    = get_option( 'scporder_options', [] );
		$saved_tags = isset( $options['tags'] ) && is_array( $options['tags'] ) ? $options['tags'] : [];
		$taxonomies = get_taxonomies( [ 'show_ui' => true ], 'objects' );

		echo '<fieldset>';
		echo '<legend class="screen-reader-text"><span>' . esc_html__( 'Taxonomies', 'simple-custom-post-order' ) . '</span></legend>';

		foreach ( $taxonomies as $taxonomy ) {
			if ( 'post_format' === $taxonomy->name ) {
				continue;
			}

			$checked = in_array( $taxonomy->name, $saved_tags, true );
			printf(
				'<label><input type="checkbox" name="scporder_options[tags][]" value="%s" %s /> %s</label><br />',
				esc_attr( $taxonomy->name ),
				checked( $checked, true, false ),
				esc_html( $taxonomy->label )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Render drag-and-drop engine section description.
	 *
	 * @return void
	 */
	public function render_engine_section(): void {
		echo '<p>' . esc_html__( 'Choose how drag-and-drop reordering behaves. Saving works identically either way — only the interface differs.', 'simple-custom-post-order' ) . '</p>';
	}

	/**
	 * Render the drag-and-drop engine choice (Modern vs Classic).
	 *
	 * The stored choice is the default; a `scpo_use_sortablejs` filter added by
	 * a theme/plugin overrides it at runtime, which we surface to the admin.
	 *
	 * @return void
	 */
	public function render_engine_field(): void {
		$options = get_option( 'scporder_options', [] );
		$engine  = ( isset( $options['engine'] ) && 'classic' === $options['engine'] ) ? 'classic' : 'sortable';
		$forced  = has_filter( 'scpo_use_sortablejs' );

		echo '<fieldset>';
		echo '<legend class="screen-reader-text"><span>' . esc_html__( 'Sorting engine', 'simple-custom-post-order' ) . '</span></legend>';

		printf(
			'<label><input type="radio" name="scporder_options[engine]" value="sortable" %s /> %s</label><br />',
			checked( 'sortable', $engine, false ),
			esc_html__( 'Modern — smooth animation, touch & keyboard support, save feedback (recommended)', 'simple-custom-post-order' )
		);
		printf(
			'<label><input type="radio" name="scporder_options[engine]" value="classic" %s /> %s</label>',
			checked( 'classic', $engine, false ),
			esc_html__( 'Classic — legacy jQuery UI (use only if the modern engine causes a problem)', 'simple-custom-post-order' )
		);

		if ( $forced ) {
			echo '<p class="description">' . esc_html__( 'A theme or plugin is currently overriding this choice via the scpo_use_sortablejs filter, so the option above may not reflect what loads.', 'simple-custom-post-order' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Developers can override this per-site with the scpo_use_sortablejs filter.', 'simple-custom-post-order' ) . '</p>';
		}

		echo '</fieldset>';
	}

	/**
	 * Render the "show drag handle" toggle, with a live preview of the grip icon.
	 *
	 * This only controls the *visible* (mouse-hover) grip. Rows stay draggable
	 * from anywhere, and keyboard users can always reveal the handle by tabbing
	 * to a row — so turning this off never affects accessibility. Applies to the
	 * Modern (SortableJS) engine.
	 *
	 * @return void
	 */
	public function render_handle_field(): void {
		$options = get_option( 'scporder_options', [] );
		$show    = ! isset( $options['show_handle'] ) || '0' !== $options['show_handle'];

		// Static, trusted markup (no user input) — the same grip the script injects.
		$grip = '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false" style="vertical-align:middle;fill:#787c82">'
			. '<circle cx="5" cy="3" r="1.5"/><circle cx="11" cy="3" r="1.5"/>'
			. '<circle cx="5" cy="8" r="1.5"/><circle cx="11" cy="8" r="1.5"/>'
			. '<circle cx="5" cy="13" r="1.5"/><circle cx="11" cy="13" r="1.5"/></svg>';

		echo '<fieldset><label>';
		printf(
			'<input type="checkbox" name="scporder_options[show_handle]" value="1" %s /> ',
			checked( $show, true, false )
		);
		echo $grip . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, trusted SVG icon.
		echo esc_html__( 'Show this drag handle when hovering a row', 'simple-custom-post-order' );
		echo '</label>';
		echo '<p class="description">'
			. esc_html__( 'Rows stay draggable from anywhere — this just adds the grip icon as a hover cue. Keyboard users can always reveal the handle by tabbing to a row, so turning this off does not affect accessibility. Applies to the Modern engine.', 'simple-custom-post-order' )
			. '</p></fieldset>';
	}

	/**
	 * Render advanced section description.
	 *
	 * @return void
	 */
	public function render_advanced_section(): void {
		echo '<p>' . esc_html__( 'Configure advanced plugin options.', 'simple-custom-post-order' ) . '</p>';
	}

	/**
	 * Render advanced view checkbox.
	 *
	 * @return void
	 */
	public function render_advanced_view_field(): void {
		$options      = get_option( 'scporder_options', [] );
		$checked      = isset( $options['show_advanced_view'] ) && '1' === $options['show_advanced_view'];

		printf(
			'<label><input type="checkbox" name="scporder_options[show_advanced_view]" value="1" %s /> %s</label>',
			checked( $checked, true, false ),
			esc_html__( 'Show all registered post types (including hidden ones)', 'simple-custom-post-order' )
		);
		echo '<p class="description">' . esc_html__( 'Enable this to see post types that are normally hidden from the admin menu. For advanced users only.', 'simple-custom-post-order' ) . '</p>';
	}

	/**
	 * Render the "new items placement" choice (#45).
	 *
	 * @return void
	 */
	public function render_new_post_position_field(): void {
		$pos = $this->get_new_post_position();
		echo '<fieldset>';
		echo '<legend class="screen-reader-text"><span>' . esc_html__( 'New items', 'simple-custom-post-order' ) . '</span></legend>';
		printf(
			'<label><input type="radio" name="scporder_options[new_post_position]" value="bottom" %s /> %s</label><br />',
			checked( 'bottom', $pos, false ),
			esc_html__( 'Add to the bottom of the order', 'simple-custom-post-order' )
		);
		printf(
			'<label><input type="radio" name="scporder_options[new_post_position]" value="top" %s /> %s</label>',
			checked( 'top', $pos, false ),
			esc_html__( 'Add to the top of the order (default)', 'simple-custom-post-order' )
		);
		echo '<p class="description">' . esc_html__( 'Where a newly created item lands in the manual order of an enabled post type.', 'simple-custom-post-order' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Render the optional "Order" column toggle (#76 / #89).
	 *
	 * @return void
	 */
	public function render_order_column_field(): void {
		$on = $this->is_order_column_enabled();
		printf(
			'<label><input type="checkbox" name="scporder_options[order_column]" value="1" %s /> %s</label>',
			checked( $on, true, false ),
			esc_html__( 'Show an editable “Order” number column on enabled post-type lists', 'simple-custom-post-order' )
		);
		echo '<p class="description">' . esc_html__( 'Adds a column where you can type an exact position — handy for jumping an item across paginated lists. Hide it any time via Screen Options. Off by default.', 'simple-custom-post-order' ) . '</p>';
	}

	/**
	 * Render the "who can reorder" role checkboxes (#95).
	 *
	 * @return void
	 */
	public function render_allowed_roles_field(): void {
		$selected = $this->get_allowed_roles();
		$roles    = function_exists( 'get_editable_roles' ) ? get_editable_roles() : [];
		echo '<fieldset>';
		echo '<legend class="screen-reader-text"><span>' . esc_html__( 'Who can reorder', 'simple-custom-post-order' ) . '</span></legend>';
		foreach ( $roles as $key => $role ) {
			printf(
				'<label><input type="checkbox" name="scporder_options[allowed_roles][]" value="%s" %s /> %s</label><br />',
				esc_attr( $key ),
				checked( in_array( $key, $selected, true ), true, false ),
				esc_html( translate_user_role( $role['name'] ) )
			);
		}
		echo '<p class="description">' . esc_html__( 'Restrict drag-and-drop reordering to these roles. Leave all unchecked to allow anyone who can edit posts (default). Developers can override with the scpo_capability filter.', 'simple-custom-post-order' ) . '</p>';
		echo '</fieldset>';
	}

	/* ---- 2.8.0 option helpers ---------------------------------------- */

	public function get_new_post_position(): string {
		$o = get_option( 'scporder_options', [] );
		return ( isset( $o['new_post_position'] ) && 'bottom' === $o['new_post_position'] ) ? 'bottom' : 'top';
	}

	public function is_order_column_enabled(): bool {
		$o = get_option( 'scporder_options', [] );
		return isset( $o['order_column'] ) && '1' === $o['order_column'];
	}

	public function get_allowed_roles(): array {
		$o = get_option( 'scporder_options', [] );
		return ( isset( $o['allowed_roles'] ) && is_array( $o['allowed_roles'] ) ) ? $o['allowed_roles'] : [];
	}

	public function get_reorder_capability(): string {
		return (string) apply_filters( 'scpo_capability', 'edit_posts' );
	}

	/**
	 * Whether the current user may reorder: must hold the (filterable) capability
	 * and, if specific roles are configured, hold one of them (#95).
	 *
	 * @return bool
	 */
	public function scporder_user_can_reorder(): bool {
		if ( ! current_user_can( $this->get_reorder_capability() ) ) {
			return false;
		}
		$roles = $this->get_allowed_roles();
		if ( empty( $roles ) ) {
			return true;
		}
		// A network super admin usually holds no role on a subsite, so a role
		// list would otherwise lock them out of every site that sets one.
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}
		$user = wp_get_current_user();
		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	/**
	 * Object-level authorization for the post reorder AJAX handlers.
	 *
	 * scporder_user_can_reorder() gates *access* to the endpoints; this is the
	 * per-object counterpart that stops a user holding the broad reorder
	 * capability from forging arbitrary IDs (an IDOR). The post must exist,
	 * belong to an enabled sortable post type, and be editable by the current
	 * user under that type's own capabilities — so e.g. someone who can edit
	 * posts but not pages cannot reorder pages.
	 *
	 * @param int        $post_id Post ID.
	 * @param array|null $objects Enabled post types; fetched when null.
	 * @return bool
	 */
	private function scporder_user_can_edit_post( int $post_id, ?array $objects = null ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( null === $objects ) {
			$objects = $this->get_scporder_options_objects();
		}
		if ( ! in_array( $post->post_type, $objects, true ) ) {
			return false;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Object-level authorization for the term reorder AJAX handler.
	 *
	 * The term counterpart of scporder_user_can_edit_post(): the term must
	 * exist, belong to an enabled sortable taxonomy, and the user must hold that
	 * taxonomy's manage_terms capability — the same capability WordPress requires
	 * to reach the term-list screen where reordering happens.
	 *
	 * @param int        $term_id Term ID.
	 * @param array|null $tags    Enabled taxonomies; fetched when null.
	 * @return bool
	 */
	private function scporder_user_can_edit_term( int $term_id, ?array $tags = null ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		$term = get_term( $term_id );
		if ( ! $term instanceof WP_Term ) {
			return false;
		}
		if ( null === $tags ) {
			$tags = $this->get_scporder_options_tags();
		}
		if ( ! in_array( $term->taxonomy, $tags, true ) ) {
			return false;
		}
		$taxonomy = get_taxonomy( $term->taxonomy );
		if ( ! $taxonomy ) {
			return false;
		}
		return current_user_can( $taxonomy->cap->manage_terms );
	}

	/* ---- #45: placement of newly created items ----------------------- */

	/**
	 * Place a newly created item at the top or bottom of its post type's order.
	 * Runs only once — while the item still has the default menu_order of 0.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @return void
	 */
	public function scporder_place_new_post( $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( in_array( $post->post_status, [ 'auto-draft', 'trash', 'inherit' ], true ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, $this->get_scporder_options_objects(), true ) ) {
			return;
		}
		if ( 0 !== (int) $post->menu_order ) {
			return; // already placed / ordered
		}

		global $wpdb;

		// Both branches move exactly one row: the new post is placed just outside
		// the current range rather than shifting every sibling to make room.
		//
		// 'top' used to run `menu_order = menu_order + 1` across the whole post
		// type, which cost an O(N) write on every publish and — being a raw query —
		// left all N shifted rows stale in a persistent object cache (PR #154).
		// Writing MIN - 1 gets the same placement from a single-row update, so the
		// clean_post_cache() below is complete on its own. Gaps and negatives are
		// fine: every read sorts on the raw value, and refresh() normalises the
		// type back to 1..N the next time its list screen is rendered.
		$to_top = ( 'top' === $this->get_new_post_position() );

		// $aggregate is one of two hard-coded literals, never user input; every
		// value in the statement is still bound through prepare().
		$aggregate    = $to_top ? 'MIN' : 'MAX';
		$statuses     = $this->order_post_statuses();
		$placeholders = $this->order_post_statuses_placeholders();
		$sql          = "SELECT $aggregate(menu_order) FROM $wpdb->posts
			WHERE post_type = %s AND ID <> %d AND post_status IN ($placeholders)";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$boundary = $wpdb->get_var( $wpdb->prepare( $sql, array_merge( [ $post->post_type, $post_id ], $statuses ) ) );

		if ( null === $boundary ) {
			$menu_order = 1; // No siblings yet — first item of the type starts the sequence.
		} else {
			$menu_order = $to_top ? (int) $boundary - 1 : (int) $boundary + 1;
		}

		// menu_order 0 is this handler's "not yet placed" sentinel (see the guard
		// above), so never write it — a post landing on 0 would be re-placed on
		// every subsequent save. Stepping past it keeps the post at the top.
		if ( 0 === $menu_order ) {
			$menu_order = $to_top ? -1 : 1;
		}

		$wpdb->update( $wpdb->posts, [ 'menu_order' => $menu_order ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );
	}

	/* ---- #76 / #89: optional numeric "Order" column ------------------ */

	/**
	 * Register the Order column + assets on enabled list screens, gated by the
	 * setting and the reorder capability.
	 *
	 * @return void
	 */
	public function setup_order_column(): void {
		if ( ! $this->is_order_column_enabled() || ! $this->scporder_user_can_reorder() ) {
			return;
		}
		foreach ( $this->get_scporder_options_objects() as $type ) {
			$type = sanitize_key( $type );
			// The numeric column does a flat renumber, which would fight the page
			// tree on hierarchical types. Proper hierarchical ordering is #58 (2.9.0).
			if ( is_post_type_hierarchical( $type ) ) {
				continue;
			}
			add_filter( "manage_edit-{$type}_columns", [ $this, 'add_order_column' ] );
			add_action( "manage_{$type}_posts_custom_column", [ $this, 'render_order_column' ], 10, 2 );
		}
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_order_column_assets' ] );
	}

	public function add_order_column( array $columns ): array {
		$columns['scpo_order'] = __( 'Order', 'simple-custom-post-order' );
		return $columns;
	}

	public function render_order_column( string $column, int $post_id ): void {
		if ( 'scpo_order' !== $column ) {
			return;
		}

		$order = (int) get_post_field( 'menu_order', $post_id );

		// `scpo_set_position` requires edit_post on this specific row (2.8.3), but
		// the column itself is registered off the broad reorder capability. Where
		// the two disagree — most often a CPT registered with a custom
		// `capability_type` and no `map_meta_cap`, so even administrators fail the
		// meta-cap check — an editable input would render and then reject every
		// save. Show the number read-only instead of a control that cannot work.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			printf(
				'<span class="scpo-order-static" title="%s">%d</span>',
				esc_attr__( 'You do not have permission to reorder this item.', 'simple-custom-post-order' ),
				$order
			);
			return;
		}

		printf(
			'<input type="number" class="scpo-order-input small-text" value="%d" min="1" step="1" data-id="%d" aria-label="%s" />',
			$order,
			$post_id,
			esc_attr__( 'Set position', 'simple-custom-post-order' )
		);
	}

	public function enqueue_order_column_assets( $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		$type = ( isset( $_GET['post_type'] ) && is_string( $_GET['post_type'] ) ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
		// Hierarchical types get no column (setup_order_column()), so no script.
		if ( ! in_array( $type, $this->get_scporder_options_objects(), true ) || is_post_type_hierarchical( $type ) ) {
			return;
		}
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_script( 'scpo-order-column', SCPORDER_URL . "/assets/scporder-order-column{$suffix}.js", [], SCPORDER_VERSION, true );
		wp_localize_script( 'scpo-order-column', 'scpoOrderCol', [
			'ajax_url' => $this->get_ajax_url(),
			'nonce'    => wp_create_nonce( 'scporder_nonce_action' ),
			// `error` is the last-resort fallback. The script prefers the server's
			// own message, and uses the two specific strings below where it can tell
			// the failure apart — one generic alert for every cause made these
			// reports impossible to diagnose from the user's description.
			'error'    => __( 'Couldn’t update the order — please try again.', 'simple-custom-post-order' ),
			'expired'  => __( 'Your session expired and the order wasn’t saved. Please reload the page and try again.', 'simple-custom-post-order' ),
			'network'  => __( 'Couldn’t reach the server, so the order wasn’t saved. Check your connection and try again.', 'simple-custom-post-order' ),
		] );
		add_action( 'admin_print_styles', [ $this, 'print_order_column_style' ] );
	}

	public function print_order_column_style(): void {
		echo '<style>.column-scpo_order{width:70px}.scpo-order-input{width:58px}.scpo-order-input.is-saving{opacity:.5;pointer-events:none}.scpo-order-static{color:#646970;cursor:help}</style>';
	}

	/**
	 * Move a post to an absolute position in its post type's order. Independent
	 * of list pagination — the position is absolute across the whole type.
	 *
	 * @return void
	 */
	public function scpo_ajax_set_position(): void {
		check_ajax_referer( 'scporder_nonce_action', 'nonce' );

		if ( ! $this->scporder_user_can_reorder() ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'simple-custom-post-order' ) ], 403 );
		}

		$post_id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$position = isset( $_POST['position'] ) ? absint( $_POST['position'] ) : 0;
		$post     = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! in_array( $post->post_type, $this->get_scporder_options_objects(), true ) || is_post_type_hierarchical( $post->post_type ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid item.', 'simple-custom-post-order' ) ] );
		}

		// Object-level authorization: holding the reorder capability is not enough,
		// the user must be able to edit this specific post (defense against IDOR).
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'simple-custom-post-order' ) ], 403 );
		}

		if ( $position < 1 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid position.', 'simple-custom-post-order' ) ] );
		}

		global $wpdb;
		$statuses     = $this->order_post_statuses();
		$placeholders = $this->order_post_statuses_placeholders();
		$order_by     = $this->post_order_by( $post->post_type );
		$rows         = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated %s list plus a hard-coded ORDER BY literal; values bound here.
			$wpdb->prepare(
				"SELECT ID, menu_order FROM $wpdb->posts
				WHERE post_type = %s AND post_status IN ($placeholders)
				ORDER BY $order_by",
				array_merge( [ $post->post_type ], $statuses )
			),
			ARRAY_N
		);
		$ids = array_map( 'intval', array_column( $rows, 0 ) );

		// Pull the post out, then splice it in at the requested 1-based position.
		$ids    = array_values( array_diff( $ids, [ $post_id ] ) );
		$target = min( $position, count( $ids ) + 1 ) - 1;
		array_splice( $ids, $target, 0, [ $post_id ] );

		// Batched, and only the rows whose number actually changes — this used to
		// be one UPDATE plus one clean_post_cache() per row of the whole type, so
		// a single typed position on a 20,000-product store cost 40,000 queries
		// and could time out half-way through, leaving duplicates. Ties are broken
		// the same way as refresh() and seeding.
		$this->renumber_rows( $wpdb->posts, 'ID', 'menu_order', $ids, '', array_column( $rows, 1, 0 ) );

		do_action( 'scp_update_menu_order' );
		wp_send_json_success( [ 'message' => __( 'Order updated.', 'simple-custom-post-order' ) ] );
	}

	/**
	 * Whether previous/next adjacent-post links should be reversed relative to
	 * the manual order.
	 *
	 * "Previous/next" under manual ordering is inherently ambiguous. The default
	 * (false, the #146 behaviour since 2.7.2) treats "previous" as the item
	 * *before* the current one in the arranged order and "next" as the item
	 * *after* — the natural reading for sequential content (chapters, lessons,
	 * steps). Sites/themes built around WordPress's native chronological
	 * convention expect the opposite (the pre-2.7.2 direction); flip them back
	 * with this filter without touching the theme's template tags. (#146)
	 *
	 * @return bool
	 */
	private function scporder_adjacent_reversed(): bool {
		return (bool) apply_filters( 'scpo_reverse_adjacent_posts', false );
	}

	/**
	 * Rewrite the adjacent-post WHERE so previous/next walk menu_order.
	 *
	 * Modern WP builds a compound clause with a date/ID tiebreaker, e.g.
	 *   (p.post_date < 'X' OR (p.post_date = 'X' AND p.ID < N))
	 * We strip that tiebreaker, then swap the remaining date comparison for the
	 * menu_order equivalent — with its own ID tiebreaker. Without one, the
	 * strict `<`/`>` on menu_order skipped every post sharing the current
	 * post's number (a copy made by a duplicate-post plugin, two items created
	 * at the same moment) until the list screen next renumbered the type.
	 * "Previous" = the item immediately before this one in the manual order (#146).
	 *
	 * @param mixed        $where          WHERE clause.
	 * @param bool         $in_same_term   Unused.
	 * @param array|string $excluded_terms Unused.
	 * @param string       $taxonomy       Unused.
	 * @param WP_Post|null $post           Post navigated from (WP 4.4+).
	 * @return string
	 */
	public function scporder_previous_post_where( $where, $in_same_term = false, $excluded_terms = '', $taxonomy = '', $post = null ): string {
		return $this->adjacent_where( $where, $post, true );
	}

	/**
	 * @param mixed        $order_by ORDER BY clause.
	 * @param WP_Post|null $post     Post navigated from (WP 4.4+).
	 * @return string
	 */
	public function scporder_previous_post_sort( $order_by, $post = null ): string {
		return $this->adjacent_sort( $order_by, $post, true );
	}

	/**
	 * "Next" = the item immediately after this one in the manual order (#146).
	 *
	 * @param mixed        $where          WHERE clause.
	 * @param bool         $in_same_term   Unused.
	 * @param array|string $excluded_terms Unused.
	 * @param string       $taxonomy       Unused.
	 * @param WP_Post|null $post           Post navigated from (WP 4.4+).
	 * @return string
	 */
	public function scporder_next_post_where( $where, $in_same_term = false, $excluded_terms = '', $taxonomy = '', $post = null ): string {
		return $this->adjacent_where( $where, $post, false );
	}

	/**
	 * @param mixed        $order_by ORDER BY clause.
	 * @param WP_Post|null $post     Post navigated from (WP 4.4+).
	 * @return string
	 */
	public function scporder_next_post_sort( $order_by, $post = null ): string {
		return $this->adjacent_sort( $order_by, $post, false );
	}

	/**
	 * The post adjacent-post navigation starts from, when its type is sorted by
	 * this plugin. Prefers the post core passes to the filter over the global.
	 *
	 * @param mixed $post Post passed to the filter, if any.
	 * @return WP_Post|null
	 */
	private function adjacent_sortable_post( $post ): ?WP_Post {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post();
		}
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $this->get_scporder_options_objects(), true ) ) {
			return null;
		}
		return $post;
	}

	/**
	 * Whether the requested link points *before* the current post in the manual
	 * order: "previous" does, "next" doesn't — unless the scpo_reverse_adjacent_posts
	 * filter swaps them.
	 *
	 * @param bool $previous Whether this is the previous-post query.
	 * @return bool
	 */
	private function adjacent_points_before( bool $previous ): bool {
		return $previous !== $this->scporder_adjacent_reversed();
	}

	/**
	 * @param mixed $where    WHERE clause from core.
	 * @param mixed $post     Post passed to the filter, if any.
	 * @param bool  $previous Whether this is the previous-post query.
	 * @return string
	 */
	private function adjacent_where( $where, $post, bool $previous ): string {
		$where = (string) $where;
		$post  = $this->adjacent_sortable_post( $post );
		if ( null === $post ) {
			return $where;
		}

		$operator  = $this->adjacent_points_before( $previous ) ? '<' : '>';
		$condition = sprintf(
			'(p.menu_order %1$s %2$d OR (p.menu_order = %2$d AND p.ID %1$s %3$d))',
			$operator,
			(int) $post->menu_order,
			(int) $post->ID
		);

		$where = preg_replace( "/\s+OR\s+\(\s*p\.post_date = '[^']*'\s+AND\s+p\.ID [<>] \d+\s*\)/i", '', $where );
		return (string) preg_replace( "/p\.post_date [<>] '[^']*'/i", $condition, (string) $where, 1 );
	}

	/**
	 * @param mixed $order_by ORDER BY clause from core.
	 * @param mixed $post     Post passed to the filter, if any.
	 * @param bool  $previous Whether this is the previous-post query.
	 * @return string
	 */
	private function adjacent_sort( $order_by, $post, bool $previous ): string {
		if ( null === $this->adjacent_sortable_post( $post ) ) {
			return (string) $order_by;
		}

		$direction = $this->adjacent_points_before( $previous ) ? 'DESC' : 'ASC';
		return "ORDER BY p.menu_order $direction, p.ID $direction LIMIT 1";
	}

	/**
	 * Apply the manual order to post queries for enabled post types.
	 *
	 * @param WP_Query $wp_query Query being set up.
	 * @return void
	 */
	public function scporder_pre_get_posts( $wp_query ): void {
		if ( ! $wp_query instanceof WP_Query ) {
			return;
		}

		$objects = $this->get_scporder_options_objects();

		if ( empty( $objects ) ) {
			return;
		}

		$is_admin = is_admin() && ! wp_doing_ajax();

		/*
		 * Skip our ordering during a genuine search.
		 *
		 * Outside the admin the query's own is_search() is the signal. (It used
		 * to be the global is_search(), which describes the *main* query: every
		 * secondary query on a search results page lost the manual order, a
		 * search run by a live-search plugin was forced out of relevance order,
		 * and queries running before the main one triggered a "called
		 * incorrectly" notice.) In the admin is_search() is not reliable:
		 * WordPress marks a query as a search whenever the `s` var is merely
		 * *present*, and the Posts list screen's filter form — the "All dates"
		 * and category dropdowns — always submits an empty `s=` alongside the
		 * (empty) search box. So in the admin, bail only on a *non-empty* search
		 * term. (#153)
		 */
		if ( ( $is_admin && '' !== (string) $wp_query->get( 's' ) ) || ( ! $is_admin && $wp_query->is_search() ) ) {
			return;
		}

		if ( $is_admin ) {
			if ( isset( $wp_query->query['post_type'] ) && ! isset( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( is_string( $wp_query->query['post_type'] ) && in_array( $wp_query->query['post_type'], $objects, true ) ) {
					if ( ! $wp_query->get( 'orderby' ) ) {
						$wp_query->set( 'orderby', 'menu_order' );
					}
					// Core sorts the Drafts and Pending list views by last-modified
					// date and expects its default direction (most recent first);
					// forcing ASC there turned that upside down. Every other admin
					// query keeps the long-standing ASC default.
					$core_modified_view = $wp_query->is_main_query() && 'modified' === $wp_query->get( 'orderby' );
					if ( ! $wp_query->get( 'order' ) && ! $core_modified_view ) {
						$wp_query->set( 'order', 'ASC' );
					}
				}
			}
			return;
		}

		$active = false;

		if ( isset( $wp_query->query['post_type'] ) ) {
			if ( ! is_array( $wp_query->query['post_type'] ) ) {
				if ( in_array( $wp_query->query['post_type'], $objects, true ) ) {
					$active = true;
				}
			}
		} elseif ( in_array( 'post', $objects, true ) ) {
			$active = true;
		}

		if ( ! $active ) {
			return;
		}

		if ( isset( $wp_query->query['suppress_filters'] ) ) {
			// get_posts() always passes suppress_filters, and always passes its
			// own defaults — orderby=date, order=DESC — so neither an unordered
			// call nor a missing `order` can be told apart from an explicit one.
			// The default date sort gets the manual order, and the default DESC
			// is flipped to ASC, as it always has been: get_posts( orderby=title )
			// without an order has meant A→Z on these sites for years.
			//
			// The exception is an explicit sort on a date column. DESC is what
			// "latest first" means there, and flipping it made
			// wp_get_recent_posts() (orderby=post_date, order=DESC) return the
			// *oldest* posts.
			$orderby = $wp_query->get( 'orderby' );
			if ( 'date' === $orderby ) {
				$wp_query->set( 'orderby', 'menu_order' );
				$orderby = 'menu_order';
			}
			$is_date_sort = is_string( $orderby ) && in_array( strtolower( $orderby ), [ 'post_date', 'modified', 'post_modified' ], true );
			if ( ! $is_date_sort && 'DESC' === strtoupper( (string) $wp_query->get( 'order' ) ) ) {
				$wp_query->set( 'order', 'ASC' );
			}
		} else {
			if ( ! $wp_query->get( 'orderby' ) ) {
				$wp_query->set( 'orderby', 'menu_order' );
			}
			if ( ! $wp_query->get( 'order' ) ) {
				$wp_query->set( 'order', 'ASC' );
			}
		}
	}

	/**
	 * Whether a term query's orderby is one the manual order replaces.
	 *
	 * The defaults (`name` for get_terms()/wp_get_object_terms(), `id` for
	 * wp_dropdown_categories()) and the other plain-field sorts have always
	 * been replaced, and still are. Orderings that carry meaning of their own
	 * are now respected: `count` (tag clouds and the editor's "most used"
	 * tags showed arbitrary terms, since only the first N by manual order were
	 * ever fetched), `include` (PR #67), `slug__in` / `name__in`, `parent`,
	 * `term_taxonomy_id`, meta sorts and `none` — except WooCommerce's
	 * menu-order meta sort, which is a request for the manual order.
	 *
	 * @param array $args Term query args.
	 * @return bool
	 */
	private function is_replaceable_term_orderby( array $args ): bool {
		// WooCommerce turns a "menu order" term query (the default for product
		// categories and attributes) into `meta_value_num` on its own `order` meta
		// in pre_get_terms, flagging it with force_menu_order_sort. That query
		// asked for the manual order, so on a taxonomy sorted here it gets ours —
		// as it always did before 2.8.9.
		if ( ! empty( $args['force_menu_order_sort'] ) ) {
			return true;
		}
		if ( ! isset( $args['orderby'] ) ) {
			return true;
		}
		if ( ! is_string( $args['orderby'] ) ) {
			return false;
		}
		return in_array( strtolower( trim( $args['orderby'] ) ), [ '', 'name', 'id', 'term_id', 'slug', 'term_group', 'description', 'term_order', 'menu_order' ], true );
	}

	/**
	 * Whether the admin user clicked a list-table column to sort by it, which
	 * always wins over the manual order.
	 *
	 * @return bool
	 */
	private function is_admin_column_sort(): bool {
		return is_admin() && ! wp_doing_ajax() && isset( $_GET['orderby'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Put term_order first in the SQL of term queries for enabled taxonomies.
	 *
	 * This used to be skipped for every admin page, relying on the PHP re-sort
	 * below instead. That re-sort only sees the rows SQL already returned, so
	 * on a paginated tag list page 1 held the first 20 tags *alphabetically*,
	 * shuffled into manual order — the wrong 20 terms, which is what the user
	 * then dragged. Only an explicit column sort is exempt now.
	 *
	 * @param mixed $orderby    ORDER BY clause (without the keyword).
	 * @param mixed $args       Term query args.
	 * @param mixed $taxonomies Unused.
	 * @return string
	 */
	public function scporder_get_terms_orderby( $orderby, $args = array(), $taxonomies = null ): string {
		$orderby = (string) $orderby;
		$args    = is_array( $args ) ? $args : array();

		if ( $this->is_admin_column_sort() || ! $this->is_replaceable_term_orderby( $args ) || ! $this->term_ordering_available() ) {
			return $orderby;
		}

		$tags = $this->get_scporder_options_tags();

		if ( empty( $tags ) || ! isset( $args['taxonomy'] ) ) {
			return $orderby;
		}

		// Apply our ordering if ANY queried taxonomy is sortable — not just the
		// first one — and keep the caller's orderby as a fallback tiebreaker (PR #104).
		$taxonomies = array_map( 'strval', (array) $args['taxonomy'] );
		if ( empty( array_intersect( $taxonomies, $tags ) ) ) {
			return $orderby;
		}

		return '' !== $orderby ? 't.term_order, ' . $orderby : 't.term_order';
	}

	/**
	 * Filter callback for `wp_get_object_terms` (passes $args as the 4th argument).
	 *
	 * @param mixed $terms       Terms (array of objects, IDs, etc.).
	 * @param mixed $object_ids  Unused.
	 * @param mixed $taxonomies  Unused.
	 * @param array $args        Query args.
	 * @return mixed
	 */
	public function scporder_get_object_terms( $terms, $object_ids = null, $taxonomies = null, $args = array() ) {
		return $this->sort_terms_by_order( $terms, is_array( $args ) ? $args : array() );
	}

	/**
	 * Filter callback for `get_terms` (passes $args as the 3rd argument).
	 *
	 * @param mixed $terms      Terms.
	 * @param mixed $taxonomies Unused.
	 * @param array $args       Query args.
	 * @return mixed
	 */
	public function scporder_get_terms( $terms, $taxonomies = null, $args = array() ) {
		return $this->sort_terms_by_order( $terms, is_array( $args ) ? $args : array() );
	}

	/**
	 * Filter callback for `get_the_terms`, whose per-post relationship cache is
	 * not cleared by a term reorder.
	 *
	 * @param mixed $terms Terms, false or WP_Error.
	 * @return mixed
	 */
	public function scporder_get_the_terms( $terms ) {
		return $this->sort_terms_by_order( $terms, array() );
	}

	/**
	 * Re-sort returned terms by term_order, unless the caller asked for a specific
	 * order. Shared by the term filter callbacks above.
	 *
	 * @param mixed $terms Terms as returned by core.
	 * @param array $args  Query args.
	 * @return mixed
	 */
	private function sort_terms_by_order( $terms, array $args ) {
		if ( ! is_array( $terms ) || count( $terms ) < 2 ) {
			return $terms;
		}

		if ( $this->is_admin_column_sort() || ! $this->is_replaceable_term_orderby( $args ) || ! $this->term_ordering_available() ) {
			return $terms;
		}

		$tags = $this->get_scporder_options_tags();

		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->taxonomy ) || ! in_array( $term->taxonomy, $tags, true ) ) {
				return $terms;
			}
		}

		// Stable on every PHP version (usort() only became stable in PHP 8.0):
		// terms sharing a term_order keep the order SQL returned them in.
		$decorated = [];
		foreach ( array_values( $terms ) as $i => $term ) {
			$decorated[] = [ $i, $term ];
		}
		usort(
			$decorated,
			function ( $a, $b ) {
				return $this->taxcmp( $a[1], $b[1] ) ?: $a[0] <=> $b[0];
			}
		);

		return array_column( $decorated, 1 );
	}


	public function taxcmp( object $a, object $b ): int {
		return (int) ( $a->term_order ?? 0 ) <=> (int) ( $b->term_order ?? 0 );
	}

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


	/**
	 * Reset Order (AJAX): set menu_order back to 0 for the chosen post types and
	 * stop sorting them. Taxonomies cannot be reset here.
	 *
	 * @return void
	 */
	public function scpo_ajax_reset_order(): void {
		global $wpdb;

		check_ajax_referer( 'scpo-reset-order', 'scpo_security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'simple-custom-post-order' ) ], 403 );
		}

		$items = ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) )
			? array_map( 'sanitize_key', array_filter( wp_unslash( $_POST['items'] ), 'is_string' ) )
			: [];

		// Only the post types the Reset form offers — this used to accept any
		// string, e.g. nav_menu_item.
		$items = array_values( array_intersect( $items, $this->resettable_post_types() ) );

		if ( empty( $items ) ) {
			wp_send_json_error( [ 'message' => __( 'No items selected.', 'simple-custom-post-order' ) ] );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $items ), '%s' ) );

		// The rows being changed, so their cache entries can be dropped: a raw
		// UPDATE bypasses clean_post_cache(), which under a persistent object
		// cache left the old numbers in place after a reset.
		$ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated %s list, values bound here.
			$wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type IN ($placeholders) AND menu_order <> 0", $items )
		);

		$result = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated %s list, values bound here.
			$wpdb->prepare( "UPDATE $wpdb->posts SET `menu_order` = 0 WHERE `post_type` IN ($placeholders)", $items )
		);

		$this->invalidate_order_cache( $wpdb->posts, $ids );

		$scpo_options = get_option( 'scporder_options' );

		if ( is_array( $scpo_options ) && isset( $scpo_options['objects'] ) && is_array( $scpo_options['objects'] ) ) {
			$scpo_options['objects'] = array_values( array_diff( $scpo_options['objects'], $items ) );
			update_option( 'scporder_options', $scpo_options );
		}

		if ( false !== $result ) {
			wp_send_json_success( [ 'message' => __( 'Items have been reset.', 'simple-custom-post-order' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to reset items.', 'simple-custom-post-order' ) ] );
		}
	}

	/**
	 * Post types offered by the Reset Order form (and accepted by its handler).
	 *
	 * @return string[]
	 */
	public function resettable_post_types(): array {
		$args = apply_filters(
			'scpo_post_types_args',
			[
				'show_ui'      => true,
				'show_in_menu' => true,
			],
			get_option( 'scporder_options', [] )
		);

		return array_values( array_diff( get_post_types( $args ), [ 'attachment' ] ) );
	}

	/**
	 * Print inline admin style.
	 *
	 * @since 2.5.4
	 */
	public function print_scpo_style(): void {
		?>
		<style>
			.ui-sortable tr:hover {
				cursor : move;
			}

			.ui-sortable tr.alternate {
				background-color : #F9F9F9;
			}

			.ui-sortable tr.ui-sortable-helper {
				background-color : #F9F9F9;
				border-top       : 1px solid #DFDFDF;
			}
		</style>
		<?php
	}

}


function scporder_doing_ajax(): bool {
	if ( function_exists( 'wp_doing_ajax' ) ) {
		return wp_doing_ajax();
	}

	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		return true;
	}

	return false;
}

/**
 * SCP Order Uninstall hook.
 */
register_uninstall_hook( __FILE__, 'scporder_uninstall' );

function scporder_uninstall(): void {
	if ( is_multisite() ) {
		// Every site of every network, restoring after each switch so the switch
		// stack does not grow with the number of sites.
		foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			scporder_uninstall_db();
			restore_current_blog();
		}
	} else {
		scporder_uninstall_db();
	}
}

function scporder_uninstall_db(): void {
	global $wpdb;

	// Leave the column alone if it was already there when this plugin was
	// installed — another ordering plugin created it and may still rely on it.
	if ( 'foreign' !== get_option( 'scporder_term_order_owner' ) ) {
		$result = $wpdb->query( "DESCRIBE $wpdb->terms `term_order`" );
		if ( $result ) {
			$wpdb->query( "ALTER TABLE $wpdb->terms DROP `term_order`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	// Every option this plugin ever writes, including the one owned by the
	// bundled review notice. Before 2.8.7 only `scporder_install` was
	// removed, so `scporder_notice`, `scporder_options` and `simple-rate-time`
	// survived an uninstall and were silently restored on a later reinstall
	// (reported by @jamieburchell).
	foreach ( array( 'scporder_install', 'scporder_notice', 'scporder_options', 'simple-rate-time', 'scporder_term_order_owner' ) as $option ) {
		delete_option( $option );
	}
	delete_transient( 'scporder_install_failed' );
}
