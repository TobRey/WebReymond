<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Prozedurale Audioerzeugung (16-Bit-Mono-WAV, 22050 Hz).
 *
 * Damit kommt das Spiel ohne grosse Mediendateien aus: Raumtoene,
 * Geraeusche fuer Sprachnachrichten, Morsezeichen und Horror-Effekte
 * werden serverseitig berechnet und zwischengespeichert.
 */
final class AudioSynth
{
    private const RATE = 22050;

    private const MORSE = [
        'A' => '.-', 'B' => '-...', 'C' => '-.-.', 'D' => '-..', 'E' => '.', 'F' => '..-.',
        'G' => '--.', 'H' => '....', 'I' => '..', 'J' => '.---', 'K' => '-.-', 'L' => '.-..',
        'M' => '--', 'N' => '-.', 'O' => '---', 'P' => '.--.', 'Q' => '--.-', 'R' => '.-.',
        'S' => '...', 'T' => '-', 'U' => '..-', 'V' => '...-', 'W' => '.--', 'X' => '-..-',
        'Y' => '-.--', 'Z' => '--..', '0' => '-----', '1' => '.----', '2' => '..---',
        '3' => '...--', '4' => '....-', '5' => '.....', '6' => '-....', '7' => '--...',
        '8' => '---..', '9' => '----.',
    ];

    /** @return string|null WAV-Daten oder null bei unbekannter Spur */
    public function render(string $track): ?string
    {
        $samples = match ($track) {
            'amb_station'   => $this->ambienceStation(10.0),
            'amb_evidence'  => $this->ambienceEvidence(10.0),
            'voice_toby'    => $this->voiceMessage(),
            'voice_nora'    => $this->voiceShort(14.0, 0.7),
            'call_unknown'  => $this->unknownCall(),
            'morse_reverse' => $this->reversedMorse('P4'),
            'morse_plain'   => $this->morse('P4', 640.0, 0.06),
            'sting_low'     => $this->sting(),
            'static_burst'  => $this->staticBurst(2.4),
            'whisper'       => $this->whisper(4.0),
            'heartbeat'     => $this->heartbeat(6.0),
            'cctv_hum'      => $this->cctvHum(8.0),
            'ui_click'      => $this->blip(880.0, 0.04, 0.22),
            'ui_open'       => $this->blip(520.0, 0.09, 0.2),
            'ui_error'      => $this->errorTone(),
            'ui_success'    => $this->successTone(),
            'ui_alert'      => $this->alertTone(),
            default         => null,
        };
        return $samples === null ? null : $this->wav($samples);
    }

    /** @return string[] Alle verfuegbaren Spuren */
    public static function tracks(): array
    {
        return [
            'amb_station', 'amb_evidence', 'voice_toby', 'voice_nora', 'call_unknown',
            'morse_reverse', 'morse_plain', 'sting_low', 'static_burst', 'whisper',
            'heartbeat', 'cctv_hum', 'ui_click', 'ui_open', 'ui_error', 'ui_success', 'ui_alert',
        ];
    }

    /* =====================================================
     |  Spuren
     ===================================================== */

    /** Ruhiger Raumton der Ermittlungsstation: Netzbrummen, Lueftung, fernes Klicken. */
    private function ambienceStation(float $seconds): array
    {
        $count = (int)($seconds * self::RATE);
        $buffer = array_fill(0, $count, 0.0);
        $this->addTone($buffer, 50.0, 0.05, 0, $count);
        $this->addTone($buffer, 100.0, 0.018, 0, $count);
        $this->addTone($buffer, 148.0, 0.008, 0, $count);
        $noise = $this->lowpass($this->noise($count, 0.05), 0.02);
        $this->mix($buffer, $noise, 1.0);
        for ($i = 0; $i < 6; $i++) {
            $at = random_int(0, max(1, $count - 2000));
            $this->addClick($buffer, $at, 0.03);
        }
        $this->fade($buffer, 0.4, 0.6);
        return $buffer;
    }

