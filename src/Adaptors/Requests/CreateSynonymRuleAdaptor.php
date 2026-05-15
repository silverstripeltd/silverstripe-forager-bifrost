<?php

namespace SilverStripe\ForagerBifrost\Adaptors\Requests;

use SilverStripe\Forager\Interfaces\Requests\CreateSynonymRuleAdaptor as PostSynonymRuleAdaptorInterface;
use SilverStripe\Forager\Service\IndexConfiguration;
use SilverStripe\Forager\Service\Query\SynonymRule as SynonymRuleQuery;
use SilverStripe\Forager\Service\Results\SynonymRule as SynonymRuleResult;
use SilverStripe\ForagerBifrost\Processors\SynonymRuleProcessor;
use Silverstripe\Search\Client\Client;
use Silverstripe\Search\Client\Request\Engine\SynonymRuleRequest;

class CreateSynonymRuleAdaptor implements PostSynonymRuleAdaptorInterface
{

    private ?Client $client = null;

    private static array $dependencies = [
        'client' => '%$' . Client::class . '.managementClient',
    ];

    public function setClient(?Client $client): void
    {
        $this->client = $client;
    }

    public function process(int|string $synonymCollectionId, SynonymRuleQuery $synonymRule): SynonymRuleResult
    {
        // Silverstripe Search simply uses the engine name as the Synonym Collection ID
        $engineName = IndexConfiguration::singleton()->environmentizeIndex($synonymCollectionId);
        // Convert the query into a Silverstripe Search synonym rule string
        $synonyms = SynonymRuleProcessor::getStringFromQuery($synonymRule);
        $request = new SynonymRuleRequest($synonyms);

        // Should either be successful or throw an exception, which we'll let fly
        $response = $this->client->synonymRulePost($engineName, $request);
        $body = json_decode((string) $response->getBody());

        $synonymRuleResult = SynonymRuleResult::create($body->id);
        SynonymRuleProcessor::applyStringToResult($synonymRuleResult, $body->synonyms);

        return $synonymRuleResult;
    }

}
