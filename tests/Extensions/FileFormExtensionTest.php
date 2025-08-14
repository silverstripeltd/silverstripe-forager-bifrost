<?php

namespace SilverStripe\ForagerBifrost\Tests\Extensions;

use ReflectionMethod;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forager\Extensions\SearchServiceExtension;
use SilverStripe\ForagerBifrost\Extensions\FileExtension;
use SilverStripe\ForagerBifrost\Extensions\FileFormExtension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormFactory;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;

class FileFormExtensionTest extends SapphireTest
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

    public function testUpdateForm(): void
    {
        $form = Form::create();
        $context = [];

        $extension = new FileFormExtension();
        $extension->updateForm($form, null, FormFactory::DEFAULT_NAME, $context);

        $this->assertNull($form->Fields()->fieldByName('Editor.Details'));

        $fields = new FieldList(new TabSet('Editor', new Tab('Details')));
        $form->setFields($fields);
        $extension->updateForm($form, null, FormFactory::DEFAULT_NAME, $context);

        $fields = $form->Fields();
        $this->assertInstanceOf(Tab::class, $fields->fieldByName('Editor.Details'));
        $this->assertNull($fields->fieldByName('Editor.Details.LiteralFileLimitMessage'));

        $file = File::create();
        $file->setFromString('Example text file content', 'Uploads/testfile.txt');
        $file->write();
        $context['Record'] = $file;

        $extension->updateForm($form, null, FormFactory::DEFAULT_NAME, $context);
        $fields = $form->Fields();

        $this->assertInstanceOf(
            LiteralField::class,
            $fields->fieldByName('Editor.Details.LiteralFileLimitMessage')
        );
        $this->assertNull($fields->fieldByName('Editor.Details.LiteralLargeFileWarning'));

        // Creates a 16 MB file which exceeds limit of 15 MB
        $file->setFromString(str_repeat('012345678901234567890', pow(1024, 2)), 'Uploads/testfile.txt');
        $file->write();
        $context['Record'] = $file;
        $extension->updateForm($form, null, FormFactory::DEFAULT_NAME, $context);
        $fields = $form->Fields();

        $this->assertInstanceOf(
            LiteralField::class,
            $fields->fieldByName('Editor.Details.LiteralLargeFileWarning')
        );

        // Transparent 1px gif
        $gif = Image::create();
        $gif->setFromString(
            base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==', true),
            'Uploads/testfile.gif'
        );
        $gif->write();

        $this->assertEquals('image/gif', $gif->getMimeType());

        $context['Record'] = $gif;
        $extension->updateForm($form, null, FormFactory::DEFAULT_NAME, $context);
        $fields = $form->Fields();

        $form = Form::create();
        $form->setFields(new FieldList(new TabSet('Editor', new Tab('Details'))));
        $extension->updateForm($form, null, FormFactory::DEFAULT_NAME, $context);
        $fields = $form->Fields();

        $this->assertNull($fields->fieldByName('Editor.Details.LiteralFileLimitMessage'));
        $this->assertNull($fields->fieldByName('Editor.Details.LiteralLargeFileWarning'));
    }

    public function testCreateFileLimitMessage(): void
    {
        $extension = new FileFormExtension();
        $reflection = new ReflectionMethod($extension, 'createFileLimitMessage');
        $reflection->setAccessible(true);
        $file = File::create();
        $file->setFromString('Example text file content', 'Uploads/testfile.txt');
        $file->write();
        /** @var LiteralField|null $field */
        $field = $reflection->invoke($extension, $file);

        $this->assertInstanceOf(LiteralField::class, $field);
        $this->assertEquals(
            '<p class="alert alert-info">Document search extraction limit is 15 MB</p>',
            $field->getContent()
        );
    }

    public function testCreateLargeFileWarning(): void
    {
        $extension = new FileFormExtension();
        $reflection = new ReflectionMethod($extension, 'createLargeFileWarning');
        $reflection->setAccessible(true);
        $file = File::create();
        $file->setFromString('Example text file content', 'Uploads/testfile.txt');
        $file->write();
        /** @var LiteralField|null $field */
        $field = $reflection->invoke($extension, $file);

        $this->assertNull($field);

        $file = File::create();
        // Creates a 16 MB file which exceeds limit of 15 MB
        $file->setFromString(str_repeat('0123456789012345', pow(1024, 2)), 'Uploads/testfile.txt');
        $file->write();
        $field = $reflection->invoke($extension, $file);

        $this->assertInstanceOf(LiteralField::class, $field);
        $this->assertEquals(
            '<p class="alert alert-warning">Text contained within this 16 MB file cannot be indexed for search.'
                . ' The file size limit for text extraction is 15 MB.</p>',
            $field->getContent()
        );
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
