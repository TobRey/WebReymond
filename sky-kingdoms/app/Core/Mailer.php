<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

/**
 * E-Mail-Versand über PHP-mail() oder SMTP (eigene, schlanke Implementierung –
 * keine externe Bibliothek nötig).
 *
 * Ist kein Versand eingerichtet, gibt send() false zurück; der Aufrufer zeigt
 * dann den Hinweis, dass der Administrator das Zurücksetzen übernimmt.
 */
final class Mailer
{
    public static function enabled(): bool
    {
        return (bool) App::config('mail.enabled', false);
    }

    public static function send(string $to, string $subject, string $textBody): bool
    {
        if (!self::enabled()) {
            return false;
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $from     = (string) App::config('mail.from', '');
        $fromName = (string) App::config('mail.from_name', App::config('name', 'Sky Kingdoms'));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            Logger::warn('E-Mail-Versand ohne gültige Absenderadresse abgebrochen.');

            return false;
        }

        // Kopfzeilen-Injection ausschliessen
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $body    = str_replace("\r\n", "\n", $textBody);
        $body    = str_replace("\n", "\r\n", $body);

        try {
            if (App::config('mail.transport', 'mail') === 'smtp') {
                return self::sendSmtp($to, $subject, $body, $from, $fromName);
            }

            $headers = [
                'From: ' . self::encodeName($fromName) . ' <' . $from . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];

            return @mail($to, self::encodeSubject($subject), $body, implode("\r\n", $headers));
        } catch (\Throwable $e) {
            Logger::error('E-Mail-Versand fehlgeschlagen', ['fehler' => $e->getMessage()]);

            return false;
        }
    }

    private static function sendSmtp(string $to, string $subject, string $body, string $from, string $fromName): bool
    {
        $host   = (string) App::config('mail.smtp_host', '');
        $port   = (int) App::config('mail.smtp_port', 587);
        $user   = (string) App::config('mail.smtp_user', '');
        $pass   = (string) App::config('mail.smtp_pass', '');
        $secure = (string) App::config('mail.smtp_secure', 'tls');

        if ($host === '') {
            return false;
        }

        $target  = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket  = @stream_socket_client($target, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
        if ($socket === false) {
            Logger::error('SMTP-Verbindung fehlgeschlagen', ['fehler' => $errstr]);

            return false;
        }
        stream_set_timeout($socket, 12);

        $read = static function () use ($socket): string {
            $data = '';
            while (($line = fgets($socket, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }

            return $data;
        };
        $write = static function (string $command) use ($socket, $read): string {
            fwrite($socket, $command . "\r\n");

            return $read();
        };

        $ok = static fn (string $response, string $code): bool => str_starts_with(trim($response), $code);

        $greeting = $read();
        if (!$ok($greeting, '220')) {
            fclose($socket);

            return false;
        }

        $domain = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $write('EHLO ' . $domain);

        if ($secure === 'tls') {
            if (!$ok($write('STARTTLS'), '220')) {
                fclose($socket);

                return false;
            }
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);

                return false;
            }
            $write('EHLO ' . $domain);
        }

        if ($user !== '') {
            if (!$ok($write('AUTH LOGIN'), '334')) {
                fclose($socket);

                return false;
            }
            $write(base64_encode($user));
            if (!$ok($write(base64_encode($pass)), '235')) {
                fclose($socket);
                Logger::error('SMTP-Anmeldung abgelehnt.');

                return false;
            }
        }

        if (!$ok($write('MAIL FROM:<' . $from . '>'), '250') || !$ok($write('RCPT TO:<' . $to . '>'), '250')) {
            fclose($socket);

            return false;
        }
        if (!$ok($write('DATA'), '354')) {
            fclose($socket);

            return false;
        }

        $message = 'From: ' . self::encodeName($fromName) . ' <' . $from . ">\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Subject: ' . self::encodeSubject($subject) . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            . preg_replace('/^\./m', '..', $body) . "\r\n.";

        $sent = $ok($write($message), '250');
        $write('QUIT');
        fclose($socket);

        return $sent;
    }

    private static function encodeSubject(string $subject): string
    {
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    private static function encodeName(string $name): string
    {
        $name = str_replace(["\r", "\n", '<', '>'], '', $name);

        return '=?UTF-8?B?' . base64_encode($name) . '?=';
    }
}
