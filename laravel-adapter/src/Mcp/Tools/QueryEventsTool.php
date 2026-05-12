<?php

declare(strict_types=1);

namespace Periscope\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Query a trace\'s observability events with optional type, JSON-path filter, and de-dup grouping.')]
final class QueryEventsTool extends DaemonTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Trace id from list_traces.')
                ->required(),
            'type' => $schema->string()
                ->description('Optional event type filter (sql, log, cache, http, redis, job, event, mail, exception, model, …).'),
            'filter' => $schema->string()
                ->description('Optional JSON-path filter, e.g. `payload.level:error AND payload.context.user_id:42`. Unquoted values are case-insensitive substring + numeric equality; quoted are exact. AND is the only operator.'),
            'group' => $schema->boolean()
                ->description('When true, collapse identical events (modulo timestamps + per-event timing fields) into groups.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $args = $request->validate(
            [
                'id'     => ['required', 'string'],
                'type'   => ['nullable', 'string'],
                'filter' => ['nullable', 'string'],
                'group'  => ['nullable', 'boolean'],
            ],
            ['id.required' => 'You must specify a trace id. Call list_traces first to find one.'],
        );
        return Response::json($this->daemon->listEvents(
            $args['id'],
            isset($args['type']) && $args['type'] !== '' ? $args['type'] : null,
            isset($args['filter']) && $args['filter'] !== '' ? $args['filter'] : null,
            (bool) ($args['group'] ?? false),
        ));
    }
}
