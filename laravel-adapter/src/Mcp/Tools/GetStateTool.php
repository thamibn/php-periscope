<?php

declare(strict_types=1);

namespace Periscope\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Time-travel: reconstruct the call stack, scope variables, and prefix events at one microsecond inside a trace.')]
final class GetStateTool extends DaemonTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Trace id from list_traces.')
                ->required(),
            'at_micros' => $schema->integer()
                ->description('Microsecond offset within the trace.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $args = $request->validate(
            [
                'id'        => ['required', 'string'],
                'at_micros' => ['required', 'integer', 'min:0'],
            ],
            [
                'id.required'        => 'You must specify a trace id. Call list_traces first to find one.',
                'at_micros.required' => 'You must specify at_micros — the microsecond offset to scrub to. Pull it from an event in get_timeline or query_events.',
                'at_micros.integer'  => 'at_micros must be a non-negative integer.',
            ],
        );
        return Response::json($this->daemon->getState($args['id'], (int) $args['at_micros']));
    }
}