    /** Archivraum: tiefes Summen und Leuchtstoffroehren-Flackern. */
    private function ambienceEvidence(float $seconds): array
    {
        $count = (int)($seconds * self::RATE);
        $buffer = array_fill(0, $count, 0.0);
        $this->addTone($buffer, 60.0, 0.04, 0, $count);
        $this->addTone($buffer, 121.0, 0.012, 0, $count);
        $noise = $this->lowpass($this->noise($count, 0.04), 0.06);
        $this->mix($buffer, $noise, 1.0);
        for ($i = 0; $i < 14; $i++) {
            $at = random_int(0, max(1, $count - 4000));
            $burst = $this->noise(1200, 0.05);
            $this->fadeBuffer($burst, 0.1, 0.6);
            $this->mixAt($buffer, $burst, $at, 1.0);
        }
        $this->fade($buffer, 0.5, 0.8);
        return $buffer;
    }

    /**
     * Tobys letzte Sprachnachricht (23:12 Uhr).
     * Enthaelt: gedaempfte Stimme, rhythmisches Pumpengeraeusch,
     * ein Zug-Signalhorn bei Sekunde 19 und einen abrupten Abbruch.
     */
    private function voiceMessage(): array
    {
        $seconds = 31.0;
        $count = (int)($seconds * self::RATE);
        $buffer = array_fill(0, $count, 0.0);

        // Grundrauschen der Telefonleitung
        $line = $this->bandpass($this->noise($count, 0.05), 0.12, 0.45);
        $this->mix($buffer, $line, 1.0);

        // Gedaempfte Stimme: unregelmaessige Silben im Sprachband
        $position = (int)(1.2 * self::RATE);
        while ($position < (int)(26.0 * self::RATE)) {
            $syllables = random_int(4, 11);
            for ($s = 0; $s < $syllables && $position < (int)(26.0 * self::RATE); $s++) {
                $length = random_int((int)(0.09 * self::RATE), (int)(0.22 * self::RATE));
                $base = 105.0 + random_int(-18, 26);
                $syllable = array_fill(0, $length, 0.0);
                $this->addTone($syllable, $base, 0.20, 0, $length);
                $this->addTone($syllable, $base * 2.0, 0.10, 0, $length);
                $this->addTone($syllable, $base * 3.2, 0.05, 0, $length);
                $breath = $this->bandpass($this->noise($length, 0.10), 0.2, 0.6);
                $this->mix($syllable, $breath, 0.7);
                $this->fadeBuffer($syllable, 0.25, 0.35);
                $this->mixAt($buffer, $syllable, $position, 1.0);
                $position += $length + random_int((int)(0.02 * self::RATE), (int)(0.09 * self::RATE));
            }
            $position += random_int((int)(0.4 * self::RATE), (int)(1.4 * self::RATE));
        }

        // Pumpwerk: langsamer, tiefer Rhythmus (ca. 0,9 Sekunden Takt)
        $beat = (int)(0.9 * self::RATE);
        for ($at = 0; $at < $count - $beat; $at += $beat) {
            $thump = array_fill(0, (int)(0.45 * self::RATE), 0.0);
            $this->addTone($thump, 42.0, 0.34, 0, count($thump));
            $this->addTone($thump, 63.0, 0.12, 0, count($thump));
            $this->fadeBuffer($thump, 0.04, 0.9);
            $this->mixAt($buffer, $thump, $at, 1.0);
        }

        // Zug-Signalhorn bei 19 Sekunden (zwei Stoesse, Terz)
        foreach ([[19.0, 1.1], [20.6, 0.8]] as [$start, $length]) {
            $horn = array_fill(0, (int)($length * self::RATE), 0.0);
            $this->addTone($horn, 311.1, 0.22, 0, count($horn));
            $this->addTone($horn, 370.0, 0.18, 0, count($horn));
            $this->addTone($horn, 466.2, 0.10, 0, count($horn));
            $this->fadeBuffer($horn, 0.12, 0.5);
            $this->mixAt($buffer, $horn, (int)($start * self::RATE), 1.0);
        }

        // Abbruch bei 27,4 Sekunden: Knacken, dann Stille
        $cut = (int)(27.4 * self::RATE);
        $this->addClick($buffer, $cut, 0.6);
        for ($i = $cut + 400; $i < $count; $i++) {
            $buffer[$i] *= max(0.0, 1.0 - (($i - $cut - 400) / 2000));
        }
        $this->fade($buffer, 0.15, 0.2);
        return $buffer;
    }

