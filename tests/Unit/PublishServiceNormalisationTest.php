<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Article\Draft;
use Grafida\Article\DraftRepository;
use Grafida\Http\HttpResponse;
use Grafida\I18n\LanguageService;
use Grafida\Joomla\ApiClient;
use Grafida\Media\InlineImageExtractor;
use Grafida\Media\MediaRepository;
use Grafida\Media\MediaUploadTarget;
use Grafida\Publish\PublishService;
use Grafida\Reference\ReferenceRepository;
use Grafida\Reference\ReferenceService;
use Grafida\Site\Site;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Storage\SettingsRepository;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\Support\FakeTransport;
use Grafida\Text\ContentNormalisationService;
use Grafida\Text\ContentNormaliser;
use Joomla\Database\DatabaseInterface;

/**
 * The invisible-character sweep at the publish boundary.
 *
 * Publishing is the last thing that happens to an article before other people
 * read it, and the only step every route into a draft passes through — so it is
 * where the marks have to be gone, regardless of whether they arrived from the
 * AI panel, an ordinary paste, or an imported file.
 */
final class PublishServiceNormalisationTest extends TestCase
{
    private const ENDPOINT = 'https://example.com/index.php/api/v1/content/articles';

    private DatabaseInterface $db;
    private DraftRepository $drafts;
    private FakeTransport $transport;
    private Site $site;

    protected function setUp(): void
    {
        $this->db   = TestDatabase::memory();
        $connection = TestDatabase::connection($this->db);
        $connection->exec(
            'INSERT INTO sites (id, title, base_url, api_base, insecure_token, created_at, updated_at) '
            . "VALUES (1, 'Site', 'https://example.com', 'https://example.com/index.php/api', 'tok', "
            . "'2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );

        $this->drafts    = new DraftRepository($this->db);
        $this->transport = new FakeTransport();
        $this->site      = new Site(
            id: 1,
            title: 'Site',
            baseUrl: 'https://example.com',
            apiBase: 'https://example.com/index.php/api',
            secretRef: null,
            hasInsecureToken: true,
        );

        $references = new ReferenceRepository($this->db);
        $references->put(1, ReferenceService::KIND_FIELDS, []);
        $references->put(1, ReferenceService::KIND_CATEGORIES, []);

        // Joomla echoes the title it stored; the service compares it against
        // the one it *sent*, which is the cleaned one.
        $this->transport->on(self::ENDPOINT, new HttpResponse(
            201,
            (string) json_encode(['data' => ['type' => 'articles', 'id' => '42', 'attributes' => [
                'title' => 'A clean title',
            ]]]),
            ['Content-Type' => 'application/vnd.api+json'],
        ));
    }

    private function publishService(): PublishService
    {
        $apiClient = new ApiClient($this->transport);
        $sites     = new SiteService(new SiteRepository($this->db), $apiClient, null);
        $media     = new MediaRepository($this->db);
        $settings  = new SettingsRepository($this->db);

        return new PublishService(
            $sites,
            $apiClient,
            new ReferenceService(new ReferenceRepository($this->db), $sites, $apiClient),
            $this->drafts,
            $media,
            new LanguageService($settings, \dirname(__DIR__, 2)),
            new InlineImageExtractor($media),
            new MediaUploadTarget($apiClient),
            new ContentNormaliser(new ContentNormalisationService($settings)),
        );
    }

    /** @return array<string, mixed> The attributes of the last article write. */
    private function publishedAttributes(): array
    {
        foreach (array_reverse($this->transport->requests) as $request) {
            if ($request['url'] !== self::ENDPOINT || $request['body'] === null) {
                continue;
            }

            $body = json_decode($request['body'], true);

            self::assertIsArray($body);

            return $body;
        }

        self::fail('No article write was sent.');
    }

    private function draft(string $title, string $html, string $metadesc = ''): Draft
    {
        return new Draft(
            id: null,
            siteId: 1,
            remoteId: null,
            title: $title,
            alias: 'a-clean-title',
            catid: null,
            access: 1,
            language: '*',
            state: 1,
            html: $html,
            metadesc: $metadesc,
        );
    }

    public function testStripsInvisibleCharactersFromTheWholePayload(): void
    {
        $this->publishService()->publish(
            $this->draft(
                "A cle\u{200B}an title",
                "<p>Body\u{FEFF} text with\u{00A0}a space and a \u{202E}mark\u{202C}.</p>",
                "Meta\u{200B}description",
            ),
            $this->site,
        );

        $attributes = $this->publishedAttributes();

        self::assertSame('A clean title', $attributes['title']);
        self::assertSame('<p>Body text with a space and a mark.</p>', $attributes['introtext']);
        self::assertSame('Metadescription', $attributes['metadesc']);
    }

    /** An emoji sequence survives the sweep intact — its joiners are not marks. */
    public function testKeepsEmojiSequencesInTheBody(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

        $this->publishService()->publish(
            $this->draft('A clean title', '<p>Us: ' . $family . '</p>'),
            $this->site,
        );

        self::assertSame('<p>Us: ' . $family . '</p>', $this->publishedAttributes()['introtext']);
    }

    /** With the preference off, the article is published exactly as it was written. */
    public function testPublishesVerbatimWhenTheCleanUpIsOff(): void
    {
        (new ContentNormalisationService(new SettingsRepository($this->db)))
            ->set(ContentNormalisationService::OFF);

        $html = "<p>Body\u{200B} text</p>";

        $this->publishService()->publish($this->draft('A clean title', $html), $this->site);

        self::assertSame($html, $this->publishedAttributes()['introtext']);
    }
}
