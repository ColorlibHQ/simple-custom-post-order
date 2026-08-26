<?php
/**
 * WordPress.org rating prompt.
 *
 * Independent of SCPO_Engine. Kept on the legacy `epsilon_simple_review` AJAX
 * action and `simple-rate-time` option so existing installs carry their state
 * forward untouched.
 */
class Simple_Review {

	/**
	 * @var int Timestamp after which the review notice may be shown.
	 */
	private int $value = 0;

	/**
	 * @var string URL to WordPress.org reviews page
	 */
	private string $link = 'https://wordpress.org/plugins/simple-custom-post-order/#reviews';

	/**
	 * @var string Plugin slug
	 */
	private string $slug = 'simple-custom-post-order';

	public function __construct() {
		/*
		 * SCPO_Engine includes this file from `load_dependencies()`, which itself
		 * runs on `init` at priority 10. Registering another `init`/10 callback
		 * from inside that same priority never fires: WP_Hook::apply_filters()
		 * iterates `$this->callbacks[ $priority ]` with a by-value foreach, so
		 * PHP walks the copy the loop started with and the late arrival is not
		 * seen. The notice had therefore been dead since the file was moved to
		 * `init` (reported by @jamieburchell).
		 *
		 * Run immediately when `init` has already started, and hook it normally
		 * otherwise, so this class no longer depends on when it gets included.
		 */
		if ( did_action( 'init' ) ) {
			$this->init();
		} else {
			add_action( 'init', [ $this, 'init' ] );
		}
	}

	public function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		// Nothing below this point should run for users who could never see the
		// notice — in particular value() writes an option, and doing that on
		// every front-of-house admin request for every subscriber is waste.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->value = $this->value();

		if ( time() > $this->value ) {
			add_action( 'admin_notices', [ $this, 'five_star_wp_rate_notice' ] );
			add_action( 'admin_print_footer_scripts', [ $this, 'ajax_script' ] );
		}

