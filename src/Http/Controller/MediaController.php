<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Controller;

use Boson\Contracts\Http\ResponseInterface;
use Grafida\Http\Json;
use Grafida\Http\RouteContext;
use Grafida\Http\Router;
use Grafida\Http\SiteContext;
use Grafida\Joomla\ApiClient;
use Grafida\Media\MediaRepository;
use Grafida\Media\SiteImageException;
use Grafida\Media\SiteImageFetcher;

/**
 * Handles `/api/sites/{id}/media*` (the online Media Manager screen) and
 * `GET /api/media/{id}` (an offline draft image blob).
 */
final class MediaController extends Controller
{
    public function __construct(
        private readonly SiteContext $siteContext,
        private readonly ApiClient $apiClient,
        private readonly MediaRepository $media,
        private readonly SiteImageFetcher $siteImages,
    ) {}

    public function registerRoutes(Router $router): void
    {
        $router->add('GET', '/api/sites/{id}/media', fn (RouteContext $ctx): ResponseInterface => $this->browseMedia($ctx->int('id'), $ctx->request()->url->query->get('path', '') ?? ''));
        $router->add('POST', '/api/sites/{id}/media', fn (RouteContext $ctx): ResponseInterface => $this->uploadOfflineMedia($ctx->int('id'), $ctx->body()));
        $router->add('DELETE', '/api/sites/{id}/media', fn (RouteContext $ctx): ResponseInterface => $this->deleteSiteMedia($ctx->int('id'), $ctx->request()->url->query->get('path', '') ?? ''));
        $router->add('GET', '/api/sites/{id}/media/adapters', fn (RouteContext $ctx): ResponseInterface => $this->mediaAdapters($ctx->int('id')));
        $router->add('GET', '/api/sites/{id}/media/file', fn (RouteContext $ctx): ResponseInterface => $this->siteMediaFile($ctx->int('id'), $ctx->request()->url->query->get('path', '') ?? ''));
        $router->add('POST', '/api/sites/{id}/media/files', fn (RouteContext $ctx): ResponseInterface => $this->uploadSiteMedia($ctx->int('id'), $ctx->body()));
        $router->add('POST', '/api/sites/{id}/media/folder', fn (RouteContext $ctx): ResponseInterface => $this->createSiteMediaFolder($ctx->int('id'), $ctx->body()));
        $router->add('POST', '/api/sites/{id}/media/rename', fn (RouteContext $ctx): ResponseInterface => $this->renameSiteMedia($ctx->int('id'), $ctx->body()));
        $router->add('POST', '/api/sites/{id}/media/content', fn (RouteContext $ctx): ResponseInterface => $this->updateSiteMediaContent($ctx->int('id'), $ctx->body()));
        $router->add('GET', '/api/media/{id}', fn (RouteContext $ctx): ResponseInterface => $this->mediaBlob($ctx->int('id')));
        $router->add('GET', '/api/sites/{id}/image', fn (RouteContext $ctx): ResponseInterface => $this->siteImage($ctx->int('id'), $ctx->request()->url->query->get('url', '') ?? ''));
    }

    /**
     * Fetches an article image published on the site and returns it as a data: URI.
     *
     * Used when handing an article to a multimodal model: an already-published
     * article references its images by URL, and the webview cannot fetch those
     * itself (CORS / macOS ATS), so the bytes come through PHP.
     *
     * Unlike the rest of this controller this needs no API token — the image is
     * public — so it only requires the site to exist, not to be connected.
     */
    public function siteImage(int $siteId, string $url): ResponseInterface
    {
        $site = $this->siteContext->requireSite($siteId);

        try {
            return Json::ok($this->siteImages->fetch($site, $url));
        } catch (SiteImageException $e) {
            return Json::error($e->getMessage(), $e->httpStatus);
        }
    }

    /**
     * Lists a folder of the site's Media Manager so the editor can pick an
     * existing image for the intro / full-text article image.
     */
    public function browseMedia(int $siteId, string $path): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        [, $token, $base] = $conn;
        $entries = $this->apiClient->listMedia($base, $token, $path);

