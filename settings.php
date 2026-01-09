<?php
$scporder_options = get_option( 'scporder_options' );
$scporder_objects = isset( $scporder_options['objects'] ) ? $scporder_options['objects'] : array();
$scporder_tags    = isset( $scporder_options['tags'] ) ? $scporder_options['tags'] : array();

$advanced_view = isset( $scporder_options['show_advanced_view'] ) ? $scporder_options['show_advanced_view'] : '';
?>
<style>

	.epsilon-toggle {
		position: relative;
		display:inline-block;
		user-select: none;
	}

	.epsilon-toggle__items {
		box-sizing: border-box;
	}

	.epsilon-toggle__items > * {
		box-sizing: inherit;
	}

	.epsilon-toggle__input[type=checkbox] {
		border-radius: 2px;
		border: 2px solid #6c7781;
		margin-right: 12px;
		transition: none;
		height: 100%;
		left: 0;
		top: 0;
		margin: 0;
		padding: 0;
		opacity: 0;
		position: absolute;
		width: 100%;
		z-index: 1;
	}

	.epsilon-toggle__track {
		background-color: #fff;
		border: 2px solid #6c7781;
		border-radius: 9px;
		display: inline-block;
		height: 18px;
		width: 36px;
		vertical-align: top;
		transition: background .2s ease;
	}

	.epsilon-toggle__thumb {
		background-color: #6c7781;
		border: 5px solid #6c7781;
		border-radius: 50%;
		display: block;
		height: 10px;
		width: 10px;
		position: absolute;
		left: 4px;
		top: 4px;
		transition: transform .2s ease;
	}

	.epsilon-toggle__off {
		position: absolute;
		right: 6px;
		top: 6px;
		color: #6c7781;
		fill: currentColor;
	}

	.epsilon-toggle__on {
		position: absolute;
		top: 6px;
		left: 8px;
		border: 1px solid #fff;
		outline: 1px solid transparent;
		outline-offset: -1px;
		display: none;
	}


	.epsilon-toggle__input[type=checkbox]:checked + .epsilon-toggle__items .epsilon-toggle__track {
		background-color: #11a0d2;
		border: 9px solid transparent;
	}

	.epsilon-toggle__input[type=checkbox]:checked + .epsilon-toggle__items .epsilon-toggle__thumb {
		background-color: #fff;
		border-width: 0;
		transform: translateX(18px);
	}

	.epsilon-toggle__input[type=checkbox]:checked + .epsilon-toggle__items .epsilon-toggle__off {
		display: none;
	}

	.epsilon-toggle__input[type=checkbox]:checked + .epsilon-toggle__items .epsilon-toggle__on {
		display: inline-block;
	}

	.scpo-reset-response {
		margin-left:15px;
		color:#0085ba;
	}
	</style>
