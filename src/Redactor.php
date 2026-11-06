<?php

namespace AutoHeal\Laravel;

/**
 * Applies the platform's redaction rules before anything leaves the application, so a secret that
 * reaches a log context or an exception message is never sent. The cases in
 * tests/Fixtures/redaction-cases.json are shared with the platform and must pass on both sides.
 */
final class Redactor
{
    public const MARKER = '[redacted]';

    /**
     * Whole-match patterns come first, so the narrower ones below never split a match in two.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----/s' => self::MARKER,
        '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/' => self::MARKER,
        '/\b(Bearer)\s+[A-Za-z0-9\-._~+\/]+=*/i' => '$1 ' . self::MARKER,
        '/\bah[eak]_[A-Za-z0-9]{40,}/' => self::MARKER,
        '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/' => self::MARKER,
        '~\b([a-z][a-z0-9+.\-]*://[^:/?#@\s]*:)[^@/?#\s]+@~i' => '$1' . self::MARKER . '@',
        '/\b(password|pwd)=[^;&\s]+/i' => '$1=' . self::MARKER,
    ];

    /** @var list<string> */
    private array $sensitiveNames;

    /**
     * @param  array<int, string>  $sensitiveKeys
     */
    public function __construct(array $sensitiveKeys)
    {
        $this->sensitiveNames = array_values(array_filter(array_map(
            fn (string $key): string => $this->normalizeKey($key),
            $sensitiveKeys,
        )));
    }

    public function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            $redacted[$key] = is_string($key) && $item !== null && $this->isSensitiveKey($key)
                ? self::MARKER
                : $this->redact($item);
        }

        return $redacted;
    }

    public function redactString(string $value): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = $this->normalizeKey($key);

        foreach ($this->sensitiveNames as $sensitiveName) {
            if (str_contains($normalized, $sensitiveName)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['-', '_'], '', $key));
    }
}
