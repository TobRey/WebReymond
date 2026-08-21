<?php
/**
 * Kleine Helfer, die in den Vorlagen benutzt werden.
 *
 * @package DjAtze
 */

defined( 'ABSPATH' ) || exit;

/**
 * Standardwerte aller einstellbaren Texte.
 *
 * Sie entsprechen eins zu eins der ausgelieferten Seite. Wer im Customizer
 * nichts ändert, sieht genau diese Inhalte.
 *
 * @return array
 */
function rt_defaults() {
	return array(
		'hero_eyebrow' => 'DJ · Schweiz',
		'hero_line1'   => 'DJ',
		'hero_line2'   => 'Atze',
		'hero_lead'    => 'Dunkel, treibend, kompromisslos auf die Tanzfläche gebaut. Sets für Club, Festival und private Anlässe.',
		'hero_cta_1'   => 'Musik hören',
		'hero_cta_2'   => 'Booking anfragen',
		'stat_1'       => 'Techno|Sound',
		'stat_2'       => 'Schweiz|Basis',
		'stat_3'       => 'Offen|Booking',
		'about_title'  => 'Wer|hinter|dem Pult|steht',
		'about_tags'   => 'Techno, Hard Groove, Melodic Dark, House, Warm Up, Closing',
		'marquee'      => 'Techno, Hard Groove, Club, Festival, Afterhour, Private Events',
		'dates_title'  => 'Wo als Nächstes',
		'cta_title'    => 'Bereit für die |Nacht',
		'cta_button'   => 'Booking anfragen',
		'email'        => 'booking@reymond-tobias.ch',
		'phone'        => '+41 00 000 00 00',
		'base'         => 'Schweiz',
		'instagram'    => '',
		'soundcloud'   => '',
		'spotify'      => '',
		'tiktok'       => '',
	);
}

/**
 * Einen einstellbaren Text holen.
 *
 * @param string $key Schlüssel aus rt_defaults().
 * @return string
 */
function rt_opt( $key ) {
	$defaults = rt_defaults();
	$default  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';

	return (string) get_theme_mod( 'rt_' . $key, $default );
}

/**
 * Eine kommagetrennte Liste in ein Feld verwandeln.
 *
 * @param string $value Liste, z. B. „Techno, House“.
 * @return array
 */
function rt_list( $value ) {
	$parts = array_map( 'trim', explode( ',', $value ) );

	return array_values( array_filter( $parts, 'strlen' ) );
}

/**
 * Bild für das Banner: eigenes Bild aus dem Customizer, sonst Platzhalter.
 *
 * @return string
 */
function rt_hero_image() {
	$image = get_theme_mod( 'rt_hero_image' );

	if ( $image ) {
		return $image;
	}

	return RT_URI . '/assets/img/banner-placeholder.svg';
}

/**
 * Navigation ausgeben.
 *
 * Wird kein Menü zugewiesen, greift die Liste der Seiten – die Seite ist
 * also auch dann vollständig bedienbar, wenn im Backend nichts eingestellt ist.
 *
 * @param string $class CSS-Klasse der Verweise.
 * @return void
 */
function rt_nav( $class = 'nav__link' ) {
	$items    = array();
	$location = get_nav_menu_locations();

	if ( ! empty( $location['primary'] ) ) {
		$menu_items = wp_get_nav_menu_items( $location['primary'] );

		if ( $menu_items ) {
			foreach ( $menu_items as $item ) {
				if ( (int) $item->menu_item_parent > 0 ) {
					continue; // Nur die oberste Ebene – das Design kennt keine Untermenüs.
				}

				$items[] = array(
					'url'   => $item->url,
					'title' => $item->title,
					'id'    => (int) $item->object_id,
				);
			}
		}
	}

	if ( ! $items ) {
		$pages = get_pages( array( 'sort_column' => 'menu_order,post_title' ) );

		foreach ( $pages as $page ) {
			$items[] = array(
				'url'   => get_permalink( $page ),
				'title' => $page->post_title,
				'id'    => (int) $page->ID,
			);
		}
	}

	$current = (int) get_queried_object_id();

	foreach ( $items as $item ) {
		$is_current = ( $item['id'] && $item['id'] === $current );

		printf(
			'<a class="%1$s" href="%2$s"%3$s>%4$s</a>',
			esc_attr( $class ),
			esc_url( $item['url'] ),
			$is_current ? ' aria-current="page"' : '',
			esc_html( $item['title'] )
		);
	}
}

/**
 * Grosse Titel: jede Zeile in eine eigene Maske, damit sie nacheinander
 * von unten einlaufen. Getrennt wird mit dem senkrechten Strich.
 *
 * @param string $value Zeilen, getrennt mit „|“.
 * @param string $split Buchstabenweise animieren?
 * @return void
 */
function rt_lines( $value, $split = false ) {
	$lines = array_map( 'trim', explode( '|', $value ) );

	foreach ( $lines as $index => $line ) {
		if ( '' === $line ) {
			continue;
		}

		printf(
			'<span class="line-mask"><span class="%1$s"%2$s>%3$s</span></span>',
			esc_attr( 1 === $index ? 'line-2' : '' ),
			$split ? ' data-split' : '',
			esc_html( $line )
		);
	}
}
