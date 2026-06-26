<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Display;

use Grafida\Storage\SettingsRepository;

/**
 * Stores the user's interface display-mode preference.
 *
 * The stored value is one of {@see self::AVAILABLE}; "auto" lets the front-end
 * follow the operating system's light/dark preference. Resolving "auto" to a
 * concrete light/dark value happens in the SPA, which is the only side that can
 * observe `prefers-color-scheme`.
 */
final class DisplayModeService
{
    public const AUTO        = 'auto';
    public const LIGHT       = 'light';
    public const DARK        = 'dark';
    public const SETTING_KEY = 'display_mode';

    /** @var list<string> */
    public const AVAILABLE = [self::AUTO, self::LIGHT, self::DARK];

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /** The stored preference, defaulting to "auto". */
    public function current(): string
    {
        $mode = $this->settings->get(self::SETTING_KEY, self::AUTO) ?? self::AUTO;

        return \in_array($mode, self::AVAILABLE, true) ? $mode : self::AUTO;
    }

    /** Persists and returns the (validated) preference. */
    public function set(string $mode): string
    {
        $mode = \in_array($mode, self::AVAILABLE, true) ? $mode : self::AUTO;
        $this->settings->set(self::SETTING_KEY, $mode);

        return $mode;
    }
}
