<?php
/**
 * Verschluesselung des Passwortspeichers.
 *
 * Der Schluessel wird beim Anmelden aus dem Anmeldepasswort abgeleitet und
 * ausschliesslich in der Sitzung gehalten – nie auf der Platte. Wer die
 * Datendatei in die Haende bekommt, kann ohne das Passwort nichts damit
 * anfangen. Umgekehrt bedeutet das: geht das Passwort verloren, sind die
 * gespeicherten Zugangsdaten verloren. Genau so soll es sein.
 */

if (defined('RT_CRYPTO')) { return; }
define('RT_CRYPTO', true);

require_once __DIR__ . '/../../lib/store.php';

/** Welches Ableitungsverfahren steht zur Verfuegung? */
function rt_kdf_name()
{
    if (function_exists('sodium_crypto_pwhash') && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13')) {
        return 'argon2id';
    }
    return 'pbkdf2';
}

/**
 * 32-Byte-Schluessel aus Passwort und Salz.
 *
 * @param string $password Anmeldepasswort
 * @param string $saltHex  32 Zeichen in Hexschreibweise (= 16 Byte)
 * @param string $kdf      'argon2id' oder 'pbkdf2'
 */
function rt_derive_key($password, $saltHex, $kdf = null)
{
    if ($kdf === null) { $kdf = rt_kdf_name(); }
    $salt = @hex2bin($saltHex);
    if ($salt === false || strlen($salt) < 16) {
        $salt = str_pad((string) $salt, 16, "\0");
    }
    $salt = substr($salt, 0, 16);

    if ($kdf === 'argon2id' && function_exists('sodium_crypto_pwhash')) {
        return sodium_crypto_pwhash(
            32,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }

    return hash_pbkdf2('sha256', $password, $salt, 210000, 32, true);
}

/** Text verschluesseln. Gibt eine Zeichenkette mit Verfahrenskennung zurueck. */
function rt_seal($plain, $key)
{
    $plain = (string) $plain;
    if ($plain === '') { return ''; }

    if (function_exists('sodium_crypto_secretbox')) {
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        return 's1:' . base64_encode($nonce . $cipher);
    }

    if (function_exists('openssl_encrypt')) {
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) { return ''; }
        return 'o1:' . base64_encode($iv . $tag . $cipher);
    }

    return '';
}

/** Text entschluesseln. Gibt null zurueck, wenn das nicht moeglich ist. */
function rt_open($blob, $key)
{
    $blob = (string) $blob;
    if ($blob === '') { return ''; }

    if (strncmp($blob, 's1:', 3) === 0) {
        if (!function_exists('sodium_crypto_secretbox_open')) { return null; }
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { return null; }
        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        return ($plain === false) ? null : $plain;
    }

    if (strncmp($blob, 'o1:', 3) === 0) {
        if (!function_exists('openssl_decrypt')) { return null; }
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) <= 28) { return null; }
        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return ($plain === false) ? null : $plain;
    }

    return null;
}

/** Schluessel der laufenden Sitzung holen. */
function rt_session_key()
{
    if (empty($_SESSION['vk'])) { return null; }
    $key = base64_decode($_SESSION['vk'], true);
    return ($key === false || strlen($key) !== 32) ? null : $key;
}

/* ------------------------------------------------------------------
   Passwortspeicher: Laden und Sichern
   ------------------------------------------------------------------ */

define('RT_VAULT', RT_PRIVATE . '/vault.php');

/** Diese Felder liegen verschluesselt auf der Platte. */
function rt_vault_fields()
{
    return array('title', 'username', 'url', 'secret', 'note');
}

/**
 * Eintraege lesen und entschluesseln.
 * Laesst sich ein Feld nicht oeffnen, steht dort null – dann stimmt der
 * Schluessel nicht mehr zu den Daten.
 */
function rt_vault_load($key)
{
    $raw = rt_read(RT_VAULT, array());
    if (!is_array($raw)) { return array(); }

    $out = array();
    foreach ($raw as $item) {
        if (!is_array($item)) { continue; }
        $entry = array(
            'id'      => isset($item['id']) ? (string) $item['id'] : '',
            'created' => isset($item['created']) ? (string) $item['created'] : '',
            'updated' => isset($item['updated']) ? (string) $item['updated'] : '',
        );
        foreach (rt_vault_fields() as $field) {
            $entry[$field] = isset($item[$field]) ? rt_open($item[$field], $key) : '';
        }
        $out[] = $entry;
    }
    return $out;
}

/** Eintraege verschluesseln und atomar schreiben. */
function rt_vault_save($items, $key)
{
    $packed = array();
    foreach ($items as $item) {
        $row = array(
            'id'      => isset($item['id']) ? (string) $item['id'] : bin2hex(random_bytes(8)),
            'created' => isset($item['created']) ? (string) $item['created'] : date('c'),
            'updated' => isset($item['updated']) ? (string) $item['updated'] : date('c'),
        );
        foreach (rt_vault_fields() as $field) {
            $value = isset($item[$field]) ? (string) $item[$field] : '';
            $row[$field] = ($value === '') ? '' : rt_seal($value, $key);
        }
        $packed[] = $row;
    }
    rt_backup(RT_VAULT, 'vault');
    return rt_write(RT_VAULT, $packed);
}

/** Ein zufaelliges, gut lesbares Passwort erzeugen. */
function rt_suggest_password($length = 20)
{
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!?%+-=@#';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}
