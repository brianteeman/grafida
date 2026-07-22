<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Media;

use Grafida\Article\DraftRepository;
use Grafida\Html\InlineMedia;

/**
 * Resyncs every **closed** draft that references a local media blob after
 * that blob's bytes change in place (gh-43: the Local Media tab's crop/
 * resize/rotate/flip editor). An already-open editor gets the live-DOM
 * treatment (see the JS-side `fitDimensions()` step); this is the other half
 * — the reported bug closes the article, edits the picture, then reopens it
 * and finds it distorted, and that round trip only ever touches
 * `drafts.html`, never a live TinyMCE instance.
 */
final class LocalMediaSync
{
    public function __construct(
        private readonly DraftRepository $drafts,
        private readonly InlineMedia $inlineMedia = new InlineMedia(),
    ) {}

    /**
     * Rewrites every draft on `$siteId` that references `$mediaId`, updating
     * each matching `<img>`'s `src` to `$newSrc` and, per
     * {@see ImageDimensions::fit()}, its `width`/`height` attributes.
     * Persists **only** the drafts whose HTML actually changed
     * ({@see \Grafida\Html\InlineMedia::resyncLocalImage()} returns the
     * original string byte-identical when nothing matched, which is what
     * makes an unaffected draft a no-op write here too).
     *
     * @return int how many drafts were updated
     */
    public function resync(
        int $siteId,
        int $mediaId,
        string $newSrc,
        ?int $oldW,
        ?int $oldH,
        ?int $newW,
        ?int $newH,
    ): int {
        $updated = 0;

        foreach ($this->drafts->listReferencingMedia($siteId, $mediaId) as $draft) {
            $resynced = $this->inlineMedia->resyncLocalImage($draft->html, $mediaId, $newSrc, $oldW, $oldH, $newW, $newH);

            if ($resynced === $draft->html) {
                continue;
            }

            \assert($draft->id !== null);
            $this->drafts->updateHtml($draft->id, $resynced);
            $updated++;
        }

        return $updated;
    }
}
