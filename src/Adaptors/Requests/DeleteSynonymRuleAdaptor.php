<?php

namespace SilverStripe\ForagerBifrost\Adaptors\Requests;

use SilverStripe\Forager\Interfaces\Requests\DeleteSynonymRuleAdaptor as DeleteSynonymRuleAdaptorInterface;
use SilverStripe\Forager\Service\IndexConfiguration;
use Silverstripe\Search\Client\Client;
use Silverstripe\Search\Client\Exception\SynonymRuleDeleteNotFoundException;
use Silverstripe\Search\Client\Exception\SynonymRuleDeleteUnprocessableEntityException;

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

    /**
     * @throws SynonymRuleDeleteNotFoundException
     * @throws SynonymRuleDeleteUnprocessableEntityException
     */
    public function process(int|string $synonymCollectionId, int|string $synonymRuleId): bool
    {
        // Silverstripe Search simply uses the engine name as the Synonym Collection ID
        $engineName = IndexConfiguration::singleton()->environmentizeIndex($synonymCollectionId);

        // Should either be successful or throw an exception, which we'll let fly
        $response = $this->client->synonymRuleDelete($synonymRuleId, $engineName);

        return $response->getSuccess();
    }

}
