<?php

namespace SilverStripe\ForagerBifrost\Service\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\DeleteDocuments as AppSearchDeleteDocuments;

class DeleteDocuments extends AppSearchDeleteDocuments
{

    public function __construct(string $engineName, ?array $documentIds = null)
    {
        parent::__construct($engineName, $documentIds);

        $this->path = sprintf('/api/v1/%s/documents/', $engineName);
    }

}
