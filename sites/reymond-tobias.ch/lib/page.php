<?php
/**
 * Die Startseite – ein Geruest fuer beide Sprachen.
 *
 * Erwartet vor dem Einbinden:
 *   $lang  'de' oder 'en'
 *   $base  Pfad zurueck zum Wurzelverzeichnis ('' oder '../')
 *
 * Jede Liste (Schwerpunkte, Kenntnisse, Projekte, Werdegang, Freizeit) wird
 * durchlaufen. Kommt im Bearbeitungsbereich ein Eintrag dazu, erscheint er
 * hier automatisch – am Aufbau der Seite muss dafuer nichts geaendert werden.
 */

if (!isset($lang)) { $lang = 'de'; }
if (!isset($base)) { $base = ''; }
$lang = ($lang === 'en') ? 'en' : 'de';

require_once __DIR__ . '/content.php';
require_once __DIR__ . '/strings.php';

$c  = rt_content($lang);
$ui = rt_ui($lang);
$s  = rt_site();

$isEn      = ($lang === 'en');
$urlDe     = $isEn ? '../' : './';
$urlEn     = $isEn ? './'  : 'en/';
$legalUrl  = $isEn ? 'legal-notice.html' : 'impressum.html';
$privUrl   = $isEn ? 'privacy.html'      : 'datenschutz.html';
$otherUrl  = $isEn ? '../' : 'en/';
$canonical = $s['url'] . ($isEn ? '/en/' : '/');
$tab       = $ui['new_tab'];

$portrait   = rt_media_src($c, 'portrait', $base);
$werdegang  = rt_list($c, 'werdegang');
$railItems  = count($werdegang) + 1;

$navItems = array(
    'ueber'     => $ui['nav_ueber'],
    'arbeit'    => $ui['nav_arbeit'],
    'projekte'  => $ui['nav_projekte'],
    'werdegang' => $ui['nav_werdegang'],
    'neben'     => $ui['nav_neben'],
    'kontakt'   => $ui['nav_kontakt'],
);

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="<?php echo rt_h($ui['html_lang']); ?>" data-base="<?php echo rt_h($base); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo rt_t($c, 'meta_titel'); ?></title>
<meta name="description" content="<?php echo rt_t($c, 'meta_text'); ?>">
<link rel="canonical" href="<?php echo rt_h($canonical); ?>">
<meta name="theme-color" content="#07080c">
<meta name="robots" content="index, follow">

<link rel="alternate" hreflang="de-CH" href="<?php echo rt_h($s['url']); ?>/">
<link rel="alternate" hreflang="en" href="<?php echo rt_h($s['url']); ?>/en/">
<link rel="alternate" hreflang="x-default" href="<?php echo rt_h($s['url']); ?>/">

<meta property="og:type" content="profile">
<meta property="og:site_name" content="<?php echo rt_h($s['name']); ?>">
<meta property="og:locale" content="<?php echo $isEn ? 'en_GB' : 'de_CH'; ?>">
<meta property="og:locale:alternate" content="<?php echo $isEn ? 'de_CH' : 'en_GB'; ?>">
<meta property="og:title" content="<?php echo rt_t($c, 'meta_titel'); ?>">
<meta property="og:description" content="<?php echo rt_t($c, 'meta_text'); ?>">
<meta property="og:url" content="<?php echo rt_h($canonical); ?>">
<meta property="og:image" content="<?php echo rt_h($s['url']); ?>/og.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?php echo $isEn ? 'The words Reymond Tobias, Informatiker EFZ, on a dark background' : 'Schriftzug Reymond Tobias, Informatiker EFZ, auf dunklem Grund'; ?>">
<meta name="twitter:card" content="summary_large_image">

<link rel="icon" href="<?php echo rt_h($base); ?>favicon.svg" type="image/svg+xml">
<link rel="icon" href="<?php echo rt_h($base); ?>favicon.ico" sizes="32x32">
<link rel="apple-touch-icon" href="<?php echo rt_h($base); ?>apple-touch-icon.png">
<link rel="manifest" href="<?php echo rt_h($base); ?>site.webmanifest">

