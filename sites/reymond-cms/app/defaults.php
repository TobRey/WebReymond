<?php
/**
 * Reymond CMS – Startinhalte.
 *
 * Beim ersten Aufruf entsteht daraus data/site.json: die fertige Website,
 * genau wie ausgeliefert. Ab dann wird nur noch data/site.json benutzt –
 * diese Datei dient bloss als Vorlage und für „Inhalte zurücksetzen“.
 */

declare(strict_types=1);

require_once RC_APP . '/sections.php';

/**
 * Einen Abschnitt mit abweichenden Werten bauen.
 */
function rc_seed_section( string $type, array $overrides = array() ): array {
	$section = rc_new_section( $type );

	if ( ! $section ) {
		return array();
	}

	$section['props'] = array_merge( $section['props'], $overrides );

	return $section;
}

/**
 * Die Startfassung der Website.
 */
function rc_default_site(): array {
	return array(
		'version'  => RC_VERSION,
		'updated'  => gmdate( 'c' ),
		'settings' => array(
			'siteName'    => 'Reymond Tobias',
			'tagline'     => 'DJ · Schweiz',
			'email'       => 'booking@reymond-tobias.ch',
			'phone'       => '+41 00 000 00 00',
			'base'        => 'Schweiz',
			'socials'     => array(
				'instagram'  => '',
				'soundcloud' => '',
				'spotify'    => '',
				'tiktok'     => '',
			),
			'effects'     => array(
				'grain'     => true,
				'cursor'    => true,
				'preloader' => true,
				'vignette'  => true,
			),
			'mailTo'      => '',
			'analytics'   => '',
		),
		'pages'    => array(
			'start'   => array(
				'title'    => 'Start',
				'home'     => true,
				'seo'      => array(
					'title'       => 'Reymond Tobias – DJ',
					'description' => 'Reymond Tobias – DJ aus der Schweiz. Dunkler, treibender Sound für Club, Festival und private Anlässe.',
				),
				'sections' => array(
					rc_seed_section( 'hero' ),
					rc_seed_section( 'marquee', array( 'space' => 'ohne' ) ),
					rc_seed_section( 'about' ),
					rc_seed_section( 'dates' ),
					rc_seed_section(
						'cta',
						array(
							'borderTop' => true,
							'space'     => 'gross',
						)
					),
				),
			),
			'musik'   => array(
				'title'    => 'Musik',
				'home'     => false,
				'seo'      => array(
					'title'       => 'Musik – Reymond Tobias',
					'description' => 'Sets und Tracks von Reymond Tobias. Dunkler Techno zum direkt Anhören.',
				),
				'sections' => array(
					rc_seed_section(
						'pagehead',
						array(
							'eyebrow' => 'Musik',
							'title'   => 'Sets',
							'text'    => 'Reinhören, durchklicken, laut stellen. Leertaste startet und stoppt.',
							'space'   => 'klein',
						)
					),
					rc_seed_section( 'player', array( 'space' => 'ohne' ) ),
					rc_seed_section( 'tracklist', array( 'space' => 'klein' ) ),
					rc_seed_section(
						'marquee',
						array(
							'space'   => 'ohne',
							'reverse' => true,
							'items'   => array(
								array( 'text' => 'Laut aufdrehen' ),
								array( 'text' => 'Techno' ),
								array( 'text' => 'Dunkel' ),
								array( 'text' => 'Treibend' ),
							),
						)
					),
					rc_seed_section(
						'cta',
						array(
							'title'     => 'Sound für deine',
							'outline'   => 'Nacht',
							'borderTop' => true,
							'space'     => 'gross',
						)
					),
				),
			),
			'kontakt' => array(
				'title'    => 'Kontakt',
				'home'     => false,
				'seo'      => array(
					'title'       => 'Kontakt – Reymond Tobias',
					'description' => 'Booking und Kontakt: Club, Festival, Firmenanlass oder private Feier.',
				),
				'sections' => array(
					rc_seed_section(
						'pagehead',
						array(
							'eyebrow' => 'Kontakt',
							'title'   => 'Booking',
							'text'    => 'Club, Festival, Firmenanlass oder private Feier – schreib mir Datum, Ort und was du dir vorstellst. Antwort kommt in der Regel innert 48 Stunden.',
							'space'   => 'klein',
						)
					),
					rc_seed_section( 'contact', array( 'space' => 'klein' ) ),
					rc_seed_section(
						'marquee',
						array(
							'space' => 'ohne',
							'items' => array(
								array( 'text' => 'Booking offen' ),
								array( 'text' => 'Schweiz & Umgebung' ),
								array( 'text' => 'Antwort in 48h' ),
								array( 'text' => 'Let’s go' ),
							),
						)
					),
					rc_seed_section(
						'cta',
						array(
							'title'     => 'Hör zuerst',
							'outline'   => 'rein',
							'btn'       => array( 'label' => 'Zur Musik', 'url' => 'musik', 'style' => 'umriss' ),
							'borderTop' => true,
							'space'     => 'gross',
						)
					),
				),
			),
		),
		'tracks'   => array(
			array( 'title' => 'Nachtschicht', 'tag' => 'Peak Time Techno', 'duration' => '6:12', 'audio' => '', 'cover' => 'assets/img/cover-01.svg' ),
			array( 'title' => 'Betonliebe', 'tag' => 'Hard Groove', 'duration' => '5:48', 'audio' => '', 'cover' => 'assets/img/cover-02.svg' ),
			array( 'title' => 'Sirenen', 'tag' => 'Industrial', 'duration' => '7:03', 'audio' => '', 'cover' => 'assets/img/cover-03.svg' ),
			array( 'title' => 'Schwarzlicht', 'tag' => 'Melodic Dark', 'duration' => '5:21', 'audio' => '', 'cover' => 'assets/img/cover-04.svg' ),
			array( 'title' => 'Kaltstart', 'tag' => 'Warm Up Set', 'duration' => '8:40', 'audio' => '', 'cover' => 'assets/img/cover-05.svg' ),
			array( 'title' => 'Morgengrauen', 'tag' => 'Closing Set', 'duration' => '9:15', 'audio' => '', 'cover' => 'assets/img/cover-06.svg' ),
		),
	);
}

/**
 * Der Startzugang.
 *
 * Das Passwort steht hier nur, weil es beim ersten Start gesetzt werden
 * muss – gespeichert wird ausschliesslich der Hash. Im Dashboard unter
 * „Konto“ lässt es sich jederzeit ändern.
 */
function rc_default_users(): array {
	return array(
		array(
			'user'    => 'tobiasreymond',
			'hash'    => password_hash( 'Marihuana420!!', PASSWORD_DEFAULT ),
			'created' => gmdate( 'c' ),
		),
	);
}
