<?php

namespace SilverStripe\ForagerBifrost\Tests\Reports;

use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
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
            'Documents excluded for content ingestion in Silverstripe Search which exceeds 15 MB',
            $report->description()
        );

        // Removing extension should result in a new description
        $this->removeFileExtension();
        $this->assertEquals(
            'This report requires the FileExtension being applied to Files.',
            $report->description()
        );

    }

    public function testCanView(): void
    {
        $report = new LargeDocumentReport();

        $this->assertTrue($report->canView());

        // When the extension is not present on the File then the report should not be viewable
        $this->removeFileExtension();
        $this->assertfalse($report->canView());
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
        $file5Mb = File::create();
        $file5Mb->setFromString(str_repeat('12345', pow(1024, 2)), 'Uploads/testfile.txt');
        $file5Mb->write();

        $this->assertCount(0, $report->sourceRecords());

        $file21Mb = File::create();
        // Creates a 16 MB file which exceeds limit of 15 MB
        $file21Mb->setFromString(str_repeat('0123456789012345', pow(1024, 2)), 'Uploads/testfile6.txt');
        $file21MbId = $file21Mb->write();
        $files = $report->sourceRecords();

        $this->assertCount(1, $files);
        $this->assertEquals($file21MbId, $files->first()->ID);
    }

    protected function removeFileExtension(): void
    {
        File::remove_extension(FileExtension::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        TestAssetStore::activate('SearchFileTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();

        parent::tearDown();
    }

}
