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
 *
 * NOTE: Do NOT define COOKIE_DOMAIN when using Mercator.
 * Mercator manages cookie domains internally; defining COOKIE_DOMAIN will cause
 * the error: "The constant COOKIE_DOMAIN is defined (probably in wp-config.php). Please
 * remove or comment out that define() line."
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Proxy fix: normalize HTTP_HOST and HTTPS from forwarded headers so that
// Alias Governor sees the real domain and SSL status behind
// Cloudflare → Nginx → Varnish → Apache stacks.
// ---------------------------------------------------------------------------
if ( php_sapi_name() !== 'cli' ) {
	if ( isset( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
		// When chained proxies append hosts, take only the first (original) value.
		$spx_fwd_host = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_HOST'], 2 )[0];
		$spx_fwd_host = preg_replace( '/[^A-Za-z0-9.-]/', '', trim( $spx_fwd_host ) );
		if ( $spx_fwd_host !== '' ) {
			$_SERVER['HTTP_HOST'] = $spx_fwd_host;
		}
	}
	if (
		isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) &&
		(string) $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'
	) {
		$_SERVER['HTTPS'] = 'on';
	}
}

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

	// Use WordPress's own native domain for this site — works for any multisite
	// topology (subdomain, subdirectory, any network domain) without constants.
	$spx_site     = get_site();
	$native_host  = $spx_site ? strtolower( $spx_site->domain ) : strtolower( SPX_PRIMARY_DOMAIN );
	$current_host = strtolower( $host );
	$is_canonical = ( $current_host === $native_host );

	// Never touch admin or login pages – sunrise already enforces those.
	if (
		is_admin() ||
		strpos( $uri, 'wp-login.php' ) !== false ||
		strpos( $uri, 'wp-admin' )     !== false
	) {
		return;
	}

	// Defense-in-depth: hard-block xmlrpc on alias domains (exact path only).
	if ( ! $is_canonical && preg_match( '#^/xmlrpc\.php$#', $uri ) ) {
		wp_die(
			'XML-RPC endpoint is disabled on this domain.',
			'Forbidden',
			array( 'response' => 403 )
		);
	}

	/**
	 * NATIVE (canonical) domain frontend → redirect to the mapped alias.
	 *
	 * Mercator maps domain → blog, so home_url() already reflects the
	 * canonical (alias) host for the current site.  If the user is
	 * browsing the native domain for frontend content, send them to
	 * the alias with a 301.
	 */
	if ( $is_canonical ) {
		$home   = home_url( '/' );
		$parsed = wp_parse_url( $home );

		if (
			! empty( $parsed['host'] ) &&
			$parsed['host'] !== $native_host &&
			$parsed['host'] !== $current_host
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
		return spx_swap_url_host( $url, $host );
	}, 10, 3 );

	add_filter( 'site_url', function ( $url, $path, $scheme ) use ( $host ) {
		return spx_swap_url_host( $url, $host );
	}, 10, 3 );

	// Core canonical (used by the default rel=canonical output).
	add_filter( 'get_canonical_url', function ( $url ) use ( $host ) {
		$scheme  = is_ssl() ? 'https://' : 'http://';
		$req_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
		$path    = parse_url( $req_uri, PHP_URL_PATH );
		if ( $path === null || $path === '' ) {
			$path = '/';
		}
		return $scheme . $host . $path;
	}, 10, 1 );

	// Yoast SEO canonical override.
	add_filter( 'wpseo_canonical', function ( $url ) use ( $host ) {
		$scheme  = is_ssl() ? 'https://' : 'http://';
		$req_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
		$path    = parse_url( $req_uri, PHP_URL_PATH );
		if ( $path === null || $path === '' ) {
			$path = '/';
		}
		return $scheme . $host . $path;
	}, 10, 1 );

}, 1 );

// ---------------------------------------------------------------------------
// 2. Deep Asset Filters
// Replaces the site's native WordPress domain with the current alias for all
// standard WP asset hooks.  Uses get_site()->domain so it works for any
// multisite topology without requiring domain constants.
// ---------------------------------------------------------------------------

/**
 * Reassemble a URL replacing only its host component.
 *
 * Parses $url into components, swaps the host, and rebuilds the string so
 * that port, path, query, and fragment are preserved exactly as WordPress
 * produced them.  Returns the original $url unchanged when it cannot be
 * parsed or contains no host.
 *
 * @param string $url  The URL to rewrite.
 * @param string $host The replacement host (already validated).
 * @return string
 */
