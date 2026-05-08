<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;

/**
 * Captures every Blade `view(...)` render. Also detects Inertia.js renders
 * by sniffing the root view's `$page` payload (`['component', 'props',
 * 'url', 'version']`) and emits a separate `inertia` event with the SPA
 * component name + prop keys.
 *
 * Vue/React/Svelte SPA components themselves render client-side, after PHP
 * has already returned, so they never appear in the trace — but the props
 * we shipped to them do, which is the actionable signal.
 */
final readonly class ViewHook implements Hook
{
    public function __construct(
        private ExtensionBridge $bridge,
        private CallSiteResolver $callSites,
        private ViewFactory $views,
    ) {}

    public function register(): void
    {
        if (!$this->bridge->isAvailable()) {
            return;
        }

        $this->views->composer('*', function (View $view): void {
            $callSite = $this->callSites->resolve();
            $data     = $view->getData();

            $this->bridge->recordEvent('view', [
                'name'      => $view->name(),
                'path'      => $view->getPath(),
                'data_keys' => array_keys($data),
            ], $callSite);

            if (($inertia = $this->extractInertia($data)) !== null) {
                $this->bridge->recordEvent('inertia', $inertia, $callSite);
            }
        });
    }

    /**
     * Detect an Inertia.js root-view render. Inertia\Response::toResponse()
     * passes a `$page` array to the root Blade template:
     *   ['component' => 'User/Show', 'props' => [...], 'url' => '/u/42',
     *    'version' => '...']
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function extractInertia(array $data): ?array
    {
        $page = $data['page'] ?? null;
        if (!is_array($page) || !isset($page['component'])) {
            return null;
        }

        $props = is_array($page['props'] ?? null) ? $page['props'] : [];

        return [
            'component'  => (string) $page['component'],
            'url'        => (string) ($page['url'] ?? ''),
            'version'    => (string) ($page['version'] ?? ''),
            'prop_keys'  => array_keys($props),
            'prop_count' => count($props),
        ];
    }
}
