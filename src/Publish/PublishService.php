<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Publish;

use Grafida\Article\Draft;
use Grafida\Article\DraftRepository;
use Grafida\Field\FieldSupport;
use Grafida\Html\ContentSplitter;
use Grafida\Html\InlineMedia;
use Grafida\Joomla\ApiClient;
use Grafida\Media\MediaRepository;
use Grafida\Reference\ReferenceService;
use Grafida\Site\Site;
use Grafida\Site\SiteService;

/**
 * Publishes a local draft to its Joomla site.
 *
 * Pipeline:
 *   1. Block if the site requires unsupported custom field types.
 *   2. Upload offline images and swap their data: URIs for public URLs.
 *   3. Create any tags that do not yet exist and resolve all tags to IDs.
 *   4. Split the HTML into introtext / fulltext on the read-more marker.
 *   5. Map supported custom-field values into `com_fields`.
 *   6. POST a new article (or PATCH an existing one) and remember its remote ID.
 */
final class PublishService
{
    /** Sentinel an editor image carries until its offline blob is uploaded on publish. */
    private const MEDIA_REF_PREFIX = 'grafida-media://';

    /** The Joomla article `images` subfields, in the order the editor presents them. */
    private const IMAGE_KEYS = [
        'image_intro', 'image_intro_alt', 'image_intro_alt_empty', 'float_intro', 'image_intro_caption',
        'image_fulltext', 'image_fulltext_alt', 'image_fulltext_alt_empty', 'float_fulltext', 'image_fulltext_caption',
    ];

    public function __construct(
        private readonly SiteService $sites,
        private readonly ApiClient $api,
        private readonly ReferenceService $references,
        private readonly DraftRepository $drafts,
        private readonly MediaRepository $media,
        private readonly FieldSupport $fields = new FieldSupport(),
        private readonly ContentSplitter $splitter = new ContentSplitter(),
        private readonly InlineMedia $inlineMedia = new InlineMedia(),
    ) {}

    /**
     * @return array{remoteId: int, created: bool}
     *
     * @throws PublishBlockedException        When required unsupported fields exist.
     * @throws \Grafida\Joomla\ApiException   On any API failure.
     * @throws \RuntimeException              When the site is not connectable.
     */
    public function publish(Draft $draft, Site $site): array
    {
        $token = $this->sites->tokenFor($site);

        if ($token === null || $site->apiBase === null) {
            throw new \RuntimeException('The site is not connected; test the connection first.');
        }

        $base = $site->apiBase;

        $fieldDefs = $this->references->fields($site);
        $this->guardRequiredUnsupportedFields($fieldDefs, $draft->html);

        $html = $this->uploadOfflineMedia($draft, $site, $base, $token);

        // Persist the uploaded-image HTML back into the local draft: the data:
        // URIs have become real Media-Manager <img> tags, so the stored draft now
        // mirrors what is published (and a re-publish won't upload the images a
        // second time). Only a saved draft has a row to update.
        if ($draft->id !== null && $html !== $draft->html) {
            $draft->html = $html;
            $this->drafts->update($draft);
        }

        $tagIds = $this->resolveTags($draft->tags, $site, $base, $token);

        $split = $this->splitter->split($html);

        // Always-present attributes.
        //
        // The body is sent as the canonical `introtext` / `fulltext` columns rather
        // than the combined `articletext` field. On a PATCH, Joomla's API controller
        // backfills every real DB column we omit from the *existing* record, and
        // `Content::bind()` finishes with `parent::bind()` — which overwrites the
        // introtext/fulltext it derived from `articletext` with whatever is in the
        // array. Sending only `articletext` therefore restores the OLD body on every
        // update (a create has no backfill, so it appeared to work). Sending the two
        // columns directly keeps them present in the data, so they are never
        // backfilled and bind writes our new values for both POST and PATCH.
        $attributes = [
            'title'     => $draft->title,
            'catid'     => $draft->catid,
            'access'    => $draft->access,
            'state'     => $draft->state,
            'language'  => $draft->language,
            'introtext' => $split['introtext'],
            'fulltext'  => $split['fulltext'],
        ];

        // Optional attributes, included only when they carry a value.
        if ($draft->alias !== '') {
            $attributes['alias'] = $draft->alias;
        }
        if ($draft->metadesc !== '') {
            $attributes['metadesc'] = $draft->metadesc;
        }
        if ($draft->metakey !== '') {
            $attributes['metakey'] = $draft->metakey;
        }
        $images = $this->resolveImages($draft->images, $site, $base, $token);
        if ($images !== []) {
            $attributes['images'] = $images;
        }
        if ($tagIds !== []) {
            $attributes['tags'] = $tagIds;
        }
        $mappedFields = $this->mapFields($draft->fields, $fieldDefs);
        if ($mappedFields !== []) {
            $attributes['com_fields'] = $mappedFields;
        }

        if ($draft->remoteId === null) {
            $article = $this->api->createArticle($base, $token, $attributes);
            $created = true;
        } else {
            $article = $this->api->updateArticle($base, $token, $draft->remoteId, $attributes);
            $created = false;
        }

        $this->assertArticleSaved($article, $draft->title);

        $articleId = $article['id'] ?? null;
        $remoteId  = is_int($articleId) ? $articleId : (is_numeric($articleId) ? (int) $articleId : ($draft->remoteId ?? 0));

        if ($draft->id !== null && $remoteId > 0) {
            $this->drafts->setRemoteId($draft->id, $remoteId);
        }

        return ['remoteId' => $remoteId, 'created' => $created];
    }

