<?php

/*
  Plugin Name: Simple Custom Post Order
  Plugin URI: http://hsameer.com.np/simple-custom-post-order/
  Description: Order Items (Posts, Pages, and Custom Post Types) using a Drag and Drop Sortable JavaScript.
  Version: 2.2
  Author: Sameer Humagain
  Author URI: http://hsameer.com.np/
 */


/* ====================================================================
  Define
  ==================================================================== */

define( 'SCPO_URL', plugins_url( '', __FILE__ ) );
define( 'SCPO_DIR', plugin_dir_path( __FILE__ ) );

/* ====================================================================
  Class & Method
  ==================================================================== */

$scporder = new SCPO_Engine();

class SCPO_Engine {

	function __construct() {
		if ( !get_option( 'scporder_options' ) )
			$this->scporder_install();

		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		add_action( 'admin_init', array( &$this, 'refresh' ) );
		add_action( 'admin_init', array( &$this, 'update_options' ) );
		add_action( 'admin_init', array( &$this, 'load_script_css' ) );

		add_action( 'wp_ajax_update-menu-order', array( &$this, 'update_menu_order' ) );


		add_filter( 'pre_get_posts', array( &$this, 'scporder_filter_active' ) );
		add_filter( 'pre_get_posts', array( &$this, 'scporder_pre_get_posts' ) );


		add_filter( 'get_previous_post_where', array( &$this, 'scporder_previous_post_where' ) );
		add_filter( 'get_previous_post_sort', array( &$this, 'scporder_previous_post_sort' ) );
		add_filter( 'get_next_post_where', array( &$this, 'scporder_next_post_where' ) );
		add_filter( 'get_next_post_sort', array( &$this, 'scporder_next_post_sort' ) );
	}

	function scporder_install() {
		global $wpdb;

		//Initialize Options

		$post_types = get_post_types( array(
			'public' => true
				), 'objects' );

		foreach ( $post_types as $post_type ) {
			$init_objects[] = $post_type->name;
		}
		$input_options = array( 'objects' => $init_objects );

		update_option( 'scporder_options', $input_options );


		// Initialize : menu_order from date_post

		$scporder_options = get_option( 'scporder_options' );
		$objects = $scporder_options['objects'];

		foreach ( $objects as $object ) {
			$sql = "SELECT
						ID
					FROM
						$wpdb->posts
					WHERE
						post_type = '" . $object . "'
						AND post_status IN ('publish', 'pending', 'draft', 'private', 'future')
					ORDER BY
						post_date DESC
					";

			$results = $wpdb->get_results( $sql );

			foreach ( $results as $key => $result ) {
				$wpdb->update( $wpdb->posts, array( 'menu_order' => $key + 1 ), array( 'ID' => $result->ID ) );
			}
		}
	}

	function admin_menu() {
		add_options_page( __( 'SCPOrder', 'scporder' ), __( 'SCPOrder', 'scporder' ), 'manage_options', 'scporder-settings', array( &$this, 'admin_page' ) );
	}

	function admin_page() {
		require SCPO_DIR . 'settings.php';
	}

	function _check_load_script_css() {
		
		$active = false;
		
		$scporder_options = get_option( 'scporder_options' );
		$objects = $scporder_options['objects'];
		
		if ( is_array( $objects ) ) { 
			if ( !strstr( $_SERVER["REQUEST_URI"], 'action=edit' ) && !strstr( $_SERVER["REQUEST_URI"], 'wp-admin/post-new.php' ) ) {
				 
				if ( isset( $_GET['post_type'] ) ) {
					if ( in_array( $_GET['post_type'], $objects ) ) {
						$active = true;
					} 
				} else if ( strstr( $_SERVER["REQUEST_URI"], 'wp-admin/edit.php' ) ) {
					if ( in_array( 'post', $objects ) ) {
						$active = true;
					}
				}
			}
		}
		return $active;
	}

	function load_script_css() {
		if ( $this->_check_load_script_css() ) { 
			wp_enqueue_script( 'jQuery' );
			wp_enqueue_script( 'jquery-ui-sortable' );
			wp_enqueue_script( 'scporderjs', SCPO_URL . '/scporder.js', array( 'jquery' ), null, true ); 
			wp_enqueue_style( 'scporder', SCPO_URL . '/scporder.css', array( ), null );
		}
	}

	function refresh()
	{
		 
		global $wpdb;
		$scporder_options = get_option( 'scporder_options' );
		$objects = $scporder_options['objects']; 
		
		if ( is_array( $objects ) ) {
			foreach( $objects as $object) { 
				
				$result = $wpdb->get_results( "SELECT count(*) as cnt, max(menu_order) as max, min(menu_order) as min FROM $wpdb->posts WHERE post_type = '".$object."' AND post_status IN ('publish', 'pending', 'draft', 'private', 'future')" );
				if ( count( $result ) > 0 && $result[0]->cnt == $result[0]->max && $result[0]->min != 0 ) {
					continue;
				}
				
				$results = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_type = '".$object."' AND post_status IN ('publish', 'pending', 'draft', 'private', 'future') ORDER BY menu_order ASC" );
				
				foreach( $results as $key => $result ) { 
					$wpdb->update( $wpdb->posts, array( 'menu_order' => $key+1 ), array( 'ID' => $result->ID ) );
				}
			}
		}
	}

