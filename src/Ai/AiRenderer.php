<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Ai;

use Grafida\Markdown\MarkdownService;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Turns an AI assistant reply into HTML that is safe to drop into the chat panel.
 *
 * Replies are untrusted model output: the system prompt asks for HTML5, while
 * the "Generate" writing tool returns Markdown. This service normalises both to
 * sanitised HTML so the panel can render *formatted* text — and so Insert can
 * drop it into the article — without ever exposing the user to script/iframe/
 * event-handler injection:
 *
 *   - Markdown input is converted with CommonMark ({@see MarkdownService}) first.
 *   - The resulting (or already-HTML) markup is run through Symfony's
 *     HtmlSanitizer, restricted to the W3C "safe" element/attribute subset plus
 *     the `class`/`style` attributes article markup needs (see the constructor).
 *
 * The raw reply is still what Insert/Copy use in the SPA; this only governs the
 * rendered preview.
 */
final class AiRenderer
{
    private readonly HtmlSanitizer $sanitizer;

    public function __construct(private readonly MarkdownService $markdown)
    {
        // The W3C-defined safe subset (headings, paragraphs, lists, tables,
        // links, images, inline formatting, code/pre, blockquote, …) is exactly
        // the article-grade markup we want; relative URLs keep site-root-relative
        // image/link references (e.g. images/…) working in the preview.
        //
        // `class` and `style` are NOT in the W3C safe set, but article markup
        // relies on them: editor.css classes (Joomla's Styles drop-down) and
        // inline styling (text-align, colours, spacing) are the whole point of a
        // formatted reply, and Insert drops the sanitised HTML straight into the
        // article — stripping them would mangle every styled paragraph. Allowing
        // them is safe here: the sanitiser still removes script/iframe/object and
        // event-handler attributes and neutralises javascript: URLs, and modern
        // webviews don't execute CSS expression()/behaviour, so `style` carries no
        // script vector.
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowAttribute('class', '*')
            ->allowAttribute('style', '*');

        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * Render an assistant reply to sanitised HTML.
     *
     * @param string $content The raw reply text.
     * @param string $format  'html', 'markdown', or 'auto' (detect — the default).
     */
    public function render(string $content, string $format = 'auto'): string
    {
        $isMarkdown = match ($format) {
            'markdown' => true,
            'html'     => false,
            default    => !$this->looksLikeHtml($content),
        };

        $html = $isMarkdown ? $this->markdown->toHtml($content) : $content;

        return $this->sanitizer->sanitize($html);
    }

    /** Heuristic: does the text contain at least one HTML tag? */
    private function looksLikeHtml(string $content): bool
    {
        return preg_match('/<([a-z][a-z0-9]*)\b[^>]*>/i', $content) === 1;
    }
}
