<?php
/**
 * Feste Beschriftungen der Oberflaeche.
 *
 * Hier steht nur, was zum Geruest gehoert: Menue, Formular, Fusszeile.
 * Alle inhaltlichen Texte stehen in lib/defaults.php und lassen sich im
 * Bearbeitungsbereich aendern.
 */

function rt_ui($lang)
{
    $de = array(
        'html_lang'      => 'de-CH',
        'skip'           => 'Zum Inhalt springen',
        'brand_sub'      => 'Informatiker EFZ',
        'nav_label'      => 'Hauptnavigation',
        'nav_mobile'     => 'Navigation für kleine Bildschirme',
        'menu'           => 'Menü',
        'scroll'         => 'Scrollen',
        'new_tab'        => 'öffnet in neuem Tab',
        'nav_ueber'      => 'Über mich',
        'nav_arbeit'     => 'Was ich mache',
        'nav_projekte'   => 'Projekte',
        'nav_werdegang'  => 'Werdegang',
        'nav_neben'      => 'Neben der Arbeit',
        'nav_kontakt'    => 'Kontakt',
        'kontakt_email'  => 'E-Mail',
        'kontakt_tel'    => 'Telefon',
        'kontakt_adr'    => 'Adresse',
        'form_ok'        => 'Danke, deine Nachricht ist unterwegs.',
        'form_error'     => 'Das hat nicht geklappt. Bitte versuch es noch einmal oder schreib direkt eine E-Mail.',
        'form_sending'   => 'Wird gesendet …',
        'form_hp'        => 'Dieses Feld bitte leer lassen',
        'form_name'      => 'Name',
        'form_mail'      => 'E-Mail',
        'form_subject'   => 'Betreff',
        'form_subject_h' => 'Zum Beispiel: Frage zu deinem Werdegang',
        'form_message'   => 'Nachricht',
        'form_message_h' => 'Ein paar Sätze reichen.',
        'form_consent_1' => 'Ich bin einverstanden, dass meine Angaben zur Bearbeitung meiner Anfrage verwendet werden. Mehr dazu in der ',
        'form_consent_2' => 'Datenschutzerklärung',
        'form_consent_3' => '.',
        'form_submit'    => 'Nachricht senden',
        'form_required'  => 'Mit * markierte Felder sind Pflichtfelder.',
        'foot_page'      => 'Seite',
        'foot_more'      => 'Mehr',
        'foot_note'      => 'Diese Seite lädt nichts von fremden Servern und setzt keine Cookies zur Auswertung.',
        'legal_notice'   => 'Impressum',
        'privacy'        => 'Datenschutz',
        'other_lang'     => 'English version',
    );

    $en = array(
        'html_lang'      => 'en',
        'skip'           => 'Skip to content',
        'brand_sub'      => 'Informatiker EFZ',
        'nav_label'      => 'Main navigation',
        'nav_mobile'     => 'Navigation for small screens',
        'menu'           => 'Menu',
        'scroll'         => 'Scroll',
        'new_tab'        => 'opens in a new tab',
        'nav_ueber'      => 'About me',
        'nav_arbeit'     => 'What I do',
        'nav_projekte'   => 'Projects',
        'nav_werdegang'  => 'Career',
        'nav_neben'      => 'Beyond work',
        'nav_kontakt'    => 'Contact',
        'kontakt_email'  => 'Email',
        'kontakt_tel'    => 'Phone',
        'kontakt_adr'    => 'Address',
        'form_ok'        => 'Thank you, your message is on its way.',
        'form_error'     => 'That did not work. Please try again or send an email directly.',
        'form_sending'   => 'Sending …',
        'form_hp'        => 'Please leave this field empty',
        'form_name'      => 'Name',
        'form_mail'      => 'Email',
        'form_subject'   => 'Subject',
        'form_subject_h' => 'For example: a question about your career',
        'form_message'   => 'Message',
        'form_message_h' => 'A few sentences are enough.',
        'form_consent_1' => 'I agree that my details may be used to process my enquiry. More on this in the ',
        'form_consent_2' => 'privacy policy',
        'form_consent_3' => '.',
        'form_submit'    => 'Send message',
        'form_required'  => 'Fields marked with * are required.',
        'foot_page'      => 'Page',
        'foot_more'      => 'More',
        'foot_note'      => 'This page loads nothing from third-party servers and sets no tracking cookies.',
        'legal_notice'   => 'Legal notice',
        'privacy'        => 'Privacy',
        'other_lang'     => 'Deutsche Fassung',
    );

    return ($lang === 'en') ? $en : $de;
}