<link rel="stylesheet" href="<?php echo rt_h($base); ?>assets/css/style.css">
</head>
<body>

<a class="skip-link" href="#inhalt"><?php echo rt_h($ui['skip']); ?></a>
<div class="progress" aria-hidden="true"></div>

<header class="site-header">
  <div class="wrap site-header__inner">
    <a class="brand" href="#top">
      <span class="brand__mark" aria-hidden="true">RT</span>
      <span><?php echo rt_h($s['name']); ?><span class="brand__sub"> · <?php echo rt_h($ui['brand_sub']); ?></span></span>
    </a>

    <nav class="nav" aria-label="<?php echo rt_h($ui['nav_label']); ?>">
      <ul class="nav__list">
        <?php foreach ($navItems as $id => $label): ?>
        <li><a class="nav__link" href="#<?php echo rt_h($id); ?>"><?php echo rt_h($label); ?></a></li>
        <?php endforeach; ?>
      </ul>

      <div class="header-tools">
        <div class="lang-switch">
          <a href="<?php echo rt_h($urlDe); ?>" hreflang="de-CH"<?php echo $isEn ? '' : ' aria-current="true"'; ?> lang="de">DE</a>
          <a href="<?php echo rt_h($urlEn); ?>" hreflang="en"<?php echo $isEn ? ' aria-current="true"' : ''; ?> lang="en">EN</a>
        </div>
        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="mobile-nav">
          <span class="nav-toggle__bars" aria-hidden="true"><span></span><span></span><span></span></span>
          <?php echo rt_h($ui['menu']); ?>
        </button>
      </div>
    </nav>
  </div>
</header>

<nav class="mobile-nav" id="mobile-nav" aria-label="<?php echo rt_h($ui['nav_mobile']); ?>">
  <ul>
    <?php foreach ($navItems as $id => $label): ?>
    <li><a href="#<?php echo rt_h($id); ?>"><?php echo rt_h($label); ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>

