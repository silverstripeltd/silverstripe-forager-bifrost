<?php

namespace SilverStripe\ForagerBifrost\Service\Requests;

use Elastic\EnterpriseSearch\AppSearch\Request\CreateEngine as AppSearchCreateEngine;
use Elastic\EnterpriseSearch\AppSearch\Schema\Engine;

class PostEngine extends AppSearchCreateEngine
{

    public function __construct(?Engine $engine = null)
    {
        parent::__construct($engine);

        $this->path = '/api/v1/engines';
    }

}
