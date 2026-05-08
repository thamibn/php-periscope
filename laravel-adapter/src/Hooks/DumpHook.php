<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\VarDumper;

/**
 * Captures global dump()/dd() output to the trace instead of the response.
 *
 * Off by default — flip `PERISCOPE_HOOK_DUMP=true` when actively debugging.
 * Replaces VarDumper's handler so dump output is invisible in the browser/CLI
 * and only visible in the periscope timeline.
 */
final readonly class DumpHook implements Hook
{
    public function __construct(
        private ExtensionBridge $bridge,
        private CallSiteResolver $callSites,
    ) {}

    public function register(): void
    {
        if (!$this->bridge->isAvailable()) {
            return;
        }

        $cloner = new VarCloner();
        $dumper = new CliDumper();

        VarDumper::setHandler(function (mixed $var) use ($cloner, $dumper): void {
            $rendered = $dumper->dump($cloner->cloneVar($var), true) ?? '';

            $this->bridge->recordEvent('dump', [
                'rendered' => $rendered,
            ], $this->callSites->resolve());
        });
    }
}
