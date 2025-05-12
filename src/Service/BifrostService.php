<?php

namespace SilverStripe\ForagerBifrost\Service;

use Elastic\EnterpriseSearch\AppSearch\Request\GetDocuments;
use Elastic\EnterpriseSearch\AppSearch\Schema\Engine;
use Elastic\EnterpriseSearch\AppSearch\Schema\SchemaUpdateRequest;
use Elastic\EnterpriseSearch\Client;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forager\Exception\IndexConfigurationException;
use SilverStripe\Forager\Exception\IndexingServiceException;
use SilverStripe\Forager\Interfaces\BatchDocumentRemovalInterface;
use SilverStripe\Forager\Interfaces\DocumentInterface;
use SilverStripe\Forager\Interfaces\IndexingInterface;
use SilverStripe\Forager\Schema\Field;
use SilverStripe\Forager\Service\DocumentBuilder;
use SilverStripe\Forager\Service\IndexConfiguration;
use SilverStripe\Forager\Service\Traits\ConfigurationAware;
use SilverStripe\ForagerBifrost\Service\Requests\DeleteDocuments;
use SilverStripe\ForagerBifrost\Service\Requests\PostDocuments;
use SilverStripe\ForagerBifrost\Service\Requests\PostDocumentsList;
use SilverStripe\ForagerBifrost\Service\Requests\PostEngine;
use SilverStripe\ForagerBifrost\Service\Requests\PostEngines;
use SilverStripe\ForagerBifrost\Service\Requests\PostSchema;

class BifrostService implements IndexingInterface, BatchDocumentRemovalInterface
{

    use Configurable;
    use ConfigurationAware;
    use Injectable;

    public function getExternalURL(): ?string
    {
        return null;
    }

    public function getExternalURLDescription(): ?string
    {
        return null;
    }

    public function getDocumentationURL(): ?string
    {
        return sprintf('%s/api/v1/docs', $this->getExternalURL());
    }

    private const DEFAULT_FIELD_TYPE = 'text';

    private Client $client;

    private DocumentBuilder $builder;

    private static int $max_document_size = 26214400;

    private static string $default_field_type = self::DEFAULT_FIELD_TYPE;

    private static array $valid_field_types = [
        'text' => self::DEFAULT_FIELD_TYPE,
        'date' => 'date',
        'number' => 'number',
        'geolocation' => 'geolocation',
        'binary' => 'binary',
    ];

    public function __construct(Client $client, IndexConfiguration $configuration, DocumentBuilder $exporter)
    {
        $this->setClient($client);
        $this->setConfiguration($configuration);
        $this->setBuilder($exporter);
    }

    public function environmentizeIndex(string $indexName): string
    {
        $variant = IndexConfiguration::singleton()->getIndexVariant();

        if ($variant) {
            return sprintf('%s-%s', $variant, $indexName);
        }

        return $indexName;
    }

    /**
     * @throws IndexingServiceException
     * @throws NotFoundExceptionInterface
     */
    public function addDocument(DocumentInterface $document): ?string
    {
        $processedIds = $this->addDocuments([$document]);

        return array_shift($processedIds);
    }

    /**
     * @param DocumentInterface[] $documents
     * @throws IndexingServiceException
     * @throws NotFoundExceptionInterface
     */
    public function addDocuments(array $documents): array
    {
        $documentMap = $this->getContentMapForDocuments($documents);
        $processedIds = [];

        foreach ($documentMap as $indexName => $docsToAdd) {
            $request = new PostDocuments($this->environmentizeIndex($indexName), $docsToAdd);
            $response = $this->getClient()->appSearch()
                ->indexDocuments($request)
                ->asArray();

            $this->handleError($response);

            // Grab all the ID values, and also cast them to string
            $processedIds += array_map('strval', array_column($response, 'id'));
        }

        // One document could have existed in multiple indexes, we only care to track it once
        return array_unique($processedIds);
    }

    /**
     * @param DocumentInterface $document
     * @throws Exception
     */
    public function removeDocument(DocumentInterface $document): ?string
    {
        $processedIds = $this->removeDocuments([$document]);

        return array_shift($processedIds);
    }

