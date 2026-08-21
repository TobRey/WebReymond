<?php
/**
 * Reymond CMS – Ausgabe der Seiten.
 *
 * Jeder Abschnitt hat hier genau eine Funktion, die das gleiche HTML
 * erzeugt wie die ausgelieferte Website. Der Editor benutzt denselben
 * Renderer – deshalb sieht das Backend eins zu eins aus wie das Frontend.
 */

declare(strict_types=1);

/**
 * Ist gerade der Editor aktiv?
 */
function rc_editing(): bool {
	return ! empty( $GLOBALS['rc_edit_mode'] );
}

/**
 * Adresse aus einem Verweisfeld: interne Seite oder vollständige Adresse.
 */
function rc_link_url( string $url ): string {
	if ( '' === $url ) {
		return '#';
	}

	// Vollständige Adressen, Mail, Telefon und Sprungmarken bleiben, wie sie sind.
	if ( preg_match( '~^(https?:|mailto:|tel:|\#)~i', $url ) ) {
		return $url;
	}

	return rc_page_url( ltrim( $url, '/' ) );
}

/**
 * Bildadresse: eigene Datei aus uploads/ oder mitgeliefertes Bild.
 */
function rc_image_url( string $path ): string {
	if ( '' === $path ) {
		return '';
	}

	if ( preg_match( '#^https?://#i', $path ) ) {
		return $path;
	}

	return rc_url( ltrim( $path, '/' ) );
}

/**
 * Klassen und Stil für den Rahmen eines Abschnitts.
 *
 * @return array{0:string,1:string}
 */
function rc_section_shell( array $props ): array {
	$classes = array( 'rc-section' );
	$style   = array();

	$bg = (string) rc_get( $props, 'bg', 'schwarz' );

	$map = array(
		'schwarz' => 'var(--c-black)',
		'tief'    => '#000',
		'karte'   => 'var(--c-ink-2)',
		'verlauf' => 'linear-gradient(180deg, var(--c-ink-2), var(--c-black))',
	);

	if ( 'bild' === $bg ) {
		$image = rc_image_url( (string) rc_get( $props, 'bgImage', '' ) );
		$dim   = (int) rc_get( $props, 'bgDim', 55 ) / 100;

		if ( $image ) {
			$style[] = 'background-image: linear-gradient(rgba(0,0,0,' . $dim . '), rgba(0,0,0,' . $dim . ')), url(' . $image . ')';
			$style[] = 'background-size: cover';
			$style[] = 'background-position: center';
		}
	} elseif ( isset( $map[ $bg ] ) ) {
		$style[] = 'background: ' . $map[ $bg ];
	}

	$space = (string) rc_get( $props, 'space', 'normal' );

	$spaces = array(
		'ohne'   => '0',
		'klein'  => 'clamp(2rem, 5vw, 4rem)',
		'normal' => 'var(--section)',
		'gross'  => 'clamp(6rem, 14vw, 14rem)',
	);

	if ( isset( $spaces[ $space ] ) ) {
		$style[] = 'padding-block: ' . $spaces[ $space ];
	}

	if ( ! empty( $props['borderTop'] ) ) {
		$style[] = 'border-top: 1px solid var(--border)';
	}

	if ( ! empty( $props['borderBottom'] ) ) {
		$style[] = 'border-bottom: 1px solid var(--border)';
	}

	if ( ! empty( $props['hideMobile'] ) ) {
		$classes[] = 'rc-hide-mobile';
	}

	return array( implode( ' ', $classes ), implode( '; ', $style ) );
}

/**
 * Eine Zeile im Editor bearbeitbar machen.
 *
 * Ausserhalb des Editors kommt nichts dazu – das Frontend bleibt sauber.
 */
function rc_edit_attr( string $field ): string {
	return rc_editing() ? ' data-rc-edit="' . e( $field ) . '"' : '';
}

