<?php

namespace SilverStripe\ForagerBifrost\Service\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\GetSchema as AppSearchGetSchema;

class GetSchema extends AppSearchGetSchema
{

    public function __construct(string $engineName)
    {
        parent::__construct($engineName);

        $this->path = sprintf('/api/v1/%s/schema', $engineName);
    }

}
