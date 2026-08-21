<?php
/**
 * Backend – die Bausteine.
 *
 * Hier steht, welche Abschnitte es gibt und welche Felder sie haben.
 * Es gibt bewusst nur das, was auf dieser Website wirklich vorkommt –
 * keine Karten, keine Preistabellen, kein Formularbaukasten.
 *
 * Aus dieser Liste baut der Editor:
 *   - die Auswahl unter „Elemente“
 *   - die Eingabefelder im Einstellungsfenster
 */

declare(strict_types=1);

/**
 * Felder, die jeder Abschnitt hat (Reiter „Stil“).
 */
function rc_style_fields(): array {
	return array(
		array(
			'key'     => 'bg',
			'type'    => 'select',
			'label'   => 'Hintergrund',
			'options' => array(
				'schwarz' => 'Schwarz',
				'tief'    => 'Tiefschwarz',
				'karte'   => 'Anthrazit',
				'verlauf' => 'Verlauf',
				'bild'    => 'Bild',
			),
		),
		array(
			'key'   => 'bgImage',
			'type'  => 'image',
			'label' => 'Hintergrundbild',
			'show'  => 'bg=bild',
		),
		array(
			'key'   => 'bgDim',
			'type'  => 'range',
			'label' => 'Bild abdunkeln',
			'min'   => 0,
			'max'   => 100,
			'step'  => 5,
			'unit'  => '%',
			'show'  => 'bg=bild',
		),
		array(
			'key'     => 'space',
			'type'    => 'select',
			'label'   => 'Abstand oben und unten',
			'options' => array(
				'ohne'   => 'Ohne',
				'klein'  => 'Klein',
				'normal' => 'Normal',
				'gross'  => 'Gross',
			),
		),
		array(
			'key'   => 'borderTop',
			'type'  => 'toggle',
			'label' => 'Linie oben',
		),
		array(
			'key'   => 'borderBottom',
			'type'  => 'toggle',
			'label' => 'Linie unten',
		),
	);
}

/**
 * Felder im Reiter „Erweitert“.
 */
function rc_advanced_fields(): array {
	return array(
		array(
			'key'   => 'anchor',
			'type'  => 'text',
			'label' => 'Sprungmarke',
			'hint'  => 'Erlaubt Verweise wie #termine.',
		),
		array(
			'key'   => 'hideMobile',
			'type'  => 'toggle',
			'label' => 'Auf dem Handy ausblenden',
		),
	);
}

/**
 * Alle Abschnittstypen.
 */