    private function voiceShort(float $seconds, float $pitch): array
    {
        $count = (int)($seconds * self::RATE);
        $buffer = array_fill(0, $count, 0.0);
        $line = $this->bandpass($this->noise($count, 0.04), 0.15, 0.5);
        $this->mix($buffer, $line, 1.0);
        $position = (int)(0.6 * self::RATE);
        while ($position < $count - (int)(0.5 * self::RATE)) {
            $length = random_int((int)(0.08 * self::RATE), (int)(0.2 * self::RATE));
            $syllable = array_fill(0, $length, 0.0);
            $base = (150.0 + random_int(-20, 30)) * $pitch;
            $this->addTone($syllable, $base, 0.22, 0, $length);
            $this->addTone($syllable, $base * 2.2, 0.08, 0, $length);
            $this->fadeBuffer($syllable, 0.25, 0.35);
            $this->mixAt($buffer, $syllable, $position, 1.0);
            $position += $length + random_int((int)(0.03 * self::RATE), (int)(0.5 * self::RATE));
        }
        $this->fade($buffer, 0.2, 0.3);
        return $buffer;
    }

    private function unknownCall(): array
    {
        $count = (int)(9.0 * self::RATE);
        $buffer = array_fill(0, $count, 0.0);
        // Amerikanisches Freizeichen: 440 + 480 Hz, 2 s an, 4 s aus
        for ($cycle = 0; $cycle < 2; $cycle++) {
            $start = (int)($cycle * 4.0 * self::RATE);
            $ring = array_fill(0, (int)(2.0 * self::RATE), 0.0);
            $this->addTone($ring, 440.0, 0.18, 0, count($ring));
            $this->addTone($ring, 480.0, 0.18, 0, count($ring));
            $this->fadeBuffer($ring, 0.02, 0.05);
            $this->mixAt($buffer, $ring, $start, 1.0);
        }
        $hiss = $this->bandpass($this->noise($count, 0.05), 0.1, 0.4);
        $this->mix($buffer, $hiss, 1.0);
        return $buffer;
    }

    /** Morsezeichen, rueckwaerts gespeichert - erst rueckwaerts abgespielt lesbar. */
    private function reversedMorse(string $text): array
    {
        $buffer = $this->morse($text, 620.0, 0.075);
        // Vor- und Nachlauf mit Rauschen, damit der Trick nicht sofort auffaellt
        $lead = $this->bandpass($this->noise((int)(1.4 * self::RATE), 0.06), 0.1, 0.5);
        $buffer = array_merge($lead, $buffer, $lead);
        $this->mix($buffer, $this->noise(count($buffer), 0.02), 1.0);
        return array_reverse($buffer);
    }

