<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;

final readonly class HttpClientHook implements Hook
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

        $this->events->listen(RequestSending::class,    $this->onSending(...));
        $this->events->listen(ResponseReceived::class,  $this->onResponse(...));
        $this->events->listen(ConnectionFailed::class,  $this->onFailed(...));
    }

    private function onSending(RequestSending $event): void
    {
        $req = $event->request;
        $this->bridge->recordEvent('http', [
            'phase'        => 'sending',
            'method'       => $req->method(),
            'url'          => $req->url(),
            'headers'      => $req->headers(),
            'body_bytes'   => strlen((string) $req->body()),
        ], $this->callSites->resolve());
    }

    private function onResponse(ResponseReceived $event): void
    {
        $req = $event->request;
        $res = $event->response;
        $this->bridge->recordEvent('http', [
            'phase'        => 'received',
            'method'       => $req->method(),
            'url'          => $req->url(),
            'status'       => $res->status(),
            'headers'      => $res->headers(),
            'body_bytes'   => strlen((string) $res->body()),
        ], $this->callSites->resolve());
    }

    private function onFailed(ConnectionFailed $event): void
    {
        $req = $event->request;
        $this->bridge->recordEvent('http', [
            'phase'   => 'failed',
            'method'  => $req->method(),
            'url'     => $req->url(),
        ], $this->callSites->resolve());
    }
}