/* =========================================================================
 * Die einzelnen Abschnitte
 * ====================================================================== */

/**
 * Banner.
 */
function rc_render_hero( array $p ): string {
	$image = rc_image_url( (string) rc_get( $p, 'image', '' ) );
	$focus = (string) rc_get( $p, 'focus', '72% 28%' );

	$html = '<section class="hero">';

	$html .= '<div class="hero__media"><img class="hero__img" data-parallax="0.12" src="' . e( $image ) . '" alt="' . e( rc_setting( 'siteName', '' ) ) . '" style="object-position:' . e( $focus ) . '" fetchpriority="high" /></div>';
	$html .= '<div class="hero__scrim" aria-hidden="true"></div>';

	$html .= '<div class="hero__inner"><div class="hero__text">';

	if ( rc_get( $p, 'eyebrow' ) ) {
		$html .= '<p class="eyebrow hero__eyebrow reveal"' . rc_edit_attr( 'eyebrow' ) . '>' . e( rc_get( $p, 'eyebrow' ) ) . '</p>';
	}

	$html .= '<h1 class="display hero__title">';
	$html .= '<span class="line-mask"><span data-split' . rc_edit_attr( 'line1' ) . '>' . e( rc_get( $p, 'line1' ) ) . '</span></span>';

	if ( rc_get( $p, 'line2' ) ) {
		$html .= '<span class="line-mask"><span class="line-2" data-split' . rc_edit_attr( 'line2' ) . '>' . e( rc_get( $p, 'line2' ) ) . '</span></span>';
	}

	$html .= '</h1>';

	if ( rc_get( $p, 'lead' ) ) {
		$html .= '<p class="hero__lead reveal reveal--delay-2"' . rc_edit_attr( 'lead' ) . '>' . e( rc_get( $p, 'lead' ) ) . '</p>';
	}

	$html .= '<div class="hero__actions reveal reveal--delay-3">';
	$html .= rc_render_button( rc_get( $p, 'btn1', array() ) );
	$html .= rc_render_button( rc_get( $p, 'btn2', array() ) );
	$html .= '</div>';

	$html .= '</div>';

	$stats = rc_get( $p, 'stats', array() );

	if ( is_array( $stats ) && $stats ) {
		$html .= '<div class="hero__side">';

		foreach ( array_values( $stats ) as $index => $stat ) {
			$html .= '<div class="hero__stat reveal reveal--delay-' . ( $index + 1 ) . '">';
			$html .= '<b>' . e( rc_get( $stat, 'value' ) ) . '</b>';
			$html .= '<span>' . e( rc_get( $stat, 'label' ) ) . '</span>';
			$html .= '</div>';
		}

		$html .= '</div>';
	}

	$html .= '</div>';

	if ( ! empty( $p['scrollHint'] ) ) {
		$html .= '<div class="hero__scroll" aria-hidden="true"><i></i>Scrollen</div>';
	}

	$html .= '</section>';

	return $html;
}

/**
 * Knopf.
 */
function rc_render_button( $btn ): string {
	if ( ! is_array( $btn ) || '' === (string) rc_get( $btn, 'label' ) ) {
		return '';
	}

	$class = 'voll' === rc_get( $btn, 'style', 'voll' ) ? 'btn btn--solid' : 'btn';

	return '<a class="' . $class . '" href="' . e( rc_link_url( (string) rc_get( $btn, 'url' ) ) ) . '" data-magnetic>'
		. e( rc_get( $btn, 'label' ) )
		. '<span class="btn__arrow">&rarr;</span></a>';
}

/**
 * Seitentitel.
 */
