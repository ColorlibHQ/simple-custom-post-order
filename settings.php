<?php
/**
 * Settings page for Simple Custom Post Order plugin.
 *
 * Uses WordPress Settings API for proper integration with core admin UI.
 *
 * @package SimpleCustomPostOrder
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$options = get_option( 'scporder_options', [] );
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'scporder_options' ); ?>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'scporder_settings' );
		do_settings_sections( 'scporder-settings' );
		submit_button( __( 'Save Changes', 'simple-custom-post-order' ) );
		?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Reset Post Order', 'simple-custom-post-order' ); ?></h2>
	<p><?php esc_html_e( 'Select post types to reset their custom order back to default (by date for posts, alphabetical for pages).', 'simple-custom-post-order' ); ?></p>

	<form id="scpo-reset-form">
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post Types to Reset', 'simple-custom-post-order' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><span><?php esc_html_e( 'Post Types to Reset', 'simple-custom-post-order' ); ?></span></legend>
							<?php
							$post_types_args = apply_filters(
								'scpo_post_types_args',
								[
									'show_ui'      => true,
									'show_in_menu' => true,
								],
								$options
							);
							$post_types = get_post_types( $post_types_args, 'objects' );

							foreach ( $post_types as $post_type ) {
								if ( 'attachment' === $post_type->name ) {
									continue;
								}
								printf(
									'<label><input type="checkbox" name="scpo_reset_types[]" value="%s" /> %s</label><br />',
									esc_attr( $post_type->name ),
									esc_html( $post_type->label )
								);
							}
							?>
						</fieldset>
					</td>
				</tr>
			</tbody>
		</table>

		<?php wp_nonce_field( 'scpo-reset-order', 'scpo_reset_nonce' ); ?>

		<p class="submit">
			<button type="submit" id="scpo-reset-button" class="button button-secondary">
				<?php esc_html_e( 'Reset Order', 'simple-custom-post-order' ); ?>
			</button>
			<span id="scpo-reset-message" class="description" style="margin-left: 10px;"></span>
		</p>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Support', 'simple-custom-post-order' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: 1: link to reviews, 2: link to Colorlib */
			esc_html__( 'Enjoying this plugin? Please %1$s on WordPress.org! For support, visit %2$s.', 'simple-custom-post-order' ),
			'<a href="https://wordpress.org/support/plugin/simple-custom-post-order/reviews/?filter=5" target="_blank">' . esc_html__( 'leave a review', 'simple-custom-post-order' ) . '</a>',
			'<a href="https://colorlib.com/" target="_blank">Colorlib.com</a>'
		);
		?>
	</p>
</div>

<script>
(function($) {
	'use strict';

	$('#scpo-reset-form').on('submit', function(e) {
		e.preventDefault();

		var $form = $(this);
		var $button = $('#scpo-reset-button');
		var $message = $('#scpo-reset-message');
		var checkedItems = $form.find('input[name="scpo_reset_types[]"]:checked');

		if (checkedItems.length === 0) {
			$message.text('<?php echo esc_js( __( 'Please select at least one post type to reset.', 'simple-custom-post-order' ) ); ?>');
			return;
		}

		var items = [];
		checkedItems.each(function() {
			items.push($(this).val());
		});

		$button.prop('disabled', true);
		$message.text('<?php echo esc_js( __( 'Resetting...', 'simple-custom-post-order' ) ); ?>');

		$.ajax({
			url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
			type: 'POST',
			data: {
				action: 'scpo_reset_order',
				scpo_security: '<?php echo esc_js( wp_create_nonce( 'scpo-reset-order' ) ); ?>',
				items: items
			},
			success: function(response) {
				if (response && response.success) {
					$message.text(response.data.message);
					setTimeout(function() {
						location.reload();
					}, 1500);
				} else if (response && response.data && response.data.message) {
					$message.text(response.data.message);
				} else {
					$message.text('<?php echo esc_js( __( 'An error occurred.', 'simple-custom-post-order' ) ); ?>');
				}
				$button.prop('disabled', false);
			},
			error: function() {
				$message.text('<?php echo esc_js( __( 'An error occurred.', 'simple-custom-post-order' ) ); ?>');
				$button.prop('disabled', false);
			}
		});
	});

})(jQuery);
</script>
