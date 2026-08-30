<?php
/**
 * Einstellungen – das Einzige, was du von Hand ausfüllen musst.
 */

// 1. Dein Schlüssel von console.anthropic.com (beginnt mit sk-ant-).
//    Ohne ihn sagt die Seite, dass sie noch nicht eingerichtet ist.
$ANTHROPIC_API_KEY = '';

// 2. Passwort für die Seite. Leer lassen = jeder, der die Adresse kennt,
//    darf Figuren erzeugen – und das kostet dich Geld. Ein Wort genügt.
$SITE_PASSWORT = '';

// 3. Wie viele Bilder ein Besucher pro Stunde erzeugen darf.
//    Eine Animation braucht sechs bis acht davon.
$LIMIT_PRO_STUNDE = 60;

// 4. Sorgfalt des Modells: 'low' ist schnell, 'high' zeichnet genauer.
//    Bei 'high' dauert ein Bild länger – manche Server brechen dann ab.
$AUFWAND = 'medium';

// 5. Das Modell. Nur ändern, wenn du weisst, warum.
$MODELL = 'claude-opus-5';
