<?php

namespace SilverStripe\ForagerBifrost\Adaptors\Requests;

use Elastic\EnterpriseSearch\Client;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forager\Interfaces\Requests\CreateSynonymRuleAdaptor as PostSynonymRuleAdaptorInterface;
use SilverStripe\Forager\Service\Query\SynonymRule as SynonymRuleQuery;
use SilverStripe\Forager\Service\Results\SynonymRule as SynonymRuleResult;
use SilverStripe\ForagerBifrost\Processors\SynonymRuleProcessor;
use SilverStripe\ForagerBifrost\Service\Requests\CreateSynonymRule;

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
        $request = Injector::inst()->create(CreateSynonymRule::class, $synonymCollectionId, $synonymRule);

        // Should either be successful or throw an exception, which we'll let fly
        $body = $this->client->appSearch()->createSynonymSet($request)->asString();
        $body = json_decode($body, true);

        $synonymRuleResult = SynonymRuleResult::create($body['id']);
        SynonymRuleProcessor::applyStringToResult($synonymRuleResult, $body['synonyms']);

        return $synonymRuleResult;
    }

}
