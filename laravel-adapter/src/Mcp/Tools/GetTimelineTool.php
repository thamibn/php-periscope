<?php

declare(strict_types=1);

namespace Periscope\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Time-ordered timeline of every function call and observability event for a trace.')]
final class GetTimelineTool extends DaemonTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Trace id from list_traces.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $args = $request->validate(
            ['id' => ['required', 'string']],
            ['id.required' => 'You must specify a trace id. Call list_traces first to find one.'],
        );
        return Response::json($this->daemon->getTimeline($args['id']));
    }
}
