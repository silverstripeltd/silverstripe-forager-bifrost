<?php

namespace SilverStripe\ForagerBifrost\Extensions;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Core\Extension;
use SilverStripe\ForagerBifrost\Constants\SearchFile;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormFactory;
use SilverStripe\Forms\LiteralField;
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

        $warningMessage = $this->createLargeFileWarning($file);

        if ($warningMessage) {
            // Add the warning message, and return before the notice is added (as it has duplicate content)
            $fields->unshift($warningMessage);

            return;
        }

        // If there isn't a warning message, then we'll add the general notice instead
        $fields->unshift($this->createFileLimitMessage($file));
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
                    [
                        'limit' => SearchFile::sizeLimit(),
                    ]
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
                        'Text contained within this {size} file cannot be indexed for search. '
                            . 'The file size limit for text extraction is {limit}.',
                        [
                            'size' => $file->getSize(),
                            'limit' => SearchFile::sizeLimit(),
                        ]
                    )
                )
            );
        }

        return null;
    }

}
