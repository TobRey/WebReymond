<?php
/**
 * Reymond CMS – Einstiegspunkt.
 *
 * Jede Anfrage landet hier. Die Adresse entscheidet, was passiert:
 *
 *   /              Startseite
 *   /musik         weitere Seiten
 *   /login         Anmeldung
 *   /editor        Baukasten (nur angemeldet)
 *   /dashboard     Einstellungen (nur angemeldet)
 *   /api/…         Schnittstellen des Editors
 */

declare(strict_types=1);

// Beim eingebauten PHP-Server (nur für Tests) echte Dateien direkt ausliefern.
if ( 'cli-server' === PHP_SAPI ) {
	$file = __DIR__ . parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );

	if ( is_file( $file ) && '.php' !== substr( $file, -4 ) ) {
		return false;
	}
}

require_once __DIR__ . '/app/config.php';
require_once RC_APP . '/store.php';
require_once RC_APP . '/auth.php';
require_once RC_APP . '/sections.php';
require_once RC_APP . '/render.php';
require_once RC_APP . '/layout.php';

/* -------------------------------------------------------------------------
 * Adresse zerlegen
 * ---------------------------------------------------------------------- */

$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
$path    = parse_url( $request, PHP_URL_PATH );
$path    = is_string( $path ) ? $path : '/';

// Den Ordner abziehen, in dem das CMS liegt.
$base = rc_base_path();

if ( '' !== $base && 0 === strpos( $path, $base ) ) {
	$path = substr( $path, strlen( $base ) );
}

$route = trim( (string) $path, '/' );

// Alternative ohne saubere Adressen: index.php?p=musik
if ( '' === $route && isset( $_GET['p'] ) ) {
	$route = trim( (string) $_GET['p'], '/' );
}

/* -------------------------------------------------------------------------
 * Feste Adressen
 * ---------------------------------------------------------------------- */

switch ( $route ) {
	case 'login':
		require RC_APP . '/views/login.php';
		exit;

	case 'logout':
		rc_logout();
		header( 'Location: ' . rc_url( 'login' ) );
		exit;

	case 'editor':
		rc_require_login();
		require RC_APP . '/views/editor.php';
		exit;

	case 'dashboard':
		rc_require_login();
		require RC_APP . '/views/dashboard.php';
		exit;

	case 'anfrage':
		require RC_APP . '/contact.php';
		rc_handle_contact();
		exit;
}

// Schnittstellen des Editors.
if ( 0 === strpos( $route, 'api/' ) ) {
	require RC_APP . '/api.php';
	rc_api( substr( $route, 4 ) );
	exit;
}

/* -------------------------------------------------------------------------
 * Seiten der Website
 * ---------------------------------------------------------------------- */

$slug = '' === $route ? rc_home_slug() : $route;
$page = rc_page( $slug );

if ( ! $page ) {
	http_response_code( 404 );
	$page = array(
		'title'    => 'Nicht gefunden',
		'slug'     => '404',
		'sections' => array(
			array(
				'id'    => '404',
				'type'  => 'cta',
				'props' => array(
					'bg'      => 'schwarz',
					'space'   => 'gross',
					'title'   => '404',
					'outline' => 'verloren',
					'glow'    => true,
					'btn'     => array( 'label' => 'Zur Startseite', 'url' => '', 'style' => 'voll' ),
				),
			),
		),
	);
}

$page['slug'] = $slug;

// Im Editor wird dieselbe Seite gezeichnet, nur mit Kennungen an den
// Abschnitten und ohne Ladevorhang.
$GLOBALS['rc_edit_mode'] = ( isset( $_GET['rc_edit'] ) && '1' === $_GET['rc_edit'] && rc_logged_in() );

rc_head( $page );
echo rc_render_page( $page );
rc_footer();
