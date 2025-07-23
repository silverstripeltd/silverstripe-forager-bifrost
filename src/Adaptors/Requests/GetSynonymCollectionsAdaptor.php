<?php

namespace SilverStripe\ForagerBifrost\Adaptors\Requests;

use SilverStripe\Forager\Interfaces\Requests\GetSynonymCollectionsAdaptor as GetSynonymSetsAdaptorInterface;
use SilverStripe\Forager\Service\IndexConfiguration;
use SilverStripe\Forager\Service\Results\SynonymCollection;
use SilverStripe\Forager\Service\Results\SynonymCollections;

class GetSynonymCollectionsAdaptor implements GetSynonymSetsAdaptorInterface
{

    private ?IndexConfiguration $configuration = null;

    private static array $dependencies = [
        'configuration' => '%$' . IndexConfiguration::class,
    ];

    public function setConfiguration(IndexConfiguration $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function process(): SynonymCollections
    {
        $synonymCollections = SynonymCollections::create();

        foreach (array_keys($this->configuration->getIndexes()) as $engineSuffix) {
            $synonymCollections->add(SynonymCollection::create($engineSuffix));
        }

        return $synonymCollections;
    }

}
