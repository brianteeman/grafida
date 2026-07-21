<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Debug;

/**
 * Masks credentials out of captured HTTP exchanges.
 *
 * Redaction is unconditional — every {@see RequestRecord} that leaves PHP has
 * already been through here, whether it is bound for the Request Log screen,
 * the Diagnose Connection panel, or a JSON export. There is no code path that
 * emits an unredacted token.
 */
final class Redactor
{
    /** Header names (lower-case) whose value carries a credential. */
    public const HEADER_NAMES = ['authorization', 'x-joomla-token'];

    private function __construct()
    {
        // Static-only.
    }

    /**
     * Keeps the first and last 4 characters of a secret, replacing everything
     * in between with one `.` per replaced character. A secret of 8 characters
     * or fewer is replaced wholesale with 8 dots — there is nothing safe to keep.
     */
    public static function mask(string $secret): string
    {
        $length = \strlen($secret);

        if ($length <= 8) {
            return str_repeat('.', 8);
        }

        return substr($secret, 0, 4) . str_repeat('.', $length - 8) . substr($secret, -4);
    }

    /**
     * Redacts every credential-bearing header, case-insensitively matched
     * against {@see HEADER_NAMES}. `Authorization`'s value is `Bearer <token>`
     * — only the credential part is masked, so the scheme stays visible.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public static function headers(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            $result[$name] = \in_array(strtolower($name), self::HEADER_NAMES, true)
                ? self::maskHeaderValue($name, $value)
                : $value;
        }

        return $result;
    }

    /**
     * Replaces each non-empty secret occurring literally in `$text` with its
     * mask. Used for bodies and URLs — a token can appear in a query string.
     *
     * @param list<string> $secrets
     */
    public static function redactText(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }

            $text = str_replace($secret, self::mask($secret), $text);
        }

        return $text;
    }

    /**
     * Pulls the raw credential values out of a header set — the `Bearer `
     * prefix stripped for `Authorization` — so bodies/URLs on the same record
     * can be scrubbed without the caller having to know the token.
     *
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    public static function secretsFromHeaders(array $headers): array
    {
        $secrets = [];

        foreach ($headers as $name => $value) {
            if (!\in_array(strtolower($name), self::HEADER_NAMES, true)) {
                continue;
            }

            $secrets[] = strtolower($name) === 'authorization' ? self::credentialPart($value) : $value;
        }

        return $secrets;
    }

    private static function maskHeaderValue(string $name, string $value): string
    {
        if (strtolower($name) !== 'authorization') {
            return self::mask($value);
        }

        $credential = self::credentialPart($value);
        $scheme     = substr($value, 0, \strlen($value) - \strlen($credential));

        return $scheme . self::mask($credential);
    }

    /** Returns the `Bearer <token>` value's token part, or the whole value if there is no scheme. */
    private static function credentialPart(string $value): string
    {
        if (preg_match('/^\S+\s+(.+)$/', $value, $matches) === 1) {
            return $matches[1];
        }

        return $value;
    }
}