    /**
     * @param DocumentInterface[] $documents
     * @throws Exception
     */
    public function removeDocuments(array $documents): array
    {
        $documentMap = [];
        $processedIds = [];

        foreach ($documents as $document) {
            if (!$document instanceof DocumentInterface) {
                throw new InvalidArgumentException(sprintf(
                    '%s not passed an instance of %s',
                    __FUNCTION__,
                    DocumentInterface::class
                ));
            }

            $indexes = $this->getConfiguration()->getIndexesForDocument($document);

            foreach (array_keys($indexes) as $indexName) {
                if (!isset($documentMap[$indexName])) {
                    $documentMap[$indexName] = [];
                }

                $documentMap[$indexName][] = $document->getIdentifier();
            }
        }

        foreach ($documentMap as $indexName => $idsToRemove) {
            $request = new DeleteDocuments($this->environmentizeIndex($indexName), $idsToRemove);
            $response = $this->getClient()->appSearch()
                ->deleteDocuments($request)
                ->asArray();

            $this->handleError($response);

            // Results here can be marked as deleted true or false. false would indicate that no document with that ID
            // exists in the Bifröst - which, is the same result, really
            // Grab all the ID values, and also cast them to string
            $processedIds += array_map('strval', array_column($response, 'id'));
        }

        // One document could have existed in multiple indexes, we only care to track it once
        return array_unique($processedIds);
    }

    /**
     * Forcefully remove all documents from the provided index name. Batches the requests to Bifröst based upon the
     * configured batch size, beginning at page 1 and continuing until the index is empty.
     *
     * @param string $indexName The index name to remove all documents from
     * @return int The total number of documents removed
     */
    public function removeAllDocuments(string $indexName): int
    {
        $indexName = $this->environmentizeIndex($indexName);
        $cfg = $this->getConfiguration();
        $client = $this->getClient();
        $numDeleted = 0;

        $request = new PostDocumentsList($this->environmentizeIndex($indexName));
        $request->setPageSize($cfg->getBatchSize());
        $request->setCurrentPage(1);

        $response = $client->appSearch()
            ->listDocuments($request)
            ->asArray();

        $this->handleError($response);

        $results = $response['results'] ?? [];

        // Loop forever until we no longer get any results
        while (count($results) > 0) {
            $idsToRemove = [];

            // Create the list of indexed documents to remove
            foreach ($response['results'] as $doc) {
                $idsToRemove[] = $doc['id'];
            }

            $deleteDocsRequest = new DeleteDocuments($indexName, $idsToRemove);
            // Actually delete the documents
            $deletedDocs = $client->appSearch()
                ->deleteDocuments($deleteDocsRequest)
                ->asArray();

            // Keep an accurate running count of the number of documents deleted.
            foreach ($deletedDocs as $doc) {
                $deleted = $doc['deleted'] ?? false;

                // phpcs:ignore SlevomatCodingStandard.ControlStructures.EarlyExit.EarlyExitNotUsed
                if ($deleted) {
                    $numDeleted += 1;
                }
            }

            // Re-fetch $documents now that we've deleted this batch
            $response = $client->appSearch()
                ->listDocuments($request)
                ->asArray();

            $this->handleError($response);

            $results = $response['results'] ?? [];
        }

        return $numDeleted;
    }

    public function getMaxDocumentSize(): int
    {
        return $this->config()->get('max_document_size');
    }

    /**
     * @throws IndexingServiceException
     */
    public function getDocument(string $id): ?DocumentInterface
    {
        $result = $this->getDocuments([$id]);

        return $result[0] ?? null;
    }

    /**
     * @return DocumentInterface[]
     * @throws IndexingServiceException
     */
    public function getDocuments(array $ids): array
    {
        $docs = [];

        foreach (array_keys($this->getConfiguration()->getIndexes()) as $indexName) {
            $request = Injector::inst()->create(
                GetDocuments::class,
                $this->environmentizeIndex($indexName),
                $ids
            );
            $response = $this->getClient()->appSearch()
                ->getDocuments($request)
                ->asArray();

            $this->handleError($response);

            $results = $response['results'] ?? null;

            if (!$results) {
                continue;
            }

            foreach ($results as $data) {
                $document = $this->getBuilder()->fromArray($data);

                if (!$document) {
                    continue;
                }

                // Stored by identifier as the key just in case one record exists in multiple indexes
                $docs[$document->getIdentifier()] = $document;
            }
        }

        return array_values($docs);
    }

