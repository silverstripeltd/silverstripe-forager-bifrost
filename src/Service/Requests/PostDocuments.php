<?php

namespace SilverStripe\ForagerBifrost\Service\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\IndexDocuments as AppSearchIndexDocuments;

class PostDocuments extends AppSearchIndexDocuments
{

    public function __construct(string $engineName, ?array $documents = null)
    {
        parent::__construct($engineName, $documents);

        $this->path = sprintf('/api/v1/%s/documents', $engineName);
    }

}
