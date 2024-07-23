<?php

namespace SilverStripe\ForagerBifrost\Tests\Reports;

use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forager\Extensions\SearchServiceExtension;
use SilverStripe\ForagerBifrost\Extensions\FileExtension;
use SilverStripe\ForagerBifrost\Reports\LargeDocumentReport;

class LargeDocumentReportTest extends SapphireTest
{

    // @phpcs:ignore
    protected $usesDatabase = true;

    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints
     */
    protected static $required_extensions = [
        File::class => [
            SearchServiceExtension::class,
            FileExtension::class,
        ],
    ];

    public function testDescription(): void
    {
        $report = new LargeDocumentReport();

        $this->assertEquals(
            'Documents excluded for content ingestion in Silverstripe Search which exceeds 5 MB',
            $report->description()
        );
    }

    public function testColumns(): void
    {
        $columns = (new LargeDocumentReport())->columns();

        $this->assertEqualsCanonicalizing(
            [
                'ID' => 'ID',
                'Title' => 'Title',
                'Name' => 'Name',
                'ContentSizeNice' => 'Size',
            ],
            $columns
        );
    }

    public function testSourceRecords(): void
    {
        $report = new LargeDocumentReport();
        $file5MB = File::create();
        $file5MB->setFromString(str_repeat('12345', pow(1024, 2)), 'Uploads/testfile.txt');
        $file5MB->write();

        $this->assertCount(0, $report->sourceRecords());

        $file6MB = File::create();
        $file6MB->setFromString(str_repeat('123456', pow(1024, 2)), 'Uploads/testfile6.txt');
        $fileID6MB = $file6MB->write();
        $files = $report->sourceRecords();

        $this->assertCount(1, $files);
        $this->assertEquals($fileID6MB, $files->first()->ID);
    }

    protected function setUp(): void
    {
        parent::setUp();

        TestAssetStore::activate('SearchFileTest');
        Environment::setEnv('SEARCH_INDEX_FILES', 1);
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();

        parent::tearDown();
    }

}
