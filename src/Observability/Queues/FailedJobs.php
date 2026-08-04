<?php

namespace Innertia\Observability\Queues;

use Illuminate\Support\Str;

class FailedJobs
{
    public static function list(int $limit = 100): array
    {
        try {
            $all = app('queue.failer')->all();
        } catch (\Throwable) {
            return [];
        }
        return array_map([self::class, 'map'], array_slice($all, 0, $limit));
    }

    public static function map(object $job): array
    {
        $payload = json_decode($job->payload ?? '{}', true) ?: [];
        return [
            'id'         => $job->uuid ?? ($job->id ?? null),
            'connection' => $job->connection ?? null,
            'queue'      => $job->queue ?? null,
            'name'       => $payload['displayName'] ?? ($payload['job'] ?? 'Job'),
            'exception'  => self::firstLine((string) ($job->exception ?? '')),
            'failed_at'  => $job->failed_at ?? null,
        ];
    }

    private static function firstLine(string $exception): string
    {
        $line = strtok($exception, "\n");
        return Str::limit($line !== false && $line !== '' ? $line : $exception, 200);
    }
}
