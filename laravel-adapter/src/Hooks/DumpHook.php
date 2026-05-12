<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Symfony\Component\VarDumper\VarDumper;

/**
 * Tees `dump()` / `dd()` / `var_dump()` output into the trace AND the
 * usual destination (browser HTML for HTTP, ANSI-coloured text for CLI).
 *
 * Off by default — flip `PERISCOPE_HOOK_DUMP=true` when actively debugging.
 * Earlier versions swallowed the screen output entirely; the new shape
 * keeps the on-screen dump intact so `dd()` still works as a developer
 * expects, while *also* recording every value into the trace for later
 * inspection / scrubbing.
 *
 * The trace stores the CLI-rendered form (ANSI escapes included) — small,
 * stable text, easy to render later. The on-screen output uses HtmlDumper
 * for HTTP and CliDumper for CLI so it looks the same as before this hook
 * was registered.
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

        $cloner       = new VarCloner();
        $traceDumper  = new CliDumper();              // stable text for the trace
        $isCli        = in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
        $screenDumper = $isCli ? new CliDumper() : new HtmlDumper();

        VarDumper::setHandler(function (mixed $var) use (
            $cloner,
            $traceDumper,
            $screenDumper,
        ): void {
            // Clone once, render twice — VarDumper's Data is reusable.
            $data = $cloner->cloneVar($var);

            $rendered = $traceDumper->dump($data, true) ?? '';
            $this->bridge->recordEvent('dump', [
                'rendered' => $rendered,
            ], $this->callSites->resolve());

            // Emit to the original destination so dump()/dd() still work
            // visibly. dd() calls die() after dump(); the previous version
            // of this hook left the user staring at a blank page.
            $screenDumper->dump($data);
        });
    }
}
