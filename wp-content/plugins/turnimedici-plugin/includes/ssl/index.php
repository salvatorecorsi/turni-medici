<?php

/**
 * Force SSL — forza https su tutto il sito, sul modello di Really Simple SSL:
 * redirect 301, home/siteurl riscritti in https, mixed content fixer in output buffer.
 * Si attiva da solo sugli host non locali; la costante `SC_FORCE_SSL` (true/false)
 * forza on/off a prescindere dall'host.
 */

function sc_ssl_detect_forwarded_https() {
	if ( is_ssl() ) {
		return;
	}
	$forwarded_proto = strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' );
	$forwarded_ssl   = strtolower( $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '' );
	if ( 'https' === $forwarded_proto || 'on' === $forwarded_ssl ) {
		$_SERVER['HTTPS'] = 'on';
	}
}

function sc_ssl_is_local_host() {
	$host = strtolower( $_SERVER['HTTP_HOST'] ?? '' );
	$host = trim( preg_replace( '/:\d+$/', '', $host ), '[]' );
	if ( '' === $host || in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
		return true;
	}
	foreach ( array( '.localhost', '.local', '.test' ) as $suffix ) {
		if ( str_ends_with( $host, $suffix ) ) {
			return true;
		}
	}
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
	return false;
}

function sc_ssl_is_enabled() {
	if ( defined( 'SC_FORCE_SSL' ) ) {
		return (bool) SC_FORCE_SSL;
	}
	return ! sc_ssl_is_local_host();
}

function sc_ssl_force_https_scheme( $url ) {
	return set_url_scheme( $url, 'https' );
}

function sc_ssl_redirect_to_https() {
	if ( is_ssl() || wp_doing_cron() || empty( $_SERVER['HTTP_HOST'] ) ) {
		return;
	}
	$target = 'https://' . $_SERVER['HTTP_HOST'] . ( $_SERVER['REQUEST_URI'] ?? '/' );
	wp_redirect( esc_url_raw( $target ), 301 );
	exit;
}

function sc_ssl_fix_mixed_content( $html ) {
	$host = $_SERVER['HTTP_HOST'] ?? '';
	if ( '' === $host ) {
		return $html;
	}
	return str_replace(
		array( 'http://' . $host, 'http:\/\/' . $host ),
		array( 'https://' . $host, 'https:\/\/' . $host ),
		$html
	);
}

sc_ssl_detect_forwarded_https();

if ( sc_ssl_is_enabled() ) {
	force_ssl_admin( true );
	add_filter( 'option_home', 'sc_ssl_force_https_scheme' );
	add_filter( 'option_siteurl', 'sc_ssl_force_https_scheme' );
	add_action( 'init', 'sc_ssl_redirect_to_https', 1 );
	add_action( 'template_redirect', function () {
		ob_start( 'sc_ssl_fix_mixed_content' );
	}, 0 );
}
