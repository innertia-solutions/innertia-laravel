<?php

namespace Innertia\Olimpo\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Innertia\Olimpo\Exceptions\OlimpoExceptionReporter;

class ReportFailedJob
{
    public function handle(JobFailed $event): void
    {
        OlimpoExceptionReporter::report($event->exception, [
            'source'         => 'queue',
            'job'            => $event->job->getName(),
            'queue'          => $event->job->getQueue(),
            'connection'     => $event->connectionName,
            'payload'        => $event->job->payload(),
        ]);
    }
}
