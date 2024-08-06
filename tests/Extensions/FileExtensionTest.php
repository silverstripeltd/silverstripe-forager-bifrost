<?php

namespace SilverStripe\ForagerBifrost\Tests\Extensions;

use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forager\Extensions\SearchServiceExtension;
use SilverStripe\ForagerBifrost\Extensions\FileExtension;

class FileExtensionTest extends SapphireTest
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

    public function testUpdateSearchAttribute(): void
    {
        $attributes = ['title' => 'testfile'];
        $extension = new FileExtension();
        $extension->updateSearchAttributes($attributes);

        $this->assertEqualsCanonicalizing(['title' => 'testfile'], $attributes);

        $content = 'sample text file content';
        $attributes['_attachment'] = $content;
        $file = File::create();
        $file->setFromString($content, 'Uploads/testfile.txt');
        $file->write();
        $extension->setOwner($file);
        $extension->updateSearchAttributes($attributes);

        $this->assertEqualsCanonicalizing(
            [
                'title' => 'testfile',
                '_attachment' => $content,
            ],
            $attributes
        );

        // Creates a 21MB file which exceeds limit of 20MB
        $file->setFromString(str_repeat('012345678901234567890', pow(1024, 2)), 'Uploads/testfile.txt');
        $file->write();
        $extension->updateSearchAttributes($attributes);

        $this->assertEqualsCanonicalizing(
            [
                'title' => 'testfile',
                '_attachment' => '',
            ],
            $attributes
        );
    }

    public function testContentSizeNice(): void
    {
        $file = File::create();
        $file->setFromString(str_repeat('2K', 1024), 'Uploads/testfile.txt');
        $file->write();
        $extension = new FileExtension();
        $extension->setOwner($file);

        $this->assertEquals('2K', $extension->getContentSizeNice());

        $file->setFromString(str_repeat('3MB', pow(1024, 2)), 'Uploads/testfile.txt');
        $file->write();

        $this->assertEquals('3M', $extension->getContentSizeNice());
    }

    public function testOnBeforeWrite(): void
    {
        $folder = Folder::find_or_make('Uploads');
        $extension = new FileExtension();
        $extension->setOwner($folder);
        $extension->onBeforeWrite();

        $this->assertEquals(0, $folder->ContentSize);

        $file = File::create();
        $file->setFromString(str_repeat('X', 1024), 'Uploads/testfile.txt');
        $extension->setOwner($file);
        $extension->onBeforeWrite();

        $this->assertEquals(1024, $file->ContentSize);
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
