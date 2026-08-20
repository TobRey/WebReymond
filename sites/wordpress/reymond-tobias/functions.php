<?php
/**
 * Reymond Tobias – Theme-Funktionen.
 *
 * Die Datei lädt nur die einzelnen Bausteine. Alles Weitere steht in inc/.
 *
 * @package ReymondTobias
 */

defined( 'ABSPATH' ) || exit;

define( 'RT_VERSION', '1.0.0' );
define( 'RT_DIR', get_template_directory() );
define( 'RT_URI', get_template_directory_uri() );

require_once RT_DIR . '/inc/setup.php';          // Theme-Grundlagen, Skripte, Stile.
require_once RT_DIR . '/inc/template-tags.php';  // Kleine Helfer für die Vorlagen.
require_once RT_DIR . '/inc/customizer.php';     // Einstellbare Texte und Kontaktdaten.
require_once RT_DIR . '/inc/tracks.php';         // Inhaltstyp „Titel“ für den Player.
require_once RT_DIR . '/inc/gigs.php';           // Inhaltstyp „Termin“.
require_once RT_DIR . '/inc/contact.php';        // Versand des Booking-Formulars.
require_once RT_DIR . '/inc/activation.php';     // Seiten, Menü und Beispielinhalte.