<main id="inhalt">

  <!-- ================= Aufmacher ================= -->
  <section class="hero" id="top">
    <canvas class="hero__canvas" aria-hidden="true"></canvas>
    <div class="hero__glow" aria-hidden="true"></div>

    <div class="wrap">
      <div class="hero__inner">
        <p class="hero__kicker"><span class="dot" aria-hidden="true"></span> <span><?php echo rt_t($c, 'hero_kicker'); ?></span></p>

        <h1 class="hero__title">
          <span class="line"><span>Reymond</span></span>
          <span class="line"><span class="grad">Tobias</span></span>
        </h1>

        <p class="hero__text"><?php echo rt_t($c, 'hero_text'); ?></p>

        <div class="hero__actions btn-row">
          <?php echo rt_link(rt_raw($c, 'hero_cta1_text'), rt_raw($c, 'hero_cta1_url'), 'btn btn--primary', $tab); ?>
          <?php echo rt_link(rt_raw($c, 'hero_cta2_text'), rt_raw($c, 'hero_cta2_url'), 'btn btn--ghost', $tab, false); ?>
        </div>

        <div class="hero__meta">
          <?php foreach (rt_list($c, 'hero_fakten') as $fakt):
              $url = rt_url(rt_fr($fakt, 'link_url')); ?>
          <dl>
            <dt><?php echo rt_f($fakt, 'label'); ?></dt>
            <dd><?php
              if ($url !== '') {
                  echo '<a href="' . rt_h($url) . '"' . (rt_url_extern($url) ? ' target="_blank" rel="noopener"' : '') . '>' . rt_f($fakt, 'wert') . '</a>';
              } else {
                  echo rt_f($fakt, 'wert');
              }
            ?></dd>
          </dl>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="scroll-hint" aria-hidden="true">
      <span class="scroll-hint__rail"></span>
      <?php echo rt_h($ui['scroll']); ?>
    </div>
  </section>

  <!-- ================= Über mich ================= -->
  <section class="section section--tint section--line" id="ueber">
    <div class="wrap">
      <div class="section__head" data-reveal>
        <p class="eyebrow"><?php echo rt_t($c, 'ueber_eyebrow'); ?></p>
        <h2><?php echo rt_t($c, 'ueber_titel'); ?></h2>
        <p class="lede"><?php echo rt_t($c, 'ueber_lede'); ?></p>
      </div>

      <div class="grid<?php echo $portrait !== '' ? ' grid--sidebar' : ''; ?>">
        <?php if ($portrait !== ''): ?>
        <div data-reveal="left" data-depth="0.5">
          <figure class="shot">
            <img src="<?php echo rt_h($portrait); ?>" alt="<?php echo rt_t($c, 'ueber_bild_alt'); ?>"
                 width="1200" height="960" loading="lazy" decoding="async">
          </figure>
        </div>
        <?php endif; ?>

        <div<?php echo $portrait !== '' ? ' data-reveal="right"' : ' data-reveal'; ?>>
          <div class="prose-narrow">
            <?php echo rt_paragraphs(rt_raw($c, 'ueber_text')); ?>
          </div>

          <div class="facts" style="margin-top: 2rem" data-reveal>
            <?php foreach (rt_list($c, 'ueber_fakten') as $fakt): ?>
            <div class="fact">
              <span class="fact__k"><?php echo rt_f($fakt, 'titel'); ?></span>
              <p class="fact__v"><?php echo rt_f($fakt, 'text'); ?></p>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ================= Was ich mache ================= -->
  <section class="section" id="arbeit">
    <div class="wrap">
      <div class="section__head" data-reveal>
        <p class="eyebrow"><?php echo rt_t($c, 'arbeit_eyebrow'); ?></p>
        <h2><?php echo rt_t($c, 'arbeit_titel'); ?></h2>
        <p class="lede"><?php echo rt_t($c, 'arbeit_lede'); ?></p>
      </div>

      <div class="grid grid--3 stagger perspective">
        <?php $n = 0; foreach (rt_list($c, 'schwerpunkte') as $item): $n++;
            $link = rt_item_link($item, 'btn btn--small', $tab); ?>
        <article class="card tilt" data-tilt="7" data-reveal>
          <span class="card__num"><?php echo rt_h(str_pad((string) $n, 2, '0', STR_PAD_LEFT)); ?></span>
          <h3 class="card__title"><?php echo rt_f($item, 'titel'); ?></h3>
          <p class="card__body"><?php echo rt_f($item, 'text'); ?></p>
          <?php if ($link !== ''): ?><p style="margin-top:1.25rem"><?php echo $link; ?></p><?php endif; ?>
        </article>
        <?php endforeach; ?>
      </div>

      <?php $kenntnisse = rt_list($c, 'kenntnisse'); if ($kenntnisse): ?>
      <h3 style="margin-top: 4rem; margin-bottom: 1.5rem" data-reveal><?php echo rt_t($c, 'kenntnisse_titel'); ?></h3>

      <div class="facts" data-reveal>
        <?php foreach ($kenntnisse as $item):
            $link = rt_item_link($item, 'more', $tab); ?>
        <div class="fact">
          <span class="fact__k" style="font-size: var(--step-1)"><?php echo rt_f($item, 'titel'); ?></span>
          <p class="fact__v"><?php echo rt_f($item, 'text'); ?></p>
          <?php echo $link; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ================= Projekte ================= -->
  <?php $projekte = rt_list($c, 'projekte'); if ($projekte): ?>
  <section class="section section--tint section--line" id="projekte">
    <div class="wrap">
      <div class="section__head" data-reveal>
        <p class="eyebrow"><?php echo rt_t($c, 'projekte_eyebrow'); ?></p>
        <h2><?php echo rt_t($c, 'projekte_titel'); ?></h2>
        <p class="lede"><?php echo rt_t($c, 'projekte_lede'); ?></p>
      </div>

      <div class="grid grid--3 stagger perspective">
        <?php foreach ($projekte as $item):
            $link = rt_item_link($item, 'btn btn--small', $tab); ?>
        <article class="card tilt" data-tilt="6" data-reveal>
          <span class="card__num"><?php echo rt_f($item, 'meta'); ?></span>
          <h3 class="card__title"><?php echo rt_f($item, 'titel'); ?></h3>
          <p class="card__body"><?php echo rt_f($item, 'text'); ?></p>
          <?php if ($link !== ''): ?><p style="margin-top:1.25rem"><?php echo $link; ?></p><?php endif; ?>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ================= Werdegang ================= -->
  <section class="section" id="werdegang" style="padding-bottom: 0">
    <div class="wrap">
      <div class="section__head rail-head" data-reveal>
        <p class="eyebrow"><?php echo rt_t($c, 'werdegang_eyebrow'); ?></p>
        <h2><?php echo rt_t($c, 'werdegang_titel'); ?></h2>
        <p class="lede"><?php echo rt_t($c, 'werdegang_lede'); ?></p>
      </div>
    </div>

    <div class="rail-outer" style="--rail-items: <?php echo (int) $railItems; ?>">
      <div class="rail-sticky">
        <div class="rail">
          <?php foreach ($werdegang as $item):
              $link = rt_item_link($item, 'btn btn--small', $tab); ?>
          <article class="rail__item card tilt" data-tilt="6">
            <span class="card__num"><?php echo rt_f($item, 'zeit'); ?></span>
            <h3 class="card__title"><?php echo rt_f($item, 'titel'); ?></h3>
            <p class="card__body card__meta"><?php echo rt_f($item, 'ort'); ?></p>
            <p class="card__body"><?php echo rt_f($item, 'text'); ?></p>
            <?php if ($link !== ''): ?><p style="margin-top:1.1rem"><?php echo $link; ?></p><?php endif; ?>
          </article>
          <?php endforeach; ?>

          <?php $endeLink = rt_link(rt_raw($c, 'werdegang_ende_link_text'), rt_raw($c, 'werdegang_ende_link_url'), 'btn', $tab);
                if (rt_raw($c, 'werdegang_ende_titel') !== '' || $endeLink !== ''): ?>
          <article class="rail__item card" style="display: grid; align-content: center">
            <span class="card__num"><?php echo rt_t($c, 'werdegang_ende_meta'); ?></span>
            <h3 class="card__title"><?php echo rt_t($c, 'werdegang_ende_titel'); ?></h3>
            <p class="card__body" style="margin-bottom: 1.25rem"><?php echo rt_t($c, 'werdegang_ende_text'); ?></p>
            <?php if ($endeLink !== ''): ?><p><?php echo $endeLink; ?></p><?php endif; ?>
          </article>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ================= Neben der Arbeit ================= -->
  <?php $neben = rt_list($c, 'neben'); if ($neben): ?>
  <section class="section section--tint section--line" id="neben">
    <div class="wrap">
      <div class="section__head" data-reveal>
        <p class="eyebrow"><?php echo rt_t($c, 'neben_eyebrow'); ?></p>
        <h2><?php echo rt_t($c, 'neben_titel'); ?></h2>
        <p class="lede"><?php echo rt_t($c, 'neben_lede'); ?></p>
      </div>

      <div class="grid grid--3 stagger perspective">
        <?php foreach ($neben as $item):
            $link = rt_item_link($item, 'btn btn--small', $tab); ?>
        <article class="card tilt" data-tilt="6" data-reveal>
          <span class="card__num"><?php echo rt_f($item, 'meta'); ?></span>
          <h3 class="card__title"><?php echo rt_f($item, 'titel'); ?></h3>
          <p class="card__body"><?php echo rt_f($item, 'text'); ?></p>
          <?php if ($link !== ''): ?><p style="margin-top:1.25rem"><?php echo $link; ?></p><?php endif; ?>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ================= Kontakt ================= -->
  <section class="section" id="kontakt">
    <div class="wrap">
      <div class="section__head" data-reveal>
        <p class="eyebrow"><?php echo rt_t($c, 'kontakt_eyebrow'); ?></p>
        <h2><?php echo rt_t($c, 'kontakt_titel'); ?></h2>
        <p class="lede"><?php echo rt_t($c, 'kontakt_lede'); ?></p>
      </div>

      <div class="grid grid--sidebar">
        <div data-reveal="left">
          <ul class="contact-list">
            <?php $mail = rt_raw($c, 'kontakt_email'); if ($mail !== ''): ?>
            <li>
              <dl>
                <dt><?php echo rt_h($ui['kontakt_email']); ?></dt>
                <dd><a href="mailto:<?php echo rt_h($mail); ?>"><?php echo rt_h($mail); ?></a></dd>
              </dl>
            </li>
            <?php endif; ?>

            <?php $tel = rt_raw($c, 'kontakt_telefon'); $telHref = rt_tel_href($tel); if ($tel !== ''): ?>
            <li>
              <dl>
                <dt><?php echo rt_h($ui['kontakt_tel']); ?></dt>
                <dd><?php if ($telHref !== ''): ?><a href="<?php echo rt_h($telHref); ?>"><?php echo rt_h($tel); ?></a><?php else: echo rt_h($tel); endif; ?></dd>
              </dl>
            </li>
            <?php endif; ?>

            <?php $adr = rt_raw($c, 'kontakt_adresse'); if ($adr !== ''): ?>
            <li>
              <dl>
                <dt><?php echo rt_h($ui['kontakt_adr']); ?></dt>
                <dd><address><?php echo rt_lines($adr); ?></address></dd>
              </dl>
            </li>
            <?php endif; ?>

            <?php foreach (rt_list($c, 'kontakt_links') as $item):
                $link = rt_link(rt_fr($item, 'link_text'), rt_fr($item, 'link_url'), '', $tab, false);
                if ($link === '') { continue; } ?>
            <li>
              <dl>
                <dt><?php echo rt_f($item, 'titel'); ?></dt>
                <dd><?php echo $link; ?></dd>
              </dl>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <div class="contact-card" data-reveal="right">
          <div class="notice" id="formular-meldung" hidden></div>

          <form id="kontaktformular" action="<?php echo rt_h($base); ?>api/contact.php" method="post" novalidate
                data-ok="<?php echo rt_h($ui['form_ok']); ?>"
                data-error="<?php echo rt_h($ui['form_error']); ?>"
                data-sending="<?php echo rt_h($ui['form_sending']); ?>">
            <input type="hidden" name="started" value="0">
            <input type="hidden" name="lang" value="<?php echo rt_h($lang); ?>">
            <div class="hp" aria-hidden="true">
              <label for="website"><?php echo rt_h($ui['form_hp']); ?></label>
              <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="field">
              <label for="name"><?php echo rt_h($ui['form_name']); ?> <span class="req" aria-hidden="true">*</span></label>
              <input type="text" id="name" name="name" required autocomplete="name" maxlength="80">
            </div>

            <div class="field">
              <label for="email"><?php echo rt_h($ui['form_mail']); ?> <span class="req" aria-hidden="true">*</span></label>
              <input type="email" id="email" name="email" required autocomplete="email" maxlength="120">
            </div>

            <div class="field">
              <label for="betreff"><?php echo rt_h($ui['form_subject']); ?></label>
              <input type="text" id="betreff" name="betreff" maxlength="120" aria-describedby="betreff-hint">
              <span class="hint" id="betreff-hint"><?php echo rt_h($ui['form_subject_h']); ?></span>
            </div>

            <div class="field">
              <label for="nachricht"><?php echo rt_h($ui['form_message']); ?> <span class="req" aria-hidden="true">*</span></label>
              <textarea id="nachricht" name="nachricht" required maxlength="4000" aria-describedby="nachricht-hint"></textarea>
              <span class="hint" id="nachricht-hint"><?php echo rt_h($ui['form_message_h']); ?></span>
            </div>

            <div class="field">
              <label class="check">
                <input type="checkbox" name="einverstanden" value="1" required>
                <span><?php echo rt_h($ui['form_consent_1']); ?><a href="<?php echo rt_h($privUrl); ?>"><?php echo rt_h($ui['form_consent_2']); ?></a><?php echo rt_h($ui['form_consent_3']); ?></span>
              </label>
            </div>

            <button class="btn btn--primary" type="submit"><?php echo rt_h($ui['form_submit']); ?> <span class="btn__arrow" aria-hidden="true">&rarr;</span></button>
            <p class="hint" style="margin-top: 1rem"><?php echo rt_h($ui['form_required']); ?></p>
          </form>
        </div>
      </div>

      <div class="cta" style="margin-top: var(--sp-6)" data-reveal="zoom">
        <p class="eyebrow" style="justify-content: center"><?php echo rt_t($c, 'cta_eyebrow'); ?></p>
        <h2><?php echo rt_t($c, 'cta_titel'); ?></h2>
        <p><?php echo rt_t($c, 'cta_text'); ?></p>
        <div class="btn-row">
          <?php echo rt_link(rt_raw($c, 'cta_link1_text'), rt_raw($c, 'cta_link1_url'), 'btn btn--primary', $tab); ?>
          <?php echo rt_link(rt_raw($c, 'cta_link2_text'), rt_raw($c, 'cta_link2_url'), 'btn btn--ghost', $tab, false); ?>
        </div>
      </div>
    </div>
  </section>

