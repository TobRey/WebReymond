<?php
/**
 * Reymond CMS – Anmeldung.
 *
 * Gleiche Gestaltung wie die Website: schwarz, Filmkorn, grosse Schrift.
 */

declare(strict_types=1);

rc_session();

$rc_error = '';

if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	if ( ! rc_csrf_ok( isset( $_POST['csrf'] ) ? (string) $_POST['csrf'] : null ) ) {
		$rc_error = 'Die Sitzung ist abgelaufen. Bitte erneut versuchen.';
	} else {
		$rc_error = rc_login(
			isset( $_POST['user'] ) ? (string) $_POST['user'] : '',
			isset( $_POST['password'] ) ? (string) $_POST['password'] : ''
		);

		if ( '' === $rc_error ) {
			header( 'Location: ' . rc_url( 'editor' ) );
			exit;
		}
	}
}

if ( rc_logged_in() && 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	header( 'Location: ' . rc_url( 'editor' ) );
	exit;
}
?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Anmelden – Reymond</title>
	<meta name="robots" content="noindex, nofollow" />
	<meta name="theme-color" content="#000000" />
	<link rel="icon" href="<?php echo e( rc_url( 'assets/img/favicon.svg' ) ); ?>" type="image/svg+xml" />
	<link rel="preload" href="<?php echo e( rc_url( 'assets/fonts/anton-latin.woff2' ) ); ?>" as="font" type="font/woff2" crossorigin />
	<link rel="stylesheet" href="<?php echo e( rc_url( 'assets/css/style.css' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>" />
	<link rel="stylesheet" href="<?php echo e( rc_url( 'assets/css/editor.css' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>" />
</head>
<body class="rc-login">

<div class="grain" aria-hidden="true"></div>
<div class="vignette" aria-hidden="true"></div>

<div class="rc-login__bg" aria-hidden="true">
	<span class="rc-login__beam"></span>
</div>

<main class="rc-login__inner">
	<div class="rc-login__brand">
		<span class="brand__dot"></span>
		Reymond
	</div>

	<h1 class="display rc-login__title">
		<span class="line-mask"><span>Anmelden</span></span>
	</h1>

	<p class="rc-login__hint">Baukasten und Einstellungen von <?php echo e( rc_setting( 'siteName', 'Reymond Tobias' ) ); ?>.</p>

	<form class="form rc-login__form" method="post" autocomplete="on">
		<input type="hidden" name="csrf" value="<?php echo e( rc_csrf_token() ); ?>" />

		<div class="field">
			<input type="text" id="user" name="user" placeholder=" " required autocomplete="username" autofocus />
			<label for="user">Benutzername</label>
			<span class="field__line"></span>
		</div>

		<div class="field">
			<input type="password" id="password" name="password" placeholder=" " required autocomplete="current-password" />
			<label for="password">Passwort</label>
			<span class="field__line"></span>
		</div>

		<?php if ( $rc_error ) : ?>
			<p class="rc-login__error" role="alert"><?php echo e( $rc_error ); ?></p>
		<?php endif; ?>

		<button class="btn btn--solid" type="submit">
			Anmelden
			<span class="btn__arrow">&rarr;</span>
		</button>
	</form>

	<a class="rc-login__back" href="<?php echo e( rc_url( '' ) ); ?>">&larr; Zur Website</a>
</main>

</body>
</html>
