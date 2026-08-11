<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Html\ContentSplitter;

final class ContentSplitterTest extends TestCase
{
    public function testSplitsOnReadMoreMarker(): void
    {
        $splitter = new ContentSplitter();

        $result = $splitter->split('<p>Intro paragraph.</p><hr class="readmore"><p>The rest.</p>');

        self::assertStringContainsString('Intro paragraph.', $result['introtext']);
        self::assertStringNotContainsString('The rest.', $result['introtext']);
        self::assertStringContainsString('The rest.', $result['fulltext']);
        self::assertStringNotContainsString('readmore', $result['introtext'] . $result['fulltext']);
    }

    /**
     * Joomla's own marker is an id, not a class (gh-71), and it must split just
     * as our class form does — otherwise the read-more silently disappears the
     * first time an article written in Joomla is published from Grafida.
     */
    public function testSplitsOnJoomlaIdMarker(): void
    {
        $splitter = new ContentSplitter();

        $result = $splitter->split('<p>Intro paragraph.</p><hr id="system-readmore" /><p>The rest.</p>');

        self::assertStringContainsString('Intro paragraph.', $result['introtext']);
        self::assertStringNotContainsString('The rest.', $result['introtext']);
        self::assertStringContainsString('The rest.', $result['fulltext']);
        self::assertStringNotContainsString('readmore', $result['introtext'] . $result['fulltext']);
    }

    public function testWholeContentIsIntrotextWithoutMarker(): void
    {
        $splitter = new ContentSplitter();

        $result = $splitter->split('<p>Only intro here.</p>');

        self::assertStringContainsString('Only intro here.', $result['introtext']);
        self::assertSame('', $result['fulltext']);
    }

    public function testCountsMarkers(): void
    {
        $splitter = new ContentSplitter();

        self::assertSame(0, $splitter->countMarkers('<p>x</p>'));
        self::assertSame(1, $splitter->countMarkers('<p>a</p><hr class="readmore"><p>b</p>'));
        self::assertSame(2, $splitter->countMarkers('<hr class="readmore"><hr class="foo readmore">'));
        self::assertSame(1, $splitter->countMarkers('<p>a</p><hr id="system-readmore"><p>b</p>'));
        self::assertSame(2, $splitter->countMarkers('<hr class="readmore"><hr id="system-readmore">'));
        self::assertSame(0, $splitter->countMarkers('<hr class="notreadmore"><hr id="readmore-ish">'));
    }

    /**
     * A stray `</div>` closes the wrapper the fragment is parsed inside, which
     * would leave everything after it a sibling of that wrapper — invisible to
     * both the marker search and the serialiser, i.e. an article silently
     * truncated at the stray tag. Unbalanced markup like this is routine in a
     * body that has been through a page builder or a Word paste.
     */
    public function testStrayClosingDivDoesNotTruncateTheArticle(): void
    {
        $splitter = new ContentSplitter();

        $result = $splitter->split('<p>One.</p></div><p>Two.</p><hr class="readmore"><p>Three.</p>');

        self::assertSame('<p>One.</p><p>Two.</p>', $result['introtext']);
        self::assertSame('<p>Three.</p>', $result['fulltext']);
    }

    /**
     * HTML5 tree construction, not libxml2's HTML4 repair: a block-level
     * element inside an open `<p>` closes it, misnested inline tags go through
     * the adoption agency algorithm, and a `<tr>` gets its implied `<tbody>`.
     * The full corpus is `tests/corpus/`; this pins the headline cases where
     * they are easiest to read.
     */
    public function testParsesToTheHtml5TreeConstructionRules(): void
    {
        $splitter = new ContentSplitter();

        $result = $splitter->split(
            '<p>Before<section>In</section></p><hr class="readmore">'
            . '<b><i>x</b>y</i><table><tr><td>c</td></tr></table>'
        );

        self::assertSame('<p>Before</p><section>In</section><p></p>', $result['introtext']);
        self::assertSame(
            '<b><i>x</i></b><i>y</i><table><tbody><tr><td>c</td></tr></tbody></table>',
            $result['fulltext'],
        );
    }

    public function testHandlesUtf8(): void
    {
        $splitter = new ContentSplitter();

        $result = $splitter->split('<p>Καλημέρα</p><hr class="readmore"><p>Κόσμε</p>');

        self::assertStringContainsString('Καλημέρα', $result['introtext']);
        self::assertStringContainsString('Κόσμε', $result['fulltext']);
    }
}
