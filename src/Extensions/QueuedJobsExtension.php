<?php

namespace SilverStripe\ForagerBifrost\Extensions;

use Psr\Http\Client\ClientExceptionInterface;
use SilverStripe\Core\Extension;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Throwable;

class QueuedJobsExtension extends Extension
{

    /**
     * Log response body for search client errors in queued jobs
     *
     * @param QueuedJobDescriptor $jobDescriptor
     * @param QueuedJob $job
     * @param Throwable $e
     * @return void
     */
    public function updateJobDescriptorAndJobOnException(
        QueuedJobDescriptor $jobDescriptor,
        QueuedJob $job,
        Throwable $e
    ): void {
        if (!$e instanceof ClientExceptionInterface) {
            return;
        }

        $job->addMessage(
            json_encode(['ResponseCode' => $e->getCode(), 'ResponseMessage' => $e->getMessage()]),
            'ERROR'
        );
    }

}