function rc_render_pagehead( array $p ): string {
	$html = '<div class="shell">';

	if ( rc_get( $p, 'eyebrow' ) ) {
		$html .= '<p class="eyebrow reveal"' . rc_edit_attr( 'eyebrow' ) . '>' . e( rc_get( $p, 'eyebrow' ) ) . '</p>';
	}

	$html .= '<h1 class="display page-head__title"><span class="line-mask"><span data-split' . rc_edit_attr( 'title' ) . '>' . e( rc_get( $p, 'title' ) ) . '</span></span></h1>';

	if ( rc_get( $p, 'text' ) ) {
		$html .= '<p class="page-head__sub reveal reveal--delay-2"' . rc_edit_attr( 'text' ) . '>' . e( rc_get( $p, 'text' ) ) . '</p>';
	}

	return $html . '</div>';
}

/**
 * Laufband.
 */
function rc_render_marquee( array $p ): string {
	$items = rc_get( $p, 'items', array() );
	$class = 'marquee' . ( ! empty( $p['reverse'] ) ? ' marquee--reverse' : '' );
	$speed = (int) rc_get( $p, 'speed', 34 );

	$html = '<div class="' . $class . '" style="--speed:' . $speed . 's" aria-hidden="true"><div class="marquee__track">';

	foreach ( array_values( (array) $items ) as $index => $item ) {
		$outline = 0 === $index % 2 ? '' : ' marquee__item--outline';
		$html   .= '<span class="marquee__item' . $outline . '">' . e( rc_get( $item, 'text' ) ) . '</span>';
	}

	return $html . '</div></div>';
}

/**
 * Text mit grossem Titel.
 */
function rc_render_about( array $p ): string {
	$html = '<div class="shell"><div class="about"><div class="about__title display reveal">';

	foreach ( array_map( 'trim', explode( '|', (string) rc_get( $p, 'title' ) ) ) as $line ) {
		if ( '' === $line ) {
			continue;
		}

		$html .= '<span class="line-mask"><span>' . e( $line ) . '</span></span>';
	}

	$html .= '</div><div class="about__body"><div class="reveal"' . rc_edit_attr( 'body' ) . ' data-rc-rich="1">' . rc_kses( (string) rc_get( $p, 'body' ) ) . '</div>';

	$tags = rc_get( $p, 'tags', array() );

	if ( is_array( $tags ) && $tags ) {
		$html .= '<div class="about__tags reveal reveal--delay-3">';

		foreach ( $tags as $tag ) {
			$html .= '<span class="tag">' . e( rc_get( $tag, 'text' ) ) . '</span>';
		}

		$html .= '</div>';
	}

	return $html . '</div></div></div>';
}

/**
 * Freier Text.
 */
function rc_render_text( array $p ): string {
	$width = ! empty( $p['wide'] ) ? '' : ' style="max-width:70ch"';

	return '<div class="shell"><div class="about__body reveal"' . $width . '><div' . rc_edit_attr( 'body' ) . ' data-rc-rich="1">'
		. rc_kses( (string) rc_get( $p, 'body' ) )
		. '</div></div></div>';
}

/**
 * Termine.
 */
function rc_render_dates( array $p ): string {
	$html = '<div class="shell">';

	if ( rc_get( $p, 'eyebrow' ) ) {
		$html .= '<p class="eyebrow reveal"' . rc_edit_attr( 'eyebrow' ) . '>' . e( rc_get( $p, 'eyebrow' ) ) . '</p>';
	}

	$html .= '<h2 class="display reveal" style="font-size: var(--step-3); margin-top: 1rem"' . rc_edit_attr( 'title' ) . '>' . e( rc_get( $p, 'title' ) ) . '</h2>';
	$html .= '<div class="dates">';

	foreach ( array_values( (array) rc_get( $p, 'rows', array() ) ) as $index => $row ) {
		$html .= '<a class="date-row reveal reveal--delay-' . min( $index, 3 ) . '" href="' . e( rc_link_url( (string) rc_get( $row, 'url' ) ) ) . '">';
		$html .= '<span class="date-row__day">' . e( rc_get( $row, 'day' ) ) . '</span>';
		$html .= '<span class="date-row__venue"><b>' . e( rc_get( $row, 'venue' ) ) . '</b><span>' . e( rc_get( $row, 'place' ) ) . '</span></span>';
		$html .= '<span class="date-row__status">' . e( rc_get( $row, 'status' ) ) . '</span>';
		$html .= '</a>';
	}

	return $html . '</div></div>';
}

