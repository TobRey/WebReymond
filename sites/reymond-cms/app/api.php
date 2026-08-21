<?php
/**
 * Reymond CMS – Schnittstellen für den Editor.
 *
 * Alles hier verlangt eine Anmeldung und ein gültiges Token. Antworten
 * kommen als JSON zurück.
 */

declare(strict_types=1);

/**
 * Antwort senden und beenden.
 */
function rc_json( array $data, int $status = 200 ): void {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
	echo json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	exit;
}

/**
 * Den gesendeten Datenkörper lesen.
 */
function rc_input(): array {
	$raw  = file_get_contents( 'php://input' );
	$data = $raw ? json_decode( (string) $raw, true ) : null;

	if ( is_array( $data ) ) {
		return $data;
	}

	return $_POST;
}

/**
 * Verteiler.
 */
function rc_api( string $action ): void {
	if ( ! rc_logged_in() ) {
		rc_json( array( 'error' => 'Nicht angemeldet.' ), 401 );
	}

	$input = rc_input();
	$token = isset( $input['csrf'] ) ? (string) $input['csrf'] : ( $_SERVER['HTTP_X_RC_TOKEN'] ?? null );

	// Lesende Aufrufe brauchen kein Token, schreibende schon.
	$writes = array( 'save', 'settings', 'tracks', 'account', 'upload', 'pages', 'reset', 'restore' );

	if ( in_array( $action, $writes, true ) && ! rc_csrf_ok( is_string( $token ) ? $token : null ) ) {
		rc_json( array( 'error' => 'Sitzung abgelaufen. Bitte neu anmelden.' ), 403 );
	}

	switch ( $action ) {
		case 'page':
			rc_api_page();
			break;

		case 'render':
			rc_api_render( $input );
			break;

		case 'save':
			rc_api_save( $input );
			break;

		case 'settings':
			rc_api_settings( $input );
			break;

		case 'tracks':
			rc_api_tracks( $input );
			break;

		case 'pages':
			rc_api_pages( $input );
			break;

		case 'account':
			rc_api_account( $input );
			break;

		case 'upload':
			rc_api_upload();
			break;

		case 'reset':
			rc_api_reset();
			break;

		case 'export':
			rc_api_export();
			break;

		default:
			rc_json( array( 'error' => 'Unbekannter Aufruf.' ), 404 );
	}
}

/**
 * Eine Seite als JSON – Grundlage für den Editor.
 */
function rc_api_page(): void {
	$slug = isset( $_GET['slug'] ) ? (string) $_GET['slug'] : rc_home_slug();
	$page = rc_page( $slug );

	if ( ! $page ) {
		rc_json( array( 'error' => 'Seite nicht gefunden.' ), 404 );
	}

	$site = rc_site();

	rc_json(
		array(
			'slug'     => $slug,
			'page'     => $page,
			'pages'    => array_map(
				static function ( $p ) {
					return array( 'title' => rc_get( $p, 'title' ) );
				},
				$site['pages']
			),
			'types'    => rc_section_types(),
			'style'    => rc_style_fields(),
			'advanced' => rc_advanced_fields(),
		)
	);
}

/**
 * Einen Abschnitt zeichnen – der Editor tauscht damit das HTML aus.
 */
function rc_api_render( array $input ): void {
	$section = isset( $input['section'] ) ? (array) $input['section'] : array();

	if ( ! $section ) {
		rc_json( array( 'error' => 'Kein Abschnitt übergeben.' ), 400 );
	}

	$GLOBALS['rc_edit_mode'] = true;

	rc_json( array( 'html' => rc_render_section( $section ) ) );
}

/**
 * Eine Seite speichern.
 */
function rc_api_save( array $input ): void {
	$slug     = isset( $input['slug'] ) ? (string) $input['slug'] : '';
	$sections = isset( $input['sections'] ) ? (array) $input['sections'] : array();

	$site = rc_site();

	if ( ! isset( $site['pages'][ $slug ] ) ) {
		rc_json( array( 'error' => 'Seite nicht gefunden.' ), 404 );
	}

	$clean = array();

	foreach ( $sections as $section ) {
		$type = (string) rc_get( (array) $section, 'type' );

		if ( ! isset( rc_section_types()[ $type ] ) ) {
			continue;
		}

		$clean[] = array(
			'id'    => (string) ( rc_get( (array) $section, 'id' ) ?: rc_id() ),
			'type'  => $type,
			'props' => rc_clean_props( (array) rc_get( (array) $section, 'props', array() ) ),
		);
	}

	$site['pages'][ $slug ]['sections'] = $clean;

	if ( isset( $input['seo'] ) && is_array( $input['seo'] ) ) {
		$site['pages'][ $slug ]['seo'] = array(
			'title'       => rc_text( rc_get( $input['seo'], 'title' ) ),
			'description' => rc_text( rc_get( $input['seo'], 'description' ) ),
		);
	}

	if ( ! rc_save_site( $site ) ) {
		rc_json( array( 'error' => 'Speichern nicht möglich – Schreibrechte prüfen.' ), 500 );
	}

	rc_json( array( 'ok' => true, 'saved' => gmdate( 'H:i' ) ) );
}

