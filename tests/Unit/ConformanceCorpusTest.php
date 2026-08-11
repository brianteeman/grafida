<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Tests\Unit\Support\CorpusRunner;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runs `tests/corpus/` — the round-trip fidelity corpus.
 *
 * Every case is an article body as it arrives from somewhere real (a Joomla
 * site, a Word paste, a page builder's leftovers) together with the introtext /
 * fulltext {@see \Grafida\Html\ContentSplitter} must produce for it and the flat
 * top-level JSON object {@see \Grafida\Publish\PublishService} must POST/PATCH.
 *
 * ⚠️ **This is a contract, not a snapshot.** The corpus exists so a second
 * implementation of Grafida — one parsing the same HTML with a different HTML5
 * parser — can be written against something executable instead of against this
 * codebase's behaviour-by-inspection. So an expectation that fails is a
 * question ("which of the two is right?"), never a file to regenerate. The
 * format is documented in `.claude/rules/media-and-publish.md`.
 */
final class ConformanceCorpusTest extends TestCase
{
    /**
     * @return \Generator<string, array{string}>
     */
    public static function corpusCases(): \Generator
    {
        foreach (CorpusRunner::caseNames() as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('corpusCases')]
    public function testCaseMatchesItsExpectations(string $case): void
    {
        $dir    = CorpusRunner::corpusDir() . '/' . $case;
        $actual = CorpusRunner::run($case);

        self::assertSame(
            CorpusRunner::read($dir . '/expected-intro.html'),
            $actual['introtext'],
            $case . ': introtext',
        );

        // A case with no read-more marker ships no expected-full.html at all —
        // "the whole body is introtext" is a different statement from "the
        // fulltext happens to be empty", and the format keeps them distinct.
        $fullFile = $dir . '/expected-full.html';

        self::assertSame(
            is_file($fullFile) ? CorpusRunner::read($fullFile) : '',
            $actual['fulltext'],
            $case . ': fulltext',
        );

        self::assertSame(
            CorpusRunner::readJson($dir . '/expected-body.json'),
            $actual['body'],
            $case . ': publish body',
        );

        // Optional, and only worth writing where the case is *about* which
        // request the publish makes — a draft mirroring a remote article has to
        // PATCH the article it mirrors, not POST a second copy of it.
        $requestFile = $dir . '/expected-request.json';

        if (is_file($requestFile)) {
            self::assertSame(
                CorpusRunner::readJson($requestFile),
                $actual['request'],
                $case . ': publish request',
            );
        }
    }

    /**
     * The corpus is only a contract if every case is complete and says what it
     * is for; a case missing its `meta.json` description is a case nobody can
     * argue about later.
     */
    #[DataProvider('corpusCases')]
    public function testCaseIsWellFormed(string $case): void
    {
        $dir  = CorpusRunner::corpusDir() . '/' . $case;
        $meta = CorpusRunner::readJson($dir . '/meta.json');

        self::assertIsArray($meta, $case . ': meta.json is missing');
        self::assertArrayHasKey('description', $meta, $case . ': meta.json has no description');
        self::assertIsString($meta['description']);
        self::assertNotSame('', trim($meta['description']), $case . ': the description is empty');

        self::assertArrayHasKey('source', $meta, $case . ': meta.json has no source');

        self::assertFileExists($dir . '/input.html', $case . ': input.html is missing');
        self::assertFileExists($dir . '/expected-intro.html', $case . ': expected-intro.html is missing');
        self::assertFileExists($dir . '/expected-body.json', $case . ': expected-body.json is missing');
    }

    /** A corpus that has quietly emptied itself would pass every test above. */
    public function testTheCorpusIsPopulated(): void
    {
        self::assertGreaterThanOrEqual(25, \count(CorpusRunner::caseNames()));
    }
}
