<?php

namespace SilverStripe\ForagerBifrost\Tests\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Monolog\Logger;
use Page;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forager\DataObject\DataObjectDocument;
use SilverStripe\Forager\Extensions\SearchServiceExtension;
use SilverStripe\Forager\Models\IndexingFailure;
use SilverStripe\Forager\Service\DocumentBuilder;
use SilverStripe\Forager\Service\IndexConfiguration;
use SilverStripe\Forager\Service\IndexData;
use SilverStripe\Forager\Service\IndexingFailureService;
use SilverStripe\ForagerBifrost\Service\BifrostService;
use SilverStripe\ForagerBifrost\Service\ClientFactory;
use SilverStripe\ForagerBifrost\Tests\Fake\DataObjectFake;
use SilverStripe\ForagerBifrost\Tests\Fake\DataObjectFakePrivate;
use SilverStripe\ForagerBifrost\Tests\Fake\DataObjectFakeVersioned;
use SilverStripe\ForagerBifrost\Tests\Fake\ImageFake;
use SilverStripe\ForagerBifrost\Tests\Fake\IndexConfigurationFake;
use SilverStripe\ForagerBifrost\Tests\Fake\TagFake;
use SilverStripe\Security\Member;
use Throwable;

class BifrostServiceTest extends SapphireTest
{

    protected static $fixture_file = 'BifrostServiceTest.yml'; // phpcs:ignore

    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
     * @var array
     */
    protected static $extra_dataobjects = [
        DataObjectFake::class,
        DataObjectFakePrivate::class,
        DataObjectFakeVersioned::class,
        TagFake::class,
        ImageFake::class,
        Member::class,
    ];

    protected ?MockHandler $mock;

    protected BifrostService $searchService;

    public function testMaxDocumentSize(): void
    {
        BifrostService::config()->set('max_document_size', 100);

        $this->assertEquals(100, $this->searchService->getMaxDocumentSize());
    }

    #[DataProvider('provideFieldsForValidation')]
    public function testValidateField(string $fieldName, bool $shouldBeValid): void
    {
        if (!$shouldBeValid) {
            $this->expectExceptionMessage('Invalid field name');
        } else {
            $this->expectNotToPerformAssertions();
        }

        $this->searchService->validateField($fieldName);
    }

    public static function provideFieldsForValidation(): array
    {
        return [
            [
                'title',
                true,
            ],
            [
                'title_two',
                true,
            ],
            [
                'title_2',
                true,
            ],
            [
                '_title',
                false,
            ],
            [
                'Title_two',
                false,
            ],
            [
                'title-2',
                false,
            ],
        ];
    }

    public function testGetSchemaForFields(): void
    {
        $expectedSchema = [
            'title' => 'text',
            'html_text' => 'text',
            'first_name' => 'text',
            'surname' => 'text',
            'source_class' => 'text',
            'record_base_class' => 'text',
            'record_id' => 'text',
        ];

        $fields = $this->searchService->getConfiguration()
            ->getIndexDataForSuffix('content')->getFields();

        // This method is private, so we need Reflection to access it
        $reflectionMethod = new ReflectionMethod(BifrostService::class, 'getSchemaForFields');
        $reflectionMethod->setAccessible(true);

        // Invoke our method which should simply result in [no exceptions being thrown]
        $resultSchema = $reflectionMethod->invoke($this->searchService, $fields);

        $this->assertEquals($expectedSchema, (array) $resultSchema);
    }

    public function testValidateIndexConfiguration(): void
    {
        // The default IndexConfiguration that we've defined in setUp() is valid, so we would expect this to work
        // without throwing any exception
        $this->expectNotToPerformAssertions();

        // This method is private, so we need Reflection to access it
        $reflectionMethod = new ReflectionMethod(BifrostService::class, 'validateIndexConfiguration');
        $reflectionMethod->setAccessible(true);

        // Invoke our method which should simply result in [no exceptions being thrown]
        $reflectionMethod->invoke($this->searchService, 'content');
    }