/**
 * Werte eines Abschnitts säubern.
 *
 * @param array $props Rohwerte.
 * @return array
 */
function rc_clean_props( array $props ): array {
	$clean = array();

	foreach ( $props as $key => $value ) {
		$key = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $key );

		if ( '' === $key ) {
			continue;
		}

		if ( is_array( $value ) ) {
			$clean[ $key ] = rc_clean_props( $value );
		} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			$clean[ $key ] = $value;
		} else {
			$value = (string) $value;

			// Fliesstext darf ausgewählte Auszeichnungen behalten.
			$clean[ $key ] = ( 'body' === $key ) ? rc_kses( $value ) : rc_text( $value );
		}
	}

	return $clean;
}

/**
 * Einfachen Text säubern.
 *
 * @param mixed $value Eingabe.
 */
function rc_text( $value ): string {
	$value = is_scalar( $value ) ? (string) $value : '';

	return trim( strip_tags( $value ) );
}

/**
 * Einstellungen speichern.
 */
function rc_api_settings( array $input ): void {
	$site     = rc_site();
	$settings = $site['settings'];
	$data     = (array) rc_get( $input, 'settings', array() );

	foreach ( array( 'siteName', 'tagline', 'email', 'phone', 'base', 'mailTo' ) as $key ) {
		if ( isset( $data[ $key ] ) ) {
			$settings[ $key ] = rc_text( $data[ $key ] );
		}
	}

	if ( isset( $data['socials'] ) && is_array( $data['socials'] ) ) {
		foreach ( array( 'instagram', 'soundcloud', 'spotify', 'tiktok' ) as $key ) {
			$url = rc_text( rc_get( $data['socials'], $key ) );

			if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
				$url = 'https://' . $url;
			}

			$settings['socials'][ $key ] = $url;
		}
	}

	if ( isset( $data['effects'] ) && is_array( $data['effects'] ) ) {
		foreach ( array( 'grain', 'cursor', 'preloader', 'vignette' ) as $key ) {
			$settings['effects'][ $key ] = ! empty( $data['effects'][ $key ] );
		}
	}

	$site['settings'] = $settings;

	if ( ! rc_save_site( $site ) ) {
		rc_json( array( 'error' => 'Speichern nicht möglich.' ), 500 );
	}

	rc_json( array( 'ok' => true ) );
}

/**
 * Titel für den Player speichern.
 */
function rc_api_tracks( array $input ): void {
	$site   = rc_site();
	$tracks = array();

	foreach ( (array) rc_get( $input, 'tracks', array() ) as $track ) {
		$track = (array) $track;

		if ( '' === rc_text( rc_get( $track, 'title' ) ) ) {
			continue;
		}

		$tracks[] = array(
			'title'    => rc_text( rc_get( $track, 'title' ) ),
			'tag'      => rc_text( rc_get( $track, 'tag' ) ),
			'duration' => rc_text( rc_get( $track, 'duration' ) ),
			'audio'    => rc_text( rc_get( $track, 'audio' ) ),
			'cover'    => rc_text( rc_get( $track, 'cover' ) ),
		);
	}

	$site['tracks'] = $tracks;

	if ( ! rc_save_site( $site ) ) {
		rc_json( array( 'error' => 'Speichern nicht möglich.' ), 500 );
	}

	rc_json( array( 'ok' => true, 'tracks' => $tracks ) );
}

/**
 * Seiten anlegen, umbenennen, löschen.
 */
