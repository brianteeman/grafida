<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Reference;

use Grafida\Http\HttpClient;
use Grafida\Joomla\ApiClient;
use Grafida\Joomla\ApiException;
use Grafida\Http\HttpException;
use Grafida\Site\Site;
use Grafida\Site\SiteService;

/**
 * Loads and caches the reference data a site needs while editing: categories,
 * tags, view access levels and custom-field definitions.
 *
 * Each list is fetched from the API and cached in SQLite. Reads return the
 * cached copy unless a refresh is requested or the cache is empty. A dedicated
 * short-timeout API client is used so a briefly unreachable site cannot hang
 * the editor or the connection flow.
 */
final class ReferenceService
{
    public const KIND_CATEGORIES = 'categories';
    public const KIND_TAGS       = 'tags';
    public const KIND_LEVELS     = 'levels';
    public const KIND_FIELDS     = 'fields';
    public const KIND_LANGUAGES  = 'languages';

    public function __construct(
        private readonly ReferenceRepository $repository,
        private readonly SiteService $sites,
        private readonly ApiClient $api = new ApiClient(new HttpClient(8)),
    ) {}

    /** @return list<array<string, mixed>> */
    public function categories(Site $site, bool $refresh = false, bool $bestEffort = false): array
    {
        return $this->load($site, self::KIND_CATEGORIES, $refresh, $bestEffort, fn (string $b, string $t) => $this->api->listCategories($b, $t));
    }

    /** @return list<array<string, mixed>> */
    public function tags(Site $site, bool $refresh = false, bool $bestEffort = false): array
    {
        return $this->load($site, self::KIND_TAGS, $refresh, $bestEffort, fn (string $b, string $t) => $this->api->listTags($b, $t));
    }

    /** @return list<array<string, mixed>> */
    public function accessLevels(Site $site, bool $refresh = false, bool $bestEffort = false): array
    {
        return $this->load($site, self::KIND_LEVELS, $refresh, $bestEffort, fn (string $b, string $t) => $this->api->listAccessLevels($b, $t));
    }

    /** @return list<array<string, mixed>> */
    public function fields(Site $site, bool $refresh = false, bool $bestEffort = false): array
    {
        return $this->load($site, self::KIND_FIELDS, $refresh, $bestEffort, fn (string $b, string $t) => $this->api->listArticleFields($b, $t));
    }

    /**
     * The site's installed content languages (what an article may be assigned to).
     *
     * @return list<array<string, mixed>>
     */
    public function contentLanguages(Site $site, bool $refresh = false, bool $bestEffort = false): array
    {
        return $this->load($site, self::KIND_LANGUAGES, $refresh, $bestEffort, fn (string $b, string $t) => $this->api->listContentLanguages($b, $t));
    }

    /**
     * Refreshes every reference list for a site from the network, best-effort.
     *
     * Used when connecting a site (and as a short-timeout attempt when opening
     * the editor) so the cache is warm before any selector is rendered. A
     * network failure for any one list is swallowed, leaving its cached copy —
     * if any — intact; connecting a site never fails because of this.
     */
    public function sync(Site $site): void
    {
        $this->categories($site, true, true);
        $this->tags($site, true, true);
        $this->accessLevels($site, true, true);
        $this->fields($site, true, true);
        $this->contentLanguages($site, true, true);
    }

    /**
     * @param callable(string, string): list<array<string, mixed>> $fetch
     *
     * @return list<array<string, mixed>>
     */
    private function load(Site $site, string $kind, bool $refresh, bool $bestEffort, callable $fetch): array
    {
        if ($site->id === null) {
            return [];
        }

        if (!$refresh) {
            $cached = $this->repository->get($site->id, $kind);

            if ($cached !== null) {
                /** @var list<array<string, mixed>> $payload */
                $payload = $cached['payload'];

                return $payload;
            }
        }

        $token = $this->sites->tokenFor($site);

        if ($token === null || $site->apiBase === null) {
            return $bestEffort ? $this->cachedOrEmpty($site->id, $kind) : [];
        }

        try {
            $data = $fetch($site->apiBase, $token);
        } catch (ApiException | HttpException $e) {
            // Best-effort callers (opening the editor, connecting a site) fall
            // back to the cached copy rather than blanking the whole sidebar or
            // failing the connection; strict callers (the manual refresh button)
            // surface the error.
            if ($bestEffort) {
                return $this->cachedOrEmpty($site->id, $kind);
            }

            throw $e;
        }

        $this->repository->put($site->id, $kind, $data);

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function cachedOrEmpty(int $siteId, string $kind): array
    {
        $cached = $this->repository->get($siteId, $kind);

        if ($cached === null) {
            return [];
        }

        /** @var list<array<string, mixed>> $payload */
        $payload = $cached['payload'];

        return $payload;
    }
}