    public function testvalidateIndexConfigurationInvalidType(): void
    {
        // We're going to set a new IndexConfiguration which has an invalid type specified. When we run
        // validateIndexConfiguration(), we would expect this exception to be thrown ("fail" being the name of the
        // invalid type)
        $this->expectExceptionMessage('Invalid field type: fail');

        // The field configuration that we want to use for our classes and tests
        IndexConfiguration::config()->set(
            'indexes',
            [
                'content' => [
                    'includeClasses' => [
                        Page::class => [
                            'fields' => [
                                'title' => true,
                                'html_text' => [
                                    'property' => 'getDBHTMLText',
                                    'options' => [
                                        'type' => 'fail',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        // This method is private, so we need Reflection to access it
        $reflectionMethod = new ReflectionMethod(BifrostService::class, 'validateIndexConfiguration');
        $reflectionMethod->setAccessible(true);

        // Invoke our method which should throw our expected Exception message
        $reflectionMethod->invoke($this->searchService, 'content');
    }

    public function testValidateIndexConfigurationIncompatibleFields(): void
    {
        // We're going to set a new IndexConfiguration which has the same field defined twice with a different "type"
        // specified for each. This should result in an Exception being thrown, as one field can't be two different
        // types
        $this->expectExceptionMessage('Field "fail_field" is defined twice in the same index with differing types');

        // The field configuration that we want to use for our classes and tests
        IndexConfiguration::config()->set(
            'indexes',
            [
                'content' => [
                    'includeClasses' => [
                        Page::class => [
                            'fields' => [
                                'fail_field' => [
                                    'property' => 'getDBHTMLText',
                                    'options' => [
                                        'type' => 'date',
                                    ],
                                ],
                            ],
                        ],
                        DataObjectFake::class => [
                            'fields' => [
                                'fail_field' => [
                                    'property' => 'getDBHTMLText',
                                    'options' => [
                                        'type' => 'number',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        // This method is private, so we need Reflection to access it
        $reflectionMethod = new ReflectionMethod(BifrostService::class, 'validateIndexConfiguration');
        $reflectionMethod->setAccessible(true);

        // Invoke our method which should throw our expected Exception message
        $reflectionMethod->invoke($this->searchService, 'content');
    }

    public function testGetContentMapForDocuments(): void
    {
        $documentOne = $this->objFromFixture(DataObjectFake::class, 'one');
        $documentTwo = $this->objFromFixture(DataObjectFake::class, 'two');
        $documentThree = $this->objFromFixture(DataObjectFake::class, 'three');

        $documents = [];
        // This document should be indexable
        $documents[] = DataObjectDocument::create($documentOne);
        // This document should NOT be indexable
        $documents[] = DataObjectDocument::create($documentTwo);
        // This document should be indexable
        $documents[] = DataObjectDocument::create($documentThree);

        $expectedMap = [
            [
                'id' => sprintf('silverstripe_foragerbifrost_tests_fake_dataobjectfake_%s', $documentOne->ID),
                'title' => 'Dataobject one',
                'html_text' => 'WHAT ARE WE YELLING ABOUT? Then a break Then a new line and a tab ',
                'record_base_class' => DataObjectFake::class,
                'record_id' => $documentOne->ID,
                'source_class' => DataObjectFake::class,
            ],
            [
                'id' => sprintf('silverstripe_foragerbifrost_tests_fake_dataobjectfake_%s', $documentThree->ID),
                'title' => 'Dataobject three',
                'html_text' => 'WHAT ARE WE YELLING ABOUT? Then a break Then a new line and a tab ',
                'record_base_class' => DataObjectFake::class,
                'record_id' => $documentThree->ID,
                'source_class' => DataObjectFake::class,
            ],
        ];

        // This method is private, so we need Reflection to access it
        $reflectionMethod = new ReflectionMethod(BifrostService::class, 'getContentMapForDocuments');
        $reflectionMethod->setAccessible(true);

        $indexData = $this->searchService->getConfiguration()->getIndexDataForSuffix('content');
        $indexData->withIndexContext(
            function (IndexData $index) use ($expectedMap, $reflectionMethod, $documents): void {
                // Invoke our method which will trigger 2 API calls, and we're expecting the second API call to trigger an error
                $this->assertEquals($expectedMap, $reflectionMethod->invoke($this->searchService, 'content', $documents));

            }
        );
    }

    public function testConfigureNewField(): void
    {
        // Make sure our IndexConfiguration has our IndexPrefix set
        IndexConfiguration::singleton()->setIndexPrefix('dev-test');

        // Valid headers that we can use for each Request
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        $body = [
            'acknowledged' => true,
        ];

        $expectedSchemas = [
            'content' => [
                'title' => 'text',
                'html_text' => 'text',
                'first_name' => 'text',
                'surname' => 'text',
                'source_class' => 'text',
                'record_base_class' => 'text',
                'record_id' => 'text',
            ],
        ];

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, json_encode($body)));

        $resultSchemas = $this->searchService->configure();

        // Check that our result matches the expected
        $this->assertEquals($expectedSchemas, $resultSchemas);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testGetDocumentTotal(): void
    {
        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Valid body content, containing all the metadata we need. Results are not relevant for this method, as
        // they are never accessed
        $body = json_encode([
            'meta' => [
                'page' => [
                    'current' => 1,
                    'total_pages' => 2,
                    'total_results' => 146,
                    'size' => 100,
                ],
            ],
            'results' => [],
        ]);

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        $total = $this->searchService->getDocumentTotal('content');

        // Check that the total matches what was in the meta response
        $this->assertEquals(146, $total);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testGetDocumentTotalError(): void
    {
        // We're testing that this Exception is thrown if the expected metadata is missing
        $this->expectExceptionMessage('Total results not provided in meta content');

        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Body content that is missing the key piece of data that we require (total_results)
        $body = json_encode([
            'meta' => [
                'page' => [
                    'current' => 1,
                    'total_pages' => 2,
                    'fail_total_results' => 146,
                    'size' => 100,
                ],
            ],
            'results' => [],
        ]);

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        // This should trigger the exception to be thrown
        $this->searchService->getDocumentTotal('content');

        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testListDocuments(): void
    {
        $fakeOne = $this->objFromFixture(DataObjectFake::class, 'one');
        $fakeTwo = $this->objFromFixture(DataObjectFake::class, 'two');

        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Valid body content, containing the metadata for a couple of the DataObjects that are in our fixture
        $body = json_encode([
            'meta' => [
                'page' => [
                    'current' => 1,
                    'total_pages' => 1,
                    'total_results' => 2,
                    'size' => 100,
                ],
            ],
            'results' => [
                [
                    'id' => sprintf('silverstripe_searchservice_tests_fake_dataobjectfake_%s', $fakeOne->ID),
                    'record_id' => $fakeOne->ID,
                    'record_base_class' => DataObjectFake::class,
                    'source_class' => DataObjectFake::class,
                    'title' => 'Dataobject one',
                    'page_content' => '',
                ],
                [
                    'id' => sprintf('silverstripe_searchservice_tests_fake_dataobjectfake_%s', $fakeTwo->ID),
                    'record_id' => $fakeTwo->ID,
                    'record_base_class' => DataObjectFake::class,
                    'source_class' => DataObjectFake::class,
                    'title' => 'Dataobject two',
                    'page_content' => '',
                ],
            ],
        ]);

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        $expectedDocuments = [
            [
                'title' => 'Dataobject one',
                'html_text' => 'WHAT ARE WE YELLING ABOUT? Then a break Then a new line and a tab ',
            ],
            [
                'title' => 'Dataobject two',
                'html_text' => 'WHAT ARE WE YELLING ABOUT? Then a break Then a new line and a tab ',
            ],
        ];

        $documents = $this->searchService->listDocuments('content');

        // Check that the total matches what was in the meta response
        $this->assertCount(2, $documents);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());

        $resultDocuments = [];

        foreach ($documents as $document) {
            $resultDocuments[] = $document->toArray();
        }

        $this->assertEquals($expectedDocuments, $resultDocuments);
    }

    public function testListDocumentsEmpty(): void
    {
        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Valid body content with empty results
        $body = json_encode([
            'meta' => [
                'page' => [
                    'current' => 1,
                    'total_pages' => 1,
                    'total_results' => 0,
                    'size' => 100,
                ],
            ],
            'results' => [],
        ]);

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        $documents = $this->searchService->listDocuments('content');

        // Check that the total matches what was in the meta response
        $this->assertCount(0, $documents);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testGetDocuments(): void
    {
        $fakeOne = $this->objFromFixture(DataObjectFake::class, 'one');
        $fakeTwo = $this->objFromFixture(DataObjectFake::class, 'two');

        $idOne = sprintf('silverstripe_searchservice_tests_fake_dataobjectfake_%s', $fakeOne->ID);
        $idTwo = sprintf('silverstripe_searchservice_tests_fake_dataobjectfake_%s', $fakeTwo->ID);

        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Valid body content, containing the metadata for a couple of the DataObjects that are in our fixture
        $body = json_encode([
            [
                'id' => $idOne,
                'record_id' => $fakeOne->ID,
                'record_base_class' => DataObjectFake::class,
                'source_class' => DataObjectFake::class,
                'title' => 'Dataobject one',
                'page_content' => '',
            ],
            // Doubling this one up to check that we only get one
            [
                'id' => $idTwo,
                'record_id' => $fakeTwo->ID,
                'record_base_class' => DataObjectFake::class,
                'source_class' => DataObjectFake::class,
                'title' => 'Dataobject two',
                'page_content' => '',
            ],
            [
                'id' => $idTwo,
                'record_id' => $fakeTwo->ID,
                'record_base_class' => DataObjectFake::class,
                'source_class' => DataObjectFake::class,
                'title' => 'Dataobject two',
                'page_content' => '',
            ],
        ]);

        $expectedDocuments = [
            [
                'title' => 'Dataobject one',
                'html_text' => 'WHAT ARE WE YELLING ABOUT? Then a break Then a new line and a tab ',
            ],
            [
                'title' => 'Dataobject two',
                'html_text' => 'WHAT ARE WE YELLING ABOUT? Then a break Then a new line and a tab ',
            ],
        ];

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        $documents = $this->searchService->getDocuments('content', [$idOne, $idTwo]);

        // Check that the total matches what was in the meta response
        $this->assertCount(2, $documents);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());

        $resultDocuments = [];

        foreach ($documents as $document) {
            $resultDocuments[] = $document->toArray();
        }

        $this->assertEquals($expectedDocuments, $resultDocuments);
    }

    public function testGetDocumentsEmpty(): void
    {
        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Valid body content with empty results
        $body = json_encode([]);

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        $documents = $this->searchService->getDocuments('content', [123, 321]);

        // Check that the total matches what was in the meta response
        $this->assertCount(0, $documents);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testGetDocument(): void
    {
        $fake = $this->objFromFixture(DataObjectFake::class, 'one');
        $id = sprintf('silverstripe_searchservice_tests_fake_dataobjectfake_%s', $fake->ID);

        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Valid body content, containing the metadata for a couple of the DataObjects that are in our fixture
        $body = json_encode([
            // Doubling this one up to check that we only get one
            [
                'id' => $id,
                'record_id' => $fake->ID,
                'record_base_class' => DataObjectFake::class,
                'source_class' => DataObjectFake::class,
                'title' => 'Dataobject one',
                'page_content' => '',
            ],
            [
                'id' => $id,
                'record_id' => $fake->ID,
                'record_base_class' => DataObjectFake::class,
                'source_class' => DataObjectFake::class,
                'title' => 'Dataobject one',
                'page_content' => '',
            ],
        ]);

        $expectedDocument = [
            'title' => 'Dataobject one',
            'html_text' => 'WHAT ARE WE YELLING ABOUT? Then a break Then a new line and a tab ',
        ];

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        $resultDocument = $this->searchService->getDocument('content', $id);

        // Check that the total matches what was in the meta response
        $this->assertNotNull($resultDocument);
        $this->assertEquals($expectedDocument, $resultDocument->toArray());
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testGetDocumentEmpty(): void
    {
        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Valid body content with empty results
        $body = json_encode([]);

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        $document = $this->searchService->getDocument('content', 123);

        // Check that there were no results (so we'd expect null for our one expected document)
        $this->assertNull($document);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testAddDocuments(): void
    {
        $documentOne = $this->objFromFixture(DataObjectFake::class, 'one');
        $documentThree = $this->objFromFixture(DataObjectFake::class, 'three');

        $documents = [];
        $documents[] = DataObjectDocument::create($documentOne);
        $documents[] = DataObjectDocument::create($documentThree);

        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Valid body content with our results
        $body = json_encode([
            [
                'id' => 'doc-123',
                'errors' => [],
            ],
            [
                'id' => 321, // We'll check that this is cast to string
                'errors' => [],
            ],
            [
                'id' => '321', // Should be removed as a duplicate of the above
                'errors' => [],
            ],
        ]);

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        $expectedIds = [
            'doc-123',
            '321',
        ];
        $resultIds = [];

        $indexData = $this->searchService->getConfiguration()->getIndexDataForSuffix('content');
        $indexData->withIndexContext(
            function (IndexData $index) use (&$resultIds, $documents): void {
                $resultIds = $this->searchService->addDocuments('content', $documents);
            }
        );

        $this->assertEqualsCanonicalizing($expectedIds, $resultIds);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testAddDocumentsOmitsRejectedDocuments(): void
    {
        $documentOne = $this->objFromFixture(DataObjectFake::class, 'one');
        $documentThree = $this->objFromFixture(DataObjectFake::class, 'three');

        $documents = [];
        $documents[] = DataObjectDocument::create($documentOne);
        $documents[] = DataObjectDocument::create($documentThree);

        // The engine responds 200 for the batch and reports each rejection against its own document.
        $body = json_encode([
            [
                'id' => 'doc-accepted',
                'errors' => [],
            ],
            [
                'id' => 'doc-rejected',
                'errors' => ['Field mapping rejected the document'],
            ],
        ]);

        $this->mock->append(new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], $body));

        $mockLogger = $this->getMockBuilder(Logger::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['error'])
            ->getMock();

        Injector::inst()->registerService($mockLogger, LoggerInterface::class);
        $mockLogger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('doc-rejected'));

        $resultIds = [];
        $indexData = $this->searchService->getConfiguration()->getIndexDataForSuffix('content');
        $indexData->withIndexContext(
            function (IndexData $index) use (&$resultIds, $documents): void {
                $resultIds = $this->searchService->addDocuments('content', $documents);
            }
        );

        $this->assertEqualsCanonicalizing(['doc-accepted'], $resultIds);
        $this->assertEquals(0, $this->mock->count());
    }

    public function testAddDocumentsEmpty(): void
    {
        // Adding an empty array of documents, we would expect no API calls to be made
        $resultIds = $this->searchService->addDocuments('content', []);

        // We would expect the results to be empty
        $this->assertEqualsCanonicalizing([], $resultIds);
    }

    public function testAddDocument(): void
    {
        $documentOne = $this->objFromFixture(DataObjectFake::class, 'one');
        $document = DataObjectDocument::create($documentOne);

        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Valid body content with our single result
        $body = json_encode([
            [
                'id' => 'doc-123',
                'errors' => [],
            ],
        ]);

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        $indexData = $this->searchService->getConfiguration()->getIndexDataForSuffix('content');
        $indexData->withIndexContext(
            function (IndexData $index) use (&$resultId, $document): void {
                $resultId = $this->searchService->addDocument('content', $document);
            }
        );

        $this->assertEquals('doc-123', $resultId);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testAddDocumentEmpty(): void
    {
        $documentOne = $this->objFromFixture(DataObjectFake::class, 'one');
        $document = DataObjectDocument::create($documentOne);

        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Valid body content with our single result
        $body = json_encode([]);

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        $indexData = $this->searchService->getConfiguration()->getIndexDataForSuffix('content');
        $indexData->withIndexContext(
            function (IndexData $index) use (&$resultId, $document): void {
                // Kinda just checking that the array_shift correctly returns null if no results were presented from Bifrost
                $resultId = $this->searchService->addDocument('content', $document);
            }
        );

        $this->assertNull($resultId);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testRemoveDocuments(): void
    {
        $documentOne = $this->objFromFixture(DataObjectFake::class, 'one');
        $documentTwo = $this->objFromFixture(DataObjectFake::class, 'two');
        $documentThree = $this->objFromFixture(DataObjectFake::class, 'three');

        $documents = [];
        // This should be deleted
        $documents[] = DataObjectDocument::create($documentOne);
        // This should NOT be deleted (because it never existed)
        $documents[] = DataObjectDocument::create($documentTwo);
        // This should be deleted
        $documents[] = DataObjectDocument::create($documentThree);

        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Valid body content with our results
        $body = json_encode([
            [
                'id' => sprintf('silverstripe_searchservice_tests_fake_dataobjectfake_%s', $documentOne->ID),
                'deleted' => true,
            ],
            [
                'id' => sprintf('silverstripe_searchservice_tests_fake_dataobjectfake_%s', $documentTwo->ID),
                'deleted' => false,
            ],
            [
                'id' => sprintf('silverstripe_searchservice_tests_fake_dataobjectfake_%s', $documentThree->ID),
                'deleted' => true,
            ],
            [
                'id' => 123, // Test that int is cast to string
                'deleted' => true,
            ],
        ]);

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        $expectedIds = [
            sprintf('silverstripe_searchservice_tests_fake_dataobjectfake_%s', $documentOne->ID),
            sprintf('silverstripe_searchservice_tests_fake_dataobjectfake_%s', $documentTwo->ID),
            sprintf('silverstripe_searchservice_tests_fake_dataobjectfake_%s', $documentThree->ID),
            '123',
        ];
        $resultIds = [];

        $indexData = $this->searchService->getConfiguration()->getIndexDataForSuffix('content');
        $indexData->withIndexContext(
            function (IndexData $index) use (&$resultIds, $documents): void {
                $resultIds = $this->searchService->addDocuments('content', $documents);
            }
        );

        $this->assertEqualsCanonicalizing($expectedIds, $resultIds);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testRemoveDocumentsEmpty(): void
    {
        // Removing an empty array of documents, we would expect no API calls to be made
        $resultIds = $this->searchService->removeDocuments('content', []);

        // We would expect the results to be empty
        $this->assertEqualsCanonicalizing([], $resultIds);
    }

    public function testRemoveDocument(): void
    {
        $documentOne = $this->objFromFixture(DataObjectFake::class, 'one');
        $document = DataObjectDocument::create($documentOne);

        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Valid body content with our single result
        $body = json_encode([
            [
                'id' => sprintf('silverstripe_searchservice_tests_fake_dataobjectfake_%s', $documentOne->ID),
                'deleted' => true,
            ],
        ]);

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        $expectedId = sprintf('silverstripe_searchservice_tests_fake_dataobjectfake_%s', $documentOne->ID);

        $resultId = $this->searchService->removeDocument('content', $document);

        $this->assertEquals($expectedId, $resultId);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testRemoveDocumentEmpty(): void
    {
        $documentOne = $this->objFromFixture(DataObjectFake::class, 'one');
        $document = DataObjectDocument::create($documentOne);

        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // Valid body content but with no results
        $body = json_encode([]);

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        // Kinda just checking that the array_shift correctly returns null if no results were presented from Bifrost
        $resultId = $this->searchService->removeDocument('content', $document);

        $this->assertNull($resultId);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testRemoveAllDocuments(): void
    {
        // Valid headers
        $headers = [
            'Content-Type' => 'application/json;charset=utf-8',
        ];
        // First response, listing out the documents that are available (which we'll then remove)
        $bodyOne = json_encode([
            'meta' => [
                'page' => [
                    'current' => 1,
                    'total_pages' => 1,
                    'total_results' => 2,
                    'size' => 3,
                ],
            ],
            'results' => [
                [
                    'id' => 'doc1',
                    'record_id' => '1',
                ],
                [
                    'id' => 'doc2',
                    'record_id' => '2',
                ],
                [
                    'id' => 'doc3',
                    'record_id' => '3',
                ],
            ],
        ]);
        // Second response is from our delete request. Adding a mix of deleted true/false. The way our "remove all"
        // feature works is that we request a list of all currently available documents, and then request that they
        // are removed by their IDs
        $bodyTwo = json_encode([
            [
                'id' => 'doc1',
                'deleted' => true,
            ],
            [
                'id' => 'doc2',
                'deleted' => false,
            ],
            [
                'id' => 'doc3',
                'deleted' => true,
            ],
        ]);

        // Append our mocks
        $this->mock->append(new Response(200, $headers, $bodyOne));
        $this->mock->append(new Response(200, $headers, $bodyTwo));

        $numRemoved = $this->searchService->clearIndexDocuments('content', 5);

        // A total of 3 documents were requested to be removed, but only 2 returned deleted = true
        $this->assertEqualsCanonicalizing(2, $numRemoved);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testAddDocumentsRecordsFailureWithStackTraceOnException(): void
    {
        $documents = [
            DataObjectDocument::create($this->objFromFixture(DataObjectFake::class, 'one')),
            DataObjectDocument::create($this->objFromFixture(DataObjectFake::class, 'three')),
        ];

        // A transport-level failure: the mock throws instead of returning a response.
        $this->mock->append(new RuntimeException('Simulated transport failure'));

        $threw = false;
        $indexData = $this->searchService->getConfiguration()->getIndexDataForSuffix('content');
        $indexData->withIndexContext(
            function (IndexData $index) use ($documents, &$threw): void {
                try {
                    $this->searchService->addDocuments('content', $documents);
                } catch (Throwable) {
                    // Recording must not swallow the error: it is rethrown so the job still fails.
                    $threw = true;
                }
            }
        );

        $this->assertTrue($threw, 'addDocuments should rethrow the transport exception');

        $failures = IndexingFailure::get();
        $this->assertCount(count($documents), $failures, 'Every submitted document should be recorded');

        foreach ($failures as $failure) {
            $this->assertSame(IndexingFailure::REASON_EXCEPTION, $failure->ReasonType);
            $this->assertNotEmpty($failure->StackTrace, 'A stack trace should be captured for exceptions');
            $this->assertStringContainsString('Simulated transport failure', $failure->StackTrace);
        }
    }

    public function testAddDocumentsRecordsContentError(): void
    {
        $document = DataObjectDocument::create($this->objFromFixture(DataObjectFake::class, 'one'));

        // A 200 batch response carrying a per-document error still marks that document as failed. The
        // engine reports these as an "errors" array per document, mirroring the Elasticsearch bulk items.
        $body = json_encode([
            [
                'id' => $document->getIdentifier(),
                'errors' => ['Field mapping rejected the document'],
            ],
        ]);
        $this->mock->append(new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], $body));

        $indexData = $this->searchService->getConfiguration()->getIndexDataForSuffix('content');
        $indexData->withIndexContext(
            function (IndexData $index) use ($document): void {
                $this->searchService->addDocuments('content', [$document]);
            }
        );

        $failure = IndexingFailure::get()->first();
        $this->assertNotNull($failure);
        $this->assertSame(IndexingFailure::REASON_CONTENT_ERROR, $failure->ReasonType);
        $this->assertStringContainsString('Field mapping rejected', $failure->LastMessage);
        // Content errors are engine-reported, not exceptions, so no trace is captured.
        $this->assertEmpty($failure->StackTrace);
    }

    public function testAddDocumentsResolvesPriorFailureOnSuccess(): void
    {
        $document = DataObjectDocument::create($this->objFromFixture(DataObjectFake::class, 'one'));

        // Seed a prior open failure for this document.
        IndexingFailureService::singleton()->recordForDocument(
            $document,
            'content',
            IndexingFailure::REASON_UNACKNOWLEDGED,
            'earlier miss'
        );
        $this->assertSame(IndexingFailure::STATUS_OPEN, IndexingFailure::get()->first()->Status);

        // The engine now acknowledges the document (echoing its identifier back).
        $body = json_encode([
            [
                'id' => $document->getIdentifier(),
                'errors' => [],
            ],
        ]);
        $this->mock->append(new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], $body));

        $indexData = $this->searchService->getConfiguration()->getIndexDataForSuffix('content');
        $indexData->withIndexContext(
            function (IndexData $index) use ($document): void {
                $this->searchService->addDocuments('content', [$document]);
            }
        );

        $this->assertSame(
            IndexingFailure::STATUS_RESOLVED,
            IndexingFailure::get()->first()->Status,
            'A prior failure should self-heal once the engine acknowledges the document'
        );
    }

    public function testRemoveDocumentsRecordsFailureOnErrorResponse(): void
    {
        $documents = [
            DataObjectDocument::create($this->objFromFixture(DataObjectFake::class, 'one')),
            DataObjectDocument::create($this->objFromFixture(DataObjectFake::class, 'three')),
        ];

        // search-client-php does not throw on an error response, so the status is what marks the batch failed.
        $this->mock->append(new Response(500, [], 'Internal server error'));

        $resultIds = $this->searchService->removeDocuments('content', $documents);

        $this->assertEmpty($resultIds, 'Nothing was removed, so nothing should be reported as processed');

        $failures = IndexingFailure::get();
        $this->assertCount(count($documents), $failures, 'Every document in the batch should be recorded');

        foreach ($failures as $failure) {
            $this->assertSame(IndexingFailure::REASON_REMOVE_EXCEPTION, $failure->ReasonType);
            $this->assertStringContainsString('HTTP 500', $failure->LastMessage);
        }
    }

    public function testRemoveDocumentsRecordsFailureWithStackTraceOnException(): void
    {
        $documents = [
            DataObjectDocument::create($this->objFromFixture(DataObjectFake::class, 'one')),
        ];

        $this->mock->append(new RuntimeException('Simulated transport failure'));

        $threw = false;

        try {
            $this->searchService->removeDocuments('content', $documents);
        } catch (Throwable) {
            // Recording must not swallow the error: it is rethrown so the job still fails.
            $threw = true;
        }

        $this->assertTrue($threw, 'removeDocuments should rethrow the transport exception');

        $failure = IndexingFailure::get()->first();
        $this->assertNotNull($failure);
        $this->assertSame(IndexingFailure::REASON_REMOVE_EXCEPTION, $failure->ReasonType);
        $this->assertStringContainsString('Simulated transport failure', $failure->StackTrace);
    }

    public function testRemoveDocumentsResolvesPriorFailureOnSuccess(): void
    {
        $document = DataObjectDocument::create($this->objFromFixture(DataObjectFake::class, 'one'));

        IndexingFailureService::singleton()->recordForDocument(
            $document,
            'content',
            IndexingFailure::REASON_REMOVE_EXCEPTION,
            'earlier removal failure'
        );

        $body = json_encode([
            [
                'id' => $document->getIdentifier(),
                'deleted' => true,
            ],
        ]);
        $this->mock->append(new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], $body));

        $this->searchService->removeDocuments('content', [$document]);

        $this->assertSame(
            IndexingFailure::STATUS_RESOLVED,
            IndexingFailure::get()->first()->Status,
            'A confirmed removal should clear the failure recorded against the earlier attempt'
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Failure rows are real (non-TestOnly) records shared across tests; start each test clean.
        IndexingFailure::get()->removeAll();

        // The field configuration that we want to use for our classes and tests
        IndexConfiguration::config()->set(
            'indexes',
            [
                'content' => [
                    'includeClasses' => [
                        Page::class => [
                            'fields' => [
                                'title' => true,
                            ],
                        ],
                        DataObjectFake::class => [
                            'fields' => [
                                'title' => true,
                                'html_text' => [
                                    'property' => 'getDBHTMLText',
                                ],
                            ],
                        ],
                        Member::class => [
                            'fields' => [
                                'first_name' => [
                                    'property' => 'FirstName',
                                ],
                                'surname' => true,
                            ],
                        ],
                    ],
                ],
            ]
        );
        IndexConfiguration::config()->set('crawl_page_content', false);

        // Set up a mock handler/client so that we can feed in mock responses that we expected to get from the API
        $this->mock = new MockHandler([]);
        $handler = HandlerStack::create($this->mock);
        $httpClient = new GuzzleClient(['handler' => $handler]);

        $factory = new ClientFactory();
        $client = $factory->create(
            '',
            [
                'host' => 'https://anywhere.com',
                'token' => 'FakeToken',
                'httpClient' => $httpClient,
            ]
        );

        $indexConfiguration = $this->mockConfig();
        $documentBuilder = Injector::inst()->get(DocumentBuilder::class);

        $this->searchService = BifrostService::create($client, $indexConfiguration, $documentBuilder);
    }

    protected function mockConfig(): IndexConfigurationFake
    {
        Injector::inst()->registerService($config = new IndexConfigurationFake(), IndexConfiguration::class);
        SearchServiceExtension::singleton()->setConfiguration($config);

        return $config;
    }

}
