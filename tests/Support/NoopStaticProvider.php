<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Support;

use Boson\Component\Http\Static\StaticProviderInterface;
use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;

/** A static-asset provider that never finds a file — hands every request to the kernel's fallback. */
final class NoopStaticProvider implements StaticProviderInterface
{
    public function findFileByRequest(RequestInterface $request): ?ResponseInterface
    {
        return null;
    }
}
