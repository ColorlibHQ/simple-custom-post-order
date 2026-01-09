<?php
/**
 * Plugin Name: Simple Custom Post Order
 * Plugin URI: https://wordpress.org/plugins-wp/simple-custom-post-order/
 * Description: Order Items (Posts, Pages, and Custom Post Types) using a Drag and Drop Sortable JavaScript.
 * Version: 2.6.0
 * Author: Colorlib
 * Author URI: https://colorlib.com/
 * Tested up to: 6.9
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
define( 'SCPORDER_VERSION', '2.6.0' );

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

		add_action( 'pre_get_posts', array( $this, 'scporder_pre_get_posts' ) );

		add_filter( 'get_previous_post_where', array( $this, 'scporder_previous_post_where' ) );
		add_filter( 'get_previous_post_sort', array( $this, 'scporder_previous_post_sort' ) );
		add_filter( 'get_next_post_where', array( $this, 'scporder_next_post_where' ) );
		add_filter( 'get_next_post_sort', array( $this, 'scporder_next_post_sort' ) );

		add_filter( 'get_terms_orderby', array( $this, 'scporder_get_terms_orderby' ), 10, 3 );
		add_filter( 'wp_get_object_terms', array( $this, 'scporder_get_object_terms' ), 10, 3 );
		add_filter( 'get_terms', array( $this, 'scporder_get_object_terms' ), 10, 3 );

		add_action( 'admin_notices', array( $this, 'scporder_notice_not_checked' ) );
		add_action( 'wp_ajax_scporder_dismiss_notices', array( $this, 'dismiss_notices' ) );

		add_action( 'plugins_loaded', array( $this, 'load_scpo_textdomain' ) );

		add_filter( 'scpo_post_types_args', array( $this, 'scpo_filter_post_types' ), 10, 2 );

		add_action( 'wp_ajax_scpo_reset_order', array( $this, 'scpo_ajax_reset_order' ) );

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
		if ( $this->_check_load_script_css() ) {
			wp_enqueue_script( 'jquery' );
			wp_enqueue_script( 'jquery-ui-sortable' );
			wp_enqueue_script( 'scporderjs', SCPORDER_URL . '/assets/scporder.min.js', [ 'jquery' ], SCPORDER_VERSION, true );
			wp_localize_script( 'scporderjs', 'scporder_vars', [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'scporder_nonce_action' ),
			] );
			add_action( 'admin_print_styles', [ $this, 'print_scpo_style' ] );
		}
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

				// Optimization with prepared statement for security
				$object = sanitize_key( $object );
				$wpdb->query( 'SET @row_number = 0;' );
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

		if ( ! current_user_can( 'edit_posts' ) ) {
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

		if ( ! current_user_can( 'edit_posts' ) ) {
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

	public function scporder_previous_post_where( string $where ): string {
		global $post;

		$objects = $this->get_scporder_options_objects();
		if ( empty( $objects ) ) {
			return $where;
		}

		if ( isset( $post->post_type ) && in_array( $post->post_type, $objects, true ) ) {
			$where = preg_replace( "/p.post_date < \'[0-9\-\s\:]+\'/i", "p.menu_order > '" . $post->menu_order . "'", $where );
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
			$orderby = 'ORDER BY p.menu_order ASC LIMIT 1';
		}
		return $orderby;
	}

	public function scporder_next_post_where( string $where ): string {
		global $post;

		$objects = $this->get_scporder_options_objects();
		if ( empty( $objects ) ) {
			return $where;
		}

		if ( isset( $post->post_type ) && in_array( $post->post_type, $objects, true ) ) {
			$where = preg_replace( "/p.post_date > \'[0-9\-\s\:]+\'/i", "p.menu_order < '" . $post->menu_order . "'", $where );
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
			$orderby = 'ORDER BY p.menu_order DESC LIMIT 1';
		}
		return $orderby;
	}

	public function scporder_pre_get_posts( $wp_query ): void {
		$objects = $this->get_scporder_options_objects();

		if ( empty( $objects ) ) {
			return;
		}

		if ( is_search() ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
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

		$tags = $this->get_scporder_options_tags();

		if ( ! isset( $args['taxonomy'] ) ) {
			return $orderby;
		}

		if ( is_array( $args['taxonomy'] ) ) {
			$taxonomy = $args['taxonomy'][0] ?? false;
		} else {
			$taxonomy = $args['taxonomy'];
		}

		if ( ! in_array( $taxonomy, $tags, true ) ) {
			return $orderby;
		}

		return 't.term_order';
	}

	public function scporder_get_object_terms( array $terms ): array {
		$tags = $this->get_scporder_options_tags();

		if ( is_admin() && ! wp_doing_ajax() && isset( $_GET['orderby'] ) ) {
			return $terms;
		}

		foreach ( $terms as $term ) {
			if ( is_object( $term ) && isset( $term->taxonomy ) ) {
				$taxonomy = $term->taxonomy;
				if ( ! in_array( $taxonomy, $tags, true ) ) {
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

