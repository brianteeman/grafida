<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Site;

use Grafida\Http\HttpClient;
use Grafida\Http\Transport;

/**
 * Downloads and caches a site's favicon.
 *
 * The site's home page is parsed for `<link rel="icon">` (and Apple touch icon)
 * declarations; the largest one that downloads successfully is stored as raw
 * bytes for the SPA to render. The default `/favicon.ico` and
 * `/apple-touch-icon.png` locations are tried as a fallback. Everything is
 * best-effort with a short timeout: an unreachable site keeps whatever icon it
 * already had cached (if any) and never blocks connecting or updating a site.
 *
 * The discovery logic mirrors Panopticon's SiteInfo\Retriever, trimmed to the
 * single "best icon" use case.
 */
final class FaviconService
{
    /** Accepted image MIME types, keyed by file extension. */
    private const MIME_BY_EXT = [
        'ico'  => 'image/x-icon',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    public function __construct(
        private readonly FaviconRepository $repository,
        private readonly Transport $http = new HttpClient(5),
    ) {}

    /**
     * Refreshes a site's cached favicon from the network, best-effort. Any
     * failure leaves the previously cached icon (if any) untouched.
     */
    public function sync(Site $site): void
    {
        if ($site->id === null) {
            return;
        }

        foreach ($this->candidateUrls($site) as $url) {
            $icon = $this->download($url);

            if ($icon !== null) {
                $this->repository->put($site->id, $icon['mime'], $icon['data']);

                return;
            }
        }
    }

    /** Returns the cached favicon as a data: URI, or null when none is stored. */
    public function dataUri(int $siteId): ?string
    {
        return $this->repository->dataUri($siteId);
    }

    /**
     * Ordered list of icon URLs to try: the ones declared in the home page's
     * HTML (largest first), then the conventional default locations.
     *
     * @return list<string>
     */
    private function candidateUrls(Site $site): array
    {
        $root  = rtrim($site->baseUrl, '/');
        $icons = $this->iconsFromHtml($root);

        // Conventional fallbacks, appended after any declared icons.
        $icons[] = (object) ['url' => $root . '/apple-touch-icon.png', 'size' => -1];
        $icons[] = (object) ['url' => $root . '/favicon.ico', 'size' => -2];

        // Stable sort by reported size, descending.
        usort($icons, static fn (object $a, object $b): int => $b->size <=> $a->size);

        $urls = [];
        foreach ($icons as $icon) {
            $urls[$icon->url] = true;
        }

        return array_keys($urls);
    }

    /**
     * Parses the home page HTML and returns the declared icon definitions.
     *
     * @return list<object{url: string, size: int}>
     */
    private function iconsFromHtml(string $root): array
    {
        try {
            $response = $this->http->request('GET', $root . '/');
        } catch (\Throwable) {
            return [];
        }

        if (!$response->isSuccess() || trim($response->body) === '') {
            return [];
        }

        $dom         = new \DOMDocument();
        $errorLevel  = error_reporting(0);
        $dom->loadHTML($response->body);
        error_reporting($errorLevel);

        // A <base href> overrides the document base for relative URLs.
        $base = $root . '/';
        foreach ($dom->getElementsByTagName('base') as $baseTag) {
            $href = $baseTag->getAttribute('href');
            if ($href !== '') {
                $base = $this->resolveUrl($root, $href);
            }
        }

        $icons = [];
        foreach ($dom->getElementsByTagName('link') as $link) {
            $rel  = strtolower(trim($link->getAttribute('rel')));
            $href = $link->getAttribute('href');

            if ($href === '' || !in_array($rel, ['icon', 'shortcut icon', 'apple-touch-icon', 'apple-touch-icon-precomposed'], true)) {
                continue;
            }

            $icons[] = (object) [
                'url'  => $this->resolveUrl($base, $href),
                'size' => $this->parseSizes($link->getAttribute('sizes')),
            ];
        }

        return $icons;
    }

    /**
     * Resolves a (possibly relative) icon href against a base URL. The base is
     * expected to carry a scheme + host (the site root or a <base href>).
     */
    private function resolveUrl(string $base, string $href): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $href) === 1 || str_starts_with($href, 'data:')) {
            return $href;
        }

        $parts  = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $origin = $scheme . '://' . $host . $port;

        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }

        // Relative to the base "directory".
        $basePath = $parts['path'] ?? '/';
        $dir      = preg_replace('#/[^/]*$#', '/', $basePath);

        return $origin . ($dir === '' ? '/' : $dir) . $href;
    }

    /**
     * Returns the largest dimension declared in a `sizes` attribute (0 when
     * absent or "any").
     */
    private function parseSizes(string $sizes): int
    {
        $max    = 0;
        $tokens = preg_split('/\s+/', trim($sizes));

        foreach ($tokens === false ? [] : $tokens as $token) {
            if ($token === '' || stripos($token, 'x') === false) {
                continue;
            }

            [$w, $h] = array_pad(explode('x', strtolower($token), 2), 2, '0');

            if (is_numeric($w) && is_numeric($h)) {
                $max = max($max, (int) $w, (int) $h);
            }
        }

        return $max;
    }

    /**
     * Downloads an icon URL and returns its bytes + MIME, or null on failure or
     * a non-image response.
     *
     * @return array{mime: string, data: string}|null
     */
    private function download(string $url): ?array
    {
        if (str_starts_with($url, 'data:')) {
            return $this->decodeDataUrl($url);
        }

        try {
            $response = $this->http->request('GET', $url);
        } catch (\Throwable) {
            return null;
        }

        if (!$response->isSuccess() || $response->body === '') {
            return null;
        }

        $mime = $this->detectMime($response->header('content-type'), $url);

        if ($mime === null) {
            return null;
        }

        return ['mime' => $mime, 'data' => $response->body];
    }

    /**
     * Resolves the MIME type from the response Content-Type header, falling back
     * to the URL's file extension. Returns null when the result is not a
     * recognised image type.
     */
    private function detectMime(?string $contentType, string $url): ?string
    {
        if ($contentType !== null) {
            $type = strtolower(trim(explode(';', $contentType)[0]));

            if (str_starts_with($type, 'image/')) {
                return $type;
            }
        }

        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return self::MIME_BY_EXT[$ext] ?? null;
    }

    /**
     * @return array{mime: string, data: string}|null
     */
    private function decodeDataUrl(string $url): ?array
    {
        if (preg_match('#^data:([^;,]+)(;base64)?,(.*)$#s', $url, $m) !== 1) {
            return null;
        }

        $mime = strtolower($m[1]);

        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        $data = $m[2] === ';base64' ? base64_decode($m[3], true) : rawurldecode($m[3]);

        return $data === false || $data === '' ? null : ['mime' => $mime, 'data' => $data];
    }
}
