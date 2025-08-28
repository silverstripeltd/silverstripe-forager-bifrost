<?php

namespace SilverStripe\ForagerBifrost\Service;

use Exception;
use InvalidArgumentException;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forager\Exception\IndexConfigurationException;
use SilverStripe\Forager\Exception\IndexingServiceException;
use SilverStripe\Forager\Interfaces\DocumentInterface;
use SilverStripe\Forager\Interfaces\IndexingInterface;
use SilverStripe\Forager\Schema\Field;
use SilverStripe\Forager\Service\DocumentBuilder;
use SilverStripe\Forager\Service\IndexConfiguration;
use SilverStripe\Forager\Service\Traits\ConfigurationAware;
use Silverstripe\Search\Client\Client;
use Silverstripe\Search\Client\Model\DocumentListRequest;
use Silverstripe\Search\Client\Model\PaginationNoTotals;
use Silverstripe\Search\Client\Model\Schema;
use Throwable;

class BifrostService implements IndexingInterface
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
        return Controller::join_links(Environment::getEnv('BIFROST_ENDPOINT'), '/resources/guides/index.html');
    }

    private const string DEFAULT_FIELD_TYPE = 'text';

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

    /**
     * @throws IndexingServiceException
     * @throws NotFoundExceptionInterface
     */
    public function addDocument(string $indexSuffix, DocumentInterface $document): ?string
    {
        $processedIds = $this->addDocuments($indexSuffix, [$document]);

        return array_shift($processedIds);
    }

    /**
     * @param DocumentInterface[] $documents
     * @throws IndexingServiceException
     * @throws NotFoundExceptionInterface
     */
    public function addDocuments(string $indexSuffix, array $documents): array
    {
        $documentsArray = $this->getContentMapForDocuments($indexSuffix, $documents);
        $processedIds = [];

        if (!$documentsArray) {
            return [];
        }

        $response = $this->getClient()->documentsPost(
            $this->getConfiguration()->environmentizeIndex($indexSuffix),
            $documentsArray
        );

        if (!$response) {
            return [];
        }

        foreach ($response as $documentResponse) {
            $processedIds[] = $documentResponse->getId();
        }

        // One document could have existed in multiple indexes, we only care to track it once
        return array_unique($processedIds);
    }

    public function removeDocument(string $indexSuffix, DocumentInterface $document): ?string
    {
        $processedIds = $this->removeDocuments($indexSuffix, [$document]);

        return array_shift($processedIds);
    }

    /**
     * @param DocumentInterface[] $documents
     */
    public function removeDocuments(string $indexSuffix, array $documents): array
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

            if (!isset($documentMap[$indexSuffix])) {
                $documentMap[$indexSuffix] = [];
            }

            $documentMap[$indexSuffix][] = $document->getIdentifier();
        }

        foreach ($documentMap as $indexSuffix => $idsToRemove) {
            $response = $this->getClient()->documentsDelete(
                $this->getConfiguration()->environmentizeIndex($indexSuffix),
                $idsToRemove
            );

            if (!$response) {
                continue;
            }

            foreach ($response as $documentResponse) {
                $processedIds[] = $documentResponse->getId();
            }
        }

        // One document could have existed in multiple indexes, we only care to track it once
        return array_unique($processedIds);
    }

    /**
     * @return int The total number of documents removed
     */
    public function clearIndexDocuments(string $indexSuffix, int $batchSize): int
    {
        $indexName = $this->getConfiguration()->environmentizeIndex($indexSuffix);
        $client = $this->getClient();
        $numDeleted = 0;

        $pagination = new PaginationNoTotals();
        $pagination->setSize($batchSize);
        $pagination->setCurrent(1);

        $request = new DocumentListRequest();
        $request->setPage($pagination);

        $response = $client->documentsListPost($indexName, $request);

        $idsToRemove = [];

        // Create the list of indexed documents to remove
        foreach ($response->getResults() as $doc) {
            $idsToRemove[] = $doc['id'];
        }

        if (!$idsToRemove) {
            return 0;
        }

        // Actually delete the documents
        $deletedDocs = $client->documentsDelete($indexName, $idsToRemove);

        // Keep an accurate running count of the number of documents deleted.
        foreach ($deletedDocs as $doc) {
            $deleted = $doc?->getDeleted() ?? false;

            // phpcs:ignore SlevomatCodingStandard.ControlStructures.EarlyExit.EarlyExitNotUsed
            if ($deleted) {
                $numDeleted += 1;
            }
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
     */
    public function getDocuments(array $ids): array
    {
        $docs = [];

        foreach (array_keys($this->getConfiguration()->getIndexConfigurations()) as $indexSuffix) {
            // This is going to return results as a stdClass
            $response = $this->getClient()->documentsGet(
                $this->getConfiguration()->environmentizeIndex($indexSuffix),
                $ids
            );
            // Convert to associative, because this is what the builder requires
            $response = json_decode(json_encode($response), true);

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
    public function listDocuments(string $indexSuffix, ?int $pageSize = null, int $currentPage = 1): array
    {
        $pagination = new PaginationNoTotals();
        $pagination->setCurrent($currentPage);

        if ($pageSize) {
            $pagination->setSize($pageSize);
        }

        $request = new DocumentListRequest();
        $request->setPage($pagination);

        // This is going to return results as a stdClass
        $response = $this->getClient()->documentsListPost(
            $this->getConfiguration()->environmentizeIndex($indexSuffix),
            $request
        );

        $documents = [];

        foreach ($response->getResults() as $data) {
            // Casting to array is required, because these are actually ArrayObjects, not arrays
            $document = $this->getBuilder()->fromArray((array) $data);

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
    public function getDocumentTotal(string $indexSuffix): int
    {
        $pagination = new PaginationNoTotals();
        // We're only interested in the metadata, so the number of docs we request is actually not important
        $pagination->setSize(1);
        $pagination->setCurrent(1);

        $request = new DocumentListRequest();
        $request->setPage($pagination);

        $response = $this->getClient()->documentsListPost(
            $this->getConfiguration()->environmentizeIndex($indexSuffix),
            $request
        );

        try {
            $total = $response->getMeta()->getPage()->getTotalResults();
        } catch (Throwable) {
            throw new IndexingServiceException('Total results not provided in meta content');
        }

        return $total;
    }

    /**
     * @throws IndexConfigurationException
     */
    public function configure(): array
    {
        $schemas = [];

        foreach (array_keys($this->getConfiguration()->getIndexConfigurations()) as $indexSuffix) {
            $this->validateIndexConfiguration($indexSuffix);

            $indexName = $this->getConfiguration()->environmentizeIndex($indexSuffix);

            // Fetch the Schema, as it is currently configured in our application
            $definedSchema = $this->getSchemaForFields(
                $this->getConfiguration()->getIndexDataForSuffix($indexSuffix)->getFields()
            );
            // Trigger an update to Bifröst with our current configured Schema
            $response = $this->getClient()->schemaPost($indexName, $definedSchema);

            if (!$response->getAcknowledged()) {
                continue;
            }

            $schemas[$indexSuffix] = (array) $definedSchema;
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
    private function getSchemaForFields(array $fields): Schema
    {
        $request = new Schema();

        foreach ($fields as $field) {
            $explicitFieldType = $field->getOption('type') ?? $this->config()->get('default_field_type');
            $request[$field->getSearchFieldName()] = $explicitFieldType;
        }

        return $request;
    }

    /**
     * @throws IndexConfigurationException
     */
    private function validateIndexConfiguration(string $index): void
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
        foreach ($this->getConfiguration()->getIndexDataForSuffix($index)->getClasses() as $class) {
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
    private function getContentMapForDocuments(string $indexSuffix, array $documents): array
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
                $documentToArray = $this->getBuilder()->toArray($document);
            } catch (IndexConfigurationException $e) {
                Injector::inst()->get(LoggerInterface::class)->warning(
                    sprintf('Failed to convert document to array: %s', $e->getMessage())
                );

                continue;
            }

            $indexes = $this->getConfiguration()->getIndexConfigurationsForDocument($document);

            if (!$indexes) {
                Injector::inst()->get(LoggerInterface::class)->warn(
                    sprintf('No valid indexes found for document %s, skipping...', $document->getIdentifier())
                );

                continue;
            }

            if (!in_array($indexSuffix, array_keys($indexes), true)) {
                Injector::inst()->get(LoggerInterface::class)->warn(
                    sprintf(
                        '%s is not a valid index for document %s, skipping...',
                        $indexSuffix,
                        $document->getIdentifier()
                    )
                );

                continue;
            }

            $documentMap[] = $documentToArray;
        }

        return $documentMap;
    }

}
