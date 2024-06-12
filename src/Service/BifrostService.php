<?php

namespace SilverStripe\ForagerBifrost\Service;

use SilverStripe\Core\Environment;
use SilverStripe\SearchService\Exception\IndexConfigurationException;
use SilverStripe\SearchServiceElastic\Service\EnterpriseSearchService;

class BifrostService extends EnterpriseSearchService
{

    public function getExternalURL(): ?string
    {
        return Environment::getEnv('BIFROST_ENDPOINT') ?: null;
    }

    public function getExternalURLDescription(): ?string
    {
        return 'Silverstripe Search Dashboard';
    }

    public function getDocumentationURL(): ?string
    {
        return sprintf('%s/docs', $this->getExternalURL());
    }

    /**
     * @throws IndexConfigurationException
     */
    public function validateField(string $field): void
    {
        if ($field[0] === '_' && $field !== '_attachment') {
            throw new IndexConfigurationException(sprintf(
                'Invalid field name: %s. "_attachment" is the only field that can begin with an underscore.',
                $field
            ));
        }

        if (preg_match('/[^a-z0-9_]/', $field)) {
            throw new IndexConfigurationException(sprintf(
                'Invalid field name: %s. Must contain only lowercase alphanumeric characters and underscores.',
                $field
            ));
        }
    }

}
