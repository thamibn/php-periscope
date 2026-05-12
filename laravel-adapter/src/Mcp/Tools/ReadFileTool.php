<?php

declare(strict_types=1);

namespace Periscope\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Read a slice of a project source file, optionally centred on a line, so the AI can reason about the code a trace event points at.')]
final class ReadFileTool extends DaemonTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('Absolute or project-rooted path to the file.')
                ->required(),
            'line' => $schema->integer()
                ->description('Optional 1-based line number to centre the slice on.'),
            'radius' => $schema->integer()
                ->description('Lines of context above and below `line` (default 24).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $args = $request->validate(
            [
                'path'   => ['required', 'string'],
                'line'   => ['nullable', 'integer', 'min:1'],
                'radius' => ['nullable', 'integer', 'min:0', 'max:512'],
            ],
            [
                'path.required'  => 'You must specify a file path. Pass an absolute path or one rooted in the Laravel project.',
                'radius.max'     => 'radius must be at most 512 lines.',
            ],
        );
        return Response::json($this->daemon->readFile(
            $args['path'],
            isset($args['line']) ? (int) $args['line'] : null,
            isset($args['radius']) ? (int) $args['radius'] : null,
        ));
    }
}
