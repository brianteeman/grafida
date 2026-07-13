<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application\Provider;

use Grafida\Ai\AiProxy;
use Grafida\Ai\AiRenderer;
use Grafida\Ai\AiServiceManager;
use Grafida\Ai\AiServiceRepository;
use Grafida\Ai\Defaults;
use Grafida\Application\Container;
use Grafida\Http\Transport;
use Grafida\Markdown\MarkdownService;
use Grafida\Secret\SecretStore;
use Joomla\DI\ServiceProviderInterface;

/**
 * Registers the AI assistant's non-transport services: prompt/tool defaults,
 * the AI-service manager, the proxy fallback, and the reply renderer.
 */
final class AiProvider implements ServiceProviderInterface
{
    public function register(\Joomla\DI\Container $container): void
    {
        $container->share(Defaults::class, static fn (): Defaults => new Defaults());

        $container->share(AiServiceManager::class, static function (Container $c): AiServiceManager {
            /** @var ?SecretStore $secureStore */
            $secureStore = $c->get('secret.store.resolved');

            return new AiServiceManager($c->get(AiServiceRepository::class), $secureStore);
        });

        $container->share(AiProxy::class, static function (Container $c): AiProxy {
            /** @var Transport $http */
            $http = $c->get('http.ai');

            return new AiProxy($c->get(AiServiceManager::class), $c->get(Defaults::class), $http);
        });

        $container->share(
            AiRenderer::class,
            static fn (Container $c): AiRenderer => new AiRenderer($c->get(MarkdownService::class))
        );
    }
}
