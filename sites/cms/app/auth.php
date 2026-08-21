<?php
/**
 * Backend – Anmeldung und Schutz.
 *
 * Das Passwort steht nie im Klartext in einer Datei, sondern nur als Hash.
 * Zusätzlich: Schutz vor untergeschobenen Formularen (CSRF) und eine
 * Bremse gegen das Durchprobieren von Passwörtern.
 */

declare(strict_types=1);

/**
 * Pfad zur Benutzerdatei.
 */
function rc_users_file(): string {
	return RC_DATA . '/users.json';
}

/**
 * Benutzer laden. Fehlt die Datei, wird der Startbenutzer angelegt.
 */
function rc_users(): array {
	$users = rc_read_json( rc_users_file() );

	if ( ! $users ) {
		require_once RC_APP . '/defaults.php';
		$users = rc_default_users();
		rc_write_json( rc_users_file(), $users );
	}

	return $users;
}

/**
 * Ist jemand angemeldet?
 */
function rc_logged_in(): bool {
	rc_session();

	return ! empty( $_SESSION['rc_user'] );
}

/**
 * Name der angemeldeten Person.
 */
function rc_current_user(): string {
	rc_session();

	return (string) ( $_SESSION['rc_user'] ?? '' );
}

/**
 * Seite nur für Angemeldete.
 */
function rc_require_login(): void {
	if ( rc_logged_in() ) {
		return;
	}

	header( 'Location: ' . rc_url( 'login' ) );
	exit;
}

/**
 * Anmeldeversuch prüfen.
 *
 * @return string Leerer Text bei Erfolg, sonst die Meldung.
 */
function rc_login( string $user, string $password ): string {
	rc_session();

	if ( rc_throttled() ) {
		return 'Zu viele Versuche. Bitte in 15 Minuten erneut probieren.';
	}

	$users = rc_users();
	$user  = trim( $user );

	foreach ( $users as $entry ) {
		if ( ! isset( $entry['user'], $entry['hash'] ) ) {
			continue;
		}

		if ( ! hash_equals( strtolower( (string) $entry['user'] ), strtolower( $user ) ) ) {
			continue;
		}

		if ( password_verify( $password, (string) $entry['hash'] ) ) {
			session_regenerate_id( true );

			$_SESSION['rc_user'] = (string) $entry['user'];
			$_SESSION['rc_time'] = time();

			rc_throttle_reset();

			return '';
		}
	}

	rc_throttle_hit();

	return 'Benutzername oder Passwort stimmt nicht.';
}

/**
 * Nur prüfen, ob ein Passwort stimmt – ohne Anmeldung, ohne Zähler.
 *
 * Wird beim Ändern des Zugangs gebraucht.
 */
function rc_verify_password( string $user, string $password ): bool {
	foreach ( rc_users() as $entry ) {
		if ( ! isset( $entry['user'], $entry['hash'] ) ) {
			continue;
		}

		if ( strtolower( (string) $entry['user'] ) === strtolower( $user ) ) {
			return password_verify( $password, (string) $entry['hash'] );
		}
	}

	return false;
}

/**
 * Abmelden.
 */
function rc_logout(): void {
	rc_session();

	$_SESSION = array();

	if ( ini_get( 'session.use_cookies' ) ) {
		$params = session_get_cookie_params();
		setcookie( session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly'] );
	}

	session_destroy();
}

/**
 * Passwort ändern.
 */
function rc_set_password( string $user, string $password ): bool {
	$users = rc_users();

	foreach ( $users as $index => $entry ) {
		if ( strtolower( (string) $entry['user'] ) === strtolower( $user ) ) {
			$users[ $index ]['hash'] = password_hash( $password, PASSWORD_DEFAULT );

			return rc_write_json( rc_users_file(), $users );
		}
	}

	return false;
}

/**
 * Benutzernamen ändern.
 */
function rc_set_username( string $old, string $new ): bool {
	$users = rc_users();

	foreach ( $users as $index => $entry ) {
		if ( strtolower( (string) $entry['user'] ) === strtolower( $old ) ) {
			$users[ $index ]['user'] = $new;

			if ( rc_write_json( rc_users_file(), $users ) ) {
				rc_session();
				$_SESSION['rc_user'] = $new;

				return true;
			}
		}
	}

	return false;
}

/* -------------------------------------------------------------------------
 * Bremse gegen Passwortraten
 * ---------------------------------------------------------------------- */

/**
 * Datei mit den Fehlversuchen.
 */
function rc_throttle_file(): string {
	return RC_DATA . '/throttle.json';
}

/**
 * Kennung des Anschlusses – gespeichert wird nur ein Hash, keine IP.
 */
function rc_client_key(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unbekannt';

	return substr( hash( 'sha256', $ip ), 0, 16 );
}

/**
 * Zu viele Fehlversuche?
 */
function rc_throttled(): bool {
	$data = rc_read_json( rc_throttle_file() );
	$key  = rc_client_key();

	if ( ! isset( $data[ $key ] ) ) {
		return false;
	}

	$entry = $data[ $key ];

	if ( ( $entry['time'] ?? 0 ) < time() - 900 ) {
		return false;
	}

	return ( $entry['count'] ?? 0 ) >= 5;
}

/**
 * Fehlversuch merken.
 */
function rc_throttle_hit(): void {
	$data = rc_read_json( rc_throttle_file() );
	$key  = rc_client_key();
	$now  = time();

	// Alte Einträge aufräumen.
	foreach ( $data as $k => $entry ) {
		if ( ( $entry['time'] ?? 0 ) < $now - 900 ) {
			unset( $data[ $k ] );
		}
	}

	$count = isset( $data[ $key ]['count'] ) ? (int) $data[ $key ]['count'] : 0;

	$data[ $key ] = array(
		'count' => $count + 1,
		'time'  => $now,
	);

	rc_write_json( rc_throttle_file(), $data );
}

/**
 * Zähler zurücksetzen.
 */
function rc_throttle_reset(): void {
	$data = rc_read_json( rc_throttle_file() );
	unset( $data[ rc_client_key() ] );
	rc_write_json( rc_throttle_file(), $data );
}

/* -------------------------------------------------------------------------
 * Schutz der Formulare
 * ---------------------------------------------------------------------- */

/**
 * Aktuelles Token – wird pro Sitzung einmal erzeugt.
 */
function rc_csrf_token(): string {
	rc_session();

	if ( empty( $_SESSION['rc_csrf'] ) ) {
		$_SESSION['rc_csrf'] = bin2hex( random_bytes( 32 ) );
	}

	return (string) $_SESSION['rc_csrf'];
}

/**
 * Token prüfen.
 */
function rc_csrf_ok( ?string $token ): bool {
	rc_session();

	if ( empty( $_SESSION['rc_csrf'] ) || ! $token ) {
		return false;
	}

	return hash_equals( (string) $_SESSION['rc_csrf'], $token );
}
