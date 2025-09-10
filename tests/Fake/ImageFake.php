<?php

namespace SilverStripe\ForagerBifrost\Tests\Fake;

use SilverStripe\Dev\TestOnly;
use SilverStripe\Forager\Extensions\SearchServiceExtension;
use SilverStripe\ORM\DataObject;

class ImageFake extends DataObject implements TestOnly
{

    private static string $table_name = 'Bifrost_ImageFake';

    private static array $db = [
        'URL' => 'Varchar',
    ];

    private static array $has_one = [
        'Parent' => DataObjectFake::class,
    ];

    private static array $many_many = [
        'Tags' => TagFake::class,
    ];

    private static array $extensions = [
        SearchServiceExtension::class,
    ];

}
