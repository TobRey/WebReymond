<?php
/**
 * Backend – Dateien hochladen.
 *
 * Erlaubt sind Bilder und Tondateien. Geprüft werden Endung und Inhalt,
 * der Name wird neu vergeben. Im Ordner uploads/ liegt zusätzlich eine
 * .htaccess, die dort jede Skriptausführung verbietet.
 */

declare(strict_types=1);

/**
 * Erlaubte Dateitypen.
 */
function rc_allowed_types(): array {
	return array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'gif'  => 'image/gif',
		'svg'  => 'image/svg+xml',
		'mp3'  => 'audio/mpeg',
		'm4a'  => 'audio/mp4',
		'wav'  => 'audio/wav',
		'ogg'  => 'audio/ogg',
	);
}

/**
 * Den Upload entgegennehmen.
 *
 * @return array Ergebnis mit 'url' oder 'error'.
 */
function rc_handle_upload(): array {
	if ( empty( $_FILES['datei'] ) ) {
		return array( 'error' => 'Keine Datei erhalten.' );
	}

	$file = $_FILES['datei'];

	if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
		return array( 'error' => 'Der Upload ist fehlgeschlagen (Fehler ' . (int) rc_get( $file, 'error', 0 ) . ').' );
	}

	// 40 MB Obergrenze – ein DJ-Set als MP3 passt hinein.
	if ( $file['size'] > 40 * 1024 * 1024 ) {
		return array( 'error' => 'Die Datei ist grösser als 40 MB.' );
	}

	$name      = (string) $file['name'];
	$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
	$allowed   = rc_allowed_types();

	if ( ! isset( $allowed[ $extension ] ) ) {
		return array( 'error' => 'Dieser Dateityp ist nicht erlaubt.' );
	}

	// Zusätzlich den tatsächlichen Inhalt prüfen.
	if ( function_exists( 'finfo_open' ) ) {
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = finfo_file( $finfo, (string) $file['tmp_name'] );
		finfo_close( $finfo );

		$ok = array_values( $allowed );

		if ( 'svg' !== $extension && $mime && ! in_array( $mime, $ok, true ) ) {
			return array( 'error' => 'Der Inhalt passt nicht zur Endung.' );
		}
	}

	// SVG kann Skripte enthalten – deshalb nur ohne <script> annehmen.
	if ( 'svg' === $extension ) {
		$content = (string) file_get_contents( (string) $file['tmp_name'] );

		if ( preg_match( '/<script|onload=|javascript:/i', $content ) ) {
			return array( 'error' => 'Diese SVG-Datei enthält Skripte und wird nicht angenommen.' );
		}
	}

	if ( ! is_dir( RC_UPLOADS ) ) {
		mkdir( RC_UPLOADS, 0755, true );
	}

	rc_protect_uploads();

	$clean  = preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) pathinfo( $name, PATHINFO_FILENAME ) ) );
	$clean  = trim( (string) $clean, '-' );
	$clean  = $clean ? substr( $clean, 0, 40 ) : 'datei';
	$target = $clean . '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 ) . '.' . $extension;

	if ( ! move_uploaded_file( (string) $file['tmp_name'], RC_UPLOADS . '/' . $target ) ) {
		return array( 'error' => 'Die Datei konnte nicht gespeichert werden. Schreibrechte für uploads/ prüfen.' );
	}

	return array(
		'ok'   => true,
		'path' => 'uploads/' . $target,
		'url'  => rc_url( 'uploads/' . $target ),
		'name' => $name,
	);
}

/**
 * Im Upload-Ordner darf nichts ausgeführt werden.
 */
function rc_protect_uploads(): void {
	$file = RC_UPLOADS . '/.htaccess';

	if ( file_exists( $file ) ) {
		return;
	}

	$rules = "# Hochgeladene Dateien werden nur ausgeliefert, niemals ausgeführt.\n"
		. "php_flag engine off\n"
		. "AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .php8 .pl .py .cgi .sh\n"
		. "<FilesMatch \"\\.(php|phtml|php[0-9]|pl|py|cgi|sh)$\">\n"
		. "\tRequire all denied\n"
		. "</FilesMatch>\n";

	file_put_contents( $file, $rules );
}

/**
 * Alle hochgeladenen Dateien auflisten.
 */
function rc_uploads_list(): array {
	if ( ! is_dir( RC_UPLOADS ) ) {
		return array();
	}

	$files = glob( RC_UPLOADS . '/*' );
	$list  = array();

	foreach ( (array) $files as $file ) {
		$name = basename( (string) $file );

		if ( '.htaccess' === $name || is_dir( (string) $file ) ) {
			continue;
		}

		$list[] = array(
			'path' => 'uploads/' . $name,
			'url'  => rc_url( 'uploads/' . $name ),
			'name' => $name,
			'size' => filesize( (string) $file ),
			'time' => filemtime( (string) $file ),
		);
	}

	usort(
		$list,
		static function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		}
	);

	return $list;
}
