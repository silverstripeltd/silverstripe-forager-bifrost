<?php

namespace SilverStripe\SearchServiceBifrost\Service;

use Elastic\EnterpriseSearch\Client;
use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\SearchService\Exception\IndexConfigurationException;
use SilverStripe\SearchService\Interfaces\BatchDocumentRemovalInterface;
use SilverStripe\SearchService\Interfaces\DocumentInterface;
use SilverStripe\SearchService\Interfaces\IndexingInterface;
use SilverStripe\SearchService\Service\DocumentBuilder;
use SilverStripe\SearchService\Service\IndexConfiguration;
use SilverStripe\SearchService\Service\Traits\ConfigurationAware;

class BifrostService implements IndexingInterface, BatchDocumentRemovalInterface
{

    use Configurable;
    use ConfigurationAware;
    use Injectable;

    private const DEFAULT_FIELD_TYPE = 'text';

    private Client $client;

    private DocumentBuilder $builder;

    private ?LoggerInterface $logger = null;

    private static int $max_document_size = 102400;

    private static array $dependencies = [
        'client' => '%$' . Client::class . '.indexingInterface',
        'configuration' => '%$' . IndexConfiguration::class,
        'builder' => '%$' . DocumentBuilder::class,
        'logger' => '%$' . LoggerInterface::class . '.errorhandler',
    ];

    public function setClient(Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function setBuilder(DocumentBuilder $builder): static
    {
        $this->builder = $builder;

        return $this;
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function environmentizeIndex(string $indexName): string
    {
        $variant = IndexConfiguration::singleton()->getIndexVariant();

        if ($variant) {
            return sprintf('%s-%s', $variant, $indexName);
        }

        return $indexName;
    }

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
        return 'TBA';
    }

    public function addDocument(DocumentInterface $document): ?string
    {
        return 'document_id_1';
    }

    /**
     * @param DocumentInterface[] $documents
     */
    public function addDocuments(array $documents): array
    {
        return [
            'document_id_1',
            'document_id_2',
        ];
    }

    public function removeDocument(DocumentInterface $document): ?string
    {
        return 'document_id_1';
    }

    /**
     * @param DocumentInterface[] $documents
     */
    public function removeDocuments(array $documents): array
    {
        return [
            'document_id_1',
            'document_id_2',
        ];
    }

    /**
     * Forcefully remove all documents from the provided index name. Batches the requests to Elastic based upon the
     * configured batch size, beginning at page 1 and continuing until the index is empty.
     *
     * @param string $indexName The index name to remove all documents from
     * @return int The total number of documents removed
     */
    public function removeAllDocuments(string $indexName): int
    {
        return 0;
    }

    public function getMaxDocumentSize(): int
    {
        return $this->config()->get('max_document_size');
    }

    public function getDocument(string $id): ?DocumentInterface
    {
        return null;
    }

    /**
     * @return DocumentInterface[]
     */
    public function getDocuments(array $ids): array
    {
        return [];
    }

    /**
     * @return DocumentInterface[]
     * @throws Exception
     */
    public function listDocuments(string $indexName, ?int $pageSize = null, int $currentPage = 0): array
    {
        return [];
    }

    public function getDocumentTotal(string $indexName): int
    {
        return 0;
    }

    public function configure(): array
    {
        return [];
    }

    /**
     * @throws IndexConfigurationException
     */
    public function validateField(string $field): void
    {
        if ($field[0] === '_') {
            throw new IndexConfigurationException(sprintf(
                'Invalid field name: %s. Fields cannot begin with underscores.',
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