/**
 * Musikplayer.
 */
function rc_render_player( array $p ): string {
	$site   = rc_site();
	$tracks = isset( $site['tracks'] ) ? array_values( $site['tracks'] ) : array();

	if ( ! $tracks ) {
		return '<div class="shell"><p class="page-head__sub">Noch keine Titel angelegt – im Dashboard unter „Musik“ hinzufügen.</p></div>';
	}

	$first = $tracks[0];
	$cover = rc_image_url( (string) rc_get( $first, 'cover' ) );

	$html  = '<div class="shell"><div class="player reveal" data-player>';
	$html .= '<figure class="player__art"><img src="' . e( $cover ) . '" alt="Cover: ' . e( rc_get( $first, 'title' ) ) . '" />';
	$html .= '<figcaption class="player__index">01</figcaption>';
	$html .= '<span class="player__live"><i></i> Läuft</span></figure>';

	$html .= '<div class="player__main">';
	$html .= '<p class="player__kicker"' . rc_edit_attr( 'kicker' ) . '>' . e( rc_get( $p, 'kicker', 'Jetzt ausgewählt' ) ) . '</p>';
	$html .= '<h2 class="display player__title"><span>' . e( rc_get( $first, 'title' ) ) . '</span></h2>';
	$html .= '<div class="player__meta"><span data-meta-tag>' . e( rc_get( $first, 'tag' ) ) . '</span>';
	$html .= '<span data-meta-count>' . sprintf( '01 / %02d', count( $tracks ) ) . '</span></div>';
	$html .= '<canvas class="player__viz" aria-hidden="true"></canvas>';

	$html .= '<div class="player__scrub" role="slider" tabindex="0" aria-label="Position im Titel" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">';
	$html .= '<div class="player__scrub-track"><div class="player__scrub-fill"></div><div class="player__scrub-knob"></div></div></div>';

	$html .= '<div class="player__times"><span data-time-now>0:00</span><span data-time-total>' . e( rc_get( $first, 'duration' ) ) . '</span></div>';

	$html .= '<div class="player__controls">';
	$html .= '<button class="ctrl" type="button" data-prev aria-label="Vorheriger Titel"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h2v14H6zM20 5v14l-11-7z"/></svg></button>';
	$html .= '<button class="ctrl ctrl--play" type="button" aria-label="Abspielen">';
	$html .= '<svg class="ctrl__play" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4l14 8-14 8z"/></svg>';
	$html .= '<svg class="ctrl__pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4h4v16H6zM14 4h4v16h-4z"/></svg></button>';
	$html .= '<button class="ctrl" type="button" data-next aria-label="Nächster Titel"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 5h2v14h-2zM4 5l11 7-11 7z"/></svg></button>';

	if ( ! empty( $p['showVolume'] ) ) {
		$html .= '<label class="player__volume"><span class="visually-hidden">Lautstärke</span>';
		$html .= '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M4 9h4l5-4v14l-5-4H4zM16 9a4 4 0 010 6" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>';
		$html .= '<input type="range" min="0" max="100" value="85" data-volume /></label>';
	}

	$html .= '</div>';
	$html .= '<p class="player__notice">Vorschau – für diesen Titel ist noch keine Audiodatei hinterlegt.</p>';

	return $html . '</div></div></div>';
}

/**
 * Titelliste.
 */
function rc_render_tracklist( array $p ): string {
	$html = '<div class="shell">';

	if ( rc_get( $p, 'eyebrow' ) ) {
		$html .= '<p class="eyebrow reveal"' . rc_edit_attr( 'eyebrow' ) . '>' . e( rc_get( $p, 'eyebrow' ) ) . '</p>';
	}

	return $html . '<div class="tracklist reveal reveal--delay-1" data-tracklist></div></div>';
}

