<?php

namespace SilverStripe\ForagerBifrost\Adaptors\Requests;

use SilverStripe\Forager\Interfaces\Requests\UpdateSynonymRuleAdaptor as PatchSynonymRuleAdaptorInterface;
use SilverStripe\Forager\Service\IndexConfiguration;
use SilverStripe\Forager\Service\Query\SynonymRule as SynonymRuleQuery;
use SilverStripe\Forager\Service\Results\SynonymRule as SynonymRuleResult;
use SilverStripe\ForagerBifrost\Processors\SynonymRuleProcessor;
use Silverstripe\Search\Client\Client;
use Silverstripe\Search\Client\Exception\SynonymRulePutNotFoundException;
use Silverstripe\Search\Client\Exception\SynonymRulePutUnprocessableEntityException;
use Silverstripe\Search\Client\Model\SynonymRuleRequest;

class UpdateSynonymRuleAdaptor implements PatchSynonymRuleAdaptorInterface
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
     * @throws SynonymRulePutNotFoundException
     * @throws SynonymRulePutUnprocessableEntityException
     */
    public function process(
        int|string $synonymCollectionId,
        int|string $synonymRuleId,
        SynonymRuleQuery $synonymRule
    ): SynonymRuleResult {
        // Silverstripe Search simply uses the engine name as the Synonym Collection ID
        $engineName = IndexConfiguration::singleton()->environmentizeIndex($synonymCollectionId);
        // Convert the query into a Silverstripe Search synonym rule string
        $synonyms = SynonymRuleProcessor::getStringFromQuery($synonymRule);
        $request = new SynonymRuleRequest();
        $request->setSynonyms($synonyms);

        // Should either be successful or throw an exception, which we'll let fly
        $response = $this->client->synonymRulePut($synonymRuleId, $engineName, $request);

        $synonymRuleResult = SynonymRuleResult::create($response->getId());
        SynonymRuleProcessor::applyStringToResult($synonymRuleResult, $response->getSynonyms());

        return $synonymRuleResult;
    }

}
