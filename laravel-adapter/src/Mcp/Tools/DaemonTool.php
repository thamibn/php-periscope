<?php

declare(strict_types=1);

namespace Periscope\Laravel\Mcp\Tools;

use Laravel\Mcp\Server\Tool;
use Periscope\Laravel\Mcp\DaemonClient;

/**
 * Base class for every periscope MCP tool — owns the single shared
 * dependency (the daemon HTTP client) so subclasses focus on their
 * schema + handler. Following Laravel docs, dependency injection
 * still happens through the container per-tool; this abstract just
 * removes a constructor repetition across 8 tool classes.
 */
abstract class DaemonTool extends Tool
{
    public function __construct(
        protected readonly DaemonClient $daemon,
    ) {}
}
