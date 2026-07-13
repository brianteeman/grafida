<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http;

use Grafida\Reference\ReferenceService;
use Grafida\Site\FaviconService;
use Grafida\Site\Site;
use Grafida\Site\SiteService;

/**
 * Site/article resolution helpers shared by several controllers.
 *
 * This used to live on the abstract `Controller` base class, which forced
 * every controller — including ones with nothing to do with sites, such as
 * `SettingsController` and `AiChatController` — to depend on `SiteService`,
 * `FaviconService` and `ReferenceService`. Extracting it into an injectable
 * collaborator means only the controllers that actually call these helpers
 * (currently `ArticleController`, `BootstrapController`, `DraftController`,
 * `MediaController`, `SiteController`) need to depend on it, composition
 * over inheritance, matching how the rest of the codebase shares behaviour
 * via injected services rather than controller-to-controller reuse.
 */
final class SiteContext
{
    public function __construct(
        private readonly SiteService $sites,
        private readonly FaviconService $favicons,
        private readonly ReferenceService $references,
    ) {}

    public function requireSite(int $id): Site
    {
        $site = $this->sites->find($id);

        if ($site === null) {
            throw new \RuntimeException('Unknown site #' . $id);
        }

        return $site;
    }

    /**
     * Resolves a site together with the credentials needed to call its REST API.
     * Returns null when the site exists but is not connected (no token / API base),
     * so callers can answer with a 409 rather than a generic failure.
     *
     * @return array{0: Site, 1: string, 2: string}|null [site, token, apiBase]
     */
    public function connectedSite(int $siteId): ?array
    {
        $site  = $this->requireSite($siteId);
        $token = $this->sites->tokenFor($site);

        if ($token === null || $site->apiBase === null) {
            return null;
        }

        return [$site, $token, $site->apiBase];
    }

    /**
     * The public representation of a site sent to the SPA, augmented with its
     * cached favicon (a data: URI) when one has been downloaded.
     *
     * @return array<string, mixed>
     */
    public function siteArray(Site $site): array
    {
        return $site->toArray() + [
            'favicon' => $site->id !== null ? $this->favicons->dataUri($site->id) : null,
        ];
    }

    /**
     * Returns the resource IDs of a JSON:API to-many relationship (or the
     * single ID of a to-one relationship as a one-element list), as preserved
     * by ApiClient's flatten() under the `relationships` key.
     *
     * @param array<string, mixed> $resource
     *
     * @return list<int>
     */
    public function relationshipIds(array $resource, string $name): array
    {
        $relationships = $resource['relationships'] ?? null;

        if (!is_array($relationships) || !isset($relationships[$name]) || !is_array($relationships[$name])) {
            return [];
        }

        $data = $relationships[$name]['data'] ?? null;

        if (!is_array($data)) {
            return [];
        }

        // A to-one relationship's `data` is a single resource-identifier object;
        // a to-many's is a list of them. Normalise both to a list of IDs.
        $entries = isset($data['id']) ? [$data] : $data;
        $ids     = [];

        foreach ($entries as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Returns the ID of a to-one JSON:API relationship (e.g. an article's
     * category), or null when it is absent.
     *
     * @param array<string, mixed> $resource
     */
    public function firstRelationshipId(array $resource, string $name): ?int
    {
        return $this->relationshipIds($resource, $name)[0] ?? null;
    }

    /**
     * Annotates each article in a list with a normalised `catid` (int|null) and a
     * human-readable `categoryTitle`, resolved from the site's cached category
     * list. Saved drafts already carry a `catid` attribute; the remote list
     * webservice exposes the category only as a JSON:API relationship — both are
     * accepted. An id the cache does not know leaves `categoryTitle` null.
     *
     * @param list<array<string, mixed>> $articles
     *
     * @return list<array<string, mixed>>
     */
    public function withCategoryTitles(array $articles, Site $site): array
    {
        $titles = [];
        foreach ($this->references->categories($site) as $cat) {
            $id = $cat['id'] ?? null;
            if (is_numeric($id)) {
                $titleVal        = $cat['title'] ?? null;
                $titles[(int) $id] = is_string($titleVal) ? $titleVal : '';
            }
        }

        foreach ($articles as &$article) {
            $catId = isset($article['catid']) && is_numeric($article['catid'])
                ? (int) $article['catid']
                : $this->firstRelationshipId($article, 'category');

            $article['catid']         = $catId;
            $article['categoryTitle'] = $catId !== null ? ($titles[$catId] ?? null) : null;
        }
        unset($article);

        return $articles;
    }
}
