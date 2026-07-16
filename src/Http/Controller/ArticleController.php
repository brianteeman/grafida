<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Controller;

use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Http\Json;
use Grafida\Http\RouteContext;
use Grafida\Http\Router;
use Grafida\Http\SiteContext;
use Grafida\Joomla\ApiClient;
use Grafida\Reference\ReferenceService;
use Grafida\Site\Site;

/** Handles `GET /api/sites/{id}/articles[/{articleId}]`, the remote-article browse/fetch. */
final class ArticleController extends Controller
{
    /**
     * The article columns the SPA may sort by. Each maps to a Joomla
     * `list[ordering]` value drawn from the article list's `filter_fields`
     * (administrator/components/com_content/ArticlesModel), so the API accepts
     * it. Anything outside this set falls back to `a.id`.
     */
    private const ARTICLE_ORDERING = [
        'a.id', 'a.title', 'category_title', 'a.created', 'a.modified',
        'a.publish_up', 'a.publish_down', 'a.access', 'a.state', 'a.featured',
        'a.ordering', 'a.hits', 'a.created_by', 'language',
    ];

    /** Published-state values Joomla's `filter[state]` accepts for articles. */
    private const ARTICLE_STATES = [1, 0, 2, -2];

    public function __construct(
        private readonly SiteContext $siteContext,
        private readonly ReferenceService $references,
        private readonly ApiClient $apiClient,
    ) {}

    public function registerRoutes(Router $router): void
    {
        $router->add('GET', '/api/sites/{id}/articles', fn (RouteContext $ctx): ResponseInterface => $this->remoteArticles($ctx->int('id'), $ctx->request()));
        $router->add('GET', '/api/sites/{id}/articles/{articleId}', fn (RouteContext $ctx): ResponseInterface => $this->remoteArticle($ctx->int('id'), $ctx->int('articleId')));
    }

    /**
     * Returns one page of the site's remote articles, sorted and filtered exactly
     * as Joomla's back-end article list would be. The SPA passes the page, page
     * size, ordering and the supported filters as query parameters; they are
     * forwarded to the Joomla REST API (which exposes `filter[search|category|
     * tag|language|state|featured|checked_out]` and `list[ordering|direction]`)
     * and the page's items are returned alongside the pagination total.
     */
    public function remoteArticles(int $siteId, RequestInterface $request): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        [$site, $token, $apiBase] = $conn;

        $q = $request->url->query;

        $limit = (int) ($q->get('limit') ?? 20);
        $limit = max(1, min(100, $limit));
        $page  = max(1, (int) ($q->get('page') ?? 1));

        $ordering = $q->get('ordering') ?? 'a.id';
        if (!in_array($ordering, self::ARTICLE_ORDERING, true)) {
            $ordering = 'a.id';
        }
        $direction = strtolower($q->get('direction') ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query = [
            'page[limit]'     => $limit,
            'page[offset]'    => ($page - 1) * $limit,
            'list[ordering]'  => $ordering,
            'list[direction]' => $direction,
        ];

        $search = trim($q->get('search') ?? '');
        if ($search !== '') {
            $query['filter[search]'] = $search;
        }

        $category = (int) ($q->get('category') ?? 0);
        if ($category > 0) {
            $query['filter[category]'] = $category;
        }

        $tag = (int) ($q->get('tag') ?? 0);
        if ($tag > 0) {
            $query['filter[tag]'] = $tag;
        }

        $language = trim($q->get('language') ?? '');
        if ($language !== '') {
            $query['filter[language]'] = $language;
        }

        $stateRaw = $q->get('state');
        if ($stateRaw !== null && $stateRaw !== '' && in_array((int) $stateRaw, self::ARTICLE_STATES, true)) {
            $query['filter[state]'] = (int) $stateRaw;
        }

        $featuredRaw = $q->get('featured');
        if ($featuredRaw === '0' || $featuredRaw === '1') {
            $query['filter[featured]'] = (int) $featuredRaw;
        }

        $checkedOutRaw = $q->get('checked_out');
        if ($checkedOutRaw === '0' || $checkedOutRaw === '-1') {
            $query['filter[checked_out]'] = (int) $checkedOutRaw;
        }

        $result = $this->apiClient->listArticlesPage($apiBase, $token, $query);

        return Json::ok([
            'items'      => $this->siteContext->withCategoryTitles($result['items'], $site),
            'page'       => $page,
            'limit'      => $limit,
            'totalPages' => $result['totalPages'],
        ]);
    }

    /**
     * Fetches a single remote article and returns it shaped as an unsaved draft
     * (id: null) ready for the editor — see {@see self::remoteArticleToDraft()}.
     */
    public function remoteArticle(int $siteId, int $articleId): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        [$site, $token, $apiBase] = $conn;

        $article = $this->apiClient->getArticle($apiBase, $token, $articleId);

