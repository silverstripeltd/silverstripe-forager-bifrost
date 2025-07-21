<?php

namespace SilverStripe\ForagerBifrost\Service\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\ListSynonymSets;

class GetSynonymRules extends ListSynonymSets
{

    public function __construct(string $synonymCollectionId)
    {
        parent::__construct($synonymCollectionId);

        $this->path = sprintf('/api/v1/%s/synonyms', $synonymCollectionId);
    }

}
