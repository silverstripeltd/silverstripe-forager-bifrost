<?php

namespace SilverStripe\ForagerBifrost\Adaptors\Requests;

use SilverStripe\Forager\Interfaces\Requests\GetSynonymRulesAdaptor as GetSynonymRulesAdaptorInterface;
use SilverStripe\Forager\Service\IndexConfiguration;
use SilverStripe\Forager\Service\Results\SynonymRule;
use SilverStripe\Forager\Service\Results\SynonymRules;
use SilverStripe\ForagerBifrost\Processors\SynonymRuleProcessor;
use Silverstripe\Search\Client\Client;
use Silverstripe\Search\Client\Exception\SynonymRulesGetNotFoundException;
use Silverstripe\Search\Client\Exception\SynonymRulesGetUnprocessableEntityException;

class GetSynonymRulesAdaptor implements GetSynonymRulesAdaptorInterface
{

    private ?Client $client = null;

    private static array $dependencies = [
        'client' => '%$' . Client::class . '.managementClient',
    ];

    public function setClient(?Client $client): void
    {
        $this->client = $client;
    }

    /**
     * @throws SynonymRulesGetNotFoundException
     * @throws SynonymRulesGetUnprocessableEntityException
     */
    public function process(int|string $synonymCollectionId): SynonymRules
    {
        // Silverstripe Search simply uses the engine name as the Synonym Collection ID
        $engineName = IndexConfiguration::singleton()->environmentizeIndex($synonymCollectionId);

        // Should either be successful or throw an exception, which we'll let fly
        $response = $this->client->synonymRulesGet($engineName);
        $synonymRules = SynonymRules::create();

        // Covers for either null being returned or an empty array
        if (!$response) {
            return $synonymRules;
        }

        foreach ($response as $result) {
            $synonymRule = SynonymRule::create($result->getId());
            SynonymRuleProcessor::applyStringToResult($synonymRule, $result->getSynonyms());

            $synonymRules->add($synonymRule);
        }

        return $synonymRules;
    }

}