    /**
     * @return DocumentInterface[]
     * @throws Exception
     */
    public function listDocuments(string $indexName, ?int $pageSize = null, int $currentPage = 0): array
    {
        $request = new PostDocumentsList($this->environmentizeIndex($indexName));
        $request->setCurrentPage($currentPage);

        if ($pageSize) {
            $request->setPageSize($pageSize);
        }

        $response = $this->getClient()->appSearch()
            ->listDocuments($request)
            ->asArray();

        $this->handleError($response);

        $results = $response['results'] ?? null;

        if (!$results) {
            return [];
        }

        $documents = [];

        foreach ($results as $data) {
            $document = $this->getBuilder()->fromArray($data);

            if (!$document) {
                continue;
            }

            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * @throws IndexingServiceException
     */
    public function getDocumentTotal(string $indexName): int
    {
        $request = new PostDocumentsList($this->environmentizeIndex($indexName));
        $response = $this->getClient()->appSearch()
            ->listDocuments($request)
            ->asArray();

        $this->handleError($response);

        $total = $response['meta']['page']['total_results'] ?? null;

        if ($total === null) {
            throw new IndexingServiceException('Total results not provided in meta content');
        }

        return $total;
    }

    /**
     * Ensure all the engines exist
     *
     * @throws IndexingServiceException
     * @throws IndexConfigurationException
     */
    public function configure(): array
    {
        $schemas = [];

        foreach (array_keys($this->getConfiguration()->getIndexes()) as $indexName) {
            $this->validateIndex($indexName);

            $envIndex = $this->environmentizeIndex($indexName);
            $this->findOrMakeIndex($envIndex);

            // Fetch the Schema, as it is currently configured in our application
            $definedSchema = $this->getSchemaForFields($this->getConfiguration()->getFieldsForIndex($indexName));

            $request = new PostSchema($envIndex, $definedSchema);
            // Trigger an update to Bifröst with our current configured Schema
            $newBifrostSchema = $this->getClient()->appSearch()
                ->putSchema($request)
                ->asArray();

            $this->handleError($newBifrostSchema);

            // Add this updated Schema to our tracked Schemas
            $schemas[$indexName] = $newBifrostSchema;
        }

        return $schemas;
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

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getBuilder(): DocumentBuilder
    {
        return $this->builder;
    }

    private function setClient(Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    private function setBuilder(DocumentBuilder $builder): static
    {
        $this->builder = $builder;

        return $this;
    }

    /**
     * @throws IndexingServiceException
     */
    private function findOrMakeIndex(string $index): void
    {
        $allEngines = $this->fetchEngines();

        if (in_array($index, $allEngines, true)) {
            return;
        }

        $engine = Injector::inst()->create(Engine::class, $index);
        $request = new PostEngine($engine);
        $response = $this->getClient()
            ->appSearch()
            ->createEngine($request)
            ->asArray();

        $this->handleError($response);
    }

    /**
     * @throws IndexingServiceException
     */
    private function fetchPaginatedEngines(int $page = 1): array
    {
        $request = new PostEngines();
        $request->setCurrentPage($page);

        $response = $this->getClient()
            ->appSearch()
            ->listEngines($request)
            ->asArray();

        $this->handleError($response);

        if (!array_key_exists('results', $response) || !is_array($response['results'])) {
            throw new IndexingServiceException('Invalid response format for listEngines; missing "results"');
        }

        return $response;
    }

    private function fetchEngines(): array
    {
        $response = $this->fetchPaginatedEngines(1);

        $results = $response['results'];

        if (isset($response['meta']['page']['total_pages']) && $response['meta']['page']['total_pages'] > 1) {
            foreach (range(2, $response['meta']['page']['total_pages']) as $page) {
                $paginatedResponse = $this->fetchPaginatedEngines($page);

                $results = array_merge($results, $paginatedResponse['results']);
            }
        }

        return array_column($results, 'name');
    }

    /**
     * @throws IndexingServiceException
     */
    private function handleError(?array $responseBody): void
    {
        if (!is_array($responseBody)) {
            return;
        }

        $errors = array_column($responseBody, 'errors');

        if (!$errors) {
            return;
        }

        $allErrors = [];

        foreach ($errors as $errorGroup) {
            $allErrors = array_merge($allErrors, $errorGroup);
        }

        if (!$allErrors) {
            return;
        }

        throw new IndexingServiceException(sprintf(
            'EnterpriseSearch API error: %s',
            print_r($allErrors, true)
        ));
    }

    /**
     * @param Field[] $fields
     */
    private function getSchemaForFields(array $fields): SchemaUpdateRequest
    {
        $request = Injector::inst()->create(SchemaUpdateRequest::class);

        foreach ($fields as $field) {
            $explicitFieldType = $field->getOption('type') ?? $this->config()->get('default_field_type');
            $request->{$field->getSearchFieldName()} = $explicitFieldType;
        }

        return $request;
    }

    /**
     * @throws IndexConfigurationException
     */
    private function validateIndex(string $index): void
    {
        $validTypes = array_filter(array_values($this->config()->get('valid_field_types'))) ?? [];

        $map = [];

        // Note: IndexConfiguration::getFieldsForIndex($index) does exist, and we could use that instead; However!
        // getFieldsForIndex() performs an array_merge() as it traverses through our classes, which means that
        // it (invisibly) removes duplicate fields
        // This is not ideal, as it means that we will never find out if two fields with the same name have been given
        // different types (which is a huge part of what this method should be about)
        // We want to be told when our configuration is invalid, we don't want it just *drop* one of our type
        // definitions

        // Loop through each Class that has a definition for this index
        foreach ($this->getConfiguration()->getClassesForIndex($index) as $class) {
            // Loop through each field that has been defined for that Class
            foreach ($this->getConfiguration()->getFieldsForClass($class) as $field) {
                // Check to see if a Type has been defined, or just default to what we have defined
                $type = $field->getOption('type') ?? $this->config()->get('default_field_type');

                // We can't progress if a type that we don't support has been defined
                if (!in_array($type, $validTypes, true)) {
                    throw new IndexConfigurationException(sprintf(
                        'Invalid field type: %s',
                        $type
                    ));
                }

                // Check to see if this field name has been defined by any other Class, and if it has, let's grab what
                // "type" it was described as
                $alreadyDefined = $map[$field->getSearchFieldName()] ?? null;

                // This field name has been defined by another Class, and it was described as a different type. We
                // don't support multiple types for a field, so we need to throw an Exception
                if ($alreadyDefined && $alreadyDefined !== $type) {
                    throw new IndexConfigurationException(sprintf(
                        'Field "%s" is defined twice in the same index with differing types.
                        (%s and %s). Consider changing the field name or explicitly defining
                        the type on each usage',
                        $field->getSearchFieldName(),
                        $alreadyDefined,
                        $type
                    ));
                }

                // Store this field and its type for later comparison
                $map[$field->getSearchFieldName()] = $type;
            }
        }
    }

    /**
     * @param DocumentInterface[] $documents
     * @throws IndexingServiceException
     * @throws NotFoundExceptionInterface
     */
    private function getContentMapForDocuments(array $documents): array
    {
        $documentMap = [];

        foreach ($documents as $document) {
            if (!$document instanceof DocumentInterface) {
                throw new InvalidArgumentException(sprintf(
                    '%s not passed an instance of %s',
                    __FUNCTION__,
                    DocumentInterface::class
                ));
            }

            if (!$document->shouldIndex()) {
                continue;
            }

            try {
                $fields = $this->getBuilder()->toArray($document);
            } catch (IndexConfigurationException $e) {
                Injector::inst()->get(LoggerInterface::class)->warning(
                    sprintf('Failed to convert document to array: %s', $e->getMessage())
                );

                continue;
            }

            $indexes = $this->getConfiguration()->getIndexesForDocument($document);

            if (!$indexes) {
                Injector::inst()->get(LoggerInterface::class)->warn(
                    sprintf('No valid indexes found for document %s, skipping...', $document->getIdentifier())
                );

                continue;
            }

            foreach (array_keys($indexes) as $indexName) {
                if (!isset($documentMap[$indexName])) {
                    $documentMap[$indexName] = [];
                }

                $documentMap[$indexName][] = $fields;
            }
        }

        return $documentMap;
    }

}
