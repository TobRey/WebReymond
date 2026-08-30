<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Config, Db, I18n, Logger, Mailer, RateLimit, Request, Response, Settings, Validator};

/**
 * Nimmt Anfragen über das Kontaktformular entgegen.
 *
 * Jede Anfrage wird gespeichert UND per E-Mail geschickt. Kommt die Mail
 * nicht durch, geht die Anfrage trotzdem nicht verloren – sie steht dann
 * im Adminbereich.
 *
 * Gegen Werbemüll wirken drei Dinge ohne nerviges Captcha:
 *   1. ein Feld, das nur Maschinen ausfüllen (Honigtopf)
 *   2. eine Zeitfalle: unter zwei Sekunden war kein Mensch am Werk
 *   3. eine Obergrenze pro IP-Adresse und Stunde
 */
final class ContactController
{
    private const MAX_PER_HOUR = 5;
    private const MIN_SECONDS = 2;

    public function submit(Request $request): Response
    {
        $ip = $request->ip();

        if (!RateLimit::hit('contact:' . $ip, self::MAX_PER_HOUR, 3600)) {
            return $this->fail(__t('errors.rate_limited'), [], 429);
        }

        // --- Werbemüll abfangen ---------------------------------------
        // Wir melden dem Absender bewusst einen Erfolg. Wüsste ein
        // Werbeprogramm, dass es aufgefallen ist, würde es sofort die
        // nächste Variante probieren.
        if ($request->input('website') !== '') {
            Logger::info('Kontaktformular: Honigtopf ausgelöst', ['ip' => $ip]);
            return $this->succeed();
        }

        $elapsed = $request->int('elapsed');
        if ($elapsed > 0 && $elapsed < self::MIN_SECONDS * 1000) {
            Logger::info('Kontaktformular: zu schnell abgeschickt', ['ip' => $ip, 'ms' => $elapsed]);
            return $this->succeed();
        }

        // --- Prüfen ---------------------------------------------------
        $data = [
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'company' => $request->input('company'),
            'subject' => $request->input('subject', 'new'),
            'message' => $request->text('message', 5000),
        ];

        $subjects = array_keys(I18n::list('contact.form.subject_options'));

        $validator = Validator::make($data)
            ->required('name', __t('contact.form.name'))
            ->maxLength('name', 120, __t('contact.form.name'))
            ->email('email', __t('contact.form.email'))
            ->maxLength('phone', 60, __t('contact.form.phone'))
            ->maxLength('company', 190, __t('contact.form.company'))
            ->in('subject', $subjects, __t('contact.form.subject'))
            ->required('message', __t('contact.form.message'))
            ->minLength('message', 10, __t('contact.form.message'))
            ->maxLength('message', 5000, __t('contact.form.message'));

        if ($validator->fails()) {
            return $this->fail(__t('errors.form_failed'), $validator->errors(), 422);
        }

        // --- Speichern ------------------------------------------------
        $leadId = Db::insert('leads', [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'company' => $data['company'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'locale' => I18n::locale(),
            'source' => 'website',
            'status' => 'new',
            'ip' => $ip,
            'mail_sent' => 0,
            'created_at' => Db::now(),
        ]);

        // --- Benachrichtigen ------------------------------------------
        $sent = $this->notify($data, $leadId);
        if ($sent) {
            Db::update('leads', ['mail_sent' => 1], 'id = :id', ['id' => $leadId]);
        }

        Audit::log('lead.received', 'Anfrage #' . $leadId, [
            'subject' => $data['subject'],
            'mail_sent' => $sent,
        ], $request);

        return $this->succeed();
    }

    private function notify(array $data, int $leadId): bool
    {
        $to = (string) Config::get('mail.to', '');
        if ($to === '') {
            return false;
        }

        $subjectLabels = I18n::list('contact.form.subject_options');
        $topic = $subjectLabels[$data['subject']] ?? $data['subject'];

        $lines = [
            'Neue Anfrage über webatze.ch',
            str_repeat('=', 40),
            '',
            'Name:    ' . $data['name'],
            'E-Mail:  ' . $data['email'],
            'Telefon: ' . ($data['phone'] !== '' ? $data['phone'] : '–'),
            'Firma:   ' . ($data['company'] !== '' ? $data['company'] : '–'),
            'Thema:   ' . $topic,
            '',
            'Nachricht:',
            str_repeat('-', 40),
            $data['message'],
            str_repeat('-', 40),
            '',
            'Im Adminbereich: ' . Config::url(
                trim((string) Config::get('create_path', 'create'), '/') . '/anfragen'
            ),
            'Anfrage-Nummer: ' . $leadId,
        ];

        return Mailer::send(
            $to,
            'Anfrage von ' . $data['name'],
            implode("\n", $lines),
            $data['email']
        );
    }

    private function succeed(): Response
    {
        return Response::json([
            'ok' => true,
            'title' => __t('contact.success_title'),
            'message' => __t('contact.success_text'),
        ])->noCache();
    }

    private function fail(string $message, array $errors = [], int $status = 400): Response
    {
        return Response::json([
            'ok' => false,
            'error' => $message,
            'errors' => $errors,
        ], $status)->noCache();
    }
}
