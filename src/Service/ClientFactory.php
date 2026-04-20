<?php

namespace SilverStripe\ForagerBifrost\Service;

use Exception;
use Http\Client\Common\Plugin\AddHostPlugin;
use Http\Client\Common\Plugin\AddPathPlugin;
use Http\Client\Common\Plugin\HeaderAppendPlugin;
use Http\Client\Common\PluginClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use SilverStripe\Core\Injector\Factory;
use Silverstripe\Search\Client\Client;

class ClientFactory implements Factory
{

    private const string ENDPOINT = 'BIFROST_ENDPOINT';
    private const string QUERY_API_KEY = 'BIFROST_QUERY_API_KEY';

    /**
     * @throws Exception
     */
    public function create(string $service, array $params = []): ?object
    {
        $host = $params['host'] ?? null;
        $token = $params['token'] ?? null;
        $httpClient = $params['httpClient'] ?? null;

        $missingEnvVars = [];

        if (!$host) {
            $missingEnvVars[] = self::ENDPOINT;
        }

        if (!$token) {
            $missingEnvVars[] = self::QUERY_API_KEY;
        }

        if ($missingEnvVars) {
            throw new Exception(sprintf('Required ENV vars missing: %s', implode(', ', $missingEnvVars)));
        }

        $plugins = [
            new AddHostPlugin(Psr17FactoryDiscovery::findUriFactory()->createUri($host)),
            new AddPathPlugin(Psr17FactoryDiscovery::findUriFactory()->createUri('/api/v1')),
            new HeaderAppendPlugin([
                'Authorization' => 'Bearer ' . $token,
            ]),
        ];

        // If no HTTP client was provided, discover one via PSR-18
        $httpClient ??= Psr18ClientDiscovery::find();

        return new Client(
            new PluginClient($httpClient, $plugins),
            Psr17FactoryDiscovery::findRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory(),
        );
    }

}
