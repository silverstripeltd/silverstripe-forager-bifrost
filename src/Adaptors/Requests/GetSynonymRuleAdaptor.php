<?php

namespace SilverStripe\ForagerBifrost\Adaptors\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\GetSynonymSet;
use Elastic\EnterpriseSearch\Client;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forager\Interfaces\Requests\GetSynonymRuleAdaptor as GetSynonymRuleAdaptorInterface;
use SilverStripe\Forager\Service\Results\SynonymRule;
use SilverStripe\ForagerBifrost\Processors\SynonymRuleProcessor;

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
        $request = Injector::inst()->create(GetSynonymSet::class, $synonymCollectionId, $synonymRuleId);

        // Should either be successful or throw an exception, which we'll let fly
        $body = $this->client->appSearch()->getSynonymSet($request)->asString();
        $body = json_decode($body, true);

        $synonymRule = SynonymRule::create($body['id']);
        SynonymRuleProcessor::applyStringToResult($synonymRule, $body['synonyms']);

        return $synonymRule;
    }

}
