<?php
/**
 * Plugin Name: Simple Custom Post Order
 * Plugin URI: https://wordpress.org/plugins-wp/simple-custom-post-order/
 * Description: Order Items (Posts, Pages, and Custom Post Types) using a Drag and Drop Sortable JavaScript.
 * Version: 2.8.3
 * Author: Colorlib
 * Author URI: https://colorlib.com/
 * Tested up to: 7.0
 * Requires: 6.2 or higher
 * License: GPLv3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.4
 * Text Domain: simple-custom-post-order
 * Domain Path: /languages
 *
 * Copyright 2013-2017 Sameer Humagain im@hsameer.com.np
 * Copyright 2017-2023 Colorlib support@colorlib.com
 *
 * SVN commit with ownership change: https://plugins.trac.wordpress.org/changeset/1590135/simple-custom-post-order
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 3, as
 * published by the Free Software Foundation.
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


define( 'SCPORDER_URL', plugins_url( '', __FILE__ ) );
define( 'SCPORDER_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCPORDER_VERSION', '2.8.3' );

$scporder = new SCPO_Engine();

class SCPO_Engine {

	function __construct() {
		if ( ! get_option( 'scporder_install' ) ) {
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

		add_filter( 'get_previous_post_where', array( $this, 'scporder_previous_post_where' ) );
		add_filter( 'get_previous_post_sort', array( $this, 'scporder_previous_post_sort' ) );
		add_filter( 'get_next_post_where', array( $this, 'scporder_next_post_where' ) );
		add_filter( 'get_next_post_sort', array( $this, 'scporder_next_post_sort' ) );

		add_filter( 'get_terms_orderby', array( $this, 'scporder_get_terms_orderby' ), 10, 3 );
		// `wp_get_object_terms` passes $args as the 4th arg, `get_terms` as the 3rd,
		// so each hook gets its own thin wrapper that hands $args to the sorter.
		add_filter( 'wp_get_object_terms', array( $this, 'scporder_get_object_terms' ), 10, 4 );
		add_filter( 'get_terms', array( $this, 'scporder_get_terms' ), 10, 3 );

		add_action( 'admin_notices', array( $this, 'scporder_notice_not_checked' ) );
		add_action( 'wp_ajax_scporder_dismiss_notices', array( $this, 'dismiss_notices' ) );

		add_action( 'plugins_loaded', array( $this, 'load_scpo_textdomain' ) );

		add_filter( 'scpo_post_types_args', array( $this, 'scpo_filter_post_types' ), 10, 2 );

		add_action( 'wp_ajax_scpo_reset_order', array( $this, 'scpo_ajax_reset_order' ) );

		// 2.8.0: new-item placement (#45) + optional numeric Order column (#76/#89).
		add_action( 'save_post', array( $this, 'scporder_place_new_post' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'setup_order_column' ) );
		add_action( 'wp_ajax_scpo_set_position', array( $this, 'scpo_ajax_set_position' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
	}

	public function load_dependencies(): void {
		include SCPORDER_DIR . 'class-simple-review.php';
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

	public function load_scpo_textdomain(): void {
		load_plugin_textdomain( 'simple-custom-post-order', false, basename( dirname( __FILE__ ) ) . '/languages/' );
	}

	public function dismiss_notices(): void {
		if ( ! check_admin_referer( 'scporder_dismiss_notice', 'scporder_nonce' ) ) {
			wp_die( 'nok' );
		}

		update_option( 'scporder_notice', '1' );

		wp_die( 'ok' );
	}

	public function scporder_notice_not_checked(): void {
		$settings = $this->get_scporder_options_objects();
		if ( ! empty( $settings ) ) {
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
			<img src="<?php echo esc_url( plugins_url( 'assets/logo.jpg', __FILE__ ) ); ?>" width="80">

			<h1><?php esc_html_e( 'Simple Custom Post Order', 'simple-custom-post-order' ); ?></h1>

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
			jQuery(document).ready(function(){
				jQuery( '#scpo-notice .notice-dismiss' ).click(function( evt ){
					evt.preventDefault();

					var ajaxData = {
						'action' : 'scporder_dismiss_notices',
						'scporder_nonce' : '<?php echo esc_js( wp_create_nonce( 'scporder_dismiss_notice' ) ); ?>'
					}

					jQuery.ajax({
						url: "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>",
						method: "POST",
						data: ajaxData,
						dataType: "html"
					}).done(function(){
						jQuery("#scpo-notice").hide();
					});

				});
			})
		</script>
		<?php
	}

	public function scporder_install(): void {
		global $wpdb;
		$result = $wpdb->query( "DESCRIBE $wpdb->terms `term_order`" );
		if ( ! $result ) {
			$query  = "ALTER TABLE $wpdb->terms ADD `term_order` INT( 4 ) NULL DEFAULT '0'";
			$wpdb->query( $query );
		}
		update_option( 'scporder_install', 1 );
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
		$objects = $this->get_scporder_options_objects();
		$tags    = $this->get_scporder_options_tags();

		if ( empty( $objects ) && empty( $tags ) ) {
			return false;
		}

		// PHP 8.1+ null safety: use null coalescing for $_SERVER
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';

		if ( isset( $_GET['orderby'] ) || strstr( $request_uri, 'action=edit' ) || strstr( $request_uri, 'wp-admin/post-new.php' ) ) {
			return false;
		}

		$active = false;

		if ( ! empty( $objects ) ) {
			// Check for custom post types
			if ( isset( $_GET['post_type'] ) && ! isset( $_GET['taxonomy'] ) && in_array( $_GET['post_type'], $objects, true ) ) {
				$active = true;
			}
			// Check for posts
			if ( ! isset( $_GET['post_type'] ) && strstr( $request_uri, 'wp-admin/edit.php' ) && in_array( 'post', $objects, true ) ) {
				$active = true;
			}
		}

		if ( ! empty( $tags ) ) {
			if ( isset( $_GET['taxonomy'] ) && in_array( $_GET['taxonomy'], $tags, true ) ) {
				$active = true;
			}
		}

		return $active;
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

	public function refresh(): void {

		if ( scporder_doing_ajax() ) {
			return;
		}

		global $wpdb;
		$objects = $this->get_scporder_options_objects();
		$tags    = $this->get_scporder_options_tags();

		if ( ! empty( $objects ) ) {

			foreach ( $objects as $object ) {
				$query = $wpdb->prepare(
					"
					SELECT COUNT(*) AS cnt, MAX(menu_order) AS max, MIN(menu_order) AS min
					FROM $wpdb->posts
					WHERE post_type = %s AND post_status IN ('publish', 'pending', 'draft', 'private', 'future')
					",
					$object
				);
				
				$result = $wpdb->get_results( $query );

				if ( 0 === (int) $result[0]->cnt || $result[0]->cnt === $result[0]->max ) {
					continue;
				}

				// Re-number menu_order into a gapless 1..N sequence, preserving the
				// existing relative order. Done in PHP rather than via a single UPDATE
				// using a MySQL user variable (@row_number): that "rank inside a derived
				// table" trick has undefined evaluation order on MariaDB / MySQL 8 and
				// could scramble the saved order (PR #147 / issue #119).
				$object      = sanitize_key( $object );
				$ordered_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM $wpdb->posts
						WHERE post_type = %s AND post_status IN ( 'publish', 'pending', 'draft', 'private', 'future' )
						ORDER BY menu_order ASC",
						$object
					)
				);
				foreach ( $ordered_ids as $position => $id ) {
					$wpdb->update( $wpdb->posts, [ 'menu_order' => $position + 1 ], [ 'ID' => (int) $id ] );
				}

			}
		}

		if ( ! empty( $tags ) ) {
			foreach ( $tags as $taxonomy ) {
				$query = $wpdb->prepare(
					"
					SELECT COUNT(*) AS cnt, MAX(term_order) AS max, MIN(term_order) AS min
					FROM $wpdb->terms AS terms
					INNER JOIN $wpdb->term_taxonomy AS term_taxonomy ON ( terms.term_id = term_taxonomy.term_id )
					WHERE term_taxonomy.taxonomy = %s
					",
					$taxonomy
				);
				$result = $wpdb->get_results( $query );
				if ( 0 === (int) $result[0]->cnt || $result[0]->cnt === $result[0]->max ) {
					continue;
				}

				$query = $wpdb->prepare(
					"
					SELECT terms.term_id
					FROM $wpdb->terms AS terms
					INNER JOIN $wpdb->term_taxonomy AS term_taxonomy ON ( terms.term_id = term_taxonomy.term_id )
					WHERE term_taxonomy.taxonomy = %s
					ORDER BY term_order ASC
					",
					$taxonomy
				);
				
				$results = $wpdb->get_results( $query );
				foreach ( $results as $key => $result ) {
					$wpdb->update( $wpdb->terms, array( 'term_order' => $key + 1 ), array( 'term_id' => $result->term_id ) );
				}
			}
		}
	}

	/**
	 * Update menu order for posts via AJAX.
	 *
	 * @return void
	 */
	public function update_menu_order(): void {
		global $wpdb;

		check_ajax_referer( 'scporder_nonce_action', 'nonce' );

		if ( ! $this->scporder_user_can_reorder() ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'simple-custom-post-order' ) ], 403 );
		}

		$order = isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : '';
		parse_str( $order, $data );

		if ( ! is_array( $data ) || empty( $data ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid data.', 'simple-custom-post-order' ) ] );
		}

		// Collect all IDs first
		$id_arr = [];
		foreach ( $data as $values ) {
			if ( is_array( $values ) ) {
				foreach ( $values as $id ) {
					$id_arr[] = absint( $id );
				}
			}
		}

		// Object-level authorization (defense against forged IDs / IDOR).
		// scporder_user_can_reorder() only gates *access* to this endpoint; it
		// does not prove the caller may edit the specific posts they submitted.
		// Require every submitted ID to be an enabled sortable post type that the
		// current user can actually edit, so a user holding the broad reorder
		// capability cannot reshuffle menu_order for posts/pages they cannot edit.
		$objects = $this->get_scporder_options_objects();
		foreach ( $id_arr as $id ) {
			if ( ! $this->scporder_user_can_edit_post( $id, $objects ) ) {
				wp_send_json_error( [ 'message' => __( 'Permission denied.', 'simple-custom-post-order' ) ], 403 );
			}
		}

		// Get current menu_order values
		$menu_order_arr = [];
		foreach ( $id_arr as $id ) {
			$menu_order = $wpdb->get_var(
				$wpdb->prepare( "SELECT menu_order FROM $wpdb->posts WHERE ID = %d", $id )
			);
			if ( null !== $menu_order ) {
				$menu_order_arr[] = (int) $menu_order;
			}
		}

		sort( $menu_order_arr );

		// Update posts and collect IDs for cache invalidation
		$updated_ids = [];
		$position = 0;
		foreach ( $data as $values ) {
			if ( is_array( $values ) ) {
				foreach ( $values as $id ) {
					$id = absint( $id );
					if ( isset( $menu_order_arr[ $position ] ) ) {
						$wpdb->update(
							$wpdb->posts,
							[ 'menu_order' => $menu_order_arr[ $position ] ],
							[ 'ID' => $id ],
							[ '%d' ],
							[ '%d' ]
						);
						$updated_ids[] = $id;
					}
					$position++;
				}
			}
		}

		// Targeted cache invalidation - only for posts we actually changed
		foreach ( $updated_ids as $post_id ) {
			clean_post_cache( $post_id );
		}

		do_action( 'scp_update_menu_order' );

		wp_send_json_success( [ 'message' => __( 'Order updated.', 'simple-custom-post-order' ) ] );
	}

	/**
	 * Update term order for taxonomies via AJAX.
	 *
	 * @return void
	 */
	public function update_menu_order_tags(): void {
		global $wpdb;

		check_ajax_referer( 'scporder_nonce_action', 'nonce' );

		if ( ! $this->scporder_user_can_reorder() ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'simple-custom-post-order' ) ], 403 );
		}

		$order = isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : '';
		parse_str( $order, $data );

		if ( ! is_array( $data ) || empty( $data ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid data.', 'simple-custom-post-order' ) ] );
		}

		// Collect all IDs first
		$id_arr = [];
		foreach ( $data as $values ) {
			if ( is_array( $values ) ) {
				foreach ( $values as $id ) {
					$id_arr[] = absint( $id );
				}
			}
		}

		// Object-level authorization (defense against forged IDs / IDOR).
		// Require every submitted term to belong to an enabled sortable taxonomy
		// the current user is allowed to manage, so the broad reorder capability
		// alone cannot reshuffle term_order for taxonomies outside their reach.
		$tags = $this->get_scporder_options_tags();
		foreach ( $id_arr as $id ) {
			if ( ! $this->scporder_user_can_edit_term( $id, $tags ) ) {
				wp_send_json_error( [ 'message' => __( 'Permission denied.', 'simple-custom-post-order' ) ], 403 );
			}
		}

		// Get current term_order values
		$term_order_arr = [];
		foreach ( $id_arr as $id ) {
			$term_order = $wpdb->get_var(
				$wpdb->prepare( "SELECT term_order FROM $wpdb->terms WHERE term_id = %d", $id )
			);
			if ( null !== $term_order ) {
				$term_order_arr[] = (int) $term_order;
			}
		}

		sort( $term_order_arr );

		// Update terms and collect IDs for cache invalidation
		$updated_ids = [];
		$position = 0;
		foreach ( $data as $values ) {
			if ( is_array( $values ) ) {
				foreach ( $values as $id ) {
					$id = absint( $id );
					if ( isset( $term_order_arr[ $position ] ) ) {
						$wpdb->update(
							$wpdb->terms,
							[ 'term_order' => $term_order_arr[ $position ] ],
							[ 'term_id' => $id ],
							[ '%d' ],
							[ '%d' ]
						);
						$updated_ids[] = $id;
					}
					$position++;
				}
			}
		}

		// Targeted cache invalidation - only for terms we actually changed
		foreach ( $updated_ids as $term_id ) {
			clean_term_cache( $term_id );
		}

		do_action( 'scp_update_menu_order_tags' );

		wp_send_json_success( [ 'message' => __( 'Order updated.', 'simple-custom-post-order' ) ] );
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
	 * (wp_ajax_, not nopriv) gated on the same `edit_posts` capability as the
	 * reorder endpoints, and admin-ajax sends no CORS headers, so the issued
	 * nonce cannot be read by a cross-origin attacker. This mirrors how core's
	 * Heartbeat API refreshes nonces.
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
	 * @param array $input The input array to sanitize.
	 * @return array Sanitized options.
	 */
	public function sanitize_options( $input ): array {
		global $wpdb;

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

		// Sanitize post types (objects)
		if ( isset( $input['objects'] ) && is_array( $input['objects'] ) ) {
			$sanitized['objects'] = array_map( 'sanitize_key', $input['objects'] );
		}

		// Sanitize taxonomies (tags)
		if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
			$sanitized['tags'] = array_map( 'sanitize_key', $input['tags'] );
		}

		// Sanitize advanced view option
		if ( ! empty( $input['show_advanced_view'] ) ) {
			$sanitized['show_advanced_view'] = '1';
		}

		// Sanitize drag-and-drop engine choice (anything but 'classic' is the default).
		$sanitized['engine'] = ( isset( $input['engine'] ) && 'classic' === $input['engine'] ) ? 'classic' : 'sortable';

		// Show-drag-handle toggle. Unchecked checkboxes are absent from $input,
		// so missing means "hide" ('0'); checked submits '1'.
		$sanitized['show_handle'] = ! empty( $input['show_handle'] ) ? '1' : '0';

		// Where newly created items are placed in the order.
		$sanitized['new_post_position'] = ( isset( $input['new_post_position'] ) && 'bottom' === $input['new_post_position'] ) ? 'bottom' : 'top';

		// Optional numeric "Order" column (off by default).
		$sanitized['order_column'] = ! empty( $input['order_column'] ) ? '1' : '0';

		// Roles allowed to reorder. Empty array = fall back to the capability check.
		$sanitized['allowed_roles'] = [];
		if ( isset( $input['allowed_roles'] ) && is_array( $input['allowed_roles'] ) && function_exists( 'wp_roles' ) ) {
			$valid_roles                = array_keys( wp_roles()->get_names() );
			$sanitized['allowed_roles'] = array_values( array_intersect( $valid_roles, array_map( 'sanitize_key', $input['allowed_roles'] ) ) );
		}

		// Initialize menu_order for newly enabled post types
		if ( ! empty( $sanitized['objects'] ) ) {
			foreach ( $sanitized['objects'] as $object ) {
				$object = sanitize_key( $object );
				$result = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT count(*) as cnt, max(menu_order) as max, min(menu_order) as min
						FROM $wpdb->posts
						WHERE post_type = %s AND post_status IN ('publish', 'pending', 'draft', 'private', 'future')",
						$object
					)
				);

				if ( 0 === (int) $result[0]->cnt || $result[0]->cnt === $result[0]->max ) {
					continue;
				}

				if ( 'page' === $object ) {
					$results = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT ID FROM $wpdb->posts
							WHERE post_type = %s AND post_status IN ('publish', 'pending', 'draft', 'private', 'future')
							ORDER BY post_title ASC",
							$object
						)
					);
				} else {
					$results = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT ID FROM $wpdb->posts
							WHERE post_type = %s AND post_status IN ('publish', 'pending', 'draft', 'private', 'future')
							ORDER BY post_date DESC",
							$object
						)
					);
				}

				foreach ( $results as $key => $result ) {
					$wpdb->update( $wpdb->posts, [ 'menu_order' => $key + 1 ], [ 'ID' => $result->ID ] );
				}
			}
		}

		// Initialize term_order for newly enabled taxonomies
		if ( ! empty( $sanitized['tags'] ) ) {
			foreach ( $sanitized['tags'] as $taxonomy ) {
				$taxonomy = sanitize_key( $taxonomy );
				$result   = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT count(*) as cnt, max(term_order) as max, min(term_order) as min
						FROM $wpdb->terms AS terms
						INNER JOIN $wpdb->term_taxonomy AS term_taxonomy ON ( terms.term_id = term_taxonomy.term_id )
						WHERE term_taxonomy.taxonomy = %s",
						$taxonomy
					)
				);

				if ( 0 === (int) $result[0]->cnt || $result[0]->cnt === $result[0]->max ) {
					continue;
				}

				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT terms.term_id
						FROM $wpdb->terms AS terms
						INNER JOIN $wpdb->term_taxonomy AS term_taxonomy ON ( terms.term_id = term_taxonomy.term_id )
						WHERE term_taxonomy.taxonomy = %s
						ORDER BY name ASC",
						$taxonomy
					)
				);

				foreach ( $results as $key => $result ) {
					$wpdb->update( $wpdb->terms, [ 'term_order' => $key + 1 ], [ 'term_id' => $result->term_id ] );
				}
			}
		}

		return $sanitized;
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
		if ( 'top' === $this->get_new_post_position() ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE $wpdb->posts SET menu_order = menu_order + 1
					WHERE post_type = %s AND ID <> %d AND post_status IN ('publish','pending','draft','private','future')",
					$post->post_type,
					$post_id
				)
			);
			$wpdb->update( $wpdb->posts, [ 'menu_order' => 1 ], [ 'ID' => $post_id ] );
		} else {
			$max = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(menu_order) FROM $wpdb->posts
					WHERE post_type = %s AND ID <> %d AND post_status IN ('publish','pending','draft','private','future')",
					$post->post_type,
					$post_id
				)
			);
			$wpdb->update( $wpdb->posts, [ 'menu_order' => $max + 1 ], [ 'ID' => $post_id ] );
		}
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
		printf(
			'<input type="number" class="scpo-order-input small-text" value="%d" min="1" step="1" data-id="%d" aria-label="%s" />',
			(int) get_post_field( 'menu_order', $post_id ),
			$post_id,
			esc_attr__( 'Set position', 'simple-custom-post-order' )
		);
	}

	public function enqueue_order_column_assets( $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
		if ( ! in_array( $type, $this->get_scporder_options_objects(), true ) ) {
			return;
		}
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_script( 'scpo-order-column', SCPORDER_URL . "/assets/scporder-order-column{$suffix}.js", [], SCPORDER_VERSION, true );
		wp_localize_script( 'scpo-order-column', 'scpoOrderCol', [
			'ajax_url' => $this->get_ajax_url(),
			'nonce'    => wp_create_nonce( 'scporder_nonce_action' ),
			'error'    => __( 'Couldn’t update the order — please try again.', 'simple-custom-post-order' ),
		] );
		add_action( 'admin_print_styles', [ $this, 'print_order_column_style' ] );
	}

	public function print_order_column_style(): void {
		echo '<style>.column-scpo_order{width:70px}.scpo-order-input{width:58px}.scpo-order-input.is-saving{opacity:.5;pointer-events:none}</style>';
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

		global $wpdb;
		$ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts
					WHERE post_type = %s AND post_status IN ('publish','pending','draft','private','future')
					ORDER BY menu_order ASC, post_date DESC",
					$post->post_type
				)
			)
		);

		// Pull the post out, then splice it in at the requested 1-based position.
		$ids    = array_values( array_diff( $ids, [ $post_id ] ) );
		$target = max( 1, min( $position, count( $ids ) + 1 ) ) - 1;
		array_splice( $ids, $target, 0, [ $post_id ] );

		foreach ( $ids as $i => $id ) {
			$wpdb->update( $wpdb->posts, [ 'menu_order' => $i + 1 ], [ 'ID' => (int) $id ] );
			clean_post_cache( (int) $id );
		}

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
	 * We strip that tiebreaker, then swap the remaining date comparison for a
	 * menu_order comparison. "Previous" = the item immediately before this one in
	 * the manual order — i.e. the largest menu_order below the current post (#146).
	 *
	 * @param string $where
	 * @return string
	 */
	public function scporder_previous_post_where( string $where ): string {
		global $post;

		$objects = $this->get_scporder_options_objects();
		if ( empty( $objects ) ) {
			return $where;
		}

		if ( isset( $post->post_type ) && in_array( $post->post_type, $objects, true ) ) {
			$mo       = (int) $post->menu_order;
			$operator = $this->scporder_adjacent_reversed() ? '>' : '<';
			$where    = preg_replace( "/\s+OR\s+\(\s*p\.post_date = '[^']*'\s+AND\s+p\.ID [<>] \d+\s*\)/i", '', $where );
			$where    = preg_replace( "/p\.post_date [<>] '[^']*'/i", "p.menu_order " . $operator . " '" . $mo . "'", $where );
		}
		return $where;
	}

	public function scporder_previous_post_sort( string $orderby ): string {
		global $post;

		$objects = $this->get_scporder_options_objects();
		if ( empty( $objects ) ) {
			return $orderby;
		}

		if ( isset( $post->post_type ) && in_array( $post->post_type, $objects, true ) ) {
			$direction = $this->scporder_adjacent_reversed() ? 'ASC' : 'DESC';
			$orderby   = 'ORDER BY p.menu_order ' . $direction . ' LIMIT 1';
		}
		return $orderby;
	}

	/**
	 * "Next" = the item immediately after this one in the manual order — i.e. the
	 * smallest menu_order above the current post (#146).
	 *
	 * @param string $where
	 * @return string
	 */
	public function scporder_next_post_where( string $where ): string {
		global $post;

		$objects = $this->get_scporder_options_objects();
		if ( empty( $objects ) ) {
			return $where;
		}

		if ( isset( $post->post_type ) && in_array( $post->post_type, $objects, true ) ) {
			$mo       = (int) $post->menu_order;
			$operator = $this->scporder_adjacent_reversed() ? '<' : '>';
			$where    = preg_replace( "/\s+OR\s+\(\s*p\.post_date = '[^']*'\s+AND\s+p\.ID [<>] \d+\s*\)/i", '', $where );
			$where    = preg_replace( "/p\.post_date [<>] '[^']*'/i", "p.menu_order " . $operator . " '" . $mo . "'", $where );
		}
		return $where;
	}

	public function scporder_next_post_sort( string $orderby ): string {
		global $post;

		$objects = $this->get_scporder_options_objects();
		if ( empty( $objects ) ) {
			return $orderby;
		}

		if ( isset( $post->post_type ) && in_array( $post->post_type, $objects, true ) ) {
			$direction = $this->scporder_adjacent_reversed() ? 'DESC' : 'ASC';
			$orderby   = 'ORDER BY p.menu_order ' . $direction . ' LIMIT 1';
		}
		return $orderby;
	}

	public function scporder_pre_get_posts( $wp_query ): void {
		$objects = $this->get_scporder_options_objects();

		if ( empty( $objects ) ) {
			return;
		}

		$is_admin = is_admin() && ! wp_doing_ajax();

		/*
		 * Skip our ordering during a genuine search.
		 *
		 * On the front end WP's is_search() is the correct signal. In the admin
		 * it is not: WordPress marks a query as a search whenever the `s` var is
		 * merely *present* (isset(), not a non-empty value), and the Posts list
		 * screen's filter form — the "All dates" and category dropdowns — always
		 * submits an empty `s=` alongside the (empty) search box. So is_search()
		 * reads true even though nobody searched, and the manual order silently
		 * disappeared the moment a user filtered the list. In the admin,
		 * therefore, bail only on a *non-empty* search term; real admin searches
		 * still carry a non-empty `s` and are skipped exactly as before. (#153)
		 */
		if ( ( $is_admin && '' !== $wp_query->get( 's' ) ) || ( ! $is_admin && is_search() ) ) {
			return;
		}

		if ( $is_admin ) {
			if ( isset( $wp_query->query['post_type'] ) && ! isset( $_GET['orderby'] ) ) {
				if ( in_array( $wp_query->query['post_type'], $objects, true ) ) {
					if ( ! $wp_query->get( 'orderby' ) ) {
						$wp_query->set( 'orderby', 'menu_order' );
					}
					if ( ! $wp_query->get( 'order' ) ) {
						$wp_query->set( 'order', 'ASC' );
					}
				}
			}
		} else {
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
				if ( 'date' === $wp_query->get( 'orderby' ) ) {
					$wp_query->set( 'orderby', 'menu_order' );
				}
				if ( 'DESC' === $wp_query->get( 'order' ) ) {
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
	}


	public function scporder_get_terms_orderby( string $orderby, array $args ): string {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $orderby;
		}

		// Honor an explicit `include` ordering requested by the caller (PR #67 / issue #66).
		if ( isset( $args['orderby'] ) && 'include' === $args['orderby'] ) {
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
	 * Re-sort returned terms by term_order, unless the caller asked for a specific
	 * order. Shared by the get_terms / wp_get_object_terms callbacks above.
	 *
	 * @param mixed $terms Terms as returned by core.
	 * @param array $args  Query args.
	 * @return mixed
	 */
	private function sort_terms_by_order( $terms, array $args ) {
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return $terms;
		}

		// Admin column sorting wins.
		if ( is_admin() && ! wp_doing_ajax() && isset( $_GET['orderby'] ) ) {
			return $terms;
		}

		// Honor an explicit `include` ordering requested by the caller (PR #67 / issue #66).
		if ( isset( $args['orderby'] ) && 'include' === $args['orderby'] ) {
			return $terms;
		}

		$tags = $this->get_scporder_options_tags();

		foreach ( $terms as $term ) {
			if ( is_object( $term ) && isset( $term->taxonomy ) ) {
				if ( ! in_array( $term->taxonomy, $tags, true ) ) {
					return $terms;
				}
			} else {
				return $terms;
			}
		}

		usort( $terms, [ $this, 'taxcmp' ] );
		return $terms;
	}


	public function taxcmp( object $a, object $b ): int {
		return $a->term_order <=> $b->term_order;
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
	 * SCPO reset order for post types/taxonomies
	 *
	 * @return void
	 */
	public function scpo_ajax_reset_order(): void {
		global $wpdb;

		if ( ! isset( $_POST['action'] ) || 'scpo_reset_order' !== $_POST['action'] ) {
			return;
		}

		check_ajax_referer( 'scpo-reset-order', 'scpo_security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'simple-custom-post-order' ) ], 403 );
		}

		$items = isset( $_POST['items'] ) && is_array( $_POST['items'] )
			? array_map( 'sanitize_key', $_POST['items'] )
			: [];

		if ( empty( $items ) ) {
			wp_send_json_error( [ 'message' => __( 'No items selected.', 'simple-custom-post-order' ) ] );
		}

		// Build proper IN clause with individual placeholders
		$placeholders = implode( ', ', array_fill( 0, count( $items ), '%s' ) );
		$query = $wpdb->prepare(
			"UPDATE $wpdb->posts SET `menu_order` = 0 WHERE `post_type` IN ($placeholders)",
			$items
		);
		$result = $wpdb->query( $query );

		$scpo_options = get_option( 'scporder_options' );

		if ( false !== $scpo_options && isset( $scpo_options['objects'] ) ) {
			$scpo_options['objects'] = array_diff( $scpo_options['objects'], $items );
			update_option( 'scporder_options', $scpo_options );
		}

		if ( false !== $result ) {
			wp_send_json_success( [ 'message' => __( 'Items have been reset.', 'simple-custom-post-order' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to reset items.', 'simple-custom-post-order' ) ] );
		}
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
	global $wpdb;
	if ( function_exists( 'is_multisite' ) && is_multisite() ) {
		$curr_blog = $wpdb->blogid;
		$blogids   = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
		foreach ( $blogids as $blog_id ) {
			switch_to_blog( $blog_id );
			scporder_uninstall_db();
		}
		switch_to_blog( $curr_blog );
	} else {
		scporder_uninstall_db();
	}
}

function scporder_uninstall_db(): void {
	global $wpdb;
	$result = $wpdb->query( "DESCRIBE $wpdb->terms `term_order`" );
	if ( $result ) {
		$wpdb->query( "ALTER TABLE $wpdb->terms DROP `term_order`" );
	}
	delete_option( 'scporder_install' );
}