    private function morse(string $text, float $frequency, float $unit): array
    {
        $unitSamples = (int)($unit * self::RATE);
        $buffer = [];
        foreach (preg_split('~~u', mb_strtoupper($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if ($char === ' ') {
                $buffer = array_merge($buffer, array_fill(0, $unitSamples * 4, 0.0));
                continue;
            }
            $code = self::MORSE[$char] ?? null;
            if ($code === null) {
                continue;
            }
            foreach (str_split($code) as $symbol) {
                $length = $unitSamples * ($symbol === '-' ? 3 : 1);
                $tone = array_fill(0, $length, 0.0);
                $this->addTone($tone, $frequency, 0.34, 0, $length);
                $this->fadeBuffer($tone, 0.06, 0.1);
                $buffer = array_merge($buffer, $tone, array_fill(0, $unitSamples, 0.0));
            }
            $buffer = array_merge($buffer, array_fill(0, $unitSamples * 2, 0.0));
        }
        return $buffer;
    }

    private function sting(): array
    {
        $count = (int)(3.2 * self::RATE);
        $buffer = array_fill(0, $count, 0.0);
        for ($i = 0; $i < $count; $i++) {
            $t = $i / self::RATE;
            $frequency = 220.0 - (170.0 * ($i / $count));
            $buffer[$i] += sin(2 * M_PI * $frequency * $t) * 0.28 * (1.0 - ($i / $count));
        }
        $swell = $this->noise($count, 0.3);
        for ($i = 0; $i < $count; $i++) {
            $swell[$i] *= ($i / $count) ** 2.4;
        }
        $this->mix($buffer, $this->lowpass($swell, 0.15), 1.0);
        $this->addClick($buffer, (int)($count * 0.94), 0.5);
        $this->fade($buffer, 0.02, 0.1);
        return $buffer;
    }

    private function staticBurst(float $seconds): array
    {
        $count = (int)($seconds * self::RATE);
        $buffer = $this->noise($count, 0.32);
        for ($i = 0; $i < $count; $i++) {
            $buffer[$i] *= 0.6 + 0.4 * sin(2 * M_PI * 7.0 * ($i / self::RATE));
        }
        $this->fade($buffer, 0.05, 0.25);
        return $buffer;
    }

    private function whisper(float $seconds): array
    {
        $count = (int)($seconds * self::RATE);
        $buffer = $this->bandpass($this->noise($count, 0.22), 0.25, 0.75);
        for ($i = 0; $i < $count; $i++) {
            $modulation = 0.4 + 0.6 * abs(sin(2 * M_PI * 3.2 * ($i / self::RATE) + sin($i / 9000.0)));
            $buffer[$i] *= $modulation;
        }
        $this->fade($buffer, 0.3, 0.5);
        return $buffer;
    }

    private function heartbeat(float $seconds): array
    {
        $count = (int)($seconds * self::RATE);
        $buffer = array_fill(0, $count, 0.0);
        $interval = (int)(0.95 * self::RATE);
        for ($at = 0; $at < $count - $interval; $at += $interval) {
            foreach ([[0, 0.34], [(int)(0.16 * self::RATE), 0.22]] as [$offset, $gain]) {
                $beat = array_fill(0, (int)(0.2 * self::RATE), 0.0);
                $this->addTone($beat, 48.0, $gain, 0, count($beat));
                $this->fadeBuffer($beat, 0.03, 0.8);
                $this->mixAt($buffer, $beat, $at + $offset, 1.0);
            }
        }
        return $buffer;
    }

    private function cctvHum(float $seconds): array
    {
        $count = (int)($seconds * self::RATE);
        $buffer = array_fill(0, $count, 0.0);
        $this->addTone($buffer, 120.0, 0.05, 0, $count);
        $this->addTone($buffer, 15734.0 / 8, 0.012, 0, $count); // gedaempfter Zeilenton
        $this->mix($buffer, $this->bandpass($this->noise($count, 0.06), 0.05, 0.3), 1.0);
        $this->fade($buffer, 0.3, 0.4);
        return $buffer;
    }

    private function blip(float $frequency, float $seconds, float $gain): array
    {
        $count = (int)($seconds * self::RATE);
        $buffer = array_fill(0, $count, 0.0);
        $this->addTone($buffer, $frequency, $gain, 0, $count);
        $this->fadeBuffer($buffer, 0.1, 0.6);
        return $buffer;
    }

    private function errorTone(): array
    {
        $buffer = $this->blip(190.0, 0.16, 0.25);
        return array_merge($buffer, $this->blip(150.0, 0.22, 0.22));
    }

    private function successTone(): array
    {
        return array_merge(
            $this->blip(620.0, 0.08, 0.16),
            $this->blip(830.0, 0.12, 0.16)
        );
    }

    private function alertTone(): array
    {
        $buffer = [];
        for ($i = 0; $i < 3; $i++) {
            $buffer = array_merge($buffer, $this->blip(980.0, 0.07, 0.2), array_fill(0, (int)(0.05 * self::RATE), 0.0));
        }
        return $buffer;
    }

    /* =====================================================
     |  Bausteine
     ===================================================== */

    private function addTone(array &$buffer, float $frequency, float $gain, int $start, int $length): void
    {
        $count = count($buffer);
        $phase = random_int(0, 1000) / 1000.0 * 2 * M_PI;
        for ($i = 0; $i < $length && ($start + $i) < $count; $i++) {
            $t = $i / self::RATE;
            $buffer[$start + $i] += sin(2 * M_PI * $frequency * $t + $phase) * $gain;
        }
    }

    private function addClick(array &$buffer, int $at, float $gain): void
    {
        $count = count($buffer);
        for ($i = 0; $i < 220 && ($at + $i) < $count; $i++) {
            if ($at + $i < 0) {
                continue;
            }
            $buffer[$at + $i] += (random_int(-1000, 1000) / 1000.0) * $gain * (1.0 - ($i / 220));
        }
    }

    /** @return float[] */
    private function noise(int $count, float $gain): array
    {
        $buffer = [];
        for ($i = 0; $i < $count; $i++) {
            $buffer[] = (random_int(-1000, 1000) / 1000.0) * $gain;
        }
        return $buffer;
    }

    /** Einfacher Einpol-Tiefpass (factor: 0..1, kleiner = dumpfer) */
    private function lowpass(array $buffer, float $factor): array
    {
        $last = 0.0;
        foreach ($buffer as $index => $value) {
            $last += ($value - $last) * $factor;
            $buffer[$index] = $last;
        }
        return $buffer;
    }

    private function highpass(array $buffer, float $factor): array
    {
        $lowpassed = $this->lowpass($buffer, $factor);
        foreach ($buffer as $index => $value) {
            $buffer[$index] = $value - $lowpassed[$index];
        }
        return $buffer;
    }

    private function bandpass(array $buffer, float $low, float $high): array
    {
        return $this->lowpass($this->highpass($buffer, $low), $high);
    }

    private function mix(array &$target, array $source, float $gain): void
    {
        $count = min(count($target), count($source));
        for ($i = 0; $i < $count; $i++) {
            $target[$i] += $source[$i] * $gain;
        }
    }

    private function mixAt(array &$target, array $source, int $offset, float $gain): void
    {
        $count = count($target);
        foreach ($source as $index => $value) {
            $position = $offset + $index;
            if ($position >= 0 && $position < $count) {
                $target[$position] += $value * $gain;
            }
        }
    }

    private function fade(array &$buffer, float $inSeconds, float $outSeconds): void
    {
        $this->fadeBuffer($buffer, $inSeconds, $outSeconds);
    }

    private function fadeBuffer(array &$buffer, float $inSeconds, float $outSeconds): void
    {
        $count = count($buffer);
        $fadeIn = max(1, (int)($inSeconds * self::RATE));
        $fadeOut = max(1, (int)($outSeconds * self::RATE));
        for ($i = 0; $i < min($fadeIn, $count); $i++) {
            $buffer[$i] *= $i / $fadeIn;
        }
        for ($i = 0; $i < min($fadeOut, $count); $i++) {
            $buffer[$count - 1 - $i] *= $i / $fadeOut;
        }
    }

    /** @param float[] $samples */
    private function wav(array $samples): string
    {
        $count = count($samples);
        $data = '';
        $peak = 0.0;
        foreach ($samples as $value) {
            $peak = max($peak, abs($value));
        }
        $normalize = $peak > 0.92 ? (0.92 / $peak) : 1.0;

        foreach ($samples as $value) {
            $value *= $normalize;
            $value = max(-1.0, min(1.0, $value));
            $data .= pack('v', (int)round($value * 32767) & 0xFFFF);
        }

        $byteRate = self::RATE * 2;
        $header = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
            . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
            . pack('V', self::RATE) . pack('V', $byteRate) . pack('v', 2) . pack('v', 16)
            . 'data' . pack('V', strlen($data));
        return $header . $data;
    }
}