		// Registered regardless of whether the notice renders this request: the
		// dismissal POST arrives on a later admin-ajax request that has no notice.
		add_action( 'wp_ajax_epsilon_simple_review', [ $this, 'ajax' ] );
	}

	/**
	 * Translated notice strings.
	 *
	 * Built on demand rather than in the constructor. Translating at construction
	 * is what forced the include onto `init` in the first place (to avoid WP 6.7's
	 * _load_textdomain_just_in_time notice); keeping the calls at render time means
	 * the class is safe to load at any point.
	 *
	 * @return array<string,string>
	 */
	private function messages(): array {
		return [
			'notice'  => __( "Hi there! Stoked to see you're using Simple Custom Post Order for a few days now - hope you like it! And if you do, please consider rating it. It would mean the world to us.  Keep on rocking!", 'simple-custom-post-order' ),
			'rate'    => __( 'Rate the plugin', 'simple-custom-post-order' ),
			'rated'   => __( 'Remind me later', 'simple-custom-post-order' ),
			'no_rate' => __( 'Don\'t show again', 'simple-custom-post-order' ),
		];
	}

	/**
	 * Timestamp after which the notice may appear.
	 *
	 * A stored value far in the past can only have come from the period when the
	 * notice was not rendering at all: any site that actually saw it pushed the
	 * value forward (a week for "Remind me later", five years for "Rate" and
	 * "Don't show again"). Re-enabling the notice would otherwise fire it on the
	 * very next admin page load for every one of those installs at once, so a
	 * long-elapsed value is given a fresh week instead. Values in the future are
	 * never touched — a site that dismissed the notice for good stays dismissed.
	 *
	 * @return int
	 */
	private function value(): int {
		$value = get_option( 'simple-rate-time', false );

		if ( $value ) {
			$value = (int) $value;

			if ( $value < time() - ( 90 * DAY_IN_SECONDS ) ) {
				$value = time() + WEEK_IN_SECONDS;
				update_option( 'simple-rate-time', $value );
			}

			return $value;
		}

		$value = time() + DAY_IN_SECONDS;
		update_option( 'simple-rate-time', $value );

		return $value;
	}

	public function five_star_wp_rate_notice(): void {
		$messages = $this->messages();
		?>
		<div id="<?php echo esc_attr( $this->slug ); ?>-epsilon-review-notice" class="notice notice-success is-dismissible" style="margin-top:30px;">
			<?php /* No sprintf(): the string carries no placeholder, and running a translated string through sprintf() corrupts any locale whose text contains a literal %. */ ?>
			<p><?php echo esc_html( $messages['notice'] ); ?></p>
			<p class="actions">
				<a id="epsilon-rate" href="<?php echo esc_url( $this->link ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary epsilon-review-button">
					<?php echo esc_html( $messages['rate'] ); ?>
				</a>
				<a id="epsilon-later" href="#" style="margin-left:10px" class="epsilon-review-button"><?php echo esc_html( $messages['rated'] ); ?></a>
				<a id="epsilon-no-rate" href="#" style="margin-left:10px" class="epsilon-review-button"><?php echo esc_html( $messages['no_rate'] ); ?></a>
			</p>
		</div>
		<?php
	}

	public function ajax(): void {
		check_ajax_referer( 'epsilon-simple-review', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'nok' );
		}

		if ( ! isset( $_POST['check'] ) ) {
			wp_die( 'ok' );
		}

		$check = sanitize_text_field( wp_unslash( $_POST['check'] ) );
		$time  = (int) get_option( 'simple-rate-time' );

		if ( 'epsilon-rate' === $check ) {
			$time = time() + YEAR_IN_SECONDS * 5;
		} elseif ( 'epsilon-later' === $check ) {
			$time = time() + WEEK_IN_SECONDS;
		} elseif ( 'epsilon-no-rate' === $check ) {
			$time = time() + YEAR_IN_SECONDS * 5;
		}

		update_option( 'simple-rate-time', $time );
		wp_die( 'ok' );
	}

	/**
	 * Dismissal handler.
	 *
	 * Vanilla JS on purpose. The previous version enqueued jQuery on every admin
	 * page and printed an inline jQuery block that was not dependency-ordered, so
	 * any site that defers or drops jQuery in wp-admin would have thrown
	 * "jQuery is not defined" on every screen once this notice came back.
	 *
	 * @return void
	 */
	public function ajax_script(): void {
		$ajax_nonce = wp_create_nonce( 'epsilon-simple-review' );
		$notice_id  = $this->slug . '-epsilon-review-notice';

		// Root-relative, same-origin admin-ajax path — the same derivation the
		// reorder endpoints use. An absolute admin_url() breaks the request behind
		// a proxy, on a non-standard port, or on a scheme mismatch.
		$url      = admin_url( 'admin-ajax.php' );
		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$ajax_url = ( is_string( $path ) && '' !== $path ) ? $path : $url;
		?>
		<script>
		( function () {
			var notice = document.getElementById( <?php echo wp_json_encode( $notice_id ); ?> );
			if ( ! notice ) {
				return;
			}

			function remove() {
				if ( notice && notice.parentNode ) {
					notice.parentNode.removeChild( notice );
				}
			}

			function dismiss( check ) {
				if ( ! window.fetch || ! window.URLSearchParams ) {
					remove();
					return;
				}

				var body = new URLSearchParams( {
					action:   'epsilon_simple_review',
					security: <?php echo wp_json_encode( $ajax_nonce ); ?>,
					check:    check
				} );

				// Same-origin, root-relative: survives proxies, non-standard ports
				// and http/https mismatches, same as the reorder endpoints.
				window.fetch( <?php echo wp_json_encode( $ajax_url ); ?>, {
					method:      'POST',
					credentials: 'same-origin',
					headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
					body:        body.toString()
				} ).catch( function () {} ).then( remove );
			}

			// Delegated: core injects the .notice-dismiss button from common.js on
			// DOM ready, which can land after this inline script has already run,
			// so binding it directly would miss it.
			notice.addEventListener( 'click', function ( evt ) {
				var button = evt.target.closest( '.epsilon-review-button, .notice-dismiss' );
				if ( ! button ) {
					return;
				}

				if ( button.classList.contains( 'notice-dismiss' ) ) {
					dismiss( 'epsilon-later' );
					return;
				}

				// "Rate the plugin" must still open wordpress.org in a new tab.
				if ( 'epsilon-rate' !== button.id ) {
					evt.preventDefault();
				}

				dismiss( button.id );
			} );
		} )();
		</script>
		<?php
	}
}

new Simple_Review();
