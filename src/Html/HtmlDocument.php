<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Html;

/**
 * Helpers for loading an HTML fragment into a DOM document and serialising
 * parts of it back to a string, preserving UTF-8 and without injecting a
 * doctype or wrapping <html>/<body> tags into the output.
 *
 * ⚠️ **This is PHP 8.4's `\Dom\HTMLDocument` — a real WHATWG HTML5 parser
 * (Lexbor) — not `\DOMDocument`, whose HTML support is libxml2's HTML4 parser
 * with a custom, unspecified tree-construction algorithm.** The difference is
 * not cosmetic for article HTML: HTML4 leaves `<section>`/`<figure>` inside an
 * open `<p>` (HTML5 implicitly closes the paragraph), leaves stray content
 * between `<table>` and `<tr>` where it stands (HTML5 foster-parents it out),
 * implies no `<tbody>`, repairs misnested inline tags ad hoc rather than by the
 * adoption agency algorithm, knows only the HTML4 entity table, and assumes
 * ISO-8859-1 in the absence of a `<meta charset>`. Every one of those produces
 * a *different article body* from the one the browser — and Joomla — sees. The
 * iPad app parses the same HTML with WebKit's HTML5 parser, so agreeing with
 * the standard is also what stops the two implementations from silently
 * disagreeing about someone's live website.
 *
 * The fragment is parsed inside a `<div id="grafida-root">` wrapper rather than
 * on its own. That is not a workaround for the parser — it is what gives a
 * *fragment* the tree-construction context of flow content: a bare fragment is
 * parsed as a whole document, so a leading `<style>` or `<meta>` would be
 * placed in the implied `<head>` and vanish from the body we serialise. Opening
 * a `<div>` first moves the parser into "in body" before the fragment's own
 * first token is seen.
 */
final class HtmlDocument
{
    /** The id of the wrapper element the fragment is parsed inside. */
    private const ROOT_ID = 'grafida-root';

    public static function load(string $html): \Dom\HTMLDocument
    {
        // LIBXML_NOERROR: an article body is arbitrary user HTML and parse
        // diagnostics are not actionable here — HTML5 tree construction has a
        // defined recovery for every one of them, which is the whole point.
        $dom = \Dom\HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body><div id="' . self::ROOT_ID . '">' . $html . '</div></body></html>',
            \LIBXML_NOERROR,
            'UTF-8'
        );

        self::unwrapIfEscaped($dom);

        return $dom;
    }

    /**
     * ⚠️ **A stray `</div>` in the fragment closes our wrapper**, and everything
     * after it then parses as a sibling of the wrapper rather than a child —
     * where {@see innerHtml()} would never look again, silently publishing an
     * article truncated at the stray tag. (Unbalanced markup like that is
     * routine in a body that has been through a page builder or a Word paste.)
     *
     * The tell is the body having more than the one child we gave it. When it
     * does, the wrapper has done its job — the fragment was parsed in flow
     * content — and is removed, its children moved back up in place, so the
     * body itself becomes the fragment container and nothing is lost.
     */
    private static function unwrapIfEscaped(\Dom\HTMLDocument $dom): void
    {
        $body = $dom->body;
        $root = $dom->getElementById(self::ROOT_ID);

        if ($body === null || $root === null || $body->childNodes->length < 2) {
            return;
        }

        while ($root->firstChild !== null) {
            $body->insertBefore($root->firstChild, $root);
        }

        $root->remove();
    }

    /**
     * Returns the element that holds the loaded fragment's children (our wrapper
     * div, or the body if the parser produced one).
     */
    public static function body(\Dom\HTMLDocument $dom): \Dom\Element
    {
        return $dom->getElementById(self::ROOT_ID)
            ?? $dom->body
            ?? $dom->documentElement
            ?? $dom->createElement('div');
    }

    public static function saveNode(\Dom\HTMLDocument $dom, \Dom\Node $node): string
    {
        return $dom->saveHtml($node);
    }

    /** Serialises the inner HTML of the fragment wrapper. */
    public static function innerHtml(\Dom\HTMLDocument $dom): string
    {
        $body = self::body($dom);
        $html = '';

        foreach ($body->childNodes as $child) {
            $html .= self::saveNode($dom, $child);
        }

        return $html;
    }
}
