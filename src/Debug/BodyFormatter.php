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
 * Caps and describes an HTTP body for the Request Log / Diagnose Connection
 * presentation.
 */
final class BodyFormatter
{
    /** Per-direction cap: a media upload is a multi-megabyte base64 blob. */
    public const MAX_BYTES = 65536;

    private function __construct()
    {
        // Static-only.
    }

    /**
     * Truncates a body to {@see MAX_BYTES}, called by {@see RecordingTransport}
     * at capture time so a huge body is never retained.
     *
     * @return array{0: string|null, 1: bool}
     */
    public static function cap(?string $body): array
    {
        if ($body === null) {
            return [null, false];
        }

        if (\strlen($body) <= self::MAX_BYTES) {
            return [$body, false];
        }

        return [substr($body, 0, self::MAX_BYTES), true];
    }

    /**
     * Describes an already-capped body for display: its kind (none/binary/
     * json/text), the redacted text, and whether it was truncated.
     *
     * @param list<string> $secrets
     *
     * @return array{kind: string, text: string|null, truncated: bool}
     */
    public static function describe(?string $body, bool $truncated, array $secrets): array
    {
        if ($body === null || $body === '') {
            return ['kind' => 'none', 'text' => null, 'truncated' => false];
        }

        if (!self::isDisplayableText($body)) {
            return ['kind' => 'binary', 'text' => null, 'truncated' => $truncated];
        }

        $trimmed = ltrim($body);

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($body);

            if (json_last_error() === \JSON_ERROR_NONE) {
                $pretty = json_encode($decoded, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

                if ($pretty !== false) {
                    return ['kind' => 'json', 'text' => Redactor::redactText($pretty, $secrets), 'truncated' => $truncated];
                }
            }
        }

        return ['kind' => 'text', 'text' => Redactor::redactText($body, $secrets), 'truncated' => $truncated];
    }

    /** Valid UTF-8 and free of control characters other than tab/CR/LF. */
    private static function isDisplayableText(string $body): bool
    {
        if (!mb_check_encoding($body, 'UTF-8')) {
            return false;
        }

        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $body) !== 1;
    }
}
