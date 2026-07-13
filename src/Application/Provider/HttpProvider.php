<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application\Provider;

use Grafida\Application\Container;
use Grafida\Http\HttpClient;
use Grafida\Joomla\ApiClient;
use Joomla\DI\ServiceProviderInterface;

/**
 * Registers the shared HTTP transports — one per distinct timeout the
 * application uses — and the `ApiClient` instances built on top of them.
 *
 * Today six `HttpClient`s get built per request cycle, several of them
 * invisibly through default constructor arguments in `FaviconService`,
 * `ReferenceService` and `EditorCssService`. This provider makes the four
 * distinct timeouts explicit, named, and shared.
 */
final class HttpProvider implements ServiceProviderInterface
{
    public function register(\Joomla\DI\Container $container): void
    {
        $container->share('http.default', static fn (): HttpClient => new HttpClient());
        $container->share('http.short', static fn (): HttpClient => new HttpClient(5));
        $container->share('http.reference', static fn (): HttpClient => new HttpClient(8));
        $container->share('http.ai', static fn (): HttpClient => new HttpClient(300));

        $container->share(ApiClient::class, static function (Container $c): ApiClient {
            /** @var HttpClient $http */
            $http = $c->get('http.default');

            return new ApiClient($http);
        });

        // ReferenceService takes an ApiClient built on the 8s transport — a
        // *different* ApiClient instance from the shared one above.
        $container->share('api.client.reference', static function (Container $c): ApiClient {
            /** @var HttpClient $http */
            $http = $c->get('http.reference');

            return new ApiClient($http);
        });
    }
}
