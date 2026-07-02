<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Ai;

use Grafida\Ai\AiRenderer;
use Grafida\Markdown\MarkdownService;
use Grafida\Tests\Unit\TestCase;

/**
 * Verifies that AiRenderer renders assistant replies to *formatted* HTML while
 * stripping anything unsafe: Markdown is converted via CommonMark, HTML is kept,
 * and both are sanitised down to the safe element/attribute subset.
 */
final class AiRendererTest extends TestCase
{
    private function renderer(): AiRenderer
    {
        return new AiRenderer(new MarkdownService());
    }

    public function testKeepsSafeHtmlFormatting(): void
    {
        $html = $this->renderer()->render('<h2>Title</h2><p>Hello <strong>world</strong></p>');

        self::assertStringContainsString('<h2>Title</h2>', $html);
        self::assertStringContainsString('<strong>world</strong>', $html);
    }

    public function testStripsScriptTags(): void
    {
        $html = $this->renderer()->render('<p>Safe</p><script>alert(1)</script>');

        self::assertStringContainsString('Safe', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('alert(1)', $html);
    }

    public function testStripsEventHandlerAttributes(): void
    {
        $html = $this->renderer()->render('<p onclick="steal()">Click</p>');

        self::assertStringContainsString('Click', $html);
        self::assertStringNotContainsString('onclick', $html);
    }

    public function testDropsJavascriptUrls(): void
    {
        $html = $this->renderer()->render('<a href="javascript:alert(1)">x</a>');

        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testKeepsClassAttribute(): void
    {
        // editor.css classes (Joomla's Styles drop-down) must survive so Insert
        // preserves the styling the reply asked for.
        $html = $this->renderer()->render('<p class="lead text-center">Intro</p>');

        self::assertStringContainsString('class="lead text-center"', $html);
    }

    public function testKeepsStyleAttribute(): void
    {
        $html = $this->renderer()->render('<p style="text-align: center; color: #036">Centered</p>');

        self::assertStringContainsString('text-align', $html);
        self::assertStringContainsString('color', $html);
    }

    public function testKeepsClassAndStyleOnImages(): void
    {
        $html = $this->renderer()->render(
            '<img src="images/a.jpg" class="img-fluid" style="float: right" alt="x">'
        );

        self::assertStringContainsString('class="img-fluid"', $html);
        self::assertStringContainsString('float', $html);
    }

    public function testConvertsMarkdownWhenInputIsNotHtml(): void
    {
        $html = $this->renderer()->render("# Heading\n\nSome **bold** text.");

        self::assertStringContainsString('<h1>Heading</h1>', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function testForcedMarkdownFormatConvertsEvenHtmlLookingInput(): void
    {
        $html = $this->renderer()->render("- one\n- two", 'markdown');

        self::assertStringContainsString('<li>', $html);
    }

    public function testForcedHtmlFormatDoesNotMarkdownConvert(): void
    {
        // A bare '#' would become a heading under Markdown; as HTML it stays text.
        $html = $this->renderer()->render('<p># not a heading</p>', 'html');

        self::assertStringContainsString('# not a heading', $html);
        self::assertStringNotContainsString('<h1>', $html);
    }
}
