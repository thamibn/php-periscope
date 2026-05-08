<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;

/**
 * Per plan Appendix A.4 — the *envelope* (URL, headers, body) is captured
 * framework-agnostically in the C extension at RINIT/RSHUTDOWN.
 *
 * This hook is the Laravel *enrichment* layer: emits a `request_resolved`
 * event with route name, controller@method, route parameters, authenticated
 * user id, locale. Plus a closing `response_resolved` from RequestHandled.
 */
final readonly class RequestHook implements Hook
{
    public function __construct(
        private ExtensionBridge $bridge,
        private CallSiteResolver $callSites,
        private Dispatcher $events,
    ) {}

    public function register(): void
    {
        if (!$this->bridge->isAvailable()) {
            return;
        }

        $this->events->listen(RouteMatched::class,   $this->onRouteMatched(...));
        $this->events->listen(RequestHandled::class, $this->onRequestHandled(...));
    }

    private function onRouteMatched(RouteMatched $event): void
    {
        $route   = $event->route;
        $request = $event->request;
        $user    = $request->user();

        $this->bridge->recordEvent('request_resolved', [
            'route_name'   => $route->getName(),
            'route_uri'    => $route->uri(),
            'route_action' => $route->getActionName(),
            'methods'      => $route->methods(),
            'parameters'   => $route->parameters(),
            'middleware'   => array_values($route->gatherMiddleware()),
            'auth_user'    => $user
                ? ['class' => $user::class, 'key' => $user->getAuthIdentifier()]
                : null,
            'locale'       => $request->getLocale(),
            'has_session'  => $request->hasSession(),
            'csrf_present' => (bool) $request->header('X-CSRF-TOKEN', $request->input('_token')),
        ], $this->callSites->resolve());
    }

    private function onRequestHandled(RequestHandled $event): void
    {
        $response = $event->response;

        $this->bridge->recordEvent('response_resolved', [
            'status'  => $response->getStatusCode(),
            'content_type' => $response->headers->get('Content-Type'),
            'content_length' => (int) ($response->headers->get('Content-Length') ?? 0),
        ], null);
    }
}
