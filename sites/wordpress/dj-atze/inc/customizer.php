<?php
/**
 * Customizer: alle Texte und Kontaktdaten ohne Codeänderung anpassbar.
 *
 * Aufruf im Backend: Design → Customizer → „DJ Atze“.
 *
 * @package DjAtze
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ein Textfeld anlegen.
 *
 * @param WP_Customize_Manager $wp_customize Customizer.
 * @param string               $section      Abschnitt.
 * @param string               $key          Schlüssel ohne Präfix.
 * @param string               $label        Beschriftung.
 * @param string               $type         text, textarea oder url.
 * @param string               $description  Hinweis unter dem Feld.
 * @return void
 */
function rt_add_field( $wp_customize, $section, $key, $label, $type = 'text', $description = '' ) {
	$defaults  = rt_defaults();
	$default   = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	$sanitizer = ( 'url' === $type ) ? 'esc_url_raw' : ( 'textarea' === $type ? 'sanitize_textarea_field' : 'sanitize_text_field' );

	$wp_customize->add_setting(
		'rt_' . $key,
		array(
			'default'           => $default,
			'sanitize_callback' => $sanitizer,
			'transport'         => 'refresh',
		)
	);

	$wp_customize->add_control(
		'rt_' . $key,
		array(
			'label'       => $label,
			'section'     => $section,
			'type'        => ( 'url' === $type ) ? 'url' : $type,
			'description' => $description,
		)
	);
}

/**
 * Alle Einstellungen registrieren.
 *
 * @param WP_Customize_Manager $wp_customize Customizer.
 * @return void
 */
function rt_customize_register( $wp_customize ) {
	$wp_customize->add_panel(
		'rt_panel',
		array(
			'title'       => __( 'DJ Atze', 'dj-atze' ),
			'description' => __( 'Texte, Bilder und Kontaktdaten der Seite.', 'dj-atze' ),
			'priority'    => 20,
		)
	);

	/* ------------------------------------------------------------------
	 * Startseite
	 * ---------------------------------------------------------------- */

	$wp_customize->add_section(
		'rt_hero',
		array(
			'title' => __( 'Startseite: Banner', 'dj-atze' ),
			'panel' => 'rt_panel',
		)
	);

	$wp_customize->add_setting(
		'rt_hero_image',
		array( 'sanitize_callback' => 'esc_url_raw' )
	);

	$wp_customize->add_control(
		new WP_Customize_Image_Control(
			$wp_customize,
			'rt_hero_image',
			array(
				'label'       => __( 'Bannerbild', 'dj-atze' ),
				'section'     => 'rt_hero',
				'description' => __( 'Quer, mindestens 2000 px breit. Steht die Person rechts im Bild, passt der Titel links daneben.', 'dj-atze' ),
			)
		)
	);

	rt_add_field( $wp_customize, 'rt_hero', 'hero_eyebrow', __( 'Kleine Zeile über dem Titel', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_hero', 'hero_line1', __( 'Titel, erste Zeile', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_hero', 'hero_line2', __( 'Titel, zweite Zeile (Umriss)', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_hero', 'hero_lead', __( 'Einleitungstext', 'dj-atze' ), 'textarea' );
	rt_add_field( $wp_customize, 'rt_hero', 'hero_cta_1', __( 'Erster Knopf', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_hero', 'hero_cta_2', __( 'Zweiter Knopf', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_hero', 'stat_1', __( 'Kennzahl 1', 'dj-atze' ), 'text', __( 'Aufbau: Wert|Beschriftung', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_hero', 'stat_2', __( 'Kennzahl 2', 'dj-atze' ), 'text', __( 'Aufbau: Wert|Beschriftung', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_hero', 'stat_3', __( 'Kennzahl 3', 'dj-atze' ), 'text', __( 'Aufbau: Wert|Beschriftung', 'dj-atze' ) );

	/* ------------------------------------------------------------------
	 * Weitere Abschnitte der Startseite
	 * ---------------------------------------------------------------- */

	$wp_customize->add_section(
		'rt_sections',
		array(
			'title' => __( 'Startseite: Abschnitte', 'dj-atze' ),
			'panel' => 'rt_panel',
		)
	);

	rt_add_field( $wp_customize, 'rt_sections', 'marquee', __( 'Laufband', 'dj-atze' ), 'text', __( 'Begriffe mit Komma trennen.', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_sections', 'about_title', __( 'Überschrift „Über“', 'dj-atze' ), 'text', __( 'Zeilen mit | trennen.', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_sections', 'about_tags', __( 'Stichwörter', 'dj-atze' ), 'text', __( 'Mit Komma trennen. Der Text darüber steht im Editor der Startseite.', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_sections', 'dates_title', __( 'Überschrift „Termine“', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_sections', 'cta_title', __( 'Schlusszeile', 'dj-atze' ), 'text', __( 'Der Teil nach dem | wird als Umriss gesetzt.', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_sections', 'cta_button', __( 'Knopf am Schluss', 'dj-atze' ) );

	/* ------------------------------------------------------------------
	 * Kontakt
	 * ---------------------------------------------------------------- */

	$wp_customize->add_section(
		'rt_contact',
		array(
			'title' => __( 'Kontakt und Social', 'dj-atze' ),
			'panel' => 'rt_panel',
		)
	);

	rt_add_field( $wp_customize, 'rt_contact', 'email', __( 'E-Mail für Anfragen', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_contact', 'phone', __( 'Telefon', 'dj-atze' ), 'text', __( 'Leer lassen blendet den Block aus.', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_contact', 'base', __( 'Basis / Ort', 'dj-atze' ) );
	rt_add_field( $wp_customize, 'rt_contact', 'instagram', __( 'Instagram', 'dj-atze' ), 'url' );
	rt_add_field( $wp_customize, 'rt_contact', 'soundcloud', __( 'SoundCloud', 'dj-atze' ), 'url' );
	rt_add_field( $wp_customize, 'rt_contact', 'spotify', __( 'Spotify', 'dj-atze' ), 'url' );
	rt_add_field( $wp_customize, 'rt_contact', 'tiktok', __( 'TikTok', 'dj-atze' ), 'url' );
}
add_action( 'customize_register', 'rt_customize_register' );
