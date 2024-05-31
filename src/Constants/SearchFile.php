<?php

namespace SilverStripe\SearchServiceBifrost\Constants;

use SilverStripe\Assets\File;
use SilverStripe\Core\Convert;

/**
 * By design this must not implement the Silverstripe Config API in order to prevent sending large files to the ingest
 * pipelines API
 *
 * Classes marked as final prevents inheritance
 */
final class SearchFile
{

    /*
     * Note: Initial limit in bytes and to be changed
     */
    public const SIZE_LIMIT = 5242880;

    public static function sizeLimit(): string
    {
        return Convert::bytes2memstring(self::SIZE_LIMIT);
    }

    public static function exceedsContentLimit(File $file): bool
    {
        return $file->getAbsoluteSize() > self::SIZE_LIMIT;
    }

}