function spx_swap_url_host( $url, $host ) {
	$parsed = wp_parse_url( $url );
	if ( $parsed === false || ! is_array( $parsed ) || empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
		return $url;
	}
	$out = $parsed['scheme'] . '://';
	if ( ! empty( $parsed['user'] ) ) {
		$out .= $parsed['user'];
		if ( ! empty( $parsed['pass'] ) ) {
			$out .= ':' . $parsed['pass'];
		}
		$out .= '@';
	}
	$out .= $host;
	if ( ! empty( $parsed['port'] ) ) {
		$out .= ':' . $parsed['port'];
	}
	$out .= isset( $parsed['path'] ) ? $parsed['path'] : '';
	if ( ! empty( $parsed['query'] ) ) {
		$out .= '?' . $parsed['query'];
	}
	if ( ! empty( $parsed['fragment'] ) ) {
		$out .= '#' . $parsed['fragment'];
	}
	return $out;
}

/**
 * Helper: replace the site's native WordPress domain with the current alias in a simple string URL.
 *
 * The native host is read from get_site()->domain so the function works
 * correctly for any multisite topology without requiring the SPX_PRIMARY_DOMAIN
 * constant.
 *
 * Returns the original value unchanged when:
 *  - running under CLI / WP-CLI
 *  - inside wp-admin
 *  - the URL is empty
 *  - the URL belongs to the uploads subdomain
 *  - the current host is the native domain or cannot be determined
 *
 * @param string $url
 * @return string
 */
function spx_replace_asset_domain( $url ) {
	if ( php_sapi_name() === 'cli' || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return $url;
	}
	if ( is_admin() || empty( $url ) ) {
		return $url;
	}
	$spx_site    = get_site();
	$native_host = $spx_site ? strtolower( $spx_site->domain ) : strtolower( SPX_PRIMARY_DOMAIN );
	// Skip assets that are explicitly on the uploads subdomain.
	if ( stripos( $url, 'uploads.' . $native_host ) !== false ) {
		return $url;
	}
	// Fast-path: skip processing if the native domain isn't present at all.
	if ( stripos( $url, $native_host ) === false ) {
		return $url;
	}
	$raw_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
	$host     = ( $raw_host !== '' && preg_match( '/^[A-Za-z0-9.-]+$/', $raw_host ) ) ? strtolower( $raw_host ) : '';
	if ( $host === '' || $host === $native_host ) {
		return $url;
	}
	return str_replace(
		[
			'http://'      . $native_host,
			'https://'     . $native_host,
			'//'           . $native_host,
			'http://www.'  . $native_host,
			'https://www.' . $native_host,
			'//www.'       . $native_host,
		],
		'https://' . $host,
		$url
	);
}

// Filters whose first argument is a plain string URL.
$spx_asset_string_filters = [
	'wp_get_attachment_url',
	'content_url',
	'plugins_url',
	'theme_file_uri',
	'stylesheet_directory_uri',
	'template_directory_uri',
	'script_loader_src',
	'style_loader_src',
	'rest_url',
];

foreach ( $spx_asset_string_filters as $spx_filter ) {
	add_filter( $spx_filter, 'spx_replace_asset_domain', 99 );
}
unset( $spx_filter, $spx_asset_string_filters );

// style_loader_tag / script_loader_tag receive the full HTML tag string.
// Only rewrite href/src attribute values to avoid corrupting integrity
// hashes, inline JSON, or other embedded data.
function spx_replace_asset_domain_in_tag( $tag ) {
	if ( php_sapi_name() === 'cli' || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return $tag;
	}
	if ( is_admin() || empty( $tag ) ) {
		return $tag;
	}
	// Fast-path: skip if the native domain isn't present in the tag at all.
	$spx_site_tag = get_site();
	if ( $spx_site_tag && stripos( $tag, $spx_site_tag->domain ) === false ) {
		return $tag;
	}
	return preg_replace_callback(
		'/(href|src)(\s*=\s*)(["\'])((?:(?!\3).)*)\3/i',
		function ( $matches ) {
			return $matches[1] . $matches[2] . $matches[3]
				. spx_replace_asset_domain( $matches[4] )
				. $matches[3];
		},
		$tag
	);
}

add_filter( 'style_loader_tag', 'spx_replace_asset_domain_in_tag', 99 );
add_filter( 'script_loader_tag', 'spx_replace_asset_domain_in_tag', 99 );

// wp_get_attachment_image_src returns [ url, width, height, is_intermediate ] or false.
add_filter( 'wp_get_attachment_image_src', function ( $image ) {
	if ( ! is_array( $image ) || empty( $image[0] ) ) {
		return $image;
	}
	$image[0] = spx_replace_asset_domain( $image[0] );
	return $image;
}, 99 );

