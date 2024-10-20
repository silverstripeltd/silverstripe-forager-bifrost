<?php

namespace SilverStripe\ForagerBifrost\Constants;

use SilverStripe\Assets\File;

/**
 * By design this must not implement the Silverstripe Config API in order to prevent sending large files to the ingest
 * pipelines API
 *
 * Classes marked as final prevents inheritance
 */
final class SearchFile
{

    /**
     * File size limit in bytes
     */
    public const SIZE_LIMIT = 15 * 1024 * 1024;

    public static function sizeLimit(): string
    {
        # Calculation taken from File::format_size()
        return round((self::SIZE_LIMIT / 1024 / 1024) * 10) / 10 . ' MB';
    }

    public static function exceedsContentLimit(File $file): bool
    {
        return $file->getAbsoluteSize() > self::SIZE_LIMIT;
    }

}
