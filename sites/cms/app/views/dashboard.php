<?php
/**
 * Backend – Dashboard.
 *
 * Alle Einstellungen, die nicht direkt auf der Seite bearbeitet werden:
 * Website-Angaben, Musik, Seiten, Darstellung, Anfragen, Zugang, Daten.
 */

declare(strict_types=1);

require_once RC_APP . '/upload.php';

$rc_site     = rc_site();
$rc_settings = $rc_site['settings'];
$rc_requests = rc_read_json( RC_DATA . '/anfragen.json' );
$rc_uploads  = rc_uploads_list();
?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Backend – Einstellungen</title>
	<meta name="robots" content="noindex, nofollow" />
	<link rel="icon" href="<?php echo e( rc_url( 'assets/img/favicon.svg' ) ); ?>" type="image/svg+xml" />
	<link rel="preload" href="<?php echo e( rc_url( 'assets/fonts/anton-latin.woff2' ) ); ?>" as="font" type="font/woff2" crossorigin />
	<link rel="stylesheet" href="<?php echo e( rc_url( 'assets/css/style.css' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>" />
	<link rel="stylesheet" href="<?php echo e( rc_url( 'assets/css/editor.css' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>" />
</head>
<body class="rc-dash">

<div class="grain" aria-hidden="true"></div>

<header class="rc-dash__bar">
	<span class="rc-bar__brand"><span class="brand__dot"></span> Backend</span>

	<div class="rc-bar__group">
		<a class="rc-btn" href="<?php echo e( rc_url( 'editor' ) ); ?>">&larr; Baukasten</a>
		<a class="rc-btn" href="<?php echo e( rc_url( '' ) ); ?>" target="_blank" rel="noopener">Website ansehen</a>
		<a class="rc-btn" href="<?php echo e( rc_url( 'logout' ) ); ?>">Abmelden</a>
	</div>
</header>

