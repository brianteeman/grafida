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
 * Splits article HTML into Joomla's introtext / fulltext on the "read more"
 * marker — an `<hr>` element carrying the `readmore` class, or Joomla's own
 * `<hr id="system-readmore">`.
 *
 * Both spellings are accepted, and equally (gh-71): Grafida's editor inserts the
 * class form, but Joomla's own editor — and therefore anything that reaches us
 * from a site or through a paste — writes the id form, which is the only one
 * Joomla itself splits on (`Table\Content::check()`). A marker we do not
 * recognise is not merely unstyled: the split then never happens and the
 * read-more silently disappears on publish.
 *
 * Everything before the marker is the introtext; everything after is the
 * fulltext. If there is no marker the whole content is introtext.
 */
final class ContentSplitter
{
    /**
     * The marker tokens, accepted in either the `class` or the `id` attribute.
     */
    private const MARKER_TOKENS = ['readmore', 'system-readmore'];

    /**
     * @return array{introtext: string, fulltext: string}
     */
    public function split(string $html): array
    {
        if (trim($html) === '') {
            return ['introtext' => '', 'fulltext' => ''];
        }

        $dom  = HtmlDocument::load($html);
        $body = HtmlDocument::body($dom);

        $marker = $this->findMarker($body);

        if ($marker === null) {
            return ['introtext' => trim($html), 'fulltext' => ''];
        }

        $intro = '';
        $full  = '';
        $seen  = false;

        // Iterate a static list because we mutate the live node list below.
        foreach (iterator_to_array($body->childNodes) as $node) {
            if ($node === $marker) {
                $seen = true;

                continue; // drop the marker itself
            }

            $html = HtmlDocument::saveNode($dom, $node);

            if ($seen) {
                $full .= $html;
            } else {
                $intro .= $html;
            }
        }

        return ['introtext' => trim($intro), 'fulltext' => trim($full)];
    }

    /**
     * Counts the read-more markers in the content (used by the editor to enforce
     * "at most one").
     */
    public function countMarkers(string $html): int
    {
        if (trim($html) === '') {
            return 0;
        }

        $dom   = HtmlDocument::load($html);
        $count = 0;

        foreach ($dom->getElementsByTagName('hr') as $hr) {
            if ($this->isMarker($hr)) {
                ++$count;
            }
        }

        return $count;
    }

    private function findMarker(\DOMNode $body): ?\DOMElement
    {
        foreach ($body->childNodes as $node) {
            if ($node instanceof \DOMElement
                && strtolower($node->nodeName) === 'hr'
                && $this->isMarker($node)) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Is this `<hr>` a read-more marker? Either attribute, either spelling.
     */
    private function isMarker(\DOMElement $element): bool
    {
        foreach (['class', 'id'] as $attribute) {
            $splitResult = preg_split('/\s+/', $element->getAttribute($attribute));
            $tokens      = $splitResult !== false ? $splitResult : [];

            if (array_intersect(self::MARKER_TOKENS, $tokens) !== []) {
                return true;
            }
        }

        return false;
    }
}
