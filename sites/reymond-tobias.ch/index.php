<?php
/**
 * Startseite, deutsche Fassung.
 *
 * Der Aufbau steht in lib/page.php und gilt fuer beide Sprachen. Die Texte
 * kommen aus content/content.json und lib/defaults.php.
 */

require __DIR__ . '/lib/store.php';

$lang = 'de';
$base = '';
require __DIR__ . '/lib/page.php';
