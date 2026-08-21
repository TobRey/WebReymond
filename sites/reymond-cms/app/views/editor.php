<?php
/**
 * Reymond CMS – der Baukasten.
 *
 * Aufbau: eine Werkzeugleiste oben, darunter die Seite selbst in einem
 * Rahmen (iframe). Die Breite der Seite bleibt dadurch unangetastet –
 * das Backend sieht exakt aus wie das Frontend.
 *
 * Panels (Elemente, Einstellungen) legen sich darüber, sie schieben
 * nichts zur Seite.
 */

declare(strict_types=1);

$rc_slug = isset( $_GET['page'] ) ? preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $_GET['page'] ) ) : rc_home_slug();

if ( ! rc_page( $rc_slug ) ) {
	$rc_slug = rc_home_slug();
}

$rc_site  = rc_site();
$rc_types = rc_section_types();
?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Reymond – Baukasten</title>
	<meta name="robots" content="noindex, nofollow" />
	<link rel="icon" href="<?php echo e( rc_url( 'assets/img/favicon.svg' ) ); ?>" type="image/svg+xml" />
	<link rel="preload" href="<?php echo e( rc_url( 'assets/fonts/anton-latin.woff2' ) ); ?>" as="font" type="font/woff2" crossorigin />
	<link rel="stylesheet" href="<?php echo e( rc_url( 'assets/css/style.css' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>" />
	<link rel="stylesheet" href="<?php echo e( rc_url( 'assets/css/editor.css' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>" />
</head>
<body class="rc-editor">

<!-- ================= Werkzeugleiste ================= -->
<header class="rc-bar" id="rc-bar">
	<div class="rc-bar__group">
		<span class="rc-bar__brand"><span class="brand__dot"></span> Reymond</span>

		<label class="rc-select">
			<span class="visually-hidden">Seite</span>
			<select id="rc-page-select">
				<?php foreach ( $rc_site['pages'] as $slug => $page ) : ?>
					<option value="<?php echo e( $slug ); ?>"<?php echo $slug === $rc_slug ? ' selected' : ''; ?>>
						<?php echo e( $page['title'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<button class="rc-btn" type="button" id="rc-add" title="Elemente einfügen">
			<span class="rc-plus">+</span> Elemente
		</button>
	</div>

	<div class="rc-bar__group rc-bar__center">
		<div class="rc-devices" role="group" aria-label="Ansicht">
			<button class="rc-device is-active" type="button" data-device="desktop" title="Desktop">
				<svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="13" rx="1.5"/><path d="M8 21h8"/></svg>
			</button>
			<button class="rc-device" type="button" data-device="tablet" title="iPad">
				<svg viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M11 18.5h2"/></svg>
			</button>
			<button class="rc-device" type="button" data-device="mobile" title="Handy">
				<svg viewBox="0 0 24 24"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18.5h2"/></svg>
			</button>
		</div>
	</div>

	<div class="rc-bar__group">
		<span class="rc-status" id="rc-status">Bereit</span>

		<button class="rc-btn" type="button" id="rc-undo" title="Rückgängig (Strg+Z)">
			<svg viewBox="0 0 24 24"><path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 010 10h-3"/></svg>
		</button>

		<a class="rc-btn" href="<?php echo e( rc_page_url( $rc_slug ) ); ?>" target="_blank" rel="noopener" title="Vorschau im neuen Tab">
			<svg viewBox="0 0 24 24"><path d="M12 5c5 0 9 4.5 9 7s-4 7-9 7-9-4.5-9-7 4-7 9-7z"/><circle cx="12" cy="12" r="2.5"/></svg>
		</a>

		<button class="rc-btn rc-btn--primary" type="button" id="rc-save">Speichern</button>

		<a class="rc-btn rc-btn--icon" href="<?php echo e( rc_url( 'dashboard' ) ); ?>" title="Einstellungen" id="rc-gear">
			<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.2"/><path d="M12 2.6l1.4 2.6 2.9-.6.6 2.9 2.6 1.4-1.5 2.6 1.5 2.6-2.6 1.4-.6 2.9-2.9-.6L12 21.4l-1.4-2.6-2.9.6-.6-2.9-2.6-1.4L6 12.5 4.5 9.9l2.6-1.4.6-2.9 2.9.6z"/></svg>
		</a>

		<button class="rc-btn rc-btn--icon" type="button" id="rc-hide" title="Leiste ausblenden">
			<svg viewBox="0 0 24 24"><path d="M5 15l7-7 7 7"/></svg>
		</button>
	</div>
</header>

<button class="rc-show" type="button" id="rc-show" title="Leiste einblenden">
	<svg viewBox="0 0 24 24"><path d="M5 9l7 7 7-7"/></svg>
	Reymond
</button>

<!-- ================= Elementeauswahl ================= -->
<div class="rc-panel rc-panel--elements" id="rc-elements" hidden>
	<div class="rc-panel__head">
		<b>Elemente</b>
		<span>Auf die Seite ziehen oder anklicken zum Anhängen</span>
		<button class="rc-x" type="button" data-close-elements aria-label="Schliessen">&times;</button>
	</div>

	<div class="rc-elements">
		<?php foreach ( $rc_types as $type => $info ) : ?>
			<button class="rc-element" type="button" draggable="true" data-type="<?php echo e( $type ); ?>">
				<span class="rc-element__icon" data-icon="<?php echo e( $info['icon'] ); ?>" aria-hidden="true"></span>
				<b><?php echo e( $info['label'] ); ?></b>
				<span><?php echo e( $info['hint'] ); ?></span>
			</button>
		<?php endforeach; ?>
	</div>
</div>

<!-- ================= Einstellungen des Abschnitts ================= -->
<aside class="rc-inspector" id="rc-inspector" hidden>
	<div class="rc-inspector__head">
		<b id="rc-inspector-title">Abschnitt</b>
		<button class="rc-x" type="button" id="rc-inspector-close" aria-label="Schliessen">&times;</button>
	</div>

	<div class="rc-tabs" role="tablist">
		<button class="rc-tab is-active" type="button" data-tab="inhalt" role="tab">Inhalt</button>
		<button class="rc-tab" type="button" data-tab="stil" role="tab">Stil</button>
		<button class="rc-tab" type="button" data-tab="erweitert" role="tab">Erweitert</button>
	</div>

	<div class="rc-inspector__body" id="rc-fields"></div>
</aside>

<!-- ================= Die Seite selbst ================= -->
<div class="rc-stage" id="rc-stage">
	<div class="rc-frame" id="rc-frame">
		<iframe
			id="rc-canvas"
			title="Seite bearbeiten"
			src="<?php echo e( rc_page_url( $rc_slug ) ); ?><?php echo false === strpos( rc_page_url( $rc_slug ), '?' ) ? '?' : '&'; ?>rc_edit=1"
		></iframe>
	</div>
</div>

<script>
	window.RC = {
		base: <?php echo json_encode( rc_url( '' ) ); ?>,
		slug: <?php echo json_encode( $rc_slug ); ?>,
		token: <?php echo json_encode( rc_csrf_token() ); ?>,
		user: <?php echo json_encode( rc_current_user() ); ?>
	};
</script>
<script src="<?php echo e( rc_url( 'assets/js/editor.js' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>"></script>

</body>
</html>
