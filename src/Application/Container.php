<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application;

use Joomla\DI\Container as JoomlaContainer;

/**
 * Thin subclass of joomla/di's container that gives {@see get()} a return
 * type PHPStan can use instead of the parent's untyped `mixed`.
 */
class Container extends JoomlaContainer
{
    /**
     * @template T of object
     *
     * @param class-string<T>|string $resourceName
     *
     * @return ($resourceName is class-string<T> ? T : mixed)
     */
    public function get($resourceName)
    {
        return parent::get($resourceName);
    }
}
