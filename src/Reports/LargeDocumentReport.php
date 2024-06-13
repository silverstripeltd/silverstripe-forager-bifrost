<?php

namespace SilverStripe\ForagerBifrost\Reports;

use SilverStripe\Assets\File;
use SilverStripe\ForagerBifrost\Constants\SearchFile;
use SilverStripe\ORM\SS_List;
use SilverStripe\Reports\Report;

class LargeDocumentReport extends Report
{

    // @phpcs:ignore
    protected $title = 'Search service large documents report';

    // @phpcs:ignore
    protected $description = 'Documents excluded for content ingestion in search service which exceeds %s';

    // @phpcs:ignore
    protected $dataClass = File::class;

    public function description(): string
    {
        return sprintf(
            $this->description,
            SearchFile::sizeLimit()
        );
    }

    public function columns(): array
    {
        return [
            'ID' => 'ID',
            'Title' => 'Title',
            'Name' => 'Name',
            'ContentSizeNice' => 'Size',
        ];
    }

    public function sourceRecords(array $params = []): SS_List
    {
        return File::get()
            ->filter(['ContentSize:GreaterThan' => SearchFile::SIZE_LIMIT])
            ->sort(['Created' => 'DESC']);
    }

}
