<?php
/**
 * SPARXSTAR Alias Governor – Sunrise (Enterprise Hardened)
 *
 * Runs before WordPress loads (no WP functions available here).
 *
 * Responsibilities:
 *   - Redirect admin / login requests arriving on an alias domain back to the
 *     primary domain, preserving the original URI and capturing the alias host
 *     as `spx_return` so the MU-plugin can send the user back after login.
 *   - Load Mercator's own sunrise mapping (works whether Mercator is installed
 *     as a mu-plugin or a regular plugin).
 *
 * Required wp-config.php constants
 * ----------------------------------
 *   define( 'SUNRISE', true );
 *   define( 'SPX_PRIMARY_DOMAIN',      'sparxstar.com' );
 *   define( 'SPX_DEFENDER_LOGIN_SLUG', 'secure-access' ); // custom login path slug
 *
 * Optional – hard-block alias login (uncomment the 403 lines below):
 *   Replace the redirect block with: header('HTTP/1.1 403 Forbidden'); exit;
 */

defined( 'SUNRISE' ) || define( 'SUNRISE', true );

if ( ! defined( 'SPX_PRIMARY_DOMAIN' ) ) {
	define( 'SPX_PRIMARY_DOMAIN', 'sparxstar.com' );
}

if ( ! defined( 'SPX_DEFENDER_LOGIN_SLUG' ) ) {
	define( 'SPX_DEFENDER_LOGIN_SLUG', '' );
}

if ( php_sapi_name() !== 'cli' ) {

	$spx_host = isset( $_SERVER['HTTP_HOST'] )    ? $_SERVER['HTTP_HOST']    : '';
	$spx_uri  = isset( $_SERVER['REQUEST_URI'] )  ? $_SERVER['REQUEST_URI']  : '/';

	if ( $spx_host !== '' ) {

		$spx_def_slug = trim( (string) SPX_DEFENDER_LOGIN_SLUG, '/' );

		$spx_is_sensitive =
			( strpos( $spx_uri, 'wp-admin' )     !== false ) ||
			( strpos( $spx_uri, 'wp-login.php' ) !== false ) ||
			(
				$spx_def_slug !== '' &&
				(bool) preg_match( '#^/' . preg_quote( $spx_def_slug, '#' ) . '(/|$)#', $spx_uri )
			);

		if (
			$spx_is_sensitive &&
			$spx_host !== SPX_PRIMARY_DOMAIN &&
			stripos( $spx_uri, 'spx_return=' ) === false
		) {

			/*
			 * Hard-block mode (403): uncomment the two lines below and remove
			 * the redirect block if you never want alias login attempts forwarded.
			 *
			 * header( 'HTTP/1.1 403 Forbidden' );
			 * exit;
			 */

			// Cloudflare / proxy-safe HTTPS detection.
			$spx_cf_visitor  = isset( $_SERVER['HTTP_CF_VISITOR'] ) ? $_SERVER['HTTP_CF_VISITOR'] : '';
			$spx_cf_decoded  = $spx_cf_visitor !== '' ? json_decode( $spx_cf_visitor, true ) : null;
			$spx_cf_scheme   = is_array( $spx_cf_decoded ) && isset( $spx_cf_decoded['scheme'] ) ? $spx_cf_decoded['scheme'] : '';

			$spx_https =
				( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ||
				( (string) ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '' ) === 'https' ) ||
				( $spx_cf_scheme === 'https' );

			$spx_scheme      = $spx_https ? 'https://' : 'http://';
			$spx_return_host = rawurlencode( $spx_host );
			$spx_glue        = ( strpos( $spx_uri, '?' ) === false ) ? '?' : '&';

			header(
				'Location: ' . $spx_scheme . SPX_PRIMARY_DOMAIN . $spx_uri
				. $spx_glue . 'spx_return=' . $spx_return_host,
				true,
				307
			);
			exit;
		}
	}
}

/**
 * Load Mercator's sunrise domain mapping.
 * Both paths are tried; only the existing one will load.
 */
$spx_mercator_mu = WP_CONTENT_DIR . '/mu-plugins/mercator/sunrise.php';
if ( file_exists( $spx_mercator_mu ) ) {
	require_once $spx_mercator_mu;
}

$spx_mercator_pl = WP_CONTENT_DIR . '/plugins/mercator/sunrise.php';
if ( file_exists( $spx_mercator_pl ) ) {
	require_once $spx_mercator_pl;
}
