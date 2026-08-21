<?php
/**
 * Backend – Datenhaltung.
 *
 * Alles liegt in zwei JSON-Dateien: site.json (Inhalte und Einstellungen)
 * und users.json (Zugang). Keine Datenbank nötig – das läuft auf jedem
 * einfachen Webhosting.
 *
 * Geschrieben wird immer zuerst in eine Zwischendatei und dann umbenannt.
 * So bleibt die Datei auch bei einem Abbruch mitten im Schreiben heil.
 */

declare(strict_types=1);

/**
 * Eine JSON-Datei lesen.
 */
function rc_read_json( string $file, array $fallback = array() ): array {
	if ( ! is_readable( $file ) ) {
		return $fallback;
	}

	$raw = file_get_contents( $file );

	if ( false === $raw || '' === trim( $raw ) ) {
		return $fallback;
	}

	$data = json_decode( $raw, true );

	return is_array( $data ) ? $data : $fallback;
}

/**
 * Eine JSON-Datei schreiben – atomar und lesbar formatiert.
 */
function rc_write_json( string $file, array $data ): bool {
	$dir = dirname( $file );

	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}

	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	if ( false === $json ) {
		return false;
	}

	$tmp = $file . '.tmp' . getmypid();

	if ( false === file_put_contents( $tmp, $json, LOCK_EX ) ) {
		return false;
	}

	return rename( $tmp, $file );
}

/* -------------------------------------------------------------------------
 * Website-Inhalte
 * ---------------------------------------------------------------------- */

/**
 * Pfad zur Inhaltsdatei.
 */
function rc_site_file(): string {
	return RC_DATA . '/site.json';
}

/**
 * Inhalte laden. Fehlt die Datei, werden die Startinhalte angelegt.
 */
function rc_site(): array {
	if ( isset( $GLOBALS['rc_site'] ) && is_array( $GLOBALS['rc_site'] ) ) {
		return $GLOBALS['rc_site'];
	}

	$site = rc_read_json( rc_site_file() );

	if ( ! $site ) {
		require_once RC_APP . '/defaults.php';
		$site = rc_default_site();
		rc_write_json( rc_site_file(), $site );
	}

	$GLOBALS['rc_site'] = $site;

	return $site;
}

/**
 * Inhalte speichern.
 */
function rc_save_site( array $site ): bool {
	$site['updated'] = gmdate( 'c' );

	// Sicherungskopie der letzten Fassung – drei Stände werden behalten.
	rc_backup( $site );

	if ( rc_write_json( rc_site_file(), $site ) ) {
		// Damit spätere Aufrufe in derselben Anfrage den neuen Stand sehen.
		$GLOBALS['rc_site'] = $site;

		return true;
	}

	return false;
}

/**
 * Sicherungskopien anlegen (die letzten drei Stände).
 */
function rc_backup( array $site ): void {
	$dir = RC_DATA . '/backups';

	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}

	$current = rc_read_json( rc_site_file() );

	if ( ! $current ) {
		return;
	}

	rc_write_json( $dir . '/site-' . gmdate( 'Ymd-His' ) . '.json', $current );

	$files = glob( $dir . '/site-*.json' );

	if ( $files && count( $files ) > 3 ) {
		sort( $files );

		foreach ( array_slice( $files, 0, count( $files ) - 3 ) as $old ) {
			unlink( $old );
		}
	}
}

/**
 * Eine einzelne Seite holen.
 */
function rc_page( string $slug ): ?array {
	$site = rc_site();

	return isset( $site['pages'][ $slug ] ) ? $site['pages'][ $slug ] : null;
}

/**
 * Die Startseite (erste Seite im Menü).
 */
function rc_home_slug(): string {
	$site = rc_site();

	foreach ( $site['pages'] as $slug => $page ) {
		if ( ! empty( $page['home'] ) ) {
			return (string) $slug;
		}
	}

	$keys = array_keys( $site['pages'] );

	return (string) reset( $keys );
}

/**
 * Adresse einer Seite.
 */
function rc_page_url( string $slug ): string {
	return rc_home_slug() === $slug ? rc_url( '' ) : rc_url( $slug );
}

/**
 * Eine Einstellung lesen.
 *
 * @param string $key     Schlüssel, auch verschachtelt: 'socials.instagram'.
 * @param mixed  $default Rückfallwert.
 * @return mixed
 */
function rc_setting( string $key, $default = '' ) {
	$site  = rc_site();
	$value = isset( $site['settings'] ) ? $site['settings'] : array();

	foreach ( explode( '.', $key ) as $part ) {
		if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
			return $default;
		}

		$value = $value[ $part ];
	}

	return $value;
}