    /**
     * Confirms the API actually saved what we submitted, rather than trusting the
     * HTTP status alone — but only fails on *positive* contradiction, never on a
     * mere absence of evidence, so a write that succeeded is never blocked.
     *
     *  - The response must carry a real article `id`. A write that returns no
     *    resource id never reached the article (e.g. a redirect dropped the body
     *    and we landed on a collection/error document).
     *  - If the response echoes a `title`, it must match the one we sent. Joomla
     *    stores the title verbatim, so a *different* title means the server
     *    returned some other (older) state instead of our write. A missing/omitted
     *    title is tolerated: write responses don't serialize the same field set on
     *    every Joomla version, and a body-only edit we cannot verify must not be
     *    reported as a failure.
     *
     * @param array<string, mixed> $article The flattened resource the API returned.
     *
     * @throws \Grafida\Joomla\ApiException
     */
    private function assertArticleSaved(array $article, string $sentTitle): void
    {
        $id    = $article['id'] ?? null;
        $hasId = (is_int($id) && $id > 0) || (is_string($id) && is_numeric($id) && (int) $id > 0);

        if (!$hasId) {
            throw new \Grafida\Joomla\ApiException(
                'The site reported success but returned no article id, so the change was not saved. '
                . 'The request was likely redirected and its body dropped (an http→https or '
                . 'trailing-slash rewrite), or a proxy served a read in place of the write.'
            );
        }

        $rawTitle = $article['title'] ?? null;

        if (is_string($rawTitle) && trim($rawTitle) !== trim($sentTitle)) {
            throw new \Grafida\Joomla\ApiException(sprintf(
                'The site reported success but returned a different article than the one submitted '
                . '(sent title "%s", server returned id %s with title "%s"). The change was not published.',
                $sentTitle,
                (string) $id,
                $rawTitle
            ));
        }
    }

    /**
     * @param list<array<string, mixed>> $fieldDefs
     */
    private function guardRequiredUnsupportedFields(array $fieldDefs, string $html): void
    {
        $blocking = $this->fields->blockingFields($fieldDefs);

        if ($blocking === []) {
            return;
        }

        $labels = array_map(
            static function (array $f): string {
                $label = $f['label'] ?? $f['name'] ?? 'field';

                return is_string($label) ? $label : 'field';
            },
            $blocking
        );

        throw new PublishBlockedException(array_values($labels), $html);
    }

    private function uploadOfflineMedia(Draft $draft, Site $site, string $base, string $token): string
    {
        return $this->inlineMedia->rewriteDataImages(
            $draft->html,
            fn (?int $mediaId, string $dataUri): array =>
                $this->uploadInlineImage($draft, $site, $base, $token, $mediaId, $dataUri),
        );
    }

    /**
     * Uploads a single inline editor image to the site's Media Manager and
     * returns the details needed to rebuild it as a Joomla media-field <img>.
     *
     * A tagged image resolves to its stored offline blob. An *untagged* data:
     * image — pasted or dropped straight into the editor, so it never passed
     * through the in-editor upload handler — is decoded and stored on the fly so
     * it is uploaded too, rather than leaking a raw data: URI into the published
     * article. A failure to upload aborts the publish with a clear error instead
     * of silently leaving a broken image.
     *
     * @return array{src: string, dataPath: ?string, width: ?int, height: ?int}
     *
     * @throws \Grafida\Joomla\ApiException When the image cannot be uploaded.
     */
    private function uploadInlineImage(Draft $draft, Site $site, string $base, string $token, ?int $mediaId, string $dataUri): array
    {
        if ($mediaId === null || $this->media->find($mediaId) === null) {
            $mediaId = $this->storeInlineDataUri($draft, $site, $dataUri);
        }

        $info = $mediaId !== null ? $this->uploadBlob($mediaId, $site, $base, $token) : null;

        if ($info === null || $info['src'] === '') {
            throw new \Grafida\Joomla\ApiException(
                'An image embedded in the article could not be uploaded to the site\'s Media Manager, '
                . 'so the article was not published. Check that the connected user is allowed to upload media.'
            );
        }

        return $info;
    }

