<?php

namespace SilverStripe\SearchServiceBifrost\Extensions;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormFactory;
use SilverStripe\Forms\LiteralField;
use SilverStripe\SearchServiceBifrost\Constants\SearchFile;
use SilverStripe\View\HTML;

class FileFormExtension extends Extension
{

    /**
     * Parameters follows the extension hook definition even if not all used
     *
     * @see FileFormFactory Applies to file editing only and not with folders
     */
    public function updateForm(
        Form $form,
        ?RequestHandler $controller = null,
        string $name = FormFactory::DEFAULT_NAME,
        array $context = []
    ): void {
        /** @var FieldList $fields */
        $fields = $form->Fields()->fieldByName('Editor.Details');
        $file = $context['Record'] ?? null;

        if (!$fields || !$file || $file instanceof Image) {
            return;
        }

        $fields->unshift($this->createFileLimitMessage($file));

        $warningMessage = $this->createLargeFileWarning($file);

        if (!$warningMessage) {
            return;
        }

        $fields->unshift($warningMessage);
    }

    private function createFileLimitMessage(File $file): LiteralField
    {
        return LiteralField::create(
            'LiteralFileLimitMessage',
            HTML::createTag(
                'p',
                ['class' => 'alert alert-info'],
                _t(
                    self::class . '.FILE_LIMIT_MESSAGE',
                    'Document search extraction limit is {limit}',
                    ['limit' => SearchFile::sizeLimit()]
                )
            )
        );
    }

    private function createLargeFileWarning(File $file): ?LiteralField
    {
        $fileHasExceeded = SearchFile::exceedsContentLimit($file);

        if ($fileHasExceeded) {
            return LiteralField::create(
                'LiteralLargeFileWarning',
                HTML::createTag(
                    'p',
                    ['class' => 'alert alert-warning'],
                    _t(
                        self::class . '.LARGE_FILE_WARNING',
                        'File size is {size} which exceeds the search extraction limit',
                        ['size' => $file->getSize()]
                    )
                )
            );
        }

        return null;
    }

}
