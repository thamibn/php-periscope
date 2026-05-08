<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;

final readonly class NotificationHook implements Hook
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

        $this->events->listen(NotificationSending::class, $this->onSending(...));
        $this->events->listen(NotificationSent::class,    $this->onSent(...));
    }

    private function onSending(NotificationSending $event): void
    {
        $this->bridge->recordEvent('notification', [
            'phase'        => 'sending',
            'channel'      => $event->channel,
            'notification' => $event->notification::class,
            'notifiable'   => $this->describe($event->notifiable),
        ], $this->callSites->resolve());
    }

    private function onSent(NotificationSent $event): void
    {
        $this->bridge->recordEvent('notification', [
            'phase'        => 'sent',
            'channel'      => $event->channel,
            'notification' => $event->notification::class,
            'notifiable'   => $this->describe($event->notifiable),
        ], $this->callSites->resolve());
    }

    /** @return array{class: string, key: mixed} */
    private function describe(mixed $notifiable): array
    {
        return [
            'class' => is_object($notifiable) ? $notifiable::class : get_debug_type($notifiable),
            'key'   => is_object($notifiable) && method_exists($notifiable, 'getKey')
                ? $notifiable->getKey()
                : null,
        ];
    }
}