        return Json::ok(['path' => $path, 'entries' => $entries]);
    }

    /** Lists the site's Media Manager adapters (filesystems) for the Media Manager screen. */
    public function mediaAdapters(int $siteId): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        [, $token, $base] = $conn;

        return Json::ok(['adapters' => $this->apiClient->listMediaAdapters($base, $token)]);
    }

    /** Returns a media file's bytes as a data: URI so the SPA can load it for editing. */
    public function siteMediaFile(int $siteId, string $path): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }
        if ($path === '') {
            return Json::error('A media path is required.', 400);
        }

        [, $token, $base] = $conn;
        $file    = $this->apiClient->getMediaFile($base, $token, $path);
        $content = $this->str($file, 'content');
        $mime    = $this->str($file, 'mime_type', 'application/octet-stream');

        if ($content === '') {
            return Json::error('The media file has no readable content.', 404);
        }

        return Json::ok([
            'dataUri' => 'data:' . $mime . ';base64,' . $content,
            'mime'    => $mime,
            'name'    => $this->str($file, 'name'),
        ]);
    }

    /**
     * Uploads a file directly to the site's Media Manager (the online Media Manager
     * screen, distinct from uploadOfflineMedia which stores an offline draft blob).
     *
     * @param array<string, mixed> $body
     */
    public function uploadSiteMedia(int $siteId, array $body): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        $path = $this->str($body, 'path');
        $raw  = base64_decode($this->str($body, 'dataBase64'), true);

        if ($path === '') {
            return Json::error('A destination path is required.', 400);
        }
        if ($raw === false) {
            return Json::error('Invalid file data', 400);
        }

        $overrideVal = $body['override'] ?? false;
        [, $token, $base] = $conn;
        $resource = $this->apiClient->uploadMedia($base, $token, $path, $raw, (bool) $overrideVal);

        return Json::ok($resource, 201);
    }

    /** @param array<string, mixed> $body */
    public function createSiteMediaFolder(int $siteId, array $body): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        $path = $this->str($body, 'path');

        if ($path === '') {
            return Json::error('A folder path is required.', 400);
        }

        [, $token, $base] = $conn;
        $this->apiClient->createMediaFolder($base, $token, $path);

        return Json::ok(null, 201);
    }

    /**
     * Renames a Media Manager file/folder within its current directory. The new
     * path is derived from the old path so the item stays in the same folder.
     *
     * @param array<string, mixed> $body
     */
    public function renameSiteMedia(int $siteId, array $body): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        $oldPath = $this->str($body, 'oldPath');
        $newName = trim($this->str($body, 'newName'));

        if ($oldPath === '' || $newName === '') {
            return Json::error('The current path and a new name are required.', 400);
        }
        if (str_contains($newName, '/') || str_contains($newName, ':')) {
            return Json::error('A name cannot contain "/" or ":".', 400);
        }

        $idx     = strrpos($oldPath, '/');
        $dir     = $idx === false ? '' : substr($oldPath, 0, $idx);
        $newPath = $dir . '/' . $newName;

        [, $token, $base] = $conn;
        $this->apiClient->renameMedia($base, $token, $oldPath, $newPath);

        return Json::ok(['path' => $newPath]);
    }

    /** @param array<string, mixed> $body */
    public function updateSiteMediaContent(int $siteId, array $body): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }

        $path = $this->str($body, 'path');
        $raw  = base64_decode($this->str($body, 'dataBase64'), true);

        if ($path === '') {
            return Json::error('A media path is required.', 400);
        }
        if ($raw === false) {
            return Json::error('Invalid image data', 400);
        }

        [, $token, $base] = $conn;
        $this->apiClient->updateMediaContent($base, $token, $path, $raw);

        return Json::ok(['path' => $path]);
    }

    /** Deletes a file/folder from the site's Media Manager. */
    public function deleteSiteMedia(int $siteId, string $path): ResponseInterface
    {
        $conn = $this->siteContext->connectedSite($siteId);

        if ($conn === null) {
            return Json::error('The site is not connected.', 409);
        }
        if ($path === '') {
            return Json::error('A media path is required.', 400);
        }

        [, $token, $base] = $conn;
        $this->apiClient->deleteMedia($base, $token, $path);

        return Json::ok();
    }

    /** Returns the data: URI of a stored offline image blob (for editor previews). */
    public function mediaBlob(int $id): ResponseInterface
    {
        $dataUri = $this->media->dataUri($id);

        if ($dataUri === null) {
            return Json::error('Media not found', 404);
        }

        return Json::ok(['id' => $id, 'dataUri' => $dataUri]);
    }

    /** @param array<string, mixed> $body */
    public function uploadOfflineMedia(int $siteId, array $body): ResponseInterface
    {
        $raw = base64_decode($this->str($body, 'dataBase64'), true);

        if ($raw === false) {
            return Json::error('Invalid image data', 400);
        }

        $draftIdVal = $body['draftId'] ?? null;
        $mediaId    = $this->media->store(
            $siteId,
            is_numeric($draftIdVal) ? (int) $draftIdVal : null,
            $this->str($body, 'filename', 'image.png'),
            $this->str($body, 'mime', 'image/png'),
            $raw,
        );

        return Json::ok([
            'id'      => $mediaId,
            'dataUri' => $this->media->dataUri($mediaId),
        ], 201);
    }
}
