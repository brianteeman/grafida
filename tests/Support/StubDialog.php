<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Support;

use Boson\Api\Dialog\DialogApiInterface;

/** A native Dialog API stub whose file-open / directory-select return the given paths (or null/cancel). */
final class StubDialog implements DialogApiInterface
{
    public function __construct(
        private readonly ?string $filePath = null,
        private readonly ?string $directoryPath = null,
    ) {}

    public function selectFile(?string $directory = null, iterable $filter = []): ?string
    {
        return $this->filePath;
    }

    public function selectFiles(?string $directory = null, iterable $filter = []): iterable
    {
        return [];
    }

    public function selectDirectory(?string $directory = null, iterable $filter = []): ?string
    {
        return $this->directoryPath;
    }

    public function selectDirectories(?string $directory = null, iterable $filter = []): iterable
    {
        return [];
    }

    public function open(string|\Stringable $uri): void {}
}
