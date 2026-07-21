<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Debug;

use Grafida\Debug\Redactor;
use Grafida\Tests\Unit\TestCase;

final class RedactorTest extends TestCase
{
    public function testMaskKeepsFirstAndLastFourCharactersAndDotsTheMiddle(): void
    {
        $secret = 'abcdefghijklmnop'; // 16 chars

        $masked = Redactor::mask($secret);

        self::assertSame('abcd........mnop', $masked);
        self::assertSame(\strlen($secret), \strlen($masked));
    }

    public function testMaskOfALongerSecretPreservesLength(): void
    {
        $secret = str_repeat('x', 40);

        $masked = Redactor::mask($secret);

        self::assertSame(\strlen($secret), \strlen($masked));
        self::assertStringStartsWith('xxxx', $masked);
        self::assertStringEndsWith('xxxx', $masked);
    }

    public function testShortSecretIsReplacedWholesale(): void
    {
        self::assertSame(str_repeat('.', 8), Redactor::mask('short'));
        self::assertSame(str_repeat('.', 8), Redactor::mask('12345678')); // exactly 8 chars
        self::assertSame(str_repeat('.', 8), Redactor::mask(''));
    }

    public function testHeadersMasksAuthorizationKeepingTheBearerScheme(): void
    {
        $headers = [
            'Authorization' => 'Bearer abcdefghijklmnop',
            'Accept'        => 'application/vnd.api+json',
        ];

        $result = Redactor::headers($headers);

        self::assertSame('Bearer abcd........mnop', $result['Authorization']);
        self::assertSame('application/vnd.api+json', $result['Accept']);
    }

    public function testHeadersMasksXJoomlaTokenCaseInsensitively(): void
    {
        $headers = [
            'X-Joomla-Token' => 'abcdefghijklmnop',
            'x-joomla-token' => 'qrstuvwxyz012345',
            'Content-Type'   => 'application/json',
        ];

        $result = Redactor::headers($headers);

        self::assertSame('abcd........mnop', $result['X-Joomla-Token']);
        self::assertSame('qrst........2345', $result['x-joomla-token']);
        self::assertSame('application/json', $result['Content-Type']);
    }

    public function testHeadersMatchesAuthorizationCaseInsensitively(): void
    {
        $headers = ['authorization' => 'Bearer abcdefghijklmnop'];

        $result = Redactor::headers($headers);

        self::assertSame('Bearer abcd........mnop', $result['authorization']);
    }

    public function testRedactTextReplacesEveryOccurrenceInABodyAndQueryString(): void
    {
        $secret = 'abcdefghijklmnop';
        $masked = Redactor::mask($secret);

        $body = '{"token":"' . $secret . '","again":"' . $secret . '"}';
        self::assertSame('{"token":"' . $masked . '","again":"' . $masked . '"}', Redactor::redactText($body, [$secret]));

        $query = 'https://example.test/v1/content/articles?access_token=' . $secret;
        self::assertSame(
            'https://example.test/v1/content/articles?access_token=' . $masked,
            Redactor::redactText($query, [$secret])
        );
    }

    public function testRedactTextIgnoresEmptySecrets(): void
    {
        self::assertSame('unchanged text', Redactor::redactText('unchanged text', ['']));
    }

    public function testSecretsFromHeadersStripsBearerPrefix(): void
    {
        $headers = [
            'Authorization'  => 'Bearer the-actual-token',
            'X-Joomla-Token' => 'the-actual-token',
            'Accept'         => 'application/json',
        ];

        $secrets = Redactor::secretsFromHeaders($headers);

        self::assertSame(['the-actual-token', 'the-actual-token'], $secrets);
    }

    public function testSecretsFromHeadersReturnsEmptyWithNoCredentialHeaders(): void
    {
        self::assertSame([], Redactor::secretsFromHeaders(['Accept' => 'application/json']));
    }
}
