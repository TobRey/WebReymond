<?php
/**
 * Backend – Anfragen aus dem Kontaktformular.
 *
 * Versand über die PHP-Funktion mail(). Zusätzlich wird jede Anfrage in
 * data/anfragen.json abgelegt – so geht nichts verloren, falls der
 * Mailversand beim Hoster klemmt. Im Dashboard sind sie einsehbar.
 */

declare(strict_types=1);

/**
 * Anfrage entgegennehmen.
 */
function rc_handle_contact(): void {
	$back = rc_url( 'kontakt' );

	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		header( 'Location: ' . $back );
		exit;
	}

	if ( ! rc_csrf_ok( isset( $_POST['csrf'] ) ? (string) $_POST['csrf'] : null ) ) {
		rc_contact_back( $back, 'fehler' );
	}

	// Spam-Falle: nur Automaten füllen dieses Feld aus.
	if ( ! empty( $_POST['website'] ) ) {
		rc_contact_back( $back, 'ja' );
	}

	$name      = rc_post_text( 'name' );
	$email     = rc_post_text( 'email' );
	$anlass    = rc_post_text( 'anlass' );
	$datum     = rc_post_text( 'datum' );
	$ort       = rc_post_text( 'ort' );
	$nachricht = rc_post_text( 'nachricht', true );

	if ( strlen( $name ) < 2 || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) || '' === $anlass ) {
		rc_contact_back( $back, 'ungueltig' );
	}

	$to = (string) ( rc_setting( 'mailTo' ) ? rc_setting( 'mailTo' ) : rc_setting( 'email' ) );

	$subject = 'Booking-Anfrage: ' . $anlass . ' – ' . $name;
	$body    = implode(
		"\n",
		array(
			'Name: ' . $name,
			'E-Mail: ' . $email,
			'Anlass: ' . $anlass,
			'Datum: ' . ( $datum ? $datum : '–' ),
			'Ort: ' . ( $ort ? $ort : '–' ),
			'',
			$nachricht ? $nachricht : '(keine Nachricht)',
			'',
			'—',
			'Gesendet über ' . rc_abs_url( '' ),
		)
	);

	// Anfrage sichern, unabhängig vom Mailversand.
	rc_store_request(
		array(
			'time'      => gmdate( 'c' ),
			'name'      => $name,
			'email'     => $email,
			'anlass'    => $anlass,
			'datum'     => $datum,
			'ort'       => $ort,
			'nachricht' => $nachricht,
		)
	);

	$host    = (string) ( $_SERVER['HTTP_HOST'] ?? 'localhost' );
	$headers = 'From: Website <noreply@' . preg_replace( '/[^a-z0-9.\-]/i', '', $host ) . ">\r\n"
		. 'Reply-To: ' . $email . "\r\n"
		. "Content-Type: text/plain; charset=UTF-8\r\n";

	$sent = @mail( $to, '=?UTF-8?B?' . base64_encode( $subject ) . '?=', $body, $headers );

	rc_contact_back( $back, $sent ? 'ja' : 'fehler' );
}

/**
 * Ein Feld aus dem Formular holen und säubern.
 */
function rc_post_text( string $key, bool $multiline = false ): string {
	$value = isset( $_POST[ $key ] ) ? (string) $_POST[ $key ] : '';
	$value = strip_tags( $value );

	if ( ! $multiline ) {
		$value = str_replace( array( "\r", "\n" ), ' ', $value );
	}

	return trim( $value );
}

/**
 * Anfrage in der Ablage sichern (die letzten 200).
 */
function rc_store_request( array $entry ): void {
	$file    = RC_DATA . '/anfragen.json';
	$entries = rc_read_json( $file );

	array_unshift( $entries, $entry );

	rc_write_json( $file, array_slice( $entries, 0, 200 ) );
}

/**
 * Zurück zum Formular, mit Ergebnis in der Adresse.
 */
function rc_contact_back( string $target, string $status ): void {
	header( 'Location: ' . $target . '?gesendet=' . rawurlencode( $status ) . '#kontaktformular' );
	exit;
}
