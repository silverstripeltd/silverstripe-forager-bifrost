<?php

namespace SilverStripe\ForagerBifrost\Service\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\DeleteSynonymSet;

class DeleteSynonymRule extends DeleteSynonymSet
{

    public function __construct(string $synonymCollectionId, string $synonymRuleId)
    {
        parent::__construct($synonymCollectionId, $synonymRuleId);

        $this->path = sprintf('/api/v1/%s/synonyms/%s', $synonymCollectionId, $synonymRuleId);
    }

}
