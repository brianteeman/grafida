<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Reference;

use Grafida\Storage\SettingsRepository;

/**
 * Stores the two site-metadata cache preferences (gh-42 round 2) and performs
 * the opt-in startup cache reset.
 *
 * Mirrors {@see \Grafida\Editor\SlashToolsService}'s shape — the `settings`
 * table is a generic key/value store, so neither preference needs a
 * migration.
 */
final class MetadataCacheService
{
    public const SETTING_RESET_ON_START = 'metadata_reset_on_start';
    public const SETTING_TTL_MINUTES    = 'metadata_cache_ttl';

    /**
     * Cache lifetime choices offered in Settings, in minutes. 0 = never
     * refresh automatically. ⚠️ Must stay in step with `METADATA_TTL_CHOICES`
     * in `app.js` — a value the SPA offers but this clamp does not recognise
     * would silently snap back to the 1-hour default with no explanation.
     *
     * @var list<int>
     */
    public const TTL_CHOICES = [0, 15, 30, 60, 360, 720, 1440];

    public const DEFAULT_TTL_MINUTES = 60;

    /**
     * Guards {@see resetIfRequested()} so it only ever clears once per process.
     * This service is registered as a container singleton (see SiteProvider),
     * so the flag is per **process**, not per call — a webview reload
     * re-bootstraps the SPA, and re-clearing a cache the user has just spent a
     * slow minute refilling would be worse than useless.
     */
    private bool $resetDone = false;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ReferenceRepository $repository,
    ) {}

    /**
     * Whether the site metadata cache should be discarded every time the app
     * starts. Defaults to **false** — unlike `slash_tools`/`spell_check`
     * (default on) and like `request_log`, this is opt-in: on a slow or
     * unstable connection an unconditional refetch at launch looks like the
     * app has hung (gh-42).
     */
    public function resetOnStart(): bool
    {
        return ($this->settings->get(self::SETTING_RESET_ON_START, '0') ?? '0') !== '0';
    }

    /** Persists and returns the preference. */
    public function setResetOnStart(bool $enabled): bool
    {
        $this->settings->set(self::SETTING_RESET_ON_START, $enabled ? '1' : '0');

        return $enabled;
    }

    /**
     * How long cached site metadata may be reused before a background refresh,
     * in minutes. Clamped to {@see TTL_CHOICES}: a value not in the list (e.g.
     * a hand-edited settings row) falls back to the default rather than being
     * trusted, so it can never produce a runaway refresh rate.
     */
    public function ttlMinutes(): int
    {
        $stored = (int) ($this->settings->get(self::SETTING_TTL_MINUTES, (string) self::DEFAULT_TTL_MINUTES) ?? self::DEFAULT_TTL_MINUTES);

        return \in_array($stored, self::TTL_CHOICES, true) ? $stored : self::DEFAULT_TTL_MINUTES;
    }

    /** Clamps to {@see TTL_CHOICES}, persists, and returns what was actually stored. */
    public function setTtlMinutes(int $minutes): int
    {
        $clamped = \in_array($minutes, self::TTL_CHOICES, true) ? $minutes : self::DEFAULT_TTL_MINUTES;

        $this->settings->set(self::SETTING_TTL_MINUTES, (string) $clamped);

        return $clamped;
    }

    /**
     * Clears the reference cache if the user asked for that to happen at
     * startup. Returns whether it actually cleared.
     *
     * Called from the SPA bootstrap, which is the app's one "we have just
     * started" signal reachable from the kernel. Guarded by a per-instance
     * flag: see {@see $resetDone}.
     */
    public function resetIfRequested(): bool
    {
        if ($this->resetDone) {
            return false;
        }

        $this->resetDone = true;

        if (!$this->resetOnStart()) {
            return false;
        }

        $this->repository->clearAll();

        return true;
    }
}
