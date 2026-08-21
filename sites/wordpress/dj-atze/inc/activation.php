<?php
/**
 * Beim ersten Aktivieren des Themes: Seiten, Menü und Inhalte anlegen.
 *
 * Damit steht die Seite unmittelbar nach dem Aktivieren fertig da – ohne
 * dass im Backend etwas von Hand angelegt werden muss. Der Vorgang läuft
 * genau einmal; danach bleibt alles unangetastet, auch beim erneuten
 * Wechseln des Themes.
 *
 * @package DjAtze
 */

defined( 'ABSPATH' ) || exit;

/**
 * Einstiegspunkt nach dem Aktivieren.
 *
 * @return void
 */
function rt_after_switch_theme() {
	if ( get_option( 'rt_setup_done' ) ) {
		return;
	}

	$pages = rt_create_pages();
	rt_create_menu( $pages );
	rt_seed_tracks();
	rt_seed_gigs();
	rt_remove_defaults();

	update_option( 'rt_setup_done', RT_VERSION );
	update_option( 'blogdescription', __( 'DJ · Schweiz', 'dj-atze' ) );
	flush_rewrite_rules();
}
add_action( 'after_switch_theme', 'rt_after_switch_theme' );

/**
 * Die drei Seiten anlegen und die Startseite festlegen.
 *
 * @return array Seitentitel => ID.
 */
function rt_create_pages() {
	$blueprint = array(
		'start'   => array(
			'title'    => __( 'Start', 'dj-atze' ),
			'template' => '',
			'order'    => 1,
			'content'  => rt_block_paragraphs(
				array(
					'<strong>DJ Atze</strong> legt auf, was um zwei Uhr morgens noch funktioniert: harte Kicks, weite Flächen, ein Aufbau, der nicht loslässt. Kein Set gleicht dem anderen – gelesen wird der Raum, nicht die Playlist.',
					'Von der ersten warmen Stunde bis zum Closing: die Übergänge sitzen, die Spannung bleibt. Technisch sauber, musikalisch dunkel, immer auf die Tanzfläche gerichtet.',
					'Buchbar für Clubnächte, Festivals, Firmenanlässe und private Feiern – mit eigenem Equipment oder auf der Anlage vor Ort.',
				)
			),
		),
		'musik'   => array(
			'title'    => __( 'Musik', 'dj-atze' ),
			'template' => 'template-musik.php',
			'order'    => 2,
			'content'  => rt_block_paragraphs(
				array( 'Reinhören, durchklicken, laut stellen. Leertaste startet und stoppt.' )
			),
		),
		'kontakt' => array(
			'title'    => __( 'Kontakt', 'dj-atze' ),
			'template' => 'template-kontakt.php',
			'order'    => 3,
			'content'  => rt_block_paragraphs(
				array( 'Club, Festival, Firmenanlass oder private Feier – schreib mir Datum, Ort und was du dir vorstellst. Antwort kommt in der Regel innert 48 Stunden.' )
			),
		),
	);

	$ids = array();

	foreach ( $blueprint as $key => $page ) {
		$existing = get_page_by_path( sanitize_title( $page['title'] ) );

		if ( $existing ) {
			$ids[ $key ] = (int) $existing->ID;
			continue;
		}

		$id = wp_insert_post(
			array(
				'post_title'   => $page['title'],
				'post_name'    => sanitize_title( $page['title'] ),
				'post_content' => $page['content'],
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'menu_order'   => $page['order'],
			)
		);

		if ( is_wp_error( $id ) || ! $id ) {
			continue;
		}

		if ( $page['template'] ) {
			update_post_meta( $id, '_wp_page_template', $page['template'] );
		}

		$ids[ $key ] = (int) $id;
	}

	if ( ! empty( $ids['start'] ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $ids['start'] );
	}

	return $ids;
}

/**
 * Absätze im Blockformat, damit sie im Editor sauber erscheinen.
 *
 * @param array $paragraphs Textabsätze.
 * @return string
 */
function rt_block_paragraphs( $paragraphs ) {
	$out = '';

	foreach ( $paragraphs as $text ) {
		$out .= "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->\n\n";
	}

	return trim( $out );
}

/**
 * Hauptmenü anlegen und zuweisen.
 *
 * @param array $pages Seitentitel => ID.
 * @return void
 */
function rt_create_menu( $pages ) {
	$name = __( 'Hauptmenü', 'dj-atze' );
	$menu = wp_get_nav_menu_object( $name );

	if ( ! $menu ) {
		$menu_id = wp_create_nav_menu( $name );

		if ( is_wp_error( $menu_id ) ) {
			return;
		}
	} else {
		$menu_id = (int) $menu->term_id;
	}

	// Nur befüllen, wenn das Menü noch leer ist.
	if ( ! wp_get_nav_menu_items( $menu_id ) ) {
		foreach ( array( 'start', 'musik', 'kontakt' ) as $position => $key ) {
			if ( empty( $pages[ $key ] ) ) {
				continue;
			}

			wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-object-id' => $pages[ $key ],
					'menu-item-object'    => 'page',
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
					'menu-item-position'  => $position + 1,
				)
			);
		}
	}

	$locations = (array) get_theme_mod( 'nav_menu_locations', array() );

	$locations['primary'] = $menu_id;
	$locations['footer']  = $menu_id;

	set_theme_mod( 'nav_menu_locations', $locations );
}

