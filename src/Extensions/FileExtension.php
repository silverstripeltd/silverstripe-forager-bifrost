<?php

namespace SilverStripe\ForagerBifrost\Extensions;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Convert;
use SilverStripe\ForagerBifrost\Constants\SearchFile;
use SilverStripe\ORM\DataExtension;

/**
 * @property int $ContentSize
 * @method File|$this getOwner()
 */
class FileExtension extends DataExtension
{

    /**
     * ContentSize is used for generating a report of files exceeding content size limit
     * Although this is not a real representation of actual records not indexed in Elasticsearch,
     * the common denominator is the file size exceeding the defined limit won't be ingested
     * Assuming that images and folders does not have text contents, we skip them for report generation
     */
    private static array $db = [
        'ContentSize' => 'Int',
    ];

    /**
     * When the file size is over the limit the _attachment property is empty
     *
     * @see DataObjectDocument::toArray()
     */
    public function updateSearchAttributes(array &$attributes = []): void
    {
        if (!isset($attributes['_attachment'])) {
            return;
        }

        $file = $this->getOwner();

        if (!($file instanceof Image || $file instanceof Folder) && !SearchFile::exceedsContentLimit($file)) {
            return;
        }

        $attributes['_attachment'] = '';
    }

    public function getContentSizeNice(): string
    {
        return Convert::bytes2memstring((int) $this->getOwner()->ContentSize);
    }

    public function onBeforeWrite(): void
    {
        $file = $this->getOwner();

        // Marks images and folders with zero content to exclude them from report generation
        $file->ContentSize = $file instanceof Image || $file instanceof Folder ? 0 : $file->getAbsoluteSize();
    }

}
