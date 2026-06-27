<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Html;

/**
 * Handles images that were inserted while editing offline.
 *
 * An image picked through Grafida's own media flow is embedded as
 * `<img src="data:..." data-grafida-media-id="N">`, but an image **pasted or
 * dragged straight into the editor** (e.g. dropped from a web page or another
 * app) lands as a bare `<img src="data:...">` with no tag, because it never went
 * through the in-editor upload handler. Before publishing, *every* such data:
 * URI must be uploaded and swapped for the real public URL returned by the Media
 * Manager — otherwise an untagged data: image would leak into the published
 * article as a huge, broken inline blob.
 */
final class InlineMedia
{
    public const ATTRIBUTE = 'data-grafida-media-id';

    /**
     * Rewrites every inline `data:` image into the Media-Manager `<img>` that
     * Joomla's own editor produces once the image is uploaded.
     *
     * The callback receives the offline-blob id tagged on the image (or null for
     * an untagged data: image that was pasted/dropped directly) together with the
     * raw data: URI, and returns the uploaded image's details:
     *   - `src`      the public URL (relative to the site root, as Joomla emits);
     *   - `dataPath` the Media Manager adapter path, e.g. "local-images:/grafida/x.jpg";
     *   - `width` / `height` the intrinsic pixel dimensions (or null if unknown).
     * The `data-grafida-media-id` attribute is dropped, `data-path` (the linkage
     * to the Media Manager entry), `loading="lazy"` and the dimensions are added
     * the way Joomla does. A callback may throw to abort the whole rewrite (e.g.
     * on an upload failure), so a publish never leaves a broken inline image.
     *
     * @param callable(?int $mediaId, string $dataUri): array{src: string, dataPath?: ?string, width?: ?int, height?: ?int} $upload
     *
     * @return string The rewritten HTML.
     */
    public function rewriteDataImages(string $html, callable $upload): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $dom     = HtmlDocument::load($html);
        $changed = false;

        foreach ($dom->getElementsByTagName('img') as $img) {
            $src = $img->getAttribute('src');

            if (!str_starts_with($src, 'data:')) {
                continue;
            }

            $idAttr  = $img->getAttribute(self::ATTRIBUTE);
            $mediaId = $idAttr !== '' && is_numeric($idAttr) ? (int) $idAttr : null;

            $result = $upload($mediaId, $src);

            $img->setAttribute('src', $result['src']);
            $img->removeAttribute(self::ATTRIBUTE);

            $dataPath = $result['dataPath'] ?? null;
            if (is_string($dataPath) && $dataPath !== '') {
                $img->setAttribute('data-path', $dataPath);
            }

            if (!$img->hasAttribute('loading')) {
                $img->setAttribute('loading', 'lazy');
            }

            $width = $result['width'] ?? null;
            if (is_int($width) && $width > 0 && !$img->hasAttribute('width')) {
                $img->setAttribute('width', (string) $width);
            }

            $height = $result['height'] ?? null;
            if (is_int($height) && $height > 0 && !$img->hasAttribute('height')) {
                $img->setAttribute('height', (string) $height);
            }

            $changed = true;
        }

        return $changed ? HtmlDocument::innerHtml($dom) : $html;
    }
}
