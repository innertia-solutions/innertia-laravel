<?php

namespace Innertia\Telemetry\Mcp;

use Innertia\Telemetry\Mcp\Prompts\ReviewCasePrompt;
use Innertia\Telemetry\Mcp\Tools\EmailLogTool;
use Innertia\Telemetry\Mcp\Tools\FindCaseByTraceTool;
use Innertia\Telemetry\Mcp\Tools\GetCaseTool;
use Innertia\Telemetry\Mcp\Tools\GetTraceTool;
use Innertia\Telemetry\Mcp\Tools\ListCasesTool;
use Innertia\Telemetry\Mcp\Tools\SearchLogsTool;
use Innertia\Telemetry\Mcp\Tools\SearchTracesBySessionTool;
use Innertia\Telemetry\Mcp\Tools\SearchTracesByUserTool;
use Innertia\Telemetry\Mcp\Tools\ServerHealthTool;
use Innertia\Telemetry\Mcp\Tools\WhoIsOnlineTool;
use Laravel\Mcp\Server;

class ObservabilityServer extends Server
{
    protected string $name = 'Innertia Observability';

    protected string $version = '1.0.0';

    protected string $instructions = 'Lee casos de error, traces, logs, correos, presencia y salud del server de este producto Innertia. Usa build-review-prompt (review_case) para obtener un prompt de revisión de un caso.';

    /** @var array<int, class-string> */
    protected array $tools = [
        ListCasesTool::class,
        GetCaseTool::class,
        SearchLogsTool::class,
        GetTraceTool::class,
        FindCaseByTraceTool::class,
        SearchTracesByUserTool::class,
        SearchTracesBySessionTool::class,
        WhoIsOnlineTool::class,
        EmailLogTool::class,
        ServerHealthTool::class,
    ];

    /** @var array<int, class-string> */
    protected array $prompts = [
        ReviewCasePrompt::class,
    ];

    /** @var array<int, class-string> */
    protected array $resources = [];
}
