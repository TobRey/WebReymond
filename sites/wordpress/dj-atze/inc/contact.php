<?php
/**
 * Booking-Formular: Prüfung und Versand.
 *
 * Der Versand läuft über admin-post.php, also serverseitig. Damit
 * funktioniert das Formular auch ohne JavaScript.
 *
 * @package DjAtze
 */

defined( 'ABSPATH' ) || exit;

/**
 * Anfrage entgegennehmen.
 *
 * @return void
 */
function rt_handle_contact() {
	$target = wp_get_referer() ? wp_get_referer() : home_url( '/' );

	// Nonce: schützt vor untergeschobenen Formularen.
	if ( ! isset( $_POST['rt_contact_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rt_contact_nonce'] ) ), 'rt_contact' ) ) {
		rt_contact_back( $target, 'fehler' );
	}

	// Spam-Falle: nur Automaten füllen dieses Feld aus.
	if ( ! empty( $_POST['website'] ) ) {
		rt_contact_back( $target, 'ok' ); // Bewusst kein Hinweis auf die Falle.
	}

	// Höchstens fünf Anfragen pro Stunde aus derselben Leitung.
	$key   = 'rt_contact_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unbekannt' );
	$count = (int) get_transient( $key );

	if ( $count >= 5 ) {
		rt_contact_back( $target, 'zuviel' );
	}

	$name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$anlass    = isset( $_POST['anlass'] ) ? sanitize_text_field( wp_unslash( $_POST['anlass'] ) ) : '';
	$datum     = isset( $_POST['datum'] ) ? sanitize_text_field( wp_unslash( $_POST['datum'] ) ) : '';
	$ort       = isset( $_POST['ort'] ) ? sanitize_text_field( wp_unslash( $_POST['ort'] ) ) : '';
	$nachricht = isset( $_POST['nachricht'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nachricht'] ) ) : '';

	if ( strlen( $name ) < 2 || ! is_email( $email ) || '' === $anlass ) {
		rt_contact_back( $target, 'ungueltig' );
	}

	$to      = rt_opt( 'email' );
	$subject = sprintf(
		/* translators: 1: Anlass, 2: Name */
		__( 'Booking-Anfrage: %1$s – %2$s', 'dj-atze' ),
		$anlass,
		$name
	);

	$body = implode(
		"\n",
		array(
			__( 'Name: ', 'dj-atze' ) . $name,
			__( 'E-Mail: ', 'dj-atze' ) . $email,
			__( 'Anlass: ', 'dj-atze' ) . $anlass,
			__( 'Datum: ', 'dj-atze' ) . ( $datum ? $datum : '–' ),
			__( 'Ort: ', 'dj-atze' ) . ( $ort ? $ort : '–' ),
			'',
			$nachricht ? $nachricht : __( '(keine Nachricht)', 'dj-atze' ),
			'',
			'—',
			__( 'Gesendet über ', 'dj-atze' ) . home_url( '/' ),
		)
	);

	$headers = array(
		'Content-Type: text/plain; charset=UTF-8',
		sprintf( 'Reply-To: %s <%s>', $name, $email ),
	);

	$sent = wp_mail( $to, $subject, $body, $headers );

	set_transient( $key, $count + 1, HOUR_IN_SECONDS );

	rt_contact_back( $target, $sent ? 'ok' : 'fehler' );
}
add_action( 'admin_post_nopriv_rt_contact', 'rt_handle_contact' );
add_action( 'admin_post_rt_contact', 'rt_handle_contact' );

/**
 * Zurück zur Kontaktseite, mit Ergebnis in der Adresszeile.
 *
 * @param string $target Zielseite.
 * @param string $status ok, ungueltig, zuviel oder fehler.
 * @return void
 */
function rt_contact_back( $target, $status ) {
	wp_safe_redirect( add_query_arg( 'rt', $status, remove_query_arg( 'rt', $target ) ) . '#kontaktformular' );
	exit;
}

/**
 * Rückmeldung für die Besucherin oder den Besucher.
 *
 * @return string
 */
function rt_contact_message() {
	$status = isset( $_GET['rt'] ) ? sanitize_key( wp_unslash( $_GET['rt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige.

	switch ( $status ) {
		case 'ok':
			return __( 'Danke – die Anfrage ist unterwegs.', 'dj-atze' );
		case 'ungueltig':
			return __( 'Bitte Name, gültige E-Mail-Adresse und Anlass ausfüllen.', 'dj-atze' );
		case 'zuviel':
			return __( 'Es wurden zu viele Anfragen gesendet. Bitte später erneut versuchen.', 'dj-atze' );
		case 'fehler':
			return sprintf(
				/* translators: %s: E-Mail-Adresse */
				__( 'Versand fehlgeschlagen. Bitte direkt an %s schreiben.', 'dj-atze' ),
				rt_opt( 'email' )
			);
		default:
			return '';
	}
}
