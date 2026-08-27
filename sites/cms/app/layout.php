<?php
/**
 * Backend – Kopf, Navigation und Fuss der Website.
 *
 * Dieselbe Ausgabe für Frontend und Editor. Der Editor legt lediglich
 * seine Werkzeugleiste darüber.
 */

declare(strict_types=1);

/**
 * Kopfbereich einer Seite.
 */
function rc_head( array $page ): void {
	$site     = rc_site();
	$settings = $site['settings'];
	$seo      = (array) rc_get( $page, 'seo', array() );
	$title    = rc_get( $seo, 'title' ) ? $seo['title'] : ( rc_get( $page, 'title' ) . ' – ' . rc_get( $settings, 'siteName' ) );
	$effects  = (array) rc_get( $settings, 'effects', array() );
	?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
	<title><?php echo e( $title ); ?></title>
	<meta name="description" content="<?php echo e( rc_get( $seo, 'description' ) ); ?>" />
	<meta name="theme-color" content="#000000" />

	<meta property="og:type" content="website" />
	<meta property="og:title" content="<?php echo e( $title ); ?>" />
	<meta property="og:description" content="<?php echo e( rc_get( $seo, 'description' ) ); ?>" />

	<link rel="icon" href="<?php echo e( rc_url( 'assets/img/favicon.svg' ) ); ?>" type="image/svg+xml" />
	<link rel="preload" href="<?php echo e( rc_url( 'assets/fonts/anton-latin.woff2' ) ); ?>" as="font" type="font/woff2" crossorigin />
	<link rel="preload" href="<?php echo e( rc_url( 'assets/fonts/space-grotesk-latin.woff2' ) ); ?>" as="font" type="font/woff2" crossorigin />
	<link rel="stylesheet" href="<?php echo e( rc_url( 'assets/css/style.css' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>" />
	<?php if ( rc_editing() ) : ?>
		<link rel="stylesheet" href="<?php echo e( rc_url( 'assets/css/editor.css' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>" />
	<?php endif; ?>
</head>
<body class="<?php echo e( rc_editing() ? 'rc-canvas' : '' ); ?>">

	<?php if ( ! rc_editing() && ! empty( $effects['preloader'] ) ) : ?>
		<div class="preloader">
			<div class="preloader__mark"><?php echo e( rc_get( $settings, 'siteName' ) ); ?></div>
			<span class="preloader__bar"></span>
		</div>
	<?php endif; ?>

	<?php if ( ! rc_editing() && ! empty( $effects['waves'] ) ) : ?>
		<canvas id="rc-bg" aria-hidden="true"></canvas>
	<?php endif; ?>

	<?php if ( ! empty( $effects['grain'] ) ) : ?>
		<div class="grain" aria-hidden="true"></div>
	<?php endif; ?>

	<?php if ( ! empty( $effects['vignette'] ) ) : ?>
		<div class="vignette" aria-hidden="true"></div>
	<?php endif; ?>

	<?php if ( ! rc_editing() && ! empty( $effects['cursor'] ) ) : ?>
		<div class="cursor" aria-hidden="true"></div>
		<div class="cursor-dot" aria-hidden="true"></div>
	<?php endif; ?>

	<header class="site-header">
		<div class="site-header__inner">
			<a class="brand" href="<?php echo e( rc_url( '' ) ); ?>">
				<span class="brand__dot"></span>
				<?php echo e( rc_get( $settings, 'siteName' ) ); ?>
			</a>

			<nav class="nav" aria-label="Hauptnavigation">
				<?php rc_nav( 'nav__link', (string) rc_get( $page, 'slug' ) ); ?>
			</nav>

			<button class="burger" type="button" aria-expanded="false" aria-label="Menü öffnen">
				<span></span>
				<span></span>
			</button>
		</div>
	</header>

	<nav class="menu" aria-label="Menü">
		<?php rc_nav( 'menu__link', (string) rc_get( $page, 'slug' ) ); ?>
		<div class="menu__meta">
			<?php if ( rc_get( $settings, 'base' ) ) : ?>
				<span><?php echo e( rc_get( $settings, 'base' ) ); ?></span>
			<?php endif; ?>
			<?php if ( rc_get( $settings, 'email' ) ) : ?>
				<a href="mailto:<?php echo e( rc_get( $settings, 'email' ) ); ?>"><?php echo e( rc_get( $settings, 'email' ) ); ?></a>
			<?php endif; ?>
		</div>
	</nav>

	<main id="inhalt">
	<?php
}

/**
 * Navigation ausgeben.
 */
function rc_nav( string $class, string $current ): void {
	$site = rc_site();

	foreach ( $site['pages'] as $slug => $page ) {
		if ( ! empty( $page['hidden'] ) ) {
			continue;
		}

		printf(
			'<a class="%1$s" href="%2$s"%3$s data-rc-page="%4$s">%5$s</a>',
			e( $class ),
			e( rc_page_url( (string) $slug ) ),
			$slug === $current ? ' aria-current="page"' : '',
			e( $slug ),
			e( rc_get( $page, 'title' ) )
		);
	}
}

/**
 * Fussbereich.
 */
function rc_footer(): void {
	$site     = rc_site();
	$settings = $site['settings'];
	?>
	</main>

	<footer class="site-footer">
		<div class="shell">
			<div class="site-footer__top">
				<a class="site-footer__mark" href="<?php echo e( rc_url( '' ) ); ?>"><?php echo e( rc_get( $settings, 'siteName' ) ); ?></a>

				<div class="site-footer__links">
					<?php rc_nav( '', '' ); ?>
					<?php if ( rc_get( $settings, 'email' ) ) : ?>
						<a href="mailto:<?php echo e( rc_get( $settings, 'email' ) ); ?>">E-Mail</a>
					<?php endif; ?>
				</div>
			</div>

			<div class="site-footer__bottom">
				<span>&copy; <?php echo e( gmdate( 'Y' ) ); ?> <?php echo e( rc_get( $settings, 'siteName' ) ); ?></span>
				<span><?php echo e( $_SERVER['HTTP_HOST'] ?? '' ); ?></span>
			</div>
		</div>
	</footer>

	<script>
		window.RC_TRACKS = <?php echo json_encode( rc_public_tracks(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
		window.RT_TRACKS = window.RC_TRACKS;
	</script>
	<?php if ( ! rc_editing() ) : ?>
		<script src="<?php echo e( rc_url( 'assets/js/main.js' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>" defer></script>
		<script src="<?php echo e( rc_url( 'assets/js/motion.js' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>" defer></script>
		<script src="<?php echo e( rc_url( 'assets/js/player.js' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>" defer></script>
		<script src="<?php echo e( rc_url( 'assets/js/contact.js' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>" defer></script>
	<?php else : ?>
		<script src="<?php echo e( rc_url( 'assets/js/canvas.js' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>" defer></script>
	<?php endif; ?>
</body>
</html>
	<?php
}

/**
 * Titel für den Player – mit fertigen Adressen.
 */
function rc_public_tracks(): array {
	$site   = rc_site();
	$tracks = array();

	foreach ( (array) rc_get( $site, 'tracks', array() ) as $track ) {
		$tracks[] = array(
			'title'    => (string) rc_get( $track, 'title' ),
			'tag'      => (string) rc_get( $track, 'tag' ),
			'duration' => (string) rc_get( $track, 'duration' ),
			'audio'    => rc_image_url( (string) rc_get( $track, 'audio' ) ),
			'cover'    => rc_image_url( (string) rc_get( $track, 'cover' ) ),
		);
	}

	return $tracks;
}
