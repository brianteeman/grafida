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
use Grafida\Debug\RecordingTransport;
use Grafida\Debug\RequestLog;
use Grafida\Debug\RequestLogService;
use Grafida\Http\HttpClient;
use Grafida\Http\Transport;
use Grafida\Joomla\ApiClient;
use Grafida\Storage\SettingsRepository;
use Joomla\DI\ServiceProviderInterface;

/**
 * Registers the shared HTTP transports — one per distinct timeout the
 * application uses — and the `ApiClient` instances built on top of them.
 *
 * Today six `HttpClient`s get built per request cycle, several of them
 * invisibly through default constructor arguments in `FaviconService`,
 * `ReferenceService` and `EditorCssService`. This provider makes the five
 * distinct timeouts explicit, named, and shared.
 *
 * The three **site-facing** transports (`http.default`/`http.short`/
 * `http.reference`) are wrapped in {@see RecordingTransport}, feeding the
 * container-shared {@see RequestLog} — that ring buffer is what the Request
 * Log screen reads and what gh-37's Debug setting toggles. `http.ai` is
 * deliberately left unwrapped: AI traffic is not "requests to the site", may
 * be huge, and carries a different provider's key. `http.diagnostics` is also
 * unwrapped — `Site\ConnectionDiagnostics` records into its own private sink
 * per run, so wrapping this transport too would double-record every probe
 * into the shared log.
 */
final class HttpProvider implements ServiceProviderInterface
{
    public function register(\Joomla\DI\Container $container): void
    {
        $container->share(RequestLogService::class, static fn (Container $c): RequestLogService
            => new RequestLogService($c->get(SettingsRepository::class)));

        $container->share(RequestLog::class, static fn (Container $c): RequestLog
            => new RequestLog($c->get(RequestLogService::class)));

        $container->share('http.default', static fn (Container $c): RecordingTransport => self::recorded($c, new HttpClient()));
        $container->share('http.short', static fn (Container $c): RecordingTransport => self::recorded($c, new HttpClient(5)));
        $container->share('http.reference', static fn (Container $c): RecordingTransport => self::recorded($c, new HttpClient(8)));
        $container->share('http.ai', static fn (): HttpClient => new HttpClient(300));

        // Deliberately *not* wrapped in a RecordingTransport: ConnectionDiagnostics
        // builds its own recorder over this transport for each diagnose run, so a
        // diagnose never fills the shared Request Log with duplicates of itself.
        $container->share('http.diagnostics', static fn (): HttpClient => new HttpClient(15));

        $container->share(ApiClient::class, static function (Container $c): ApiClient {
            /** @var Transport $http */
            $http = $c->get('http.default');

            return new ApiClient($http);
        });

        // ReferenceService takes an ApiClient built on the 8s transport — a
        // *different* ApiClient instance from the shared one above.
        $container->share('api.client.reference', static function (Container $c): ApiClient {
            /** @var Transport $http */
            $http = $c->get('http.reference');

            return new ApiClient($http);
        });
    }

    /** Wraps a site-facing `HttpClient` so its exchanges feed the shared Request Log. */
    private static function recorded(Container $c, HttpClient $inner): RecordingTransport
    {
        /** @var RequestLog $sink */
        $sink = $c->get(RequestLog::class);

        return new RecordingTransport($inner, $sink);
    }
}
