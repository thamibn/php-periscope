<?php

declare(strict_types=1);

namespace Periscope\Laravel\Hooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Periscope\Laravel\Bridge\ExtensionBridge;
use Periscope\Laravel\Support\CallSiteResolver;
use Symfony\Component\Mime\Email;

final readonly class MailHook implements Hook
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

        $this->events->listen(MessageSending::class, $this->onSending(...));
        $this->events->listen(MessageSent::class,    $this->onSent(...));
    }

    private function onSending(MessageSending $event): void
    {
        $this->emit('sending', $event->message);
    }

    private function onSent(MessageSent $event): void
    {
        $this->emit('sent', $event->message);
    }

    private function emit(string $phase, Email $message): void
    {
        $this->bridge->recordEvent('mail', [
            'phase'   => $phase,
            'subject' => $message->getSubject() ?? '',
            'from'    => array_map(static fn ($a) => $a->getAddress(), $message->getFrom()),
            'to'      => array_map(static fn ($a) => $a->getAddress(), $message->getTo()),
            'cc'      => array_map(static fn ($a) => $a->getAddress(), $message->getCc()),
            'bcc'     => array_map(static fn ($a) => $a->getAddress(), $message->getBcc()),
            'reply_to'=> array_map(static fn ($a) => $a->getAddress(), $message->getReplyTo()),
            'html_bytes' => strlen((string) $message->getHtmlBody()),
            'text_bytes' => strlen((string) $message->getTextBody()),
        ], $this->callSites->resolve());
    }
}