        return Json::ok($this->remoteArticleToDraft($siteId, $articleId, $article, $site));
    }

    /**
     * Maps a Joomla article resource into an unsaved draft payload. The body is
     * recovered as editor HTML by {@see remoteArticleBody()} (discrete
     * introtext/fulltext when the API exposes them, otherwise heuristically split
     * from the combined `text` attribute); the category and tags come from JSON:API
     * relationships, tag IDs being resolved to titles (best effort) via the
     * reference cache so editing then publishing does not drop them.
     *
     * @param array<string, mixed> $article
     *
     * @return array<string, mixed>
     */
    private function remoteArticleToDraft(int $siteId, int $articleId, array $article, Site $site): array
    {
        $html = $this->remoteArticleBody($article);

        $language = $this->str($article, 'language', '*');

        $catId = $this->siteContext->firstRelationshipId($article, 'category');

        return [
            'id'             => null,
            'siteId'         => $siteId,
            'remoteId'       => $articleId,
            'title'          => $this->str($article, 'title'),
            'alias'          => $this->str($article, 'alias'),
            'catid'          => $catId,
            'access'         => isset($article['access']) && is_numeric($article['access']) ? (int) $article['access'] : 1,
            'language'       => $language !== '' ? $language : '*',
            'state'          => isset($article['state']) && is_numeric($article['state']) ? (int) $article['state'] : 1,
            'html'           => $html,
            'fields'         => [],
            'tags'           => $this->remoteTagTitles($article, $site),
            'images'         => $this->remoteImages($article),
            'metadesc'       => $this->str($article, 'metadesc'),
            'metakey'        => $this->str($article, 'metakey'),
            'createdByAlias' => $this->str($article, 'created_by_alias'),
        ];
    }

    /**
     * Recovers an article's editor HTML from a Joomla article resource, restoring
     * the intro/full-text split as a read-more marker (`<hr class="readmore">`,
     * matching the editor and {@see \Grafida\Html\ContentSplitter}) so it survives
     * the round-trip back to publishing.
     *
     * Joomla's article API currently only returns the combined `text` attribute. A
     * pending Joomla PR would expose discrete `introtext` / `fulltext` attributes;
     * we prefer those if present so we pick up the improvement automatically. Until
     * then we fall back to a heuristic: Joomla joins the two parts with
     * "\r\n \r\n" (CRLF, space, CRLF) in the combined text, which is a reliable
     * separator in most articles. (The API does not preserve the read-more marker,
     * so an article whose body genuinely lacks that sequence is treated as
     * intro-only — the worst case is a missing split, never lost content.)
     *
     * @param array<string, mixed> $article
     */
    private function remoteArticleBody(array $article): string
    {
        if (array_key_exists('introtext', $article) || array_key_exists('fulltext', $article)) {
            $intro = $this->str($article, 'introtext');
            $full  = $this->str($article, 'fulltext');
        } else {
            $parts = explode("\r\n \r\n", $this->str($article, 'text'), 2);
            $intro = $parts[0];
            $full  = $parts[1] ?? '';
        }

        if (trim($full) === '') {
            return trim($intro);
        }

        return trim($intro) . "\n<hr class=\"readmore\">\n" . trim($full);
    }

    /**
     * Resolves a remote article's tags (IDs or tag objects) to titles, which is
     * how a draft stores them. Tags the local reference cache does not know are
     * skipped rather than guessed.
     *
     * @param array<string, mixed> $article
     *
     * @return list<string>
     */
    private function remoteTagTitles(array $article, Site $site): array
    {
        // Joomla exposes an article's tags as a to-many relationship, not an
        // attribute, so the IDs come from `relationships.tags.data[].id`.
        $tagIds = $this->siteContext->relationshipIds($article, 'tags');

        if ($tagIds === []) {
            return [];
        }

        $titleById = [];
        foreach ($this->references->tags($site) as $tag) {
            $id    = $tag['id'] ?? null;
            $title = $tag['title'] ?? null;
            if ((is_int($id) || is_string($id)) && is_string($title)) {
                $titleById[(int) $id] = $title;
            }
        }

        $out = [];
        foreach ($tagIds as $id) {
            if (isset($titleById[$id])) {
                $out[] = $titleById[$id];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Normalises a remote article's `images` attribute (a JSON string or object)
     * to a plain array the draft can store and republish verbatim.
     *
     * @param array<string, mixed> $article
     *
     * @return array<string, mixed>
     */
    private function remoteImages(array $article): array
    {
        $images = $article['images'] ?? null;

        if (is_string($images) && $images !== '') {
            $decoded = json_decode($images, true);
            $images  = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($images)) {
            return [];
        }

        // JSON object keys are strings; rebuild to satisfy the declared shape.
        $out = [];
        foreach ($images as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
