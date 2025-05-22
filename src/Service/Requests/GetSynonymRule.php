<?php

namespace SilverStripe\ForagerBifrost\Service\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\GetSynonymSet;

class GetSynonymRule extends GetSynonymSet
{

    public function __construct(string $synonymCollectionId, string $synonymRuleId)
    {
        parent::__construct($synonymCollectionId, $synonymRuleId);

        $this->path = sprintf('/api/v1/%s/synonyms/%s', $synonymCollectionId, $synonymRuleId);
    }

}
