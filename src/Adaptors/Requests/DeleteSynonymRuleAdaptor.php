<?php

namespace SilverStripe\ForagerBifrost\Adaptors\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\DeleteSynonymSet;
use Elastic\EnterpriseSearch\Client;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forager\Interfaces\Requests\DeleteSynonymRuleAdaptor as DeleteSynonymRuleAdaptorInterface;

class DeleteSynonymRuleAdaptor implements DeleteSynonymRuleAdaptorInterface
{

    private ?Client $client = null;

    private static array $dependencies = [
        'client' => '%$' . Client::class . '.managementClient',
    ];

    public function setClient(?Client $client): void
    {
        $this->client = $client;
    }

    public function process(int|string $synonymCollectionId, int|string $synonymRuleId): bool
    {
        $request = Injector::inst()->create(DeleteSynonymSet::class, $synonymCollectionId, $synonymRuleId);

        // Should either be successful or throw an exception, which we'll let fly
        $body = $this->client->appSearch()->deleteSynonymSet($request)->asString();
        $body = json_decode($body, true);

        return $body['success'];
    }

}