function rc_api_pages( array $input ): void {
	$site = rc_site();
	$op   = (string) rc_get( $input, 'op' );
	$slug = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) rc_get( $input, 'slug' ) ) );

	if ( 'add' === $op ) {
		$title = rc_text( rc_get( $input, 'title' ) );
		$slug  = $slug ? $slug : preg_replace( '/[^a-z0-9-]/', '', strtolower( str_replace( ' ', '-', $title ) ) );

		if ( '' === $title || '' === $slug ) {
			rc_json( array( 'error' => 'Bitte einen Namen angeben.' ), 400 );
		}

		if ( isset( $site['pages'][ $slug ] ) ) {
			rc_json( array( 'error' => 'Diese Seite gibt es schon.' ), 400 );
		}

		$site['pages'][ $slug ] = array(
			'title'    => $title,
			'home'     => false,
			'seo'      => array( 'title' => $title, 'description' => '' ),
			'sections' => array(
				rc_new_section( 'pagehead' ),
				rc_new_section( 'cta' ),
			),
		);
	} elseif ( 'rename' === $op ) {
		if ( ! isset( $site['pages'][ $slug ] ) ) {
			rc_json( array( 'error' => 'Seite nicht gefunden.' ), 404 );
		}

		$site['pages'][ $slug ]['title'] = rc_text( rc_get( $input, 'title' ) );
	} elseif ( 'delete' === $op ) {
		if ( ! isset( $site['pages'][ $slug ] ) ) {
			rc_json( array( 'error' => 'Seite nicht gefunden.' ), 404 );
		}

		if ( ! empty( $site['pages'][ $slug ]['home'] ) ) {
			rc_json( array( 'error' => 'Die Startseite kann nicht gelöscht werden.' ), 400 );
		}

		unset( $site['pages'][ $slug ] );
	} elseif ( 'order' === $op ) {
		$order = (array) rc_get( $input, 'order', array() );
		$new   = array();

		foreach ( $order as $key ) {
			$key = (string) $key;

			if ( isset( $site['pages'][ $key ] ) ) {
				$new[ $key ] = $site['pages'][ $key ];
			}
		}

		foreach ( $site['pages'] as $key => $page ) {
			if ( ! isset( $new[ $key ] ) ) {
				$new[ $key ] = $page;
			}
		}

		$site['pages'] = $new;
	} else {
		rc_json( array( 'error' => 'Unbekannter Vorgang.' ), 400 );
	}

	if ( ! rc_save_site( $site ) ) {
		rc_json( array( 'error' => 'Speichern nicht möglich.' ), 500 );
	}

	rc_json( array( 'ok' => true, 'pages' => array_keys( $site['pages'] ) ) );
}

/**
 * Zugang ändern.
 */
function rc_api_account( array $input ): void {
	$current = (string) rc_get( $input, 'current' );
	$user    = rc_current_user();

	if ( ! rc_verify_password( $user, $current ) ) {
		rc_json( array( 'error' => 'Das bisherige Passwort stimmt nicht.' ), 403 );
	}

	$newUser = rc_text( rc_get( $input, 'user' ) );
	$newPass = (string) rc_get( $input, 'password' );

	if ( '' !== $newPass ) {
		if ( strlen( $newPass ) < 10 ) {
			rc_json( array( 'error' => 'Das neue Passwort braucht mindestens 10 Zeichen.' ), 400 );
		}

		rc_set_password( $user, $newPass );
	}

	if ( '' !== $newUser && strtolower( $newUser ) !== strtolower( $user ) ) {
		rc_set_username( $user, $newUser );
	}

	rc_json( array( 'ok' => true ) );
}

/**
 * Datei hochladen (Bild oder Ton).
 */
function rc_api_upload(): void {
	require_once RC_APP . '/upload.php';

	$result = rc_handle_upload();

	if ( isset( $result['error'] ) ) {
		rc_json( $result, 400 );
	}

	rc_json( $result );
}

/**
 * Inhalte auf den Auslieferungsstand zurücksetzen.
 */
function rc_api_reset(): void {
	require_once RC_APP . '/defaults.php';

	$site           = rc_default_site();
	$current        = rc_site();
	$site['tracks'] = $current['tracks']; // Musik bleibt erhalten.

	if ( ! rc_save_site( $site ) ) {
		rc_json( array( 'error' => 'Zurücksetzen nicht möglich.' ), 500 );
	}

	rc_json( array( 'ok' => true ) );
}

/**
 * Alle Inhalte als Datei herunterladen.
 */
function rc_api_export(): void {
	$site = rc_site();

	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="reymond-inhalte-' . gmdate( 'Y-m-d' ) . '.json"' );
	echo json_encode( $site, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	exit;
}
