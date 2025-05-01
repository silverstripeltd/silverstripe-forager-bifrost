<?php

namespace SilverStripe\ForagerBifrost\Adaptors\Requests;

use Elastic\EnterpriseSearch\Client;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forager\Interfaces\Requests\UpdateSynonymRuleAdaptor as PatchSynonymRuleAdaptorInterface;
use SilverStripe\Forager\Service\Query\SynonymRule as SynonymRuleQuery;
use SilverStripe\ForagerBifrost\Service\Requests\UpdateSynonymRule;

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

    public function process(
        int|string $synonymCollectionId,
        int|string $synonymRuleId,
        SynonymRuleQuery $synonymRule
    ): string|int {
        $request = Injector::inst()->create(
            UpdateSynonymRule::class,
            $synonymCollectionId,
            $synonymRuleId,
            $synonymRule
        );

        // Should either be successful or throw an exception, which we'll let fly
        $body = $this->client->appSearch()->createSynonymSet($request)->asString();
        $body = json_decode($body, true);

        return $body['id'];
    }

}
