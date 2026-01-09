<?php

class Simple_Review {


	/**
	 * @var int|false Timestamp for when review notice should be shown
	 */
	private $value;

	/**
	 * @var array Review notice message strings
	 */
	private array $messages;

	/**
	 * @var string URL to WordPress.org reviews page
	 */
	private string $link = 'https://wordpress.org/plugins/simple-custom-post-order/#reviews';

	/**
	 * @var string Plugin slug
	 */
	private string $slug = 'simple-custom-post-order';

	public function __construct() {
		$this->messages = [
			'notice'  => esc_html__( "Hi there! Stoked to see you're using Simple Custom Post Order for a few days now - hope you like it! And if you do, please consider rating it. It would mean the world to us.  Keep on rocking!", 'simple-custom-post-order' ),
			'rate'    => esc_html__( 'Rate the plugin', 'simple-custom-post-order' ),
			'rated'   => esc_html__( 'Remind me later', 'simple-custom-post-order' ),
			'no_rate' => esc_html__( 'Don\'t show again', 'simple-custom-post-order' ),
		];

		add_action( 'init', [ $this, 'init' ] );
	}

	public function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		$this->value = $this->value();

		if ( $this->check() ) {
			add_action( 'admin_notices', [ $this, 'five_star_wp_rate_notice' ] );
			add_action( 'wp_ajax_epsilon_simple_review', [ $this, 'ajax' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
			add_action( 'admin_print_footer_scripts', [ $this, 'ajax_script' ] );
		}
	}

	private function check(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return time() > $this->value;
	}

	private function value(): int {
		$value = get_option( 'simple-rate-time', false );

		if ( $value ) {
			return (int) $value;
		}

		$value = time() + DAY_IN_SECONDS;
		update_option( 'simple-rate-time', $value );

		return $value;
	}

	public function five_star_wp_rate_notice(): void {
		$url = $this->link;
		?>
		<div id="<?php echo esc_attr($this->slug) ?>-epsilon-review-notice" class="notice notice-success is-dismissible" style="margin-top:30px;">
			<p><?php echo sprintf( esc_html( $this->messages['notice'] ), $this->value ) ; ?></p>
			<p class="actions">
				<a id="epsilon-rate" href="<?php echo esc_url( $url ) ?>" target="_blank" class="button button-primary epsilon-review-button">
					<?php echo esc_html( $this->messages['rate'] ); ?>
				</a>
				<a id="epsilon-later" href="#" style="margin-left:10px" class="epsilon-review-button"><?php echo esc_html( $this->messages['rated'] ); ?></a>
				<a id="epsilon-no-rate" href="#" style="margin-left:10px" class="epsilon-review-button"><?php echo esc_html( $this->messages['no_rate'] ); ?></a>
			</p>
		</div>
		<?php
	}

	public function ajax(): void {
		check_ajax_referer( 'epsilon-simple-review', 'security' );

		if ( ! isset( $_POST['check'] ) ) {
			wp_die( 'ok' );
		}

		$check = sanitize_text_field( wp_unslash( $_POST['check'] ) );
		$time  = get_option( 'simple-rate-time' );

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

	public function enqueue(): void {
		wp_enqueue_script( 'jquery' );
	}

	public function ajax_script(): void {
		$ajax_nonce = wp_create_nonce( 'epsilon-simple-review' );
		?>

		<script type="text/javascript">
			jQuery( document ).ready( function( $ ){

				$( '.epsilon-review-button' ).click( function( evt ){
					var href = $(this).attr('href'),
						id = $(this).attr('id');

					if ( 'epsilon-rate' !== id ) {
						evt.preventDefault();
					}

					var data = {
						action: 'epsilon_simple_review',
						security: '<?php echo esc_js( $ajax_nonce ); ?>',
						check: id
					};

					if ( 'epsilon-rated' === id || 'epsilon-rate' === id ) {
						data['epsilon-review'] = 1;
					}

					$.post( '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', data, function( response ) {
						$( '#<?php echo esc_js( $this->slug ); ?>-epsilon-review-notice' ).slideUp( 'fast', function() {
							$( this ).remove();
						} );
					});

				} );

				$('#simple-custom-post-order-epsilon-review-notice .notice-dismiss').click(function(){

					var data = {
						action: 'epsilon_simple_review',
						security: '<?php echo esc_js( $ajax_nonce ); ?>',
						check: 'epsilon-later'
					};

					$.post( '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', data, function( response ) {
						$( '#<?php echo esc_js( $this->slug ); ?>-epsilon-review-notice' ).slideUp( 'fast', function() {
							$( this ).remove();
						} );
					});
				});

			});
		</script>

		<?php
	}
}

new Simple_Review();