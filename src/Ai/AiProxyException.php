<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Ai;

/**
 * Thrown when the AI proxy cannot forward a request.
 *
 * The `$httpStatus` property carries the suggested HTTP response code so the
 * API controller can map it directly to a JSON error response.
 */
final class AiProxyException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }
}
