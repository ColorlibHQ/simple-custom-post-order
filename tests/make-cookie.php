<?php
// Print an admin auth cookie header for http.sh / e2e.mjs (valid two hours).
// Usage: wp eval-file tests/make-cookie.php > tests/.cookie
$user = (int) ( getenv( 'SCPO_USER' ) ?: 1 );
$exp  = time() + 2 * HOUR_IN_SECONDS;
$tok  = WP_Session_Tokens::get_instance( $user )->create( $exp );
// Both cookies: a non-SSL wp-admin request authenticates with the `auth` scheme.
echo LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie( $user, $exp, 'logged_in', $tok ) . '; ' . AUTH_COOKIE . '=' . wp_generate_auth_cookie( $user, $exp, 'auth', $tok );
