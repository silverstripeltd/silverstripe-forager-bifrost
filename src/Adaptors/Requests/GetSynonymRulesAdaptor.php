<?php

namespace SilverStripe\ForagerBifrost\Adaptors\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\ListSynonymSets;
use Elastic\EnterpriseSearch\Client;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forager\Interfaces\Requests\GetSynonymRulesAdaptor as GetSynonymRulesAdaptorInterface;
use SilverStripe\Forager\Service\Results\SynonymRule;
use SilverStripe\Forager\Service\Results\SynonymRules;
use SilverStripe\ForagerBifrost\Processors\SynonymRuleProcessor;

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
        $request = Injector::inst()->create(ListSynonymSets::class, $synonymCollectionId);

        // Should either be successful or throw an exception, which we'll let fly
        $body = $this->client->appSearch()->listSynonymSets($request)->asString();
        $body = json_decode($body, true);

        $synonymRules = SynonymRules::create();

        foreach ($body as $result) {
            $synonymRule = SynonymRule::create($result['id']);
            SynonymRuleProcessor::applyStringToResult($synonymRule, $result['synonyms']);

            $synonymRules->add($synonymRule);
        }

        return $synonymRules;
    }

}