<main class="rc-dash__inner">
	<h1 class="rc-dash__title">Einstellungen</h1>
	<p class="rc-dash__sub">Angemeldet als <?php echo e( rc_current_user() ); ?>. Änderungen wirken sofort auf der Website.</p>

	<nav class="rc-nav" id="rc-dash-nav">
		<button class="is-active" type="button" data-panel="website">Website</button>
		<button type="button" data-panel="musik">Musik</button>
		<button type="button" data-panel="seiten">Seiten</button>
		<button type="button" data-panel="darstellung">Darstellung</button>
		<button type="button" data-panel="anfragen">Anfragen<?php echo $rc_requests ? ' (' . count( $rc_requests ) . ')' : ''; ?></button>
		<button type="button" data-panel="dateien">Dateien</button>
		<button type="button" data-panel="konto">Konto</button>
		<button type="button" data-panel="daten">Daten</button>
	</nav>

	<!-- ================= Website ================= -->
	<section class="rc-tab-panel" data-panel="website">
		<div class="rc-card">
			<h2>Angaben zur Website</h2>
			<p class="rc-card__hint">Diese Angaben erscheinen im Kopf, im Fuss und auf der Kontaktseite.</p>

			<div class="rc-grid">
				<div class="rc-field">
					<label for="s-siteName">Name</label>
					<input type="text" id="s-siteName" data-setting="siteName" value="<?php echo e( rc_get( $rc_settings, 'siteName' ) ); ?>" />
				</div>
				<div class="rc-field">
					<label for="s-tagline">Untertitel</label>
					<input type="text" id="s-tagline" data-setting="tagline" value="<?php echo e( rc_get( $rc_settings, 'tagline' ) ); ?>" />
				</div>
				<div class="rc-field">
					<label for="s-email">E-Mail für Anfragen</label>
					<input type="text" id="s-email" data-setting="email" value="<?php echo e( rc_get( $rc_settings, 'email' ) ); ?>" />
				</div>
				<div class="rc-field">
					<label for="s-phone">Telefon</label>
					<input type="text" id="s-phone" data-setting="phone" value="<?php echo e( rc_get( $rc_settings, 'phone' ) ); ?>" />
				</div>
				<div class="rc-field">
					<label for="s-base">Basis / Ort</label>
					<input type="text" id="s-base" data-setting="base" value="<?php echo e( rc_get( $rc_settings, 'base' ) ); ?>" />
				</div>
				<div class="rc-field">
					<label for="s-mailTo">Anfragen senden an</label>
					<input type="text" id="s-mailTo" data-setting="mailTo" value="<?php echo e( rc_get( $rc_settings, 'mailTo' ) ); ?>" />
					<span class="rc-hint">Leer lassen: dann geht alles an die E-Mail oben.</span>
				</div>
			</div>
		</div>

		<div class="rc-card">
			<h2>Social</h2>
			<p class="rc-card__hint">Leere Felder erscheinen nicht auf der Seite.</p>

			<div class="rc-grid">
				<?php foreach ( array( 'instagram' => 'Instagram', 'soundcloud' => 'SoundCloud', 'spotify' => 'Spotify', 'tiktok' => 'TikTok' ) as $key => $label ) : ?>
					<div class="rc-field">
						<label for="s-<?php echo e( $key ); ?>"><?php echo e( $label ); ?></label>
						<input type="text" id="s-<?php echo e( $key ); ?>" data-social="<?php echo e( $key ); ?>"
							value="<?php echo e( rc_get( (array) rc_get( $rc_settings, 'socials', array() ), $key ) ); ?>" />
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<button class="rc-btn rc-btn--primary" type="button" id="rc-save-settings">Einstellungen speichern</button>
	</section>

	<!-- ================= Musik ================= -->
	<section class="rc-tab-panel" data-panel="musik" hidden>
		<div class="rc-card">
			<h2>Titel im Player</h2>
			<p class="rc-card__hint">Reihenfolge mit den Pfeilen. Ohne Audiodatei zeigt der Player den Vorschaumodus.</p>

			<table class="rc-table" id="rc-tracks">
				<thead>
					<tr>
						<th style="width:26%">Titel</th>
						<th style="width:20%">Stilrichtung</th>
						<th style="width:10%">Dauer</th>
						<th>Audiodatei</th>
						<th style="width:12%">Cover</th>
						<th style="width:14%"></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>

			<div style="display:flex;gap:0.5rem;margin-top:1rem">
				<button class="rc-btn" type="button" id="rc-track-add">+ Titel</button>
				<button class="rc-btn rc-btn--primary" type="button" id="rc-tracks-save">Musik speichern</button>
			</div>
		</div>
	</section>

	<!-- ================= Seiten ================= -->
	<section class="rc-tab-panel" data-panel="seiten" hidden>
		<div class="rc-card">
			<h2>Seiten</h2>
			<p class="rc-card__hint">Die Reihenfolge hier ist auch die Reihenfolge im Menü.</p>

			<table class="rc-table">
				<thead>
					<tr><th>Name</th><th>Adresse</th><th style="width:30%"></th></tr>
				</thead>
				<tbody>
					<?php foreach ( $rc_site['pages'] as $slug => $page ) : ?>
						<tr data-slug="<?php echo e( $slug ); ?>">
							<td><input type="text" data-page-title value="<?php echo e( $page['title'] ); ?>" /></td>
							<td class="rc-note">/<?php echo e( rc_home_slug() === $slug ? '' : $slug ); ?><?php echo ! empty( $page['home'] ) ? ' · Startseite' : ''; ?></td>
							<td>
								<div class="rc-row-actions">
									<a class="rc-btn" href="<?php echo e( rc_url( 'editor?page=' . $slug ) ); ?>">Bearbeiten</a>
									<button class="rc-btn" type="button" data-page-rename>Umbenennen</button>
									<?php if ( empty( $page['home'] ) ) : ?>
										<button class="rc-btn" type="button" data-page-delete>Löschen</button>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div style="display:flex;gap:0.5rem;margin-top:1.2rem;align-items:flex-end">
				<div class="rc-field" style="margin:0;flex:1;max-width:280px">
					<label for="rc-new-page">Neue Seite</label>
					<input type="text" id="rc-new-page" placeholder="z. B. Galerie" />
				</div>
				<button class="rc-btn rc-btn--primary" type="button" id="rc-page-add">Anlegen</button>
			</div>
		</div>
	</section>

	<!-- ================= Darstellung ================= -->
	<section class="rc-tab-panel" data-panel="darstellung" hidden>
		<div class="rc-card">
			<h2>Effekte</h2>
			<p class="rc-card__hint">Die Seite bleibt schwarz-weiss; hier lässt sich nur an- und abschalten.</p>

			<div class="rc-grid">
				<?php
				$rc_effects = (array) rc_get( $rc_settings, 'effects', array() );
				$rc_labels  = array(
					'grain'     => 'Filmkorn',
					'cursor'    => 'Eigener Mauszeiger',
					'preloader' => 'Ladevorhang',
					'vignette'  => 'Abdunklung an den Rändern',
				);

				foreach ( $rc_labels as $key => $label ) :
					?>
					<div class="rc-field">
						<label class="rc-switch">
							<span class="rc-label" style="margin:0"><?php echo e( $label ); ?></span>
							<input type="checkbox" data-effect="<?php echo e( $key ); ?>"<?php echo ! empty( $rc_effects[ $key ] ) ? ' checked' : ''; ?> />
							<span class="rc-switch__track"></span>
						</label>
					</div>
				<?php endforeach; ?>
			</div>

			<button class="rc-btn rc-btn--primary" type="button" id="rc-save-effects" style="margin-top:1.2rem">Speichern</button>
		</div>
	</section>

	<!-- ================= Anfragen ================= -->
	<section class="rc-tab-panel" data-panel="anfragen" hidden>
		<div class="rc-card">
			<h2>Eingegangene Anfragen</h2>
			<p class="rc-card__hint">Jede Anfrage wird zusätzlich per E-Mail verschickt. Hier stehen die letzten 200.</p>

			<?php if ( ! $rc_requests ) : ?>
				<p class="rc-note">Noch keine Anfragen.</p>
			<?php else : ?>
				<table class="rc-table">
					<thead>
						<tr><th>Wann</th><th>Wer</th><th>Anlass</th><th>Datum / Ort</th><th>Nachricht</th></tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $rc_requests, 0, 50 ) as $request ) : ?>
							<tr>
								<td class="rc-note"><?php echo e( gmdate( 'd.m.Y H:i', strtotime( (string) rc_get( $request, 'time' ) ) ) ); ?></td>
								<td>
									<?php echo e( rc_get( $request, 'name' ) ); ?><br />
									<a class="rc-note" href="mailto:<?php echo e( rc_get( $request, 'email' ) ); ?>"><?php echo e( rc_get( $request, 'email' ) ); ?></a>
								</td>
								<td><?php echo e( rc_get( $request, 'anlass' ) ); ?></td>
								<td class="rc-note"><?php echo e( trim( rc_get( $request, 'datum' ) . ' ' . rc_get( $request, 'ort' ) ) ); ?></td>
								<td class="rc-note"><?php echo e( rc_get( $request, 'nachricht' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</section>

	<!-- ================= Dateien ================= -->
	<section class="rc-tab-panel" data-panel="dateien" hidden>
		<div class="rc-card">
			<h2>Hochgeladene Dateien</h2>
			<p class="rc-card__hint">Bilder und Musik, die im Baukasten oder hier hochgeladen wurden.</p>

			<div style="display:flex;gap:0.5rem;align-items:center;margin-bottom:1.2rem">
				<input type="file" id="rc-file" accept="image/*,audio/*" />
				<button class="rc-btn rc-btn--primary" type="button" id="rc-file-upload">Hochladen</button>
			</div>

			<?php if ( ! $rc_uploads ) : ?>
				<p class="rc-note">Noch keine Dateien.</p>
			<?php else : ?>
				<table class="rc-table">
					<thead><tr><th>Datei</th><th>Grösse</th><th>Pfad</th></tr></thead>
					<tbody>
						<?php foreach ( $rc_uploads as $file ) : ?>
							<tr>
								<td><a href="<?php echo e( $file['url'] ); ?>" target="_blank" rel="noopener"><?php echo e( $file['name'] ); ?></a></td>
								<td class="rc-note"><?php echo e( round( $file['size'] / 1024 ) ); ?> KB</td>
								<td class="rc-note"><?php echo e( $file['path'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</section>

	<!-- ================= Konto ================= -->
	<section class="rc-tab-panel" data-panel="konto" hidden>
		<div class="rc-card">
			<h2>Zugang ändern</h2>
			<p class="rc-card__hint">Zum Ändern immer das bisherige Passwort eingeben.</p>

			<div class="rc-grid">
				<div class="rc-field">
					<label for="a-current">Bisheriges Passwort</label>
					<input type="password" id="a-current" autocomplete="current-password" />
				</div>
				<div class="rc-field">
					<label for="a-user">Benutzername</label>
					<input type="text" id="a-user" value="<?php echo e( rc_current_user() ); ?>" autocomplete="username" />
				</div>
				<div class="rc-field">
					<label for="a-password">Neues Passwort</label>
					<input type="password" id="a-password" autocomplete="new-password" placeholder="mindestens 10 Zeichen" />
				</div>
			</div>

			<button class="rc-btn rc-btn--primary" type="button" id="rc-save-account" style="margin-top:1rem">Zugang speichern</button>
		</div>
	</section>

	<!-- ================= Daten ================= -->
	<section class="rc-tab-panel" data-panel="daten" hidden>
		<div class="rc-card">
			<h2>Sicherung</h2>
			<p class="rc-card__hint">Alle Inhalte liegen in einer einzigen Datei. Vor grösseren Umbauten kurz herunterladen.</p>

			<div style="display:flex;gap:0.5rem;flex-wrap:wrap">
				<a class="rc-btn" href="<?php echo e( rc_url( 'api/export' ) ); ?>">Inhalte herunterladen</a>
			</div>

			<p class="rc-note" style="margin-top:1rem">
				Beim Speichern legt das System zusätzlich automatisch die letzten drei Stände
				unter <code>data/backups/</code> ab.
			</p>
		</div>

		<div class="rc-card rc-danger">
			<h2>Zurücksetzen</h2>
			<p class="rc-card__hint">Stellt alle Seiten auf den Auslieferungszustand zurück. Musik, Dateien und Zugang bleiben erhalten.</p>
			<button class="rc-btn" type="button" id="rc-reset">Inhalte zurücksetzen</button>
		</div>

		<div class="rc-card">
			<h2>Über</h2>
			<p class="rc-note">
				Backend <?php echo e( RC_VERSION ); ?> · PHP <?php echo e( PHP_VERSION ); ?><br />
				Inhalte: <code>data/site.json</code> · Dateien: <code>uploads/</code>
			</p>
		</div>
	</section>
</main>

<div class="rc-toast" id="rc-toast"></div>

<script>
	window.RC = {
		base: <?php echo json_encode( rc_url( '' ) ); ?>,
		token: <?php echo json_encode( rc_csrf_token() ); ?>,
		tracks: <?php echo json_encode( array_values( (array) rc_get( $rc_site, 'tracks', array() ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>
	};
</script>
<script src="<?php echo e( rc_url( 'assets/js/dashboard.js' ) ); ?>?v=<?php echo e( RC_VERSION ); ?>"></script>

</body>
</html>
