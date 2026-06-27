<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Html\InlineMedia;

final class InlineMediaTest extends TestCase
{
    public function testRewritesTaggedDataImageIntoMediaFieldImg(): void
    {
        $html = '<p><img src="data:image/png;base64,AAA" data-grafida-media-id="7">'
            . '<img src="https://example.com/done.png"></p>';

        $seen = [];
        $out  = (new InlineMedia())->rewriteDataImages($html, function (?int $id, string $uri) use (&$seen): array {
            $seen[] = [$id, $uri];

            return [
                'src'      => 'images/grafida/x.png',
                'dataPath' => 'local-images:/grafida/x.png',
                'width'    => 640,
                'height'   => 480,
            ];
        });

        // Only the data: image is offered to the callback, tagged with its blob id.
        self::assertSame([[7, 'data:image/png;base64,AAA']], $seen);
        self::assertStringContainsString('src="images/grafida/x.png"', $out);
        self::assertStringContainsString('data-path="local-images:/grafida/x.png"', $out);
        self::assertStringContainsString('width="640"', $out);
        self::assertStringContainsString('height="480"', $out);
        self::assertStringContainsString('loading="lazy"', $out);
        self::assertStringNotContainsString('data:image/png', $out);
        self::assertStringNotContainsString('data-grafida-media-id', $out);
    }

    public function testRewritesUntaggedDataImageWithNullId(): void
    {
        // A pasted/dropped image lands without the data-grafida-media-id tag; it
        // must still be offered to the callback (id null) so it gets uploaded.
        $html = '<p><img src="data:image/png;base64,BBB"></p>';

        $ids = [];
        $out = (new InlineMedia())->rewriteDataImages($html, function (?int $id, string $uri) use (&$ids): array {
            $ids[] = $id;

            return ['src' => 'images/grafida/y.png', 'dataPath' => 'local-images:/grafida/y.png'];
        });

        self::assertSame([null], $ids);
        self::assertStringContainsString('src="images/grafida/y.png"', $out);
        self::assertStringContainsString('data-path="local-images:/grafida/y.png"', $out);
        self::assertStringNotContainsString('data:image/png', $out);
    }

    public function testLeavesHtmlUntouchedWhenNoDataImages(): void
    {
        $html = '<p><img src="https://example.com/done.png"></p>';

        $out = (new InlineMedia())->rewriteDataImages($html, static fn (?int $id, string $uri): array => ['src' => 'unused']);

        self::assertSame($html, $out);
    }
}
