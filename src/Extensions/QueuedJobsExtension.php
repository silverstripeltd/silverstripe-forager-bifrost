<?php

namespace SilverStripe\ForagerBifrost\Extensions;

use SilverStripe\Core\Extension;
use Silverstripe\Search\Client\Exception\ClientException;
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
        if (!$e instanceof ClientException) {
            return;
        }

        if (!method_exists($e, 'getResponse')) {
            $job->addMessage(
                json_encode(['ResponseCode' => $e->getCode(), 'ResponseMessage' => $e->getMessage()]),
                'ERROR'
            );

            return;
        }

        $job->addMessage(json_encode([
            'ResponseCode' => $e->getCode(),
            'ResponseMessage' => $e->getMessage(),
            'ApiResponse' => (string) $e->getResponse()->getBody(),
        ]), 'ERROR');
    }

}
