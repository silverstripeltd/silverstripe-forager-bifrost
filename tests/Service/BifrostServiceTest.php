<?php

namespace SilverStripe\ForagerBifrost\Tests\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Page;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forager\DataObject\DataObjectDocument;
use SilverStripe\Forager\Extensions\SearchServiceExtension;
use SilverStripe\Forager\Service\DocumentBuilder;
use SilverStripe\Forager\Service\IndexConfiguration;
use SilverStripe\ForagerBifrost\Service\BifrostService;
use SilverStripe\ForagerBifrost\Service\ClientFactory;
use SilverStripe\ForagerBifrost\Tests\Fake\DataObjectFake;
use SilverStripe\ForagerBifrost\Tests\Fake\DataObjectFakePrivate;
use SilverStripe\ForagerBifrost\Tests\Fake\DataObjectFakeVersioned;
use SilverStripe\ForagerBifrost\Tests\Fake\ImageFake;
use SilverStripe\ForagerBifrost\Tests\Fake\IndexConfigurationFake;
use SilverStripe\ForagerBifrost\Tests\Fake\TagFake;
use SilverStripe\Security\Member;

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

        $fields = $this->searchService->getConfiguration()->getFieldsForIndex('content');

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
            'content' => [
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
            ],
        ];

        // This method is private, so we need Reflection to access it
        $reflectionMethod = new ReflectionMethod(BifrostService::class, 'getContentMapForDocuments');
        $reflectionMethod->setAccessible(true);

        // Invoke our method which will trigger 2 API calls, and we're expecting the second API call to trigger an error
        $this->assertEquals($expectedMap, $reflectionMethod->invoke($this->searchService, $documents));
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

        $documents = $this->searchService->getDocuments([$idOne, $idTwo]);

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

        $documents = $this->searchService->getDocuments([123, 321]);

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
            'meta' => [
                'page' => [
                    'current' => 1,
                    'total_pages' => 1,
                    'total_results' => 2,
                    'size' => 100,
                ],
            ],
            'results' => [
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
            ],
        ]);

        $expectedDocument = [
            'title' => 'Dataobject one',
            'html_text' => 'WHAT ARE WE YELLING ABOUT? Then a break Then a new line and a tab ',
        ];

        // Append this mock response to our stack
        $this->mock->append(new Response(200, $headers, $body));

        $resultDocument = $this->searchService->getDocument($id);

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

        $document = $this->searchService->getDocument(123);

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

        $resultIds = $this->searchService->addDocuments($documents, ['content']);

        $this->assertEqualsCanonicalizing($expectedIds, $resultIds);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testAddDocumentsEmpty(): void
    {
        // Adding an empty array of documents, we would expect no API calls to be made
        $resultIds = $this->searchService->addDocuments([], ['content']);

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

        $resultId = $this->searchService->addDocument($document, ['content']);

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

        // Kinda just checking that the array_shift correctly returns null if no results were presented from Bifrost
        $resultId = $this->searchService->addDocument($document, ['content']);

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

        $resultIds = $this->searchService->addDocuments($documents, ['content']);

        $this->assertEqualsCanonicalizing($expectedIds, $resultIds);
        // And make sure nothing is left in our Response Stack. This would indicate that every Request we expect to make
        // has been made
        $this->assertEquals(0, $this->mock->count());
    }

    public function testRemoveDocumentsEmpty(): void
    {
        // Removing an empty array of documents, we would expect no API calls to be made
        $resultIds = $this->searchService->removeDocuments([], ['content']);

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

        $resultId = $this->searchService->removeDocument($document, ['content']);

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
        $resultId = $this->searchService->removeDocument($document, ['content']);

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

    protected function setUp(): void
    {
        parent::setUp();

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
