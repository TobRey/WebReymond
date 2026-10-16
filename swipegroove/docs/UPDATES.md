# Updates einspielen

1. Sicherung: `config/config.php`, `storage/`, `uploads/` und ein Datenbank-Dump (cPanel → phpMyAdmin → Export).
2. Neues `swipegroove-godaddy.zip` in den Installationsordner hochladen und entpacken, bestehende Dateien ersetzen.
3. `config/config.php`, `storage/` und `uploads/` bleiben erhalten (sie sind nicht im ZIP enthalten).
4. Erste Anfrage nach dem Update führt ausstehende Migrationen aus (`migrations/NNN_*.php`, Tabelle `schema_migrations`). Fortschritt steht in `storage/logs/app-*.log`.
5. Adminbereich → *Übersicht* zeigt Version und PHP-Version.

Eine Neuinstallation oder erneute Eingabe von Zugangsdaten ist nicht nötig. Der Besitzerstatus bleibt erhalten (`platform_owner`).
