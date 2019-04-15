<?php

if ( ! class_exists( 'Colorlib_Dashboard_Widget_Extend_Feed' ) ) {

	class Colorlib_Dashboard_Widget_Extend_Feed {

		public function __construct() {

			// Actions.
			add_action( 'wp_feed_options', array( $this, 'dashboard_update_feed_urls' ), 10, 2 );

			// Filters.
			add_filter( 'dashboard_secondary_items', array( $this, 'dashboard_items_count' ) );

		}

		public function dashboard_items_count() {

			/**
			 * Apply the filters am_dashboard_feed_count for letting an admin
			 * override this count.
			 */
			return (int) apply_filters( 'colorlib_dashboard_feed_count', 6 );
		}

		public function dashboard_update_feed_urls( $feed, $url ) {

			global $pagenow;

			// Return early if not on the right page.
			if ( 'admin-ajax.php' !== $pagenow ) {
				return;
			}

			/**
			 * Return early if not on the right feed.
			 * We want to modify the feed URLs only for the
			 * WordPress Events & News Dashboard Widget
			 */
			if ( is_array( $url ) ) {
				if ( ! in_array( 'https://planet.wordpress.org/feed/', $url ) ) {
					return;
				}
			}else{
				if ( strpos( $url, 'planet.wordpress.org' ) === false ) {
					return;
				}
			}

			// Build the feed sources.
			$all_feed_urls = $this->get_feed_urls( $url );

			// Update the feed sources.
			$feed->set_feed_url( $all_feed_urls );
		}

		public function get_feed_urls( $url ) {

			// Initialize the feeds array.
			$feed_urls = array( $url );

			$check = get_transient( 'colorlib_dashboard_feed' );
			$feeds = array();
			if ( ! $check ) {

				$feed_working = 'not-working';
				
				// Load SimplePie Instance
				$feed = fetch_feed( array( 'https://colorlib.com/wp/feed/' ) );

				// TODO report error when is an error loading the feed
				if ( ! is_wp_error( $feed ) ) {
					$feed_urls[]  = 'https://colorlib.com/wp/feed/';
					$feed_working = 'working';
				}

				set_transient( 'colorlib_dashboard_feed', $feed_working, 12 * HOUR_IN_SECONDS );

			}elseif ( 'working' == $check ) {
				$feed_urls[]  = 'https://colorlib.com/wp/feed/';
			}

			// Return the feed URLs.
			return array_unique( $feed_urls );
		}
	}

	// Create an instance.
	new Colorlib_Dashboard_Widget_Extend_Feed();
}
