<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * E-Mail-Versand über die eingebaute mail()-Funktion.
 *
 * Auf cPanel funktioniert das ohne Einrichtung. Wichtig ist die Absenderadresse:
 * Sie muss zur eigenen Domain gehören, sonst landet die Nachricht im Spam.
 *
 * Sicherheit: Kopfzeilen werden nicht aus Benutzereingaben zusammengesetzt.
 * Namen und Adressen laufen durch eine Prüfung, die Zeilenumbrüche ausschliesst –
 * sonst könnte jemand über das Kontaktformular fremde Empfänger einschleusen.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $textBody, string $replyTo = ''): bool
    {
        if (!self::isValidAddress($to)) {
            Logger::warning('E-Mail nicht gesendet: ungültige Empfängeradresse.');
            return false;
        }

        $from = (string) Config::get('mail.from', '');
        if (!self::isValidAddress($from)) {
            Logger::warning('E-Mail nicht gesendet: mail.from in config.php fehlt oder ist ungültig.');
            return false;
        }

        $fromName = self::cleanHeaderValue((string) Config::get('mail.from_name', 'WebAtze'));
        $subject = self::cleanHeaderValue($subject);

        $headers = [
            'From' => sprintf('%s <%s>', self::encodeHeader($fromName), $from),
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Mailer' => 'WebAtze',
        ];

        if ($replyTo !== '' && self::isValidAddress($replyTo)) {
            $headers['Reply-To'] = $replyTo;
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $body = str_replace(["\r\n", "\r"], "\n", $textBody);
        $body = wordwrap($body, 78, "\n", false);

        $ok = @mail(
            $to,
            '=?UTF-8?B?' . base64_encode($subject) . '?=',
            $body,
            implode("\r\n", $headerLines),
            '-f' . $from
        );

        if (!$ok) {
            Logger::warning('mail() meldete einen Fehler. Läuft der Mailversand auf diesem Hosting?');
        }

        return $ok;
    }

    public static function isValidAddress(string $address): bool
    {
        if ($address === '' || mb_strlen($address) > 190) {
            return false;
        }
        // Zeilenumbrüche würden zusätzliche Kopfzeilen erlauben.
        if (preg_match('/[\r\n\0]/', $address)) {
            return false;
        }
        return (bool) filter_var($address, FILTER_VALIDATE_EMAIL);
    }

    private static function cleanHeaderValue(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], ' ', $value);
        return mb_substr(trim($value), 0, 200);
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return '"' . str_replace('"', '', $value) . '"';
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
