<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Redact credentials from subprocess stderr/stdout before persisting to
 * .tessera/events.jsonl, .tessera/state.json, or AI prompts.
 *
 * Patterns cover env-var assignments and known token shapes. Non-secret
 * diagnostic text is preserved — only the credential value itself is
 * replaced with [REDACTED].
 *
 * Add patterns here when a new credential type is encountered in the wild;
 * keep them targeted so legitimate diagnostic output survives.
 */
final class SecretRedactor
{
    /**
     * @var list<array{pattern: string, replacement: string, flags: string}>
     */
    private static array $patterns = [
        // Env-var assignments: NAME=value (any line, any shell format)
        // Covers: ANTHROPIC_API_KEY=sk-..., OPENAI_API_KEY=..., GOOGLE_API_KEY=...,
        //         GEMINI_API_KEY=..., PGPASSWORD=..., MYSQL_PWD=...
        [
            'pattern' => '((?:ANTHROPIC_API_KEY|OPENAI_API_KEY|GOOGLE_API_KEY|GEMINI_API_KEY|PGPASSWORD|MYSQL_PWD)\s*=\s*)\S+',
            'replacement' => '$1[REDACTED]',
            'flags' => 'i',
        ],
        // OpenAI / Anthropic sk- tokens (sk-proj-... or sk-ant-...)
        [
            'pattern' => '\bsk-[A-Za-z0-9\-_]{10,}',
            'replacement' => '[REDACTED]',
            'flags' => '',
        ],
        // GitHub personal-access tokens: ghp_, gho_, ghu_, ghs_, ghr_
        [
            'pattern' => '\bgh[pousr]_[A-Za-z0-9]{10,}',
            'replacement' => '[REDACTED]',
            'flags' => '',
        ],
        // Bearer tokens in Authorization headers
        [
            'pattern' => '(Bearer\s+)\S+',
            'replacement' => '$1[REDACTED]',
            'flags' => 'i',
        ],
        // Basic-auth credentials in URLs: https://user:pass@host
        [
            'pattern' => '(https?://)([^:@\s]+:[^@\s]+)(@)',
            'replacement' => '$1[REDACTED]$3',
            'flags' => 'i',
        ],
    ];

    /**
     * Redact known secret patterns from $text. Non-secret content is unchanged.
     */
    public static function redact(string $text): string
    {
        foreach (self::$patterns as $rule) {
            $regex = '~'.$rule['pattern'].'~'.$rule['flags'];
            $replaced = preg_replace($regex, $rule['replacement'], $text);

            if ($replaced !== null) {
                $text = $replaced;
            }
        }

        return $text;
    }
}
