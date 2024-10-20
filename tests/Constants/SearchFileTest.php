<?php

namespace SilverStripe\ForagerBifrost\Tests\Constants;

use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ForagerBifrost\Constants\SearchFile;

class SearchFileTest extends SapphireTest
{

    // @phpcs:ignore
    protected $usesDatabase = true;

    public function testSizeLimit(): void
    {
        $this->assertEquals('15 MB', SearchFile::sizeLimit());
    }

    public function testExceedsContentLimit(): void
    {
        $file = File::create();
        // Creates a 5 MB file
        $file->setFromString(str_repeat('12345', pow(1024, 2)), 'Uploads/testfile.txt');
        $file->write();

        $this->assertEquals('5 MB', $file->getSize());
        $this->assertFalse(SearchFile::exceedsContentLimit($file));

        // Creates a 16 MB file
        $file->setFromString(str_repeat('0123456789012345', pow(1024, 2)), 'Uploads/testfile.txt');
        $file->write();

        $this->assertEquals('16 MB', $file->getSize());
        $this->assertTrue(SearchFile::exceedsContentLimit($file));
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
