<?php
/**
 * Plugin Name: SPARXSTAR Alias Governor
 * Plugin URI:  https://github.com/Starisian-Technologies/sparxstar-alias-governor
 * Description: Enforces alias domain on frontend, keeps wp-admin / login on primary, forces canonical to alias, and supports Mercator + WPMU DEV Defender.
 * Version:     1.0.0
 * Author:      Starisian Technologies
 * License:     GPL-2.0-or-later
 *
 * Must-Use plugin – place in wp-content/mu-plugins/
 *
 * Required wp-config.php constants
 * ----------------------------------
 *   define( 'SUNRISE', true );
 *   define( 'SPX_PRIMARY_DOMAIN',      'sparxstar.com' );
 *   define( 'SPX_DEFENDER_LOGIN_SLUG', 'secure-access' );
 *   define( 'COOKIE_DOMAIN',           SPX_PRIMARY_DOMAIN );
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SPX_PRIMARY_DOMAIN' ) ) {
	define( 'SPX_PRIMARY_DOMAIN', 'sparxstar.com' );
}

// ---------------------------------------------------------------------------
// 1. Frontend alias enforcement + canonical overrides
// ---------------------------------------------------------------------------
add_action( 'init', function () {

	if ( php_sapi_name() === 'cli' ) {
		return;
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}

	$raw_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
	$host     = '';

	if ( $raw_host !== '' && preg_match( '/^[A-Za-z0-9.-]+$/', $raw_host ) ) {
		$host = $raw_host;
	}
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';

	if ( $host === '' ) {
		return;
	}

	// Never touch admin or login pages – sunrise already enforces those.
	if (
		is_admin() ||
		strpos( $uri, 'wp-login.php' ) !== false ||
		strpos( $uri, 'wp-admin' )     !== false
	) {
		return;
	}

	// Defense-in-depth: hard-block xmlrpc on alias domains (exact path only).
	if ( $host !== SPX_PRIMARY_DOMAIN && preg_match( '#^/xmlrpc\.php$#', $uri ) ) {
		status_header( 403 );
		exit;
	}

	/**
	 * PRIMARY domain frontend → redirect to the canonical alias.
	 *
	 * Mercator maps domain → blog, so home_url() already reflects the
	 * canonical (alias) host for the current site.  If the user is
	 * browsing the primary domain for frontend content, send them to
	 * the alias with a 301.
	 */
	if ( $host === SPX_PRIMARY_DOMAIN ) {
		$home   = home_url( '/' );
		$parsed = wp_parse_url( $home );

		if (
			! empty( $parsed['host'] ) &&
			$parsed['host'] !== SPX_PRIMARY_DOMAIN &&
			$parsed['host'] !== $host
		) {
			$scheme = is_ssl() ? 'https://' : 'http://';
			wp_safe_redirect( $scheme . $parsed['host'] . $uri, 301 );
			exit;
		}

		return;
	}

	/**
	 * ALIAS domain frontend → rewrite all generated URLs + canonical to alias.
	 */
	add_filter( 'home_url', function ( $url, $path, $scheme ) use ( $host ) {
		$base           = rtrim( $scheme . '://' . $host, '/' );
		$normalized_path = ltrim( (string) $path, '/' );

		if ( $normalized_path === '' ) {
			return $base . '/';
		}

		return $base . '/' . $normalized_path;
	}, 10, 3 );

	add_filter( 'site_url', function ( $url, $path, $scheme ) use ( $host ) {
		$base           = rtrim( $scheme . '://' . $host, '/' );
		$normalized_path = ltrim( (string) $path, '/' );

		if ( $normalized_path === '' ) {
			return $base . '/';
		}

		return $base . '/' . $normalized_path;
	}, 10, 3 );

	// Core canonical (used by the default rel=canonical output).
	add_filter( 'get_canonical_url', function ( $url ) use ( $host ) {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$req_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
		return $scheme . $host . $req_uri;
	}, 10, 1 );

	// Yoast SEO canonical override.
	add_filter( 'wpseo_canonical', function ( $url ) use ( $host ) {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$req_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
		return $scheme . $host . $req_uri;
	}, 10, 1 );

}, 1 );

// ---------------------------------------------------------------------------
// 2. Sitemap URL rewriting (Yoast SEO)
// ---------------------------------------------------------------------------
add_filter( 'wpseo_sitemap_url', function ( $url ) {

	if ( php_sapi_name() === 'cli' ) {
		return $url;
	}

	$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';

	if ( $host === '' || $host === SPX_PRIMARY_DOMAIN ) {
		return $url;
	}

	// Replace the primary domain prefix only (avoids false matches in query strings).
	$primary_https = 'https://' . SPX_PRIMARY_DOMAIN;
	$primary_http  = 'http://'  . SPX_PRIMARY_DOMAIN;

	if ( strpos( $url, $primary_https ) === 0 ) {
		return 'https://' . $host . substr( $url, strlen( $primary_https ) );
	}

	if ( strpos( $url, $primary_http ) === 0 ) {
		return 'http://' . $host . substr( $url, strlen( $primary_http ) );
	}

	return $url;

}, 10, 1 );

// ---------------------------------------------------------------------------
// 3. Post-login: return user to alias if sunrise captured spx_return
// ---------------------------------------------------------------------------
add_filter( 'login_redirect', function ( $redirect_to, $requested_redirect_to, $user ) {

	$return = isset( $_GET['spx_return'] ) ? $_GET['spx_return'] : '';
	$return = is_string( $return ) ? $return : '';

	// Allow only safe hostname characters (letters, digits, dots, hyphens).
	$return = preg_replace( '/[^a-z0-9.\-]/i', '', $return );

	// Must look like a real hostname (at least one dot, no consecutive dots,
	// not a bare IP address) to guard against open-redirect misuse.
	if (
		$return !== '' &&
		$return !== SPX_PRIMARY_DOMAIN &&
		strpos( $return, '.' ) !== false &&
		strpos( $return, '..' ) === false &&
		! preg_match( '/^(\d{1,3}\.){3}\d{1,3}$/', $return )
	) {
		$scheme = is_ssl() ? 'https://' : 'http://';
		return $scheme . $return . '/';
	}

	return $redirect_to;

}, 10, 3 );
