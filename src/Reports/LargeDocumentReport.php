<?php

namespace SilverStripe\ForagerBifrost\Reports;

use SilverStripe\Assets\File;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forager\Extensions\SearchServiceExtension;
use SilverStripe\ForagerBifrost\Constants\SearchFile;
use SilverStripe\Model\List\SS_List;
use SilverStripe\Reports\Report;

class LargeDocumentReport extends Report
{

    // @phpcs:ignore
    protected $title = 'Silverstripe Search large documents report';

    // @phpcs:ignore
    protected $description = 'Documents excluded for content ingestion in Silverstripe Search which exceeds %s';

    // @phpcs:ignore
    protected $dataClass = File::class;

    public function description(): string
    {
        if (!$this->isReportActive()) {
            return 'This report requires the SEARCH_INDEX_FILES environment variable and file extension.';
        }

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

    /**
     * @inheritDoc
     */
    public function canView($member = null): bool
    {
        return $this->isReportActive();
    }

    /**
     * This report can only be active if the required extension is enabled
     */
    private function isReportActive(): bool
    {
        $fileClass = Injector::inst()->get(File::class);

        return $fileClass->has_extension(SearchServiceExtension::class);
    }

}
