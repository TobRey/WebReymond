<?php
/** Abmelden. Nur per Formular, damit ein fremder Link nicht abmelden kann. */

require __DIR__ . '/inc/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && rt_csrf_ok()) {
    rt_logout();
    session_start();
    session_regenerate_id(true);
    rt_flash('ok', 'Du bist abgemeldet.');
}

rt_redirect('login.php');
