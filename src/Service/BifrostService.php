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
use SilverStripe\Forager\Models\IndexingFailure;
use SilverStripe\Forager\Schema\Field;
use SilverStripe\Forager\Service\DocumentBuilder;
use SilverStripe\Forager\Service\IndexConfiguration;
use SilverStripe\Forager\Service\IndexingFailureService;
use SilverStripe\Forager\Service\Traits\ConfigurationAware;
use Silverstripe\Search\Client\Client;
use Silverstripe\Search\Client\Model\Pagination;
use Silverstripe\Search\Client\Request\Document\DocumentListRequest;
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

        // Reconcile what the engine acknowledges against what we submitted, so documents the engine
        // silently drops or rejects are recorded rather than lost. The content map keys each document
        // under the ID field with the value of DocumentInterface::getIdentifier(), and the engine echoes
        // that same value back as $documentResponse->id — so identifiers line up on both sides.
        $idField = $this->getConfiguration()->getIDField();
        $sentIds = array_column($documentsArray, $idField);
        $documentsByIdentifier = [];

        foreach ($documents as $document) {
            $documentsByIdentifier[$document->getIdentifier()] = $document;
        }

        $failureService = IndexingFailureService::singleton();
        $contentErrorIds = [];

        try {
            $response = $this->getClient()->documentsPost(
                $this->getConfiguration()->environmentizeIndex($indexSuffix),
                $documentsArray
            );
        } catch (Throwable $e) {
            // A whole-batch transport/exception failure: record every document we tried to send, then
            // rethrow so the job still surfaces the error (recording never swallows it).
            $this->recordFailures($failureService, $documentsByIdentifier, $sentIds, $indexSuffix, [
                'reason' => IndexingFailure::REASON_EXCEPTION,
                'message' => $e->getMessage(),
                'trace' => sprintf('%s: %s' . PHP_EOL . '%s', $e::class, $e->getMessage(), $e->getTraceAsString()),
            ]);

            throw $e;
        }

        $status = $response->getStatusCode();

        if ($status >= 400) {
            // search-client-php does not throw on error responses, so without this a non-2xx status
            // would be parsed as an empty body and pass silently. Record every submitted document.
            $this->recordFailures($failureService, $documentsByIdentifier, $sentIds, $indexSuffix, [
                'reason' => IndexingFailure::REASON_EXCEPTION,
                'message' => sprintf('Engine returned HTTP %d: %s', $status, trim((string) $response->getBody())),
            ]);

            return [];
        }

        $body = json_decode((string) $response->getBody());

        // A 2xx with an empty/unparseable body means nothing was acknowledged; fall through so the
        // reconciliation loop records every submitted document as unacknowledged rather than returning.
        if (!$body) {
            $body = [];
        }

        foreach ($body as $documentResponse) {
            // The engine responds 200 for the batch whether or not individual documents were accepted,
            // reporting each rejection in that document's "errors". Returning a rejected identifier as
            // processed tells the caller the document is in the index when it is not.
            $errors = $documentResponse->errors ?? [];

            if ($errors) {
                Injector::inst()->get(LoggerInterface::class)->error(sprintf(
                    'Document "%s" was rejected by index "%s": %s',
                    $documentResponse->id,
                    $indexSuffix,
                    implode('; ', (array) $errors)
                ));

                $contentErrorIds[] = $documentResponse->id;
                $this->recordFailures($failureService, $documentsByIdentifier, [$documentResponse->id], $indexSuffix, [
                    'reason' => IndexingFailure::REASON_CONTENT_ERROR,
                    'message' => implode('; ', (array) $errors),
                ]);

                continue;
            }

            $processedIds[] = $documentResponse->id;
        }

        // One document could have existed in multiple indexes, we only care to track it once
        $processedIds = array_unique($processedIds);

        // Acknowledged documents self-heal any prior open failure; anything we sent that was neither
        // acknowledged nor explicitly errored has silently vanished, so record it as unacknowledged.
        foreach ($sentIds as $sentId) {
            $document = $documentsByIdentifier[$sentId] ?? null;

            if (!$document) {
                continue;
            }

            if (in_array($sentId, $processedIds, true)) {
                $failureService->resolveForDocument($document, $indexSuffix);
            } elseif (!in_array($sentId, $contentErrorIds, true)) {
                $failureService->recordForDocument(
                    $document,
                    $indexSuffix,
                    IndexingFailure::REASON_UNACKNOWLEDGED,
                    'Document was submitted but not acknowledged by the engine'
                );
            }
        }

        return $processedIds;
    }

    /**
     * Record a failure for each of the given identifiers that maps to a submitted document.
     *
     * @param array<string, DocumentInterface> $documentsByIdentifier
     * @param array<int, string> $identifiers
     * @param array{reason: string, message: string, trace?: string|null} $failure
     */
    private function recordFailures(
        IndexingFailureService $failureService,
        array $documentsByIdentifier,
        array $identifiers,
        string $indexSuffix,
        array $failure
    ): void {
        foreach ($identifiers as $identifier) {
            $document = $documentsByIdentifier[$identifier] ?? null;

            if (!$document) {
                continue;
            }

            $failureService->recordForDocument(
                $document,
                $indexSuffix,
                $failure['reason'],
                $failure['message'],
                $failure['trace'] ?? null
            );
        }
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

            $body = json_decode((string) $response->getBody());

            if (!$body) {
                continue;
            }

            foreach ($body as $documentResponse) {
                $processedIds[] = $documentResponse->id;
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

        $pagination = new Pagination(1, $batchSize);

        $request = new DocumentListRequest();
        $request->setPage($pagination);

        $response = $client->documentsList($indexName, $request);
        $body = json_decode((string) $response->getBody());

        $idsToRemove = [];

        // Create the list of indexed documents to remove
        foreach ($body->results as $doc) {
            $idsToRemove[] = $doc->id;
        }

        if (!$idsToRemove) {
            return 0;
        }

        // Actually delete the documents
        $deleteResponse = $client->documentsDelete($indexName, $idsToRemove);
        $deletedDocs = json_decode((string) $deleteResponse->getBody());

        // Keep an accurate running count of the number of documents deleted.
        foreach ($deletedDocs as $doc) {
            $deleted = $doc->deleted ?? false;

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
    public function getDocument(string $indexSuffix, string $id): ?DocumentInterface
    {
        $result = $this->getDocuments($indexSuffix, [$id]);

        return $result[0] ?? null;
    }

    /**
     * @return DocumentInterface[]
     */
    public function getDocuments(string $indexSuffix, array $ids): array
    {
        $docs = [];

        $response = $this->getClient()->documentsGet(
            $this->getConfiguration()->environmentizeIndex($indexSuffix),
            $ids
        );

        $results = json_decode((string) $response->getBody(), true);

        if (!$results) {
            return [];
        }

        foreach ($results as $data) {
            $document = $this->getBuilder()->fromArray($data);

            if (!$document) {
                continue;
            }

            // Stored by identifier as the key
            $docs[$document->getIdentifier()] = $document;
        }

        return array_values($docs);
    }

    /**
     * @return DocumentInterface[]
     * @throws Exception
     */
    public function listDocuments(string $indexSuffix, ?int $pageSize = null, int $currentPage = 1): array
    {
        $pagination = new Pagination($currentPage, $pageSize ?? 10);

        $request = new DocumentListRequest();
        $request->setPage($pagination);

        $response = $this->getClient()->documentsList(
            $this->getConfiguration()->environmentizeIndex($indexSuffix),
            $request
        );

        $body = json_decode((string) $response->getBody());
        $documents = [];

        foreach ($body->results as $data) {
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
        // We're only interested in the metadata, so the number of docs we request is actually not important
        $pagination = new Pagination(1, 1);

        $request = new DocumentListRequest();
        $request->setPage($pagination);

        $response = $this->getClient()->documentsList(
            $this->getConfiguration()->environmentizeIndex($indexSuffix),
            $request
        );

        $body = json_decode((string) $response->getBody());
        $total = $body->meta->page->total_results ?? null;

        if ($total === null) {
            throw new IndexingServiceException('Total results not provided in meta content');
        }

        return (int) $total;
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
            $body = json_decode((string) $response->getBody());

            if (!($body->acknowledged ?? false)) {
                continue;
            }

            $schemas[$indexSuffix] = $definedSchema;
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
     * @return array<string, string>
     */
    private function getSchemaForFields(array $fields): array
    {
        $schema = [];

        foreach ($fields as $field) {
            $explicitFieldType = $field->getOption('type') ?? $this->config()->get('default_field_type');
            $schema[$field->getSearchFieldName()] = $explicitFieldType;
        }

        return $schema;
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
                Injector::inst()->get(LoggerInterface::class)->warning(
                    sprintf('No valid indexes found for document %s, skipping...', $document->getIdentifier())
                );

                continue;
            }

            if (!in_array($indexSuffix, array_keys($indexes), true)) {
                Injector::inst()->get(LoggerInterface::class)->warning(
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