function rc_section_types(): array {
	return array(

		/* ------------------------------------------------------------- */
		'hero'      => array(
			'label' => 'Banner',
			'icon'  => 'hero',
			'hint'  => 'Grosses Bild mit Titel, Text und Knöpfen.',
			'fields' => array(
				array( 'key' => 'image', 'type' => 'image', 'label' => 'Bild' ),
				array( 'key' => 'focus', 'type' => 'text', 'label' => 'Bildausschnitt', 'hint' => 'Zwei Werte, z. B. „72% 28%“. Erster Wert links/rechts.' ),
				array( 'key' => 'eyebrow', 'type' => 'text', 'label' => 'Kleine Zeile' ),
				array( 'key' => 'line1', 'type' => 'text', 'label' => 'Titel Zeile 1' ),
				array( 'key' => 'line2', 'type' => 'text', 'label' => 'Titel Zeile 2 (Umriss)' ),
				array( 'key' => 'lead', 'type' => 'textarea', 'label' => 'Text' ),
				array( 'key' => 'btn1', 'type' => 'link', 'label' => 'Erster Knopf' ),
				array( 'key' => 'btn2', 'type' => 'link', 'label' => 'Zweiter Knopf' ),
				array(
					'key'    => 'stats',
					'type'   => 'repeater',
					'label'  => 'Kennzahlen rechts',
					'item'   => array(
						array( 'key' => 'value', 'type' => 'text', 'label' => 'Wert' ),
						array( 'key' => 'label', 'type' => 'text', 'label' => 'Beschriftung' ),
					),
				),
				array( 'key' => 'scrollHint', 'type' => 'toggle', 'label' => 'Hinweis „Scrollen“ zeigen' ),
			),
			'defaults' => array(
				'image'      => 'assets/img/banner-placeholder.svg',
				'focus'      => '72% 28%',
				'eyebrow'    => 'DJ · Schweiz',
				'line1'      => 'DJ',
				'line2'      => 'Atze',
				'lead'       => 'Dunkel, treibend, kompromisslos auf die Tanzfläche gebaut. Sets für Club, Festival und private Anlässe.',
				'btn1'       => array( 'label' => 'Musik hören', 'url' => 'musik', 'style' => 'voll' ),
				'btn2'       => array( 'label' => 'Booking anfragen', 'url' => 'kontakt', 'style' => 'umriss' ),
				'stats'      => array(
					array( 'value' => 'Techno', 'label' => 'Sound' ),
					array( 'value' => 'Schweiz', 'label' => 'Basis' ),
					array( 'value' => 'Offen', 'label' => 'Booking' ),
				),
				'scrollHint' => true,
			),
		),

		/* ------------------------------------------------------------- */
		'pagehead'  => array(
			'label' => 'Seitentitel',
			'icon'  => 'head',
			'hint'  => 'Kleine Zeile, grosser Titel, kurzer Text.',
			'fields' => array(
				array( 'key' => 'eyebrow', 'type' => 'text', 'label' => 'Kleine Zeile' ),
				array( 'key' => 'title', 'type' => 'text', 'label' => 'Titel' ),
				array( 'key' => 'text', 'type' => 'textarea', 'label' => 'Text darunter' ),
			),
			'defaults' => array(
				'eyebrow' => 'Musik',
				'title'   => 'Sets',
				'text'    => 'Reinhören, durchklicken, laut stellen.',
			),
		),

		/* ------------------------------------------------------------- */
		'marquee'   => array(
			'label' => 'Laufband',
			'icon'  => 'marquee',
			'hint'  => 'Endlos laufende Begriffe.',
			'fields' => array(
				array(
					'key'   => 'items',
					'type'  => 'repeater',
					'label' => 'Begriffe',
					'item'  => array(
						array( 'key' => 'text', 'type' => 'text', 'label' => 'Begriff' ),
					),
				),
				array( 'key' => 'reverse', 'type' => 'toggle', 'label' => 'Andere Richtung' ),
				array( 'key' => 'speed', 'type' => 'range', 'label' => 'Tempo', 'min' => 10, 'max' => 60, 'step' => 2, 'unit' => 's' ),
			),
			'defaults' => array(
				'items'   => array(
					array( 'text' => 'Techno' ),
					array( 'text' => 'Hard Groove' ),
					array( 'text' => 'Club' ),
					array( 'text' => 'Festival' ),
					array( 'text' => 'Afterhour' ),
					array( 'text' => 'Private Events' ),
				),
				'reverse' => false,
				'speed'   => 34,
			),
		),

		/* ------------------------------------------------------------- */
		'about'     => array(
			'label' => 'Text mit Titel',
			'icon'  => 'about',
			'hint'  => 'Grosser Titel links, Text und Stichwörter rechts.',
			'fields' => array(
				array( 'key' => 'title', 'type' => 'text', 'label' => 'Titel', 'hint' => 'Zeilen mit | trennen.' ),
				array( 'key' => 'body', 'type' => 'richtext', 'label' => 'Text' ),
				array(
					'key'   => 'tags',
					'type'  => 'repeater',
					'label' => 'Stichwörter',
					'item'  => array(
						array( 'key' => 'text', 'type' => 'text', 'label' => 'Stichwort' ),
					),
				),
			),
			'defaults' => array(
				'title' => 'Wer|hinter|dem Pult|steht',
				'body'  => '<p><strong>DJ Atze</strong> legt auf, was um zwei Uhr morgens noch funktioniert: harte Kicks, weite Flächen, ein Aufbau, der nicht loslässt. Kein Set gleicht dem anderen – gelesen wird der Raum, nicht die Playlist.</p><p>Von der ersten warmen Stunde bis zum Closing: die Übergänge sitzen, die Spannung bleibt. Technisch sauber, musikalisch dunkel, immer auf die Tanzfläche gerichtet.</p><p>Buchbar für Clubnächte, Festivals, Firmenanlässe und private Feiern – mit eigenem Equipment oder auf der Anlage vor Ort.</p>',
				'tags'  => array(
					array( 'text' => 'Techno' ),
					array( 'text' => 'Hard Groove' ),
					array( 'text' => 'Melodic Dark' ),
					array( 'text' => 'House' ),
					array( 'text' => 'Warm Up' ),
					array( 'text' => 'Closing' ),
				),
			),
		),

		/* ------------------------------------------------------------- */
		'text'      => array(
			'label' => 'Freier Text',
			'icon'  => 'text',
			'hint'  => 'Ein einfacher Textblock über die halbe Breite.',
			'fields' => array(
				array( 'key' => 'body', 'type' => 'richtext', 'label' => 'Text' ),
				array( 'key' => 'wide', 'type' => 'toggle', 'label' => 'Volle Breite' ),
			),
			'defaults' => array(
				'body' => '<p>Hier steht dein Text. Doppelklick genügt zum Bearbeiten.</p>',
				'wide' => false,
			),
		),

		/* ------------------------------------------------------------- */
		'dates'     => array(
			'label' => 'Termine',
			'icon'  => 'dates',
			'hint'  => 'Liste mit Datum, Ort und Status.',
			'fields' => array(
				array( 'key' => 'eyebrow', 'type' => 'text', 'label' => 'Kleine Zeile' ),
				array( 'key' => 'title', 'type' => 'text', 'label' => 'Überschrift' ),
				array(
					'key'   => 'rows',
					'type'  => 'repeater',
					'label' => 'Termine',
					'item'  => array(
						array( 'key' => 'day', 'type' => 'text', 'label' => 'Datum' ),
						array( 'key' => 'venue', 'type' => 'text', 'label' => 'Anlass' ),
						array( 'key' => 'place', 'type' => 'text', 'label' => 'Ort' ),
						array( 'key' => 'status', 'type' => 'text', 'label' => 'Status' ),
						array( 'key' => 'url', 'type' => 'text', 'label' => 'Verweis' ),
					),
				),
			),
			'defaults' => array(
				'eyebrow' => 'Termine',
				'title'   => 'Wo als Nächstes',
				'rows'    => array(
					array( 'day' => 'TBA', 'venue' => 'Clubnacht', 'place' => 'Ort folgt', 'status' => 'Beispiel', 'url' => 'kontakt' ),
					array( 'day' => 'TBA', 'venue' => 'Festival', 'place' => 'Ort folgt', 'status' => 'Beispiel', 'url' => 'kontakt' ),
					array( 'day' => 'TBA', 'venue' => 'Private Feier', 'place' => 'Auf Anfrage', 'status' => 'Beispiel', 'url' => 'kontakt' ),
				),
			),
		),

		/* ------------------------------------------------------------- */
		'player'    => array(
			'label' => 'Musikplayer',
			'icon'  => 'player',
			'hint'  => 'Cover, grosser Titel, Balkenanzeige, Bedienung.',
			'fields' => array(
				array( 'key' => 'kicker', 'type' => 'text', 'label' => 'Kleine Zeile' ),
				array( 'key' => 'showVolume', 'type' => 'toggle', 'label' => 'Lautstärkeregler zeigen' ),
				array( 'key' => 'note', 'type' => 'info', 'label' => 'Die Titel selbst werden im Dashboard unter „Musik“ gepflegt.' ),
			),
			'defaults' => array(
				'kicker'     => 'Jetzt ausgewählt',
				'showVolume' => true,
			),
		),

		/* ------------------------------------------------------------- */
		'tracklist' => array(
			'label' => 'Titelliste',
			'icon'  => 'list',
			'hint'  => 'Alle Titel als anklickbare Liste.',
			'fields' => array(
				array( 'key' => 'eyebrow', 'type' => 'text', 'label' => 'Kleine Zeile' ),
				array( 'key' => 'note', 'type' => 'info', 'label' => 'Braucht den Musikplayer auf derselben Seite.' ),
			),
			'defaults' => array(
				'eyebrow' => 'Alle Titel',
			),
		),

		/* ------------------------------------------------------------- */
		'contact'   => array(
			'label' => 'Kontakt mit Formular',
			'icon'  => 'contact',
			'hint'  => 'Direkte Wege links, Anfrageformular rechts.',
			'fields' => array(
				array( 'key' => 'showInfo', 'type' => 'toggle', 'label' => 'Direkte Wege zeigen' ),
				array( 'key' => 'showForm', 'type' => 'toggle', 'label' => 'Formular zeigen' ),
				array( 'key' => 'submit', 'type' => 'text', 'label' => 'Beschriftung des Knopfes' ),
				array(
					'key'   => 'subjects',
					'type'  => 'repeater',
					'label' => 'Auswahl „Anlass“',
					'item'  => array(
						array( 'key' => 'text', 'type' => 'text', 'label' => 'Eintrag' ),
					),
				),
				array( 'key' => 'note', 'type' => 'info', 'label' => 'E-Mail, Telefon und Social-Links stehen im Dashboard.' ),
			),
			'defaults' => array(
				'showInfo' => true,
				'showForm' => true,
				'submit'   => 'Anfrage senden',
				'subjects' => array(
					array( 'text' => 'Club' ),
					array( 'text' => 'Festival' ),
					array( 'text' => 'Firmenanlass' ),
					array( 'text' => 'Private Feier' ),
					array( 'text' => 'Anderes' ),
				),
			),
		),

		/* ------------------------------------------------------------- */
		'cta'       => array(
			'label' => 'Schlussaufruf',
			'icon'  => 'cta',
			'hint'  => 'Grosse Zeile mit Knopf und Lichtschein.',
			'fields' => array(
				array( 'key' => 'title', 'type' => 'text', 'label' => 'Zeile' ),
				array( 'key' => 'outline', 'type' => 'text', 'label' => 'Wort im Umriss' ),
				array( 'key' => 'btn', 'type' => 'link', 'label' => 'Knopf' ),
				array( 'key' => 'glow', 'type' => 'toggle', 'label' => 'Lichtschein zeigen' ),
			),
			'defaults' => array(
				'title'   => 'Bereit für die',
				'outline' => 'Nacht',
				'btn'     => array( 'label' => 'Booking anfragen', 'url' => 'kontakt', 'style' => 'voll' ),
				'glow'    => true,
			),
		),
	);
}

/**
 * Ein Abschnitt mit allen Standardwerten.
 */
function rc_new_section( string $type ): ?array {
	$types = rc_section_types();

	if ( ! isset( $types[ $type ] ) ) {
		return null;
	}

	return array(
		'id'    => rc_id(),
		'type'  => $type,
		'props' => array_merge(
			array(
				'bg'           => 'schwarz',
				'space'        => 'normal',
				'borderTop'    => false,
				'borderBottom' => false,
				'anchor'       => '',
				'hideMobile'   => false,
			),
			$types[ $type ]['defaults']
		),
	);
}
