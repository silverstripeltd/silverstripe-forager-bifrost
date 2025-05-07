<?php

namespace SilverStripe\ForagerBifrost\Service\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\CreateSynonymSet;
use SilverStripe\Forager\Service\Query\SynonymRule as SynonymRuleQuery;
use SilverStripe\ForagerBifrost\Processors\SynonymRuleProcessor;
use stdClass;

class CreateSynonymRule extends CreateSynonymSet
{

    public function __construct(string $synonymCollectionId, SynonymRuleQuery $synonymRule)
    {
        $body = new stdClass();
        $body->synonyms = SynonymRuleProcessor::singleton()->getStringFromQuery($synonymRule);

        $this->method = 'POST';
        $this->headers['Content-Type'] = 'application/json';
        $this->path = sprintf('/api/v1/%s/synonyms', $synonymCollectionId);
        $this->body = $body;
    }

}
