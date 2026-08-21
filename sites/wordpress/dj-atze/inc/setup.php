<?php
/**
 * Theme-Grundlagen: Unterstützte Funktionen, Menüs, Stile und Skripte.
 *
 * @package DjAtze
 */

defined( 'ABSPATH' ) || exit;

/**
 * Was das Theme kann.
 */
function rt_setup() {
	load_theme_textdomain( 'dj-atze', RT_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'customize-selective-refresh-widgets' );
	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
	);

	// Cover der Titel: quadratisch, wie in der Vorlage.
	add_image_size( 'rt-cover', 1000, 1000, true );

	register_nav_menus(
		array(
			'primary' => __( 'Hauptmenü', 'dj-atze' ),
			'footer'  => __( 'Fusszeile', 'dj-atze' ),
		)
	);
}
add_action( 'after_setup_theme', 'rt_setup' );

/**
 * Stile und Skripte laden.
 *
 * Die Versionsnummer kommt aus dem Änderungsdatum der Datei: so sieht
 * jeder Besucher nach einer Änderung sofort die neue Fassung.
 */
function rt_assets() {
	$css = RT_DIR . '/assets/css/style.css';

	wp_enqueue_style(
		'rt-style',
		RT_URI . '/assets/css/style.css',
		array(),
		file_exists( $css ) ? (string) filemtime( $css ) : RT_VERSION
	);

	wp_enqueue_script( 'rt-main', RT_URI . '/assets/js/main.js', array(), RT_VERSION, true );

	// Der Player läuft nur auf der Musikseite.
	if ( is_page_template( 'template-musik.php' ) ) {
		wp_enqueue_script( 'rt-player', RT_URI . '/assets/js/player.js', array(), RT_VERSION, true );
		wp_localize_script( 'rt-player', 'RT_TRACKS', rt_get_tracks() );
	}

	// Die Formularprüfung nur auf der Kontaktseite.
	if ( is_page_template( 'template-kontakt.php' ) ) {
		wp_enqueue_script( 'rt-contact', RT_URI . '/assets/js/contact.js', array(), RT_VERSION, true );
	}
}
add_action( 'wp_enqueue_scripts', 'rt_assets' );

/**
 * Schriften vorladen und Symbol setzen.
 *
 * Die Schriften liegen im Theme, es geht keine Anfrage an fremde Server.
 */
function rt_head() {
	$fonts = array( 'anton-latin.woff2', 'space-grotesk-latin.woff2' );

	foreach ( $fonts as $font ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin />' . "\n",
			esc_url( RT_URI . '/assets/fonts/' . $font )
		);
	}

	if ( ! has_site_icon() ) {
		printf(
			'<link rel="icon" href="%s" type="image/svg+xml" />' . "\n",
			esc_url( RT_URI . '/assets/img/favicon.svg' )
		);
	}

	printf( '<meta name="theme-color" content="#000000" />' . "\n" );
}
add_action( 'wp_head', 'rt_head', 5 );

/**
 * Body-Klasse je Seite, damit die Stile greifen können.
 *
 * @param array $classes Bestehende Klassen.
 * @return array
 */
function rt_body_class( $classes ) {
	if ( is_front_page() ) {
		$classes[] = 'page-home';
	} elseif ( is_page_template( 'template-musik.php' ) ) {
		$classes[] = 'page-music';
	} elseif ( is_page_template( 'template-kontakt.php' ) ) {
		$classes[] = 'page-contact';
	}

	return $classes;
}
add_filter( 'body_class', 'rt_body_class' );

/**
 * Die Admin-Leiste würde über der festen Kopfzeile liegen.
 */
function rt_admin_bar_offset() {
	if ( ! is_admin_bar_showing() ) {
		return;
	}

	echo '<style>.site-header{top:32px}@media screen and (max-width:782px){.site-header{top:46px}}</style>';
}
add_action( 'wp_head', 'rt_admin_bar_offset', 20 );
