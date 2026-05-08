<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

/**
 * Every watcher (QueryHook, LogHook, CacheHook, …) implements this interface.
 *
 * The PeriscopeServiceProvider iterates registered hooks at boot and calls
 * register() on each one whose toggle in config/periscope.php is true.
 */
interface Hook
{
    /**
     * Wire up Laravel event listeners that forward to the C extension.
     */
    public function register(): void;
}
