<?php

namespace SilverStripe\ForagerBifrost\Service\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\PutSchema as AppSearchPutSchema;
use Elastic\EnterpriseSearch\AppSearch\Schema\SchemaUpdateRequest;

/**
 * Note: Even though the App Search class is called "PutSchema", it actually uses Post
 */
class PostSchema extends AppSearchPutSchema
{

    public function __construct(string $engineName, ?SchemaUpdateRequest $schema = null)
    {
        parent::__construct($engineName, $schema);

        $this->path = sprintf('/api/v1/%s/schema/', $engineName);
    }

}
