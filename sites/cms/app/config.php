<?php
/**
 * Backend – Grundeinstellungen.
 *
 * Hier stehen Pfade und die Sitzungseinstellungen. Alles Weitere wird im
 * Dashboard gepflegt und landet in data/site.json.
 */

declare(strict_types=1);

define( 'RC_ROOT', dirname( __DIR__ ) );
define( 'RC_APP', RC_ROOT . '/app' );

/**
 * Datenordner.
 *
 * Sicherer ist ein Ordner ausserhalb von public_html. Wer das möchte, legt
 * in der Datei config.local.php eine Zeile wie diese an:
 *
 *   define( 'RC_DATA', '/home/BENUTZER/reymond-daten' );
 */
if ( file_exists( RC_ROOT . '/config.local.php' ) ) {
	require_once RC_ROOT . '/config.local.php';
}

if ( ! defined( 'RC_DATA' ) ) {
	define( 'RC_DATA', RC_ROOT . '/data' );
}

if ( ! defined( 'RC_UPLOADS' ) ) {
	define( 'RC_UPLOADS', RC_ROOT . '/uploads' );
}

define( 'RC_VERSION', '1.0.0' );

/* -------------------------------------------------------------------------
 * Basisadresse ermitteln
 *
 * Das CMS läuft sowohl direkt in public_html als auch in einem Unterordner.
 * ---------------------------------------------------------------------- */

/**
 * Der Pfadteil, unter dem das CMS liegt – z. B. '' oder '/reymond'.
 */
function rc_base_path(): string {
	static $base = null;

	if ( null !== $base ) {
		return $base;
	}

	$script = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) $_SERVER['SCRIPT_NAME'] : '';
	$base   = rtrim( str_replace( '\\', '/', dirname( $script ) ), '/' );

	if ( '/' === $base || '.' === $base ) {
		$base = '';
	}

	return $base;
}

/**
 * Eine Adresse innerhalb des CMS bauen.
 *
 * @param string $path z. B. 'musik' oder 'assets/css/style.css'.
 */
function rc_url( string $path = '' ): string {
	$path = ltrim( $path, '/' );

	return rc_base_path() . '/' . $path;
}

/**
 * Vollständige Adresse inklusive Domain – für Metaangaben.
 */
function rc_abs_url( string $path = '' ): string {
	$https  = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
		|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );
	$scheme = $https ? 'https' : 'http';
	$host   = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';

	return $scheme . '://' . $host . rc_url( $path );
}

/* -------------------------------------------------------------------------
 * Sitzung
 * ---------------------------------------------------------------------- */

/**
 * Sitzung starten – einmalig und mit sicheren Voreinstellungen.
 */
function rc_session(): void {
	if ( PHP_SESSION_ACTIVE === session_status() ) {
		return;
	}

	$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
		|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );

	session_name( 'reymond_session' );

	session_set_cookie_params(
		array(
			'lifetime' => 0,
			'path'     => rc_base_path() . '/',
			'httponly' => true,
			'secure'   => $https,
			'samesite' => 'Lax',
		)
	);

	session_start();
}

/* -------------------------------------------------------------------------
 * Kleine Helfer
 * ---------------------------------------------------------------------- */

/**
 * Text für die Ausgabe im HTML absichern.
 */
function e( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

/**
 * Wert aus einem Feld holen, mit Rückfallwert.
 *
 * @param array  $array   Datenfeld.
 * @param string $key     Schlüssel.
 * @param mixed  $default Rückfallwert.
 * @return mixed
 */
function rc_get( array $array, string $key, $default = '' ) {
	return array_key_exists( $key, $array ) ? $array[ $key ] : $default;
}

/**
 * Eine zufällige, kurze Kennung – für Abschnitte und Dateinamen.
 */
function rc_id( string $prefix = 's' ): string {
	return $prefix . substr( bin2hex( random_bytes( 6 ) ), 0, 10 );
}
