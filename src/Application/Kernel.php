<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application;

use Boson\Component\Http\Response;
use Boson\Component\Http\Static\StaticProviderInterface;
use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Http\ApiController;

/**
 * Request dispatcher: routes incoming Boson scheme requests to the JSON API
 * controller, a static asset, or the single-page-app shell. Every dependency
 * is wired by the DI container's service providers (see
 * `Grafida\Application\Provider\*`).
 */
final class Kernel
{
    public function __construct(
        private readonly StaticProviderInterface $static,
        private readonly ApiController $api,
    ) {}

    public function handle(RequestInterface $request): ResponseInterface
    {
        $path = (string) $request->url->path;

        if (str_starts_with($path, '/api/')) {
            return $this->api->dispatch($request);
        }

        $static = $this->static->findFileByRequest($request);

        if ($static !== null) {
            return $static;
        }

        // Single-page-app shell for any other route.
        $shell = @file_get_contents(\dirname(__DIR__, 2) . '/assets/private/view/index.html');

        return new Response((string) $shell, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