// wp_calculate_image_srcset returns [ width => [ 'url' => ..., 'descriptor' => ..., 'value' => ... ], ... ].
add_filter( 'wp_calculate_image_srcset', function ( $sources ) {
	if ( php_sapi_name() === 'cli' || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return $sources;
	}
	if ( ! is_array( $sources ) ) {
		return $sources;
	}
	foreach ( $sources as $width => $source ) {
		if ( isset( $source['url'] ) ) {
			$sources[ $width ]['url'] = spx_replace_asset_domain( $source['url'] );
		}
	}
	return $sources;
}, 99 );

// ---------------------------------------------------------------------------
// 3. HTML-Only Output Buffer
// The final safety net for hard-coded URLs in post_content or theme files.
// ---------------------------------------------------------------------------
add_action( 'template_redirect', function () {

	if ( php_sapi_name() === 'cli' || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	// Guards: do not buffer non-frontend or non-HTML requests.
	if ( is_admin() || wp_doing_ajax() || is_feed() ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	$raw_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
	$host     = ( $raw_host !== '' && preg_match( '/^[A-Za-z0-9.-]+$/', $raw_host ) ) ? strtolower( $raw_host ) : '';

	$spx_site    = get_site();
	$native_host = $spx_site ? strtolower( $spx_site->domain ) : strtolower( SPX_PRIMARY_DOMAIN );

	if ( $host === '' || $host === $native_host ) {
		return;
	}

	ob_start( function ( $html ) use ( $host, $native_host ) {
		// Only run on actual HTML documents; skip REST, XML feeds, JSON, etc.
		if ( stripos( $html, '<!DOCTYPE' ) === false && stripos( $html, '<html' ) === false ) {
			return $html;
		}
		// Replace all native-domain URL variants with the alias.
		// None of these prefixes match 'uploads.' . $native_host, so
		// uploads subdomain URLs are inherently preserved.
		return str_replace(
			[
				'http://'      . $native_host,
				'https://'     . $native_host,
				'//'           . $native_host,
				'http://www.'  . $native_host,
				'https://www.' . $native_host,
				'//www.'       . $native_host,
			],
			'https://' . $host,
			$html
		);
	} );

} );

// ---------------------------------------------------------------------------
// 4. Sitemap URL rewriting (Yoast SEO)
// ---------------------------------------------------------------------------
add_filter( 'wpseo_sitemap_url', function ( $url ) {

	if ( php_sapi_name() === 'cli' ) {
		return $url;
	}

	$raw_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
	$host     = strtolower( $raw_host );

	$spx_site    = get_site();
	$native_host = $spx_site ? strtolower( $spx_site->domain ) : strtolower( SPX_PRIMARY_DOMAIN );

	if ( $host === '' || $host === $native_host ) {
		return $url;
	}

	// Replace the native domain prefix only (avoids false matches in query strings).
	$primary_https = 'https://' . $native_host;
	$primary_http  = 'http://'  . $native_host;

	if ( strpos( $url, $primary_https ) === 0 ) {
		return 'https://' . $host . substr( $url, strlen( $primary_https ) );
	}

	if ( strpos( $url, $primary_http ) === 0 ) {
		return 'http://' . $host . substr( $url, strlen( $primary_http ) );
	}

	return $url;

}, 10, 1 );

// ---------------------------------------------------------------------------
// 5. Post-login: return user to alias if sunrise captured spx_return
// ---------------------------------------------------------------------------
add_filter( 'login_redirect', function ( $redirect_to, $requested_redirect_to, $user ) {

	$return = isset( $_GET['spx_return'] ) ? wp_unslash( $_GET['spx_return'] ) : '';
	$return = is_string( $return ) ? $return : '';

	// Allow only safe hostname characters (letters, digits, dots, hyphens).
	$return = preg_replace( '/[^a-z0-9.\-]/i', '', $return );
	$return = trim( $return );

	// Restrict redirects to an explicit whitelist of allowed alias hostnames.
	// Site owners can filter this list via 'spx_allowed_login_return_hosts'.
	$allowed_hosts = apply_filters( 'spx_allowed_login_return_hosts', array() );
	if ( ! is_array( $allowed_hosts ) ) {
		$allowed_hosts = array();
	}
	$allowed_hosts = array_map( 'strtolower', array_filter( array_map( 'trim', $allowed_hosts ) ) );

	$return_lc = strtolower( $return );

	if ( $return_lc !== '' && in_array( $return_lc, $allowed_hosts, true ) ) {
		$scheme = is_ssl() ? 'https://' : 'http://';
		return $scheme . $return_lc . '/';
	}

	return $redirect_to;

}, 10, 3 );
