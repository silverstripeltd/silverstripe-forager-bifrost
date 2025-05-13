<?php

namespace SilverStripe\ForagerBifrost\Service\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\CreateSynonymSet;
use SilverStripe\Forager\Service\Query\SynonymRule as SynonymRuleQuery;
use SilverStripe\ForagerBifrost\Processors\SynonymRuleProcessor;
use stdClass;

/**
 * Even though the Elastic Enterprise Search SDK request is called PUT, it's actually a PATCH, as it requires the
 * record to already exist
 */
class UpdateSynonymRule extends CreateSynonymSet
{

    public function __construct(string $synonymCollectionId, string $synonymRuleId, SynonymRuleQuery $synonymRule)
    {
        $body = new stdClass();
        $body->synonyms = SynonymRuleProcessor::singleton()->getStringFromQuery($synonymRule);

        $this->method = 'PUT';
        $this->headers['Content-Type'] = 'application/json';
        $this->path = sprintf('/api/v1/%s/synonyms/%s', $synonymCollectionId, $synonymRuleId);
        $this->body = $body;
    }

}
