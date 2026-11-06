<?php

namespace AutoHeal\Laravel\Transport;

use AutoHeal\Laravel\Jobs\SendEvents;
use Illuminate\Contracts\Bus\Dispatcher;

final class QueueTransport implements Transport
{
    public function __construct(private Dispatcher $dispatcher)
    {
    }

    public function send(array $events): DeliveryResult
    {
        $this->dispatcher->dispatch(new SendEvents($events));

        return DeliveryResult::queued(count($events));
    }
}
