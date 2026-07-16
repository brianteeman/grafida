<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Media;

/**
 * Thrown when a published article image cannot be fetched for a multimodal request.
 *
 * The `$httpStatus` property carries the suggested HTTP response code so the
 * controller can map it directly to a JSON error response.
 */
final class SiteImageException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }
}