/**
 * Kontakt mit Formular.
 */
function rc_render_contact( array $p ): string {
	$html = '<div class="shell"><div class="contact">';

	if ( ! empty( $p['showInfo'] ) ) {
		$html .= '<div class="contact__info">';

		$email = (string) rc_setting( 'email' );
		$phone = (string) rc_setting( 'phone' );
		$base  = (string) rc_setting( 'base' );

		if ( $email ) {
			$html .= '<div class="info-block reveal"><b>E-Mail</b><a href="mailto:' . e( $email ) . '">' . e( $email ) . '</a></div>';
		}

		if ( $phone ) {
			$html .= '<div class="info-block reveal reveal--delay-1"><b>Telefon</b><a href="tel:' . e( preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . e( $phone ) . '</a></div>';
		}

		if ( $base ) {
			$html .= '<div class="info-block reveal reveal--delay-2"><b>Basis</b><p>' . e( $base ) . '</p></div>';
		}

		$socials = (array) rc_setting( 'socials', array() );
		$links   = '';

		foreach ( $socials as $name => $url ) {
			if ( ! $url ) {
				continue;
			}

			$links .= '<a class="tag" href="' . e( $url ) . '" target="_blank" rel="noopener">' . e( ucfirst( (string) $name ) ) . '</a>';
		}

		if ( $links ) {
			$html .= '<div class="info-block reveal reveal--delay-3"><b>Social</b><div class="socials">' . $links . '</div></div>';
		}

		$html .= '</div>';
	}

	if ( ! empty( $p['showForm'] ) ) {
		$status  = isset( $_GET['gesendet'] ) ? (string) $_GET['gesendet'] : '';
		$message = '';

		if ( 'ja' === $status ) {
			$message = 'Danke – die Anfrage ist unterwegs.';
		} elseif ( 'ungueltig' === $status ) {
			$message = 'Bitte Name, gültige E-Mail-Adresse und Anlass ausfüllen.';
		} elseif ( 'fehler' === $status ) {
			$message = 'Versand fehlgeschlagen. Bitte direkt an ' . rc_setting( 'email' ) . ' schreiben.';
		}

		$html .= '<form id="kontaktformular" class="form reveal reveal--delay-1" method="post" action="' . e( rc_url( 'anfrage' ) ) . '" data-contact-form novalidate>';
		$html .= '<input type="hidden" name="csrf" value="' . e( rc_csrf_token() ) . '" />';

		$fields = array(
			array( 'name', 'text', 'Name' ),
			array( 'email', 'email', 'E-Mail' ),
		);

		foreach ( $fields as $field ) {
			$html .= '<div class="field"><input type="' . $field[1] . '" id="' . $field[0] . '" name="' . $field[0] . '" placeholder=" " required />';
			$html .= '<label for="' . $field[0] . '">' . e( $field[2] ) . '</label><span class="field__line"></span></div>';
		}

		$html .= '<div class="field"><select id="anlass" name="anlass" required><option value="" disabled selected hidden></option>';

		foreach ( (array) rc_get( $p, 'subjects', array() ) as $subject ) {
			$html .= '<option>' . e( rc_get( $subject, 'text' ) ) . '</option>';
		}

		$html .= '</select><label for="anlass">Anlass</label><span class="field__line"></span></div>';

		foreach ( array( array( 'datum', 'Datum' ), array( 'ort', 'Ort' ) ) as $field ) {
			$html .= '<div class="field"><input type="text" id="' . $field[0] . '" name="' . $field[0] . '" placeholder=" " />';
			$html .= '<label for="' . $field[0] . '">' . e( $field[1] ) . '</label><span class="field__line"></span></div>';
		}

		$html .= '<div class="field"><textarea id="nachricht" name="nachricht" placeholder=" " rows="4"></textarea>';
		$html .= '<label for="nachricht">Nachricht</label><span class="field__line"></span></div>';

		$html .= '<div class="visually-hidden" aria-hidden="true"><label for="website">Website</label>';
		$html .= '<input type="text" id="website" name="website" tabindex="-1" autocomplete="off" /></div>';

		$html .= '<p class="form__status" data-form-status role="status">' . e( $message ) . '</p>';
		$html .= '<button class="btn btn--solid" type="submit" data-magnetic>' . e( rc_get( $p, 'submit', 'Anfrage senden' ) ) . '<span class="btn__arrow">&rarr;</span></button>';
		$html .= '</form>';
	}

	return $html . '</div></div>';
}

/**
 * Schlussaufruf.
 */
function rc_render_cta( array $p ): string {
	$html = '';

	if ( ! empty( $p['glow'] ) ) {
		$html .= '<div class="cta__glow" aria-hidden="true"></div>';
	}

	$html .= '<div class="shell" style="text-align:center">';
	$html .= '<h2 class="display cta__title reveal"><span' . rc_edit_attr( 'title' ) . '>' . e( rc_get( $p, 'title' ) ) . '</span>';

	if ( rc_get( $p, 'outline' ) ) {
		$html .= ' <em' . rc_edit_attr( 'outline' ) . '>' . e( rc_get( $p, 'outline' ) ) . '</em>';
	}

	$html .= '</h2>';
	$html .= rc_render_button( rc_get( $p, 'btn', array() ) );

	return $html . '</div>';
}

/* =========================================================================
 * Zusammenbau
 * ====================================================================== */

/**
 * Einen Abschnitt ausgeben – mit Rahmen und, im Editor, mit Kennung.
 */
function rc_render_section( array $section ): string {
	$type  = (string) rc_get( $section, 'type' );
	$props = (array) rc_get( $section, 'props', array() );

	$renderer = 'rc_render_' . preg_replace( '/[^a-z]/', '', $type );

	if ( ! function_exists( $renderer ) ) {
		return '';
	}

	$inner = $renderer( $props );

	// Das Banner bringt seinen eigenen Rahmen mit.
	if ( 'hero' === $type ) {
		$wrapper = '<div class="rc-section rc-section--hero"%s>%s</div>';

		return sprintf(
			$wrapper,
			rc_editing() ? ' data-rc-section="' . e( rc_get( $section, 'id' ) ) . '" data-rc-type="hero"' : '',
			$inner
		);
	}

	list( $classes, $style ) = rc_section_shell( $props );

	$extra = '';

	if ( 'cta' === $type ) {
		$classes .= ' cta';
	}

	if ( 'marquee' === $type ) {
		$classes .= ' rc-section--flat';
	}

	if ( rc_get( $props, 'anchor' ) ) {
		$extra .= ' id="' . e( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) rc_get( $props, 'anchor' ) ) ) . '"';
	}

	if ( rc_editing() ) {
		$extra .= ' data-rc-section="' . e( rc_get( $section, 'id' ) ) . '" data-rc-type="' . e( $type ) . '"';
	}

	return '<section class="' . e( $classes ) . '" style="' . e( $style ) . '"' . $extra . '>' . $inner . '</section>';
}

/**
 * Alle Abschnitte einer Seite.
 */
function rc_render_page( array $page ): string {
	$html = '';

	foreach ( (array) rc_get( $page, 'sections', array() ) as $section ) {
		$html .= rc_render_section( $section );
	}

	return $html;
}

/**
 * Erlaubtes HTML im Fliesstext – alles andere wird entfernt.
 */
function rc_kses( string $html ): string {
	return strip_tags( $html, '<p><br><strong><b><em><i><u><a><ul><ol><li><h2><h3><blockquote><span>' );
}
