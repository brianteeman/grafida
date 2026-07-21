<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Debug;

use Grafida\Storage\SettingsRepository;

/**
 * Stores whether the Request Log (recording the last 20 site-facing HTTP
 * exchanges) is enabled.
 *
 * Mirrors {@see \Grafida\Editor\SlashToolsService} — the `settings` table is a
 * generic key/value store, so no migration is needed. Unlike that preference
 * this one defaults *off*: it is a diagnostic aid with a memory cost, not
 * something every user wants running.
 */
final class RequestLogService
{
    public const SETTING_KEY = 'request_log';

    private ?bool $memo = null;

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * The stored preference, defaulting to disabled. Memoised — {@see
     * RequestLog::record()} asks on every outbound HTTP call, and a SQLite
     * round trip per request would be silly.
     */
    public function current(): bool
    {
        if ($this->memo === null) {
            $this->memo = ($this->settings->get(self::SETTING_KEY, '0') ?? '0') !== '0';
        }

        return $this->memo;
    }

    /** Persists and returns the preference. */
    public function set(bool $enabled): bool
    {
        $this->settings->set(self::SETTING_KEY, $enabled ? '1' : '0');
        $this->memo = $enabled;

        return $enabled;
    }
}
