<?php
/**
 * Kopf der Seite: Ladevorhang, Effekte, Kopfzeile und Menü.
 *
 * @package DjAtze
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="preloader">
	<div class="preloader__mark"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
	<span class="preloader__bar"></span>
</div>

<div class="grain" aria-hidden="true"></div>
<div class="vignette" aria-hidden="true"></div>
<div class="cursor" aria-hidden="true"></div>
<div class="cursor-dot" aria-hidden="true"></div>

<a class="visually-hidden" href="#inhalt"><?php esc_html_e( 'Zum Inhalt springen', 'dj-atze' ); ?></a>

<header class="site-header">
	<div class="site-header__inner">
		<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<span class="brand__dot"></span>
			<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
		</a>

		<nav class="nav" aria-label="<?php esc_attr_e( 'Hauptnavigation', 'dj-atze' ); ?>">
			<?php rt_nav( 'nav__link' ); ?>
		</nav>

		<button class="burger" type="button" aria-expanded="false" aria-label="<?php esc_attr_e( 'Menü öffnen', 'dj-atze' ); ?>">
			<span></span>
			<span></span>
		</button>
	</div>
</header>

<nav class="menu" aria-label="<?php esc_attr_e( 'Menü', 'dj-atze' ); ?>">
	<?php rt_nav( 'menu__link' ); ?>

	<div class="menu__meta">
		<?php if ( rt_opt( 'base' ) ) : ?>
			<span><?php echo esc_html( rt_opt( 'base' ) ); ?></span>
		<?php endif; ?>

		<?php if ( rt_opt( 'email' ) ) : ?>
			<a href="mailto:<?php echo esc_attr( rt_opt( 'email' ) ); ?>"><?php echo esc_html( rt_opt( 'email' ) ); ?></a>
		<?php endif; ?>
	</div>
</nav>

<main id="inhalt">