    /**
     * Decodes a `data:` URI image and stores it as a new offline blob, returning
     * its id (or null when the URI cannot be parsed into image bytes).
     */
    private function storeInlineDataUri(Draft $draft, Site $site, string $dataUri): ?int
    {
        if (preg_match('#^data:([^;,]*)(;base64)?,(.*)$#s', $dataUri, $m) !== 1) {
            return null;
        }

        $mime = $m[1] !== '' ? $m[1] : 'image/png';
        $raw  = $m[2] !== '' ? base64_decode($m[3], true) : rawurldecode($m[3]);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $filename = 'inline-image.' . $this->extensionForMime($mime);

        return $this->media->store($site->id ?? 0, $draft->id, $filename, $mime, $raw);
    }

    private function extensionForMime(string $mime): string
    {
        return match (strtolower(trim($mime))) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif'               => 'gif',
            'image/webp'              => 'webp',
            'image/svg+xml'           => 'svg',
            'image/avif'              => 'avif',
            'image/bmp'               => 'bmp',
            default                   => 'png',
        };
    }

    /**
     * Uploads a single offline media blob to the site's Media Manager and returns
     * its details (or the cached details if it was already uploaded). Returns null
     * when the blob no longer exists.
     *
     * The upload path is **relative to the default Media adapter's root** — i.e.
     * `grafida/<file>`, NOT `images/grafida/<file>`. Joomla's default `local-images`
     * adapter is rooted at the site's `images/` directory, so prefixing the path
     * with `images/` writes the file to `images/images/grafida/...` while the
     * article still points at `images/grafida/...` — a guaranteed broken image.
     *
     * @return array{src: string, dataPath: ?string, width: ?int, height: ?int}|null
     */
    private function uploadBlob(int $mediaId, Site $site, string $base, string $token): ?array
    {
        $blob = $this->media->find($mediaId);

        if ($blob === null) {
            return null;
        }

        [$width, $height] = $this->imageSize($blob['data']);

        if ($blob['remote_url'] !== null && $blob['remote_url'] !== '') {
            return [
                'src'      => $blob['remote_url'],
                'dataPath' => $blob['remote_path'],
                'width'    => $width,
                'height'   => $height,
            ];
        }

        $path     = 'grafida/' . $this->safeName($blob['filename'], $mediaId);
        $resource = $this->api->uploadMedia($base, $token, $path, $blob['data']);
        $info     = $this->mediaInfo($resource, $site, $path, $width, $height);

        $this->media->markUploaded($mediaId, $info['dataPath'] ?? $path, $info['src']);

        return $info;
    }

    /**
     * Distils a Media Manager upload response into the values that rebuild the
     * Joomla media-field <img>: a site-relative `src`, the adapter `dataPath`
     * (e.g. "local-images:/grafida/x.jpg") and the image dimensions.
     *
     * @param array<string, mixed> $resource
     *
     * @return array{src: string, dataPath: ?string, width: ?int, height: ?int}
     */
    private function mediaInfo(array $resource, Site $site, string $fallbackRelPath, ?int $width, ?int $height): array
    {
        $adapterPath = is_string($resource['path'] ?? null) ? $resource['path'] : '';
        $rawUrl      = is_string($resource['url'] ?? null) ? $resource['url'] : '';

        // The API reports the intrinsic size for images; trust it over our guess.
        $width  = $this->intOrNull($resource['width'] ?? null) ?? $width;
        $height = $this->intOrNull($resource['height'] ?? null) ?? $height;

        // Public src, relative to the site root — matching what Joomla's own media
        // field inserts. Prefer the API-reported URL; otherwise derive it from the
        // adapter path ("local-images:/grafida/x.jpg" → "images/grafida/x.jpg",
        // the adapter name minus its "local-" prefix being the public sub-path).
        if ($rawUrl !== '') {
            $src = $this->relativeToSite($rawUrl, $site);
        } elseif (str_contains($adapterPath, ':')) {
            [$adapter, $rel] = explode(':', $adapterPath, 2);
            $filePath        = preg_replace('#^local-#', '', $adapter) ?? $adapter;
            $src             = trim($filePath, '/') . '/' . ltrim($rel, '/');
        } else {
            $src = ltrim($fallbackRelPath, '/');
        }

        return [
            'src'      => $src,
            'dataPath' => $adapterPath !== '' ? $adapterPath : null,
            'width'    => $width,
            'height'   => $height,
        ];
    }

    /** Strips the site root (or scheme+host) from an absolute media URL. */
    private function relativeToSite(string $url, Site $site): string
    {
        $base = rtrim($site->baseUrl, '/');

        if ($base !== '' && str_starts_with($url, $base . '/')) {
            return ltrim(substr($url, strlen($base)), '/');
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            $pathPart = parse_url($url, \PHP_URL_PATH);

            return is_string($pathPart) ? ltrim($pathPart, '/') : $url;
        }

        return ltrim($url, '/');
    }

    /**
     * Intrinsic pixel dimensions of raw image bytes, or [null, null] if undecodable.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function imageSize(string $data): array
    {
        $info = @getimagesizefromstring($data);

        if ($info === false) {
            return [null, null];
        }

        return [$info[0], $info[1]];
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Produces the canonical Joomla `images` object from the draft's stored
     * values: only the known subfields are kept, and the intro / full-text image
     * references (a `grafida-media://N` sentinel for an image picked offline) are
     * uploaded and swapped for their public URLs.
     *
     * @param array<string, mixed> $images
     *
     * @return array<string, string>
     */
    private function resolveImages(array $images, Site $site, string $base, string $token): array
    {
        $out = [];

        foreach (self::IMAGE_KEYS as $key) {
            if (!array_key_exists($key, $images)) {
                continue;
            }

            $value = $images[$key];
            $out[$key] = is_string($value) ? $value : '';
        }

        foreach (['image_intro', 'image_fulltext'] as $key) {
            $value = $out[$key] ?? '';

            if ($value === '' || !str_starts_with($value, self::MEDIA_REF_PREFIX)) {
                continue;
            }

            $mediaId = (int) substr($value, strlen(self::MEDIA_REF_PREFIX));
            $info    = $mediaId > 0 ? $this->uploadBlob($mediaId, $site, $base, $token) : null;

            // Drop the reference if its blob vanished, rather than publishing the sentinel.
            $out[$key] = $info['src'] ?? '';
        }

        return $out;
    }

    /**
     * Resolves draft tag titles to Joomla tag IDs, creating any that are new.
     *
     * @param list<string> $tagTitles
     *
     * @return list<int>
     */
    private function resolveTags(array $tagTitles, Site $site, string $base, string $token): array
    {
        if ($tagTitles === []) {
            return [];
        }

        $existing = [];
        foreach ($this->references->tags($site) as $tag) {
            if (isset($tag['title'], $tag['id']) && is_string($tag['title']) && (is_int($tag['id']) || is_string($tag['id']))) {
                $existing[mb_strtolower($tag['title'])] = (int) $tag['id'];
            }
        }

        $ids     = [];
        $created = false;

        foreach ($tagTitles as $title) {
            $title = trim($title);

            if ($title === '') {
                continue;
            }

            $key = mb_strtolower($title);

            if (isset($existing[$key])) {
                $ids[] = $existing[$key];

                continue;
            }

            $new     = $this->api->createTag($base, $token, $title);
            $newId   = $new['id'] ?? null;
            $newIdInt = is_numeric($newId) ? (int) $newId : 0;
            $ids[]          = $newIdInt;
            $existing[$key] = $newIdInt;
            $created        = true;
        }

        if ($created) {
            $this->references->tags($site, true); // refresh cache with the new tags
        }

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /**
     * @param array<string, mixed>       $values
     * @param list<array<string, mixed>> $fieldDefs
     *
     * @return array<string, mixed>
     */
    private function mapFields(array $values, array $fieldDefs): array
    {
        $supportedNames = [];
        foreach ($fieldDefs as $def) {
            $defName = $def['name'] ?? null;
            $defType = $def['type'] ?? null;
            $name    = is_string($defName) ? $defName : '';
            $type    = is_string($defType) ? $defType : '';
            if ($name !== '' && $this->fields->isSupported($type)) {
                $supportedNames[$name] = true;
            }
        }

        $out = [];
        foreach ($values as $name => $value) {
            if (isset($supportedNames[$name])) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    private function safeName(string $filename, int $mediaId): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? 'image';
        $name = trim($name, '-');

        if ($name === '' || !str_contains($name, '.')) {
            $name = $mediaId . '-' . ($name === '' ? 'image.png' : $name . '.png');
        } else {
            $name = $mediaId . '-' . $name;
        }

        return $name;
    }
}
