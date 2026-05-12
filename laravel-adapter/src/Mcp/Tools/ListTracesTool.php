<?php

declare(strict_types=1);

namespace Periscope\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List recent recorded HTTP requests (traces) most-recent first.')]
final class ListTracesTool extends DaemonTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max number of traces to return (default 50).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $args = $request->validate(
            ['limit' => ['nullable', 'integer', 'min:1', 'max:500']],
            ['limit.integer' => 'limit must be an integer between 1 and 500.'],
        );
        return Response::json($this->daemon->listTraces($args['limit'] ?? null));
    }
}