<div class="wrap">
	<h2><?php _e( 'Simple Custom Post Order Settings', 'simple-custom-post-order' ); ?></h2>
	<?php if ( isset( $_GET['msg'] ) ) : ?>
		<div id="message" class="updated below-h2">
			<?php if ( $_GET['msg'] == 'update' ) : ?>
				<p><?php _e( 'Settings Updated.', 'simple-custom-post-order' ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form method="post">

		<?php
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( 'nonce_scporder' );}
		?>

		<div id="scporder_select_objects">

			<table class="form-table">
				<tbody>
					<tr valign="top">
						<th scope="row"><?php _e( 'Check to Sort Post Types', 'simple-custom-post-order' ); ?></th>
						<td>

							<label>
								<div class="epsilon-toggle">
									<input id="scporder_allcheck_objects" class="epsilon-toggle__input" type="checkbox">
									<div class="epsilon-toggle__items">
										<span class="epsilon-toggle__track"></span>
										<span class="epsilon-toggle__thumb"></span>
										<svg class="epsilon-toggle__off" width="6" height="6" aria-hidden="true"
											 role="img" focusable="false" viewBox="0 0 6 6">
											<path d="M3 1.5c.8 0 1.5.7 1.5 1.5S3.8 4.5 3 4.5 1.5 3.8 1.5 3 2.2 1.5 3 1.5M3 0C1.3 0 0 1.3 0 3s1.3 3 3 3 3-1.3 3-3-1.3-3-3-3z"></path>
										</svg>
										<svg class="epsilon-toggle__on" width="2" height="6" aria-hidden="true"
											 role="img" focusable="false" viewBox="0 0 2 6">
											<path d="M0 0h2v6H0z"></path>
										</svg>
									</div>
								</div>
								&nbsp;<?php _e( 'Check All', 'simple-custom-post-order' ); ?></label><br>
							<?php
							$post_types_args = apply_filters(
								'scpo_post_types_args',
								array(
									'show_ui'      => true,
									'show_in_menu' => true,
								),
								$scporder_options
							);

							$post_types = get_post_types( $post_types_args, 'objects' );

							foreach ( $post_types as $post_type ) {
								if ( $post_type->name == 'attachment' ) {
									continue;
								}
								?>
								<label>
									<div class="epsilon-toggle">
										<input class="epsilon-toggle__input" type="checkbox"
											   name="objects[]" value="<?php echo $post_type->name; ?>"
																				  <?php
																					if ( isset( $scporder_objects ) && is_array( $scporder_objects ) ) {
																						if ( in_array( $post_type->name, $scporder_objects ) ) {
																							echo 'checked="checked"';
																						}
																					}
																					?>
										>
										<div class="epsilon-toggle__items">
											<span class="epsilon-toggle__track"></span>
											<span class="epsilon-toggle__thumb"></span>
											<svg class="epsilon-toggle__off" width="6" height="6" aria-hidden="true"
												 role="img" focusable="false" viewBox="0 0 6 6">
												<path d="M3 1.5c.8 0 1.5.7 1.5 1.5S3.8 4.5 3 4.5 1.5 3.8 1.5 3 2.2 1.5 3 1.5M3 0C1.3 0 0 1.3 0 3s1.3 3 3 3 3-1.3 3-3-1.3-3-3-3z"></path>
											</svg>
											<svg class="epsilon-toggle__on" width="2" height="6" aria-hidden="true"
												 role="img" focusable="false" viewBox="0 0 2 6">
												<path d="M0 0h2v6H0z"></path>
											</svg>
										</div>
									</div>
									&nbsp;<?php echo $post_type->label; ?></label><br>
									<?php
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>

		</div>


		<div id="scporder_select_tags">
			<table class="form-table">
				<tbody>
					<tr valign="top">
						<th scope="row"><?php _e( 'Check to Sort Taxonomies', 'simple-custom-post-order' ); ?></th>
						<td>

							<label>
								<div class="epsilon-toggle">
									<input id="scporder_allcheck_tags" class="epsilon-toggle__input" type="checkbox">
									<div class="epsilon-toggle__items">
										<span class="epsilon-toggle__track"></span>
										<span class="epsilon-toggle__thumb"></span>
										<svg class="epsilon-toggle__off" width="6" height="6" aria-hidden="true"
											 role="img" focusable="false" viewBox="0 0 6 6">
											<path d="M3 1.5c.8 0 1.5.7 1.5 1.5S3.8 4.5 3 4.5 1.5 3.8 1.5 3 2.2 1.5 3 1.5M3 0C1.3 0 0 1.3 0 3s1.3 3 3 3 3-1.3 3-3-1.3-3-3-3z"></path>
										</svg>
										<svg class="epsilon-toggle__on" width="2" height="6" aria-hidden="true"
											 role="img" focusable="false" viewBox="0 0 2 6">
											<path d="M0 0h2v6H0z"></path>
										</svg>
									</div>
								</div>
								&nbsp;<?php _e( 'Check All', 'simple-custom-post-order' ); ?></label><br>

							<?php
							$taxonomies = get_taxonomies(
								array(
									'show_ui' => true,
								),
								'objects'
							);

							foreach ( $taxonomies as $taxonomy ) {
								if ( $taxonomy->name == 'post_format' ) {
									continue;
								}
								?>
								<label>
									<div class="epsilon-toggle">
										<input class="epsilon-toggle__input" type="checkbox"
											   name="tags[]" value="<?php echo $taxonomy->name; ?>"
																			   <?php
																				if ( isset( $scporder_tags ) && is_array( $scporder_tags ) ) {
																					if ( in_array( $taxonomy->name, $scporder_tags ) ) {
																						echo 'checked="checked"';
																					}
																				}
																				?>
										>
										<div class="epsilon-toggle__items">
											<span class="epsilon-toggle__track"></span>
											<span class="epsilon-toggle__thumb"></span>
											<svg class="epsilon-toggle__off" width="6" height="6" aria-hidden="true"
												 role="img" focusable="false" viewBox="0 0 6 6">
												<path d="M3 1.5c.8 0 1.5.7 1.5 1.5S3.8 4.5 3 4.5 1.5 3.8 1.5 3 2.2 1.5 3 1.5M3 0C1.3 0 0 1.3 0 3s1.3 3 3 3 3-1.3 3-3-1.3-3-3-3z"></path>
											</svg>
											<svg class="epsilon-toggle__on" width="2" height="6" aria-hidden="true"
												 role="img" focusable="false" viewBox="0 0 2 6">
												<path d="M0 0h2v6H0z"></path>
											</svg>
										</div>
									</div>
								   &nbsp;<?php echo $taxonomy->label; ?></label><br>
									<?php
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>

		</div>
		<div id="scporder_advanved_view">
			<table class="form-table">
				<tbody>
				<tr valign="top">
					<th scope="row"><?php _e( 'Check to see advanced view of Post Types ', 'simple-custom-post-order' ); ?></th>
					<td>
						<label>
							<div class="epsilon-toggle">
								<input class="epsilon-toggle__input" type="checkbox"
									   name="show_advanced_view" value="1" <?php checked( '1', $advanced_view, 'checked="checked"' ); ?>>
								<div class="epsilon-toggle__items">
									<span class="epsilon-toggle__track"></span>
									<span class="epsilon-toggle__thumb"></span>
									<svg class="epsilon-toggle__off" width="6" height="6" aria-hidden="true"
										 role="img" focusable="false" viewBox="0 0 6 6">
										<path d="M3 1.5c.8 0 1.5.7 1.5 1.5S3.8 4.5 3 4.5 1.5 3.8 1.5 3 2.2 1.5 3 1.5M3 0C1.3 0 0 1.3 0 3s1.3 3 3 3 3-1.3 3-3-1.3-3-3-3z"></path>
									</svg>
									<svg class="epsilon-toggle__on" width="2" height="6" aria-hidden="true"
										 role="img" focusable="false" viewBox="0 0 2 6">
										<path d="M0 0h2v6H0z"></path>
									</svg>
								</div>
							</div>
							<?php echo __( 'Show advanced view of Post Types', 'simple-custom-post-order' ); ?>
						</label><br>
						<p class="description"><?php _e( 'NOTICE: This is for advanced users only.', 'simple-custom-post-order' ); ?></p>
						<!--@todo : @giucu please look into below description. -->
						<p class="description"><?php _e( 'Check to include other custom post types. You will need to update your settings after enabling this option.', 'simple-custom-post-order' ); ?></p>

					</td>
				</tr>
				</tbody>
			</table>
		</div>

		<p class="submit">
			<input type="submit" class="button-primary" name="scporder_submit" value="<?php _e( 'Update', 'simple-custom-post-order' ); ?>">
		</p>

	</form>
	<div class="scpo-reset-order">
		<h1>Want to reset the order of the posts?</h1>
		<div id="scpo_reset_select_objects">
			<table class="form-table">
				<tbody>
				<tr valign="top">
					<th scope="row"><?php _e( 'Check to reset order of Post Types', 'simple-custom-post-order' ); ?></th>
					<td>
						<?php
						foreach ( $post_types as $post_type ) {
							if ( $post_type->name == 'attachment' ) {
								continue;
							}
							?>
							<label>
								<div class="epsilon-toggle">
									<input class="epsilon-toggle__input" type="checkbox"
										   name="<?php echo $post_type->name; ?>" value="">
									<div class="epsilon-toggle__items">
										<span class="epsilon-toggle__track"></span>
										<span class="epsilon-toggle__thumb"></span>
										<svg class="epsilon-toggle__off" width="6" height="6" aria-hidden="true"
											 role="img" focusable="false" viewBox="0 0 6 6">
											<path d="M3 1.5c.8 0 1.5.7 1.5 1.5S3.8 4.5 3 4.5 1.5 3.8 1.5 3 2.2 1.5 3 1.5M3 0C1.3 0 0 1.3 0 3s1.3 3 3 3 3-1.3 3-3-1.3-3-3-3z"></path>
										</svg>
										<svg class="epsilon-toggle__on" width="2" height="6" aria-hidden="true"
											 role="img" focusable="false" viewBox="0 0 2 6">
											<path d="M0 0h2v6H0z"></path>
										</svg>
									</div>
								</div>
								&nbsp;<?php echo $post_type->label; ?></label><br>
							<?php
						}
						?>
					</td>
				</tr>
				</tbody>
			</table>

		</div>
		<div>
			<a id="reset-scp-order" class="button button-primary" href="#">Reset order</a>
			<span class="scpo-reset-response"></span>
		</div>
	</div>

	<h3>Like this simple plugin?</h3>
	<p>Make sure to <a href="https://wordpress.org/support/plugin/simple-custom-post-order/reviews/?filter=5"><strong>rate it</strong></a> and visit us at <a href="https://colorlib.com/wp/"><strong>Colorlib.com</strong></a></p>


</div>

<script>
	(function ($) {

		$("#scporder_allcheck_objects").on('click', function () {
			var items = $("#scporder_select_objects input");
			if ($(this).is(':checked'))
				$(items).prop('checked', true);
			else
				$(items).prop('checked', false);
		});

		$("#scporder_allcheck_tags").on('click', function () {
			var items = $("#scporder_select_tags input");
			if ($(this).is(':checked'))
				$(items).prop('checked', true);
			else
				$(items).prop('checked', false);
		});

		// Reset order function
		$('#reset-scp-order').click(function (e) {

			e.preventDefault();
			var btn = $(this),
				item_input = $(this).parents('.scpo-reset-order').find('input:checked'),
				items = [],
				data = {
					action: 'scpo_reset_order',
					scpo_security: '<?php echo wp_create_nonce( 'scpo-reset-order' ); ?>'
				};

			if (item_input.length > 0) {
				item_input.each(function (i, item) {
					items.push(item.name);
				});

				data['items'] = items;

				$.post("<?php echo admin_url( 'admin-ajax.php' ); ?>", data, function (response) {
					if (response) {
						btn.next('.scpo-reset-response').text(response);
						setTimeout(function(){
							location.reload(true);
						},1500);
					}
				});
			}
		});

	})(jQuery)
</script>
