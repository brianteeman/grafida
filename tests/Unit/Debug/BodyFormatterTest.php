<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Debug;

use Grafida\Debug\BodyFormatter;
use Grafida\Tests\Unit\TestCase;

final class BodyFormatterTest extends TestCase
{
    public function testNullBodyDescribesAsNone(): void
    {
        self::assertSame(['kind' => 'none', 'text' => null, 'truncated' => false], BodyFormatter::describe(null, false, []));
    }

    public function testEmptyBodyDescribesAsNone(): void
    {
        self::assertSame(['kind' => 'none', 'text' => null, 'truncated' => false], BodyFormatter::describe('', false, []));
    }

    public function testJsonObjectIsPrettyPrintedAndStillDecodesToTheSameStructure(): void
    {
        $original = ['name' => 'Test', 'nested' => ['a' => 1, 'b' => 2]];
        $body     = json_encode($original);
        self::assertIsString($body);

        $described = BodyFormatter::describe($body, false, []);

        self::assertSame('json', $described['kind']);
        self::assertFalse($described['truncated']);
        self::assertIsString($described['text']);
        self::assertStringContainsString("\n", $described['text']);
        self::assertStringContainsString('    ', $described['text']); // JSON_PRETTY_PRINT indentation
        self::assertSame($original, json_decode($described['text'], true));
    }

    public function testJsonArrayIsPrettyPrintedAndStillDecodesToTheSameStructure(): void
    {
        $original = [1, 2, 3];
        $body     = json_encode($original);
        self::assertIsString($body);

        $described = BodyFormatter::describe($body, false, []);

        self::assertSame('json', $described['kind']);
        self::assertIsString($described['text']);
        self::assertStringContainsString("\n", $described['text']);
        self::assertSame($original, json_decode($described['text'], true));
    }

    public function testJsonBodyContainingTheTokenComesBackRedacted(): void
    {
        $secret = 'abcdefghijklmnop';
        $body   = json_encode(['token' => $secret]);
        self::assertIsString($body);

        $described = BodyFormatter::describe($body, false, [$secret]);

        self::assertSame('json', $described['kind']);
        self::assertIsString($described['text']);
        self::assertStringNotContainsString($secret, $described['text']);
        self::assertStringContainsString('.', $described['text']);
    }

    public function testInvalidUtf8IsBinary(): void
    {
        $described = BodyFormatter::describe("\xff\xfe\x00", false, []);

        self::assertSame('binary', $described['kind']);
        self::assertNull($described['text']);
    }

    public function testPngMagicHeaderIsBinary(): void
    {
        $described = BodyFormatter::describe("\x89PNG\r\n\x1a\n", true, []);

        self::assertSame('binary', $described['kind']);
        self::assertNull($described['text']);
        self::assertTrue($described['truncated']);
    }

    public function testPlainTextIsUnchanged(): void
    {
        $described = BodyFormatter::describe('Hello, world!', false, []);

        self::assertSame(['kind' => 'text', 'text' => 'Hello, world!', 'truncated' => false], $described);
    }

    public function testCapTruncatesAtMaxBytesAndFlagsTruncated(): void
    {
        $body = str_repeat('a', BodyFormatter::MAX_BYTES + 100);

        [$capped, $truncated] = BodyFormatter::cap($body);

        self::assertSame(BodyFormatter::MAX_BYTES, \strlen($capped));
        self::assertTrue($truncated);
    }

    public function testCapLeavesAShorterBodyUntouched(): void
    {
        [$capped, $truncated] = BodyFormatter::cap('short body');

        self::assertSame('short body', $capped);
        self::assertFalse($truncated);
    }

    public function testCapOfNullStaysNull(): void
    {
        [$capped, $truncated] = BodyFormatter::cap(null);

        self::assertNull($capped);
        self::assertFalse($truncated);
    }

    public function testCapOfExactlyMaxBytesIsNotTruncated(): void
    {
        $body = str_repeat('a', BodyFormatter::MAX_BYTES);

        [$capped, $truncated] = BodyFormatter::cap($body);

        self::assertSame(BodyFormatter::MAX_BYTES, \strlen($capped));
        self::assertFalse($truncated);
    }
}