</main>

<footer class="site-footer">
  <div class="wrap">
    <div class="footer-grid">
      <div>
        <h2><?php echo rt_h($s['name']); ?></h2>
        <address>
          <?php echo rt_lines(rt_raw($c, 'kontakt_adresse'), $s['name']); ?><br>
          <a href="mailto:<?php echo rt_t($c, 'kontakt_email'); ?>"><?php echo rt_t($c, 'kontakt_email'); ?></a>
        </address>
      </div>
      <div>
        <h3><?php echo rt_h($ui['foot_page']); ?></h3>
        <ul>
          <?php foreach ($navItems as $id => $label): ?>
          <li><a href="#<?php echo rt_h($id); ?>"><?php echo rt_h($label); ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h3><?php echo rt_h($ui['foot_more']); ?></h3>
        <ul>
          <?php foreach (rt_list($c, 'kontakt_links') as $item):
              $link = rt_link(rt_fr($item, 'titel') !== '' ? rt_fr($item, 'titel') : rt_fr($item, 'link_text'), rt_fr($item, 'link_url'), '', $tab, false);
              if ($link === '') { continue; } ?>
          <li><?php echo $link; ?></li>
          <?php endforeach; ?>
          <li><a href="<?php echo rt_h($legalUrl); ?>"><?php echo rt_h($ui['legal_notice']); ?></a></li>
          <li><a href="<?php echo rt_h($privUrl); ?>"><?php echo rt_h($ui['privacy']); ?></a></li>
          <li><a href="<?php echo rt_h($otherUrl); ?>" lang="<?php echo $isEn ? 'de' : 'en'; ?>"><?php echo rt_h($ui['other_lang']); ?></a></li>
        </ul>
      </div>
    </div>

    <div class="footer-bottom">
      <p>&copy; <span data-year><?php echo date('Y'); ?></span> <?php echo rt_h($s['name']); ?> · <?php echo rt_h($s['domain']); ?></p>
      <p><?php echo rt_h($ui['foot_note']); ?></p>
    </div>
  </div>
</footer>

<script src="<?php echo rt_h($base); ?>assets/js/app.js" defer></script>
</body>
</html>
