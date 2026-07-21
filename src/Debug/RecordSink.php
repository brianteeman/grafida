<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Debug;

/** Receives one captured HTTP exchange at a time. */
interface RecordSink
{
    public function record(RequestRecord $record): void;
}
