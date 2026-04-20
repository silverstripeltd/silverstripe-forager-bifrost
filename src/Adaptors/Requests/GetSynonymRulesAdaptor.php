<?php

namespace SilverStripe\ForagerBifrost\Adaptors\Requests;

use SilverStripe\Forager\Interfaces\Requests\GetSynonymRulesAdaptor as GetSynonymRulesAdaptorInterface;
use SilverStripe\Forager\Service\IndexConfiguration;
use SilverStripe\Forager\Service\Results\SynonymRule;
use SilverStripe\Forager\Service\Results\SynonymRules;
use SilverStripe\ForagerBifrost\Processors\SynonymRuleProcessor;
use Silverstripe\Search\Client\Client;

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

    public function process(int|string $synonymCollectionId): SynonymRules
    {
        // Silverstripe Search simply uses the engine name as the Synonym Collection ID
        $engineName = IndexConfiguration::singleton()->environmentizeIndex($synonymCollectionId);

        // Should either be successful or throw an exception, which we'll let fly
        $response = $this->client->synonymRulesGet($engineName);
        $body = json_decode((string) $response->getBody());
        $synonymRules = SynonymRules::create();

        // Covers for either null being returned or an empty array
        if (!$body) {
            return $synonymRules;
        }

        foreach ($body as $result) {
            $synonymRule = SynonymRule::create($result->id);
            SynonymRuleProcessor::applyStringToResult($synonymRule, $result->synonyms);

            $synonymRules->add($synonymRule);
        }

        return $synonymRules;
    }

}