/**
 * Sechs Beispieltitel anlegen.
 *
 * @return void
 */
function rt_seed_tracks() {
	if ( get_posts( array( 'post_type' => 'rt_track', 'numberposts' => 1, 'post_status' => 'any' ) ) ) {
		return;
	}

	$tracks = array(
		array( 'Nachtschicht', 'Peak Time Techno', '6:12' ),
		array( 'Betonliebe', 'Hard Groove', '5:48' ),
		array( 'Sirenen', 'Industrial', '7:03' ),
		array( 'Schwarzlicht', 'Melodic Dark', '5:21' ),
		array( 'Kaltstart', 'Warm Up Set', '8:40' ),
		array( 'Morgengrauen', 'Closing Set', '9:15' ),
	);

	foreach ( $tracks as $index => $track ) {
		$id = wp_insert_post(
			array(
				'post_title'  => $track[0],
				'post_type'   => 'rt_track',
				'post_status' => 'publish',
				'menu_order'  => $index + 1,
			)
		);

		if ( is_wp_error( $id ) || ! $id ) {
			continue;
		}

		update_post_meta( $id, '_rt_tag', $track[1] );
		update_post_meta( $id, '_rt_duration', $track[2] );
		update_post_meta( $id, '_rt_audio', '' ); // Audiodatei später im Backend hinterlegen.
	}
}

/**
 * Drei Beispieltermine anlegen – als solche gekennzeichnet.
 *
 * @return void
 */
function rt_seed_gigs() {
	if ( get_posts( array( 'post_type' => 'rt_gig', 'numberposts' => 1, 'post_status' => 'any' ) ) ) {
		return;
	}

	$gigs = array(
		array( __( 'Clubnacht', 'dj-atze' ), 'TBA', __( 'Ort folgt', 'dj-atze' ), __( 'Beispiel', 'dj-atze' ) ),
		array( __( 'Festival', 'dj-atze' ), 'TBA', __( 'Ort folgt', 'dj-atze' ), __( 'Beispiel', 'dj-atze' ) ),
		array( __( 'Private Feier', 'dj-atze' ), 'TBA', __( 'Auf Anfrage', 'dj-atze' ), __( 'Beispiel', 'dj-atze' ) ),
	);

	foreach ( $gigs as $index => $gig ) {
		$id = wp_insert_post(
			array(
				'post_title'  => $gig[0],
				'post_type'   => 'rt_gig',
				'post_status' => 'publish',
				'menu_order'  => $index + 1,
			)
		);

		if ( is_wp_error( $id ) || ! $id ) {
			continue;
		}

		update_post_meta( $id, '_rt_gig_day', $gig[1] );
		update_post_meta( $id, '_rt_gig_place', $gig[2] );
		update_post_meta( $id, '_rt_gig_status', $gig[3] );
	}
}

/**
 * Die Beispielinhalte von WordPress in den Papierkorb legen.
 *
 * Betroffen sind nur die unveränderten Vorgaben „Beispiel-Seite“ und
 * „Hallo Welt!“. Wurde daran gearbeitet – erkennbar am Änderungsdatum –,
 * bleiben sie unangetastet. Gelöscht wird nichts: alles landet nur im
 * Papierkorb und kann zurückgeholt werden.
 *
 * @return void
 */
function rt_remove_defaults() {
	$candidates = array(
		array( 'sample-page', 'page' ),
		array( 'beispiel-seite', 'page' ),
		array( 'hello-world', 'post' ),
		array( 'hallo-welt', 'post' ),
	);

	foreach ( $candidates as $candidate ) {
		list( $slug, $type ) = $candidate;

		$post = get_page_by_path( $slug, OBJECT, $type );

		if ( ! $post || 'publish' !== $post->post_status ) {
			continue;
		}

		// WordPress legt beide Zeitstempel gleich an; nach einer Bearbeitung
		// laufen sie auseinander.
		if ( $post->post_modified_gmt !== $post->post_date_gmt ) {
			continue;
		}

		wp_trash_post( $post->ID );
	}
}

/**
 * Hinweis im Backend, solange keine Audiodatei hinterlegt ist.
 *
 * @return void
 */
function rt_admin_notice() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}

	$tracks = rt_get_tracks();
	$missing = 0;

	foreach ( $tracks as $track ) {
		if ( '' === $track['audio'] ) {
			$missing++;
		}
	}

	if ( ! $missing ) {
		return;
	}

	printf(
		'<div class="notice notice-info is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
		esc_html__( 'DJ Atze:', 'dj-atze' ),
		esc_html(
			sprintf(
				/* translators: %d: Anzahl */
				_n( '%d Titel hat noch keine Audiodatei – der Player zeigt dafür den Vorschaumodus.', '%d Titel haben noch keine Audiodatei – der Player zeigt dafür den Vorschaumodus.', $missing, 'dj-atze' ),
				$missing
			)
		),
		esc_url( admin_url( 'edit.php?post_type=rt_track' ) ),
		esc_html__( 'Titel bearbeiten', 'dj-atze' )
	);
}
add_action( 'admin_notices', 'rt_admin_notice' );
