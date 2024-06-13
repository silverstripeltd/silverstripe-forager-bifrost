<?php

namespace SilverStripe\ForagerBifrost\Service\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\ListDocuments as AppSearchListDocuments;

class GetDocumentsList extends AppSearchListDocuments
{

    public function __construct(string $engineName)
    {
        parent::__construct($engineName);

        $this->method = 'POST';
        $this->path = sprintf('/api/v1/%s/documents/list/', $engineName);
    }

}
