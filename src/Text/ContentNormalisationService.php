<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Text;

use Grafida\Storage\SettingsRepository;

/**
 * Stores how thoroughly text is stripped of the invisible characters AI tools
 * (and web pages, and word processors) leave behind.
 *
 * A three-way choice rather than an on/off toggle, for the same reason
 * {@see \Grafida\Editor\AutoCloseTagsService} is one: the two halves of the job
 * are not equally safe.
 *
 * - {@see self::FULL} — the default. Invisible formatting characters *and*
 *   exotic spaces (no-break, thin, hair, ideographic, …) collapse to a plain
 *   space.
 * - {@see self::INVISIBLE} — invisible formatting characters only; every space
 *   is left exactly as it was typed. This is the mode for anyone whose
 *   typography needs those spaces: French punctuation is set with a no-break
 *   space before `!?:;`, and Japanese/Chinese text is written with the
 *   ideographic space U+3000. Neither is a watermark, and rewriting them is
 *   rewriting the author's prose.
 * - {@see self::OFF} — nothing is touched. Also the honest answer for anyone
 *   writing right-to-left text who places directional marks deliberately:
 *   {@see ContentNormaliser} treats LRM/RLM and the bidi isolates as carriers
 *   and removes them.
 *
 * Mirrors the shape of the other preference services — the `settings` table is
 * a generic key/value store, so a new preference needs no migration.
 */
final class ContentNormalisationService
{
    public const FULL        = 'full';
    public const INVISIBLE   = 'invisible';
    public const OFF         = 'off';
    public const SETTING_KEY = 'content_normalisation';

    /** @var list<string> */
    public const AVAILABLE = [self::FULL, self::INVISIBLE, self::OFF];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** The stored preference, defaulting to the full clean-up. */
    public function current(): string
    {
        $mode = $this->settings->get(self::SETTING_KEY, self::FULL) ?? self::FULL;

        return \in_array($mode, self::AVAILABLE, true) ? $mode : self::FULL;
    }

    /** Persists and returns the (validated) preference. */
    public function set(string $mode): string
    {
        $mode = \in_array($mode, self::AVAILABLE, true) ? $mode : self::FULL;
        $this->settings->set(self::SETTING_KEY, $mode);

        return $mode;
    }
}
