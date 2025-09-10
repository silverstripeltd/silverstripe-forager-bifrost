<?php

namespace SilverStripe\ForagerBifrost\Adaptors\Requests;

use SilverStripe\Forager\Interfaces\Requests\GetSynonymRuleAdaptor as GetSynonymRuleAdaptorInterface;
use SilverStripe\Forager\Service\IndexConfiguration;
use SilverStripe\Forager\Service\Results\SynonymRule;
use SilverStripe\ForagerBifrost\Processors\SynonymRuleProcessor;
use Silverstripe\Search\Client\Client;

class GetSynonymRuleAdaptor implements GetSynonymRuleAdaptorInterface
{

    private ?Client $client = null;

    private static array $dependencies = [
        'client' => '%$' . Client::class . '.managementClient',
    ];

    public function setClient(?Client $client): void
    {
        $this->client = $client;
    }

    public function process(int|string $synonymCollectionId, int|string $synonymRuleId): SynonymRule
    {
        // Silverstripe Search simply uses the engine name as the Synonym Collection ID
        $engineName = IndexConfiguration::singleton()->environmentizeIndex($synonymCollectionId);

        // Should either be successful or throw an exception, which we'll let fly
        $response = $this->client->synonymRuleGet($synonymRuleId, $engineName);

        $synonymRule = SynonymRule::create($response->getId());
        SynonymRuleProcessor::applyStringToResult($synonymRule, $response->getSynonyms());

        return $synonymRule;
    }

}
