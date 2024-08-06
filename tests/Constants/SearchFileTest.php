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
        $this->assertEquals('20 MB', SearchFile::sizeLimit());
    }

    public function testExceedsContentLimit(): void
    {
        $file = File::create();
        // Creates a 5 MB file
        $file->setFromString(str_repeat('12345', pow(1024, 2)), 'Uploads/testfile.txt');
        $file->write();

        $this->assertEquals('5 MB', $file->getSize());
        $this->assertFalse(SearchFile::exceedsContentLimit($file));

        // Creates a 21 MB file
        $file->setFromString(str_repeat('012345678901234567890', pow(1024, 2)), 'Uploads/testfile.txt');
        $file->write();

        $this->assertEquals('21 MB', $file->getSize());
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
