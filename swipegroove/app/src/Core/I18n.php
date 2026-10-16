<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

/**
 * Übersetzungen aus lang/{de,en}.json. Deutsch ist Standard, Englisch vollständig.
 */
final class I18n
{
    public const LANGS = ['de', 'en'];
    public const DEFAULT = 'de';

    /** @var array<string,array<string,string>> */
    private array $bundles = [];
    private string $lang = self::DEFAULT;

    public function __construct(private string $dir)
    {
    }

    public function setLang(string $lang): void
    {
        $this->lang = in_array($lang, self::LANGS, true) ? $lang : self::DEFAULT;
    }

    public function lang(): string
    {
        return $this->lang;
    }

    public static function normalize(?string $lang): string
    {
        $lang = strtolower(substr((string) $lang, 0, 2));
        return in_array($lang, self::LANGS, true) ? $lang : self::DEFAULT;
    }

    public static function fromAcceptLanguage(?string $header): string
    {
        if (!$header) {
            return self::DEFAULT;
        }
        foreach (explode(',', $header) as $part) {
            $code = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
            if (in_array($code, self::LANGS, true)) {
                return $code;
            }
        }
        return self::DEFAULT;
    }

    /** @return array<string,string> */
    public function bundle(string $lang): array
    {
        $lang = self::normalize($lang);
        if (!isset($this->bundles[$lang])) {
            $file = $this->dir . '/' . $lang . '.json';
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
            $this->bundles[$lang] = is_array($data) ? $data : [];
        }
        return $this->bundles[$lang];
    }

    /** @param array<string,scalar> $params */
    public function t(string $key, array $params = [], ?string $lang = null): string
    {
        $lang = $lang ? self::normalize($lang) : $this->lang;
        $text = $this->bundle($lang)[$key] ?? $this->bundle(self::DEFAULT)[$key] ?? $key;
        foreach ($params as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }
}
