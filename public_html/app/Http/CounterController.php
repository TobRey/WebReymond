<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Logger, RateLimit, Request, Response};
use WebAtze\Domain\Visits;

/**
 * Der Zähler für fremde Websites.
 *
 * Zwei Adressen, mehr braucht es nicht:
 *
 *   /z.js?k=SCHLÜSSEL   das Skript, das in die fremde Seite kommt
 *   /z?k=…&p=…&r=…      der Aufruf, den es schickt
 *
 * Warum überhaupt: Die eingebaute Zählung setzt voraus, dass WebAtze die
 * Website gebaut hat und dort eine Datei liegt. Websites, die ich nur
 * betreue oder von Hand eingetragen habe, haben das nicht. Ein Einzeiler
 * dagegen lässt sich überall einsetzen – auch in WordPress, Wix oder
 * eine Seite, die jemand anderes gebaut hat.
 *
 * Was hier NICHT passiert: Es wird keine IP gespeichert, kein Cookie
 * gesetzt, kein Wiedererkennungswert vergeben. Die Kennung entsteht aus
 * einem Salz, das jede Nacht wechselt (siehe Domain\Visits). Deshalb
 * braucht eine Website mit diesem Zähler kein Zustimmungsbanner.
 */
final class CounterController
{
    /** Höchstens so viele Aufrufe je Schlüssel und Stunde. */
    private const MAX_PER_HOUR = 20000;

    /**
     * Das Zählskript.
     *
     * Winzig und ohne Abhängigkeit. Es schickt einen Aufruf und ist
     * danach fertig; es beobachtet nichts weiter.
     */
    public function script(Request $request): Response
    {
        $key = (string) $request->query('k', '');

        $js = "(function(){try{"
            . "var k=" . json_encode($key, JSON_THROW_ON_ERROR) . ";"
            . "if(!k)return;"
            . "var s=document.currentScript;"
            . "var b=(s&&s.src?s.src:'').split('/z.js')[0];"
            . "if(!b)return;"
            // Vorschauen, lokale Aufrufe und Wiederholungen im selben
            // Tab zaehlen nicht.
            . "if(navigator.webdriver)return;"
            . "var u=b+'/z?k='+encodeURIComponent(k)"
            . "+'&p='+encodeURIComponent(location.pathname)"
            . "+'&r='+encodeURIComponent(document.referrer||'')"
            . "+'&c='+Date.now();"
            . "if(navigator.sendBeacon){navigator.sendBeacon(u);}"
            . "else{var i=new Image();i.src=u;}"
            . "}catch(e){}})();";

        return Response::make($js)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            // Eine Stunde: kurz genug, um den Zähler ändern zu können,
            // lang genug, um nicht bei jedem Aufruf neu zu laden.
            ->header('Cache-Control', 'public, max-age=3600')
            ->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * Der Aufruf selbst.
     *
     * Antwortet immer mit einem durchsichtigen Bildpunkt – auch wenn
     * nichts gezählt wurde. Wer den Schlüssel errät, soll daraus nicht
     * ablesen können, ob er richtig lag.
     */
    public function hit(Request $request): Response
    {
        try {
            $this->count($request);
        } catch (\Throwable $e) {
            // Ein Zähler darf niemals eine fremde Website stören.
            Logger::exception($e);
        }

        return $this->pixel();
    }

    private function count(Request $request): void
    {
        $key = (string) $request->query('k', '');
        $project = Visits::byKey($key);

        if ($project === null) {
            return;
        }

        $id = (int) $project['id'];

        if (!RateLimit::hit('zaehler:' . $id, self::MAX_PER_HOUR, 3600)) {
            return;
        }

        Visits::record(
            $id,
            (string) $request->query('p', '/'),
            (string) $request->query('r', ''),
            $request->ip(),
            $request->header('User-Agent')
        );
    }

    private function pixel(): Response
    {
        $gif = base64_decode(
            'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
            true
        );

        return Response::make((string) $gif)
            ->header('Content-Type', 'image/gif')
            ->header('Access-Control-Allow-Origin', '*')
            ->noCache();
    }
}