	function update_menu_order()
	{
		global $wpdb;
		parse_str($_POST['order'], $data);
		if ( is_array($data) ) {
			$id_arr = array();
			foreach( $data as $key => $values ) {
				foreach( $values as $position => $id ) {
					$id_arr[] = $id;
				}
			}
			$menu_order_arr = array();
			foreach( $id_arr as $key => $id ) {
				$results = $wpdb->get_results( "SELECT menu_order FROM $wpdb->posts WHERE ID = ".$id );
				foreach( $results as $result ) {
					$menu_order_arr[] = $result->menu_order;
				}
			} 
			sort($menu_order_arr);
			foreach( $data as $key => $values ) {
				foreach( $values as $position => $id ) {
					$wpdb->update( $wpdb->posts, array( 'menu_order' => $menu_order_arr[$position] ), array( 'ID' => $id ) );
				}
			}
		}
	}

	function update_options()
	{
		global $wpdb;
		if ( isset( $_POST['scporder_submit'] ) ) {
			check_admin_referer( 'nonce_scporder' );
			if ( isset( $_POST['objects'] ) ) {
				$input_options = array( 'objects' => $_POST['objects'] );
			} else {
				$input_options = array( 'objects' => '' );
			}
			update_option( 'scporder_options', $input_options );
			$scporder_options = get_option( 'scporder_options' );
			$objects = $scporder_options['objects']; 
			
			if ( !empty( $objects ) ) {
				foreach( $objects as $object) { 
					$result = $wpdb->get_results( "SELECT count(*) as cnt, max(menu_order) as max, min(menu_order) as min FROM $wpdb->posts WHERE post_type = '".$object."' AND post_status IN ('publish', 'pending', 'draft', 'private', 'future')" );
					if ( count( $result ) > 0 && $result[0]->max == 0 ) {
						$results = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_type = '".$object."' AND post_status IN ('publish', 'pending', 'draft', 'private', 'future') ORDER BY post_date DESC" );
						foreach( $results as $key => $result ) {
							$wpdb->update( $wpdb->posts, array( 'menu_order' => $key+1 ), array( 'ID' => $result->ID ) );
						}
					}
				}
			}
			
			wp_redirect( 'admin.php?page=scporder-settings&msg=update' );
		}
	} 

	function scporder_previous_post_where( $where ) {
		global $post;

		$scporder_options = get_option( 'scporder_options' );
		$objects = $scporder_options['objects'];

		if ( in_array( $post->post_type, $objects ) ) {
			$current_menu_order = $post->menu_order;
			$where = "WHERE p.menu_order > '" . $current_menu_order . "' AND p.post_type = '" . $post->post_type . "' AND p.post_status = 'publish'";
		}
		return $where;
	}

	function scporder_previous_post_sort( $orderby ) {
		global $post;

		$scporder_options = get_option( 'scporder_options' );
		$objects = $scporder_options['objects'];

		if ( in_array( $post->post_type, $objects ) ) {
			$orderby = 'ORDER BY p.menu_order ASC LIMIT 1';
		}
		return $orderby;
	}

	function scporder_next_post_where( $where ) {
		global $post;

		$scporder_options = get_option( 'scporder_options' );
		$objects = $scporder_options['objects'];

		if ( in_array( $post->post_type, $objects ) ) {
			$current_menu_order = $post->menu_order;
			$where = "WHERE p.menu_order < '" . $current_menu_order . "' AND p.post_type = '" . $post->post_type . "' AND p.post_status = 'publish'";
		}
		return $where;
	}

	function scporder_next_post_sort( $orderby ) {
		global $post;

		$scporder_options = get_option( 'scporder_options' );
		$objects = $scporder_options['objects'];

		if ( in_array( $post->post_type, $objects ) ) {
			$orderby = 'ORDER BY p.menu_order DESC LIMIT 1';
		}
		return $orderby;
	}

	function scporder_filter_active( $wp_query ) {

		if ( isset($wp_query->query['suppress_filters']) ) $wp_query->query['suppress_filters'] = false;
		if ( isset($wp_query->query_vars['suppress_filters']) ) $wp_query->query_vars['suppress_filters'] = false;
		return $wp_query;
	}

	function scporder_pre_get_posts( $wp_query ) {
		global $args;
		$scporder_options = get_option( 'scporder_options' );
		$objects = $scporder_options['objects'];
		
		if ( is_array( $objects ) ) {
		 
			
			if ( is_admin() && !defined( 'DOING_AJAX' ) ) {
				 
				
				if ( isset( $wp_query->query['post_type'] ) ) {
					if ( in_array( $wp_query->query['post_type'], $objects ) ) {
						$wp_query->set( 'orderby', 'menu_order' );
						$wp_query->set( 'order', 'ASC' );
					}
				}
			 
			
			} else {
				 
					
				$active = false;
					 
				
				if ( isset( $wp_query->query['post_type'] ) ) { 
					if ( is_array( $wp_query->query['post_type'] ) ) {
						$post_types = $wp_query->query['post_type'];
						foreach( $post_types as $post_type ) {
							if ( in_array( $post_type, $objects ) ) {
								$active = true;
							}
						}
					} else {
						if ( in_array( $wp_query->query['post_type'], $objects ) ) {
							$active = true;
						}
					} 
				} else {
					if ( in_array( 'post', $objects ) ) {
						$active = true;
					}
				} 
				
				if ( $active ) {
					
					if ( isset( $args ) ) { 
						if ( is_array( $args ) ) {
							if ( !isset( $args['orderby'] ) ) {
								$wp_query->set( 'orderby', 'menu_order' );
							}
							if ( !isset( $args['order'] ) ) {
								$wp_query->set( 'order', 'ASC' );
							} 
						} else {
							if ( !strstr( $args, 'orderby=' ) ) {
								$wp_query->set( 'orderby', 'menu_order' );
							}
							if ( !strstr( $args, 'order=' ) ) {
								$wp_query->set( 'order', 'ASC' );
								
							}
						}
					} else {
						$wp_query->set( 'orderby', 'menu_order' );
						$wp_query->set( 'order', 'ASC' );
					}
				}
			}
		}

	}
} 
