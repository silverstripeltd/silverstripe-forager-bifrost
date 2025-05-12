<?php

namespace SilverStripe\ForagerBifrost\Tests\Fake;

use SilverStripe\Dev\TestOnly;
use SilverStripe\Forager\Extensions\SearchServiceExtension;
use SilverStripe\ORM\DataObject;

/**
 * @property string $Title
 * @mixin SearchServiceExtension
 */
class DataObjectFakePrivate extends DataObject implements TestOnly
{

    private static string $table_name = 'Bifrost_DataObjectFakePrivate';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $extensions = [
        SearchServiceExtension::class,
    ];

    public function canView(mixed $member = null): bool
    {
        return false;
    }

}
