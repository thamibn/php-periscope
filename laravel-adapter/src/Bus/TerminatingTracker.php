<?php

declare(strict_types=1);

namespace Periscope\Laravel\Bus;

use Illuminate\Contracts\Foundation\Application;
use Periscope\Laravel\Support\CallSiteResolver;
use ReflectionObject;

/**
 * Wraps Application::terminating() so observability events fired from
 * post-response callbacks (anything registered via $app->terminating(),
 * including dispatch_after_http_response_sent, dispatchAfterResponse,
 * Symfony's TerminableInterface, etc.) are attributed back to the line
 * that *registered* the callback — not to public/index.php:75 inside
 * Laravel's terminate kernel.
 *
 * Two pieces of state surface to the rest of the adapter:
 *
 *   - inTerminating()  — true while a wrapped terminating callback is on
 *                        the stack. ExtensionBridge::recordEvent flips
 *                        every event payload's `after_response` flag so
 *                        the UI can show an "after response" badge.
 *
 *   - peekSite()       — the call site captured at registration time, used
 *                        to override the live backtrace when the controller
 *                        frame has already returned.
 *
 * Implementation note: Application is the container itself, so we can't
 * decorate it via $this->app->extend(). Instead we replace the private
 * `$terminatingCallbacks` array with an ArrayObject subclass that
 * intercepts append (`$arr[] = $cb`) — which is how Laravel's
 * `terminating()` method writes — and wraps every callback at registration
 * time. This is brittle in principle but the property name has been stable
 * since Laravel 5.x.
 */
final class TerminatingTracker
{
    private bool $inTerminating = false;
    private ?array $currentSite = null;

    /** @var list<array{inTerminating: bool, site: ?array<string, mixed>}> */
    private array $stack = [];

    public function __construct(
        private readonly Application $app,
        private readonly CallSiteResolver $resolver,
    ) {}

    /**
     * Replace Application's terminatingCallbacks with our wrapping proxy.
     * Idempotent — second call is a no-op.
     */
    public function install(): void
    {
        $reflection = new ReflectionObject($this->app);
        if (!$reflection->hasProperty('terminatingCallbacks')) {
            return;
        }
        $prop = $reflection->getProperty('terminatingCallbacks');
        $prop->setAccessible(true);
        $existing = $prop->getValue($this->app);

        if ($existing instanceof TerminatingCallbacksProxy) {
            return;
        }

        $proxy = new TerminatingCallbacksProxy(
            existing: is_array($existing) ? $existing : [],
            resolver: $this->resolver,
            tracker:  $this,
        );
        $prop->setValue($this->app, $proxy);
    }

    public function inTerminating(): bool
    {
        return $this->inTerminating;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function peekSite(): ?array
    {
        return $this->currentSite;
    }

    /**
     * @internal Called by TerminatingCallbacksProxy when a wrapped callback
     *           starts running.
     *
     * @param array<string, mixed>|null $site
     */
    public function pushSite(?array $site): void
    {
        $this->stack[] = ['inTerminating' => $this->inTerminating, 'site' => $this->currentSite];
        $this->inTerminating = true;
        $this->currentSite = $site;
    }

    /**
     * @internal Called by TerminatingCallbacksProxy when a wrapped callback
     *           returns.
     */
    public function popSite(): void
    {
        $prev = array_pop($this->stack);
        if ($prev === null) {
            $this->inTerminating = false;
            $this->currentSite = null;
            return;
        }
        $this->inTerminating = $prev['inTerminating'];
        $this->currentSite = $prev['site'];
    }
}
