<?php

namespace SilverStripe\ForagerBifrost\Service\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\ListEngines as AppSearchListEngines;

class PostEngines extends AppSearchListEngines
{

    public function __construct()
    {
        parent::__construct();

        $this->method = 'POST';
        $this->path = '/api/v1/engines';
    }

}
