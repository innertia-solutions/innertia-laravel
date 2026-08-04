<?php

namespace Innertia\Observability\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Innertia\Observability\Queues\FailedJobs;
use Innertia\Observability\Queues\QueueDepth;

class QueuesController extends Controller
{
    public function overview(): JsonResponse
    {
        return response()->json(['depth' => QueueDepth::collect(), 'failed' => $this->failedCount()]);
    }

    public function failed(): JsonResponse
    {
        return response()->json(['jobs' => FailedJobs::list()]);
    }

    public function retry(string $id): JsonResponse
    {
        return $this->run('queue:retry', ['id' => [$id]]);
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->run('queue:forget', ['id' => $id]);
    }

    public function retryAll(): JsonResponse
    {
        return $this->run('queue:retry', ['id' => ['all']]);
    }

    public function flush(): JsonResponse
    {
        return $this->run('queue:flush', []);
    }

    private function run(string $command, array $args): JsonResponse
    {
        try {
            Artisan::call($command, $args);
            return response()->json(['ok' => true, 'output' => trim(Artisan::output())]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    private function failedCount(): int
    {
        try {
            return count(app('queue.failer')->all());
        } catch (\Throwable) {
            return 0;
        }
    }
}
