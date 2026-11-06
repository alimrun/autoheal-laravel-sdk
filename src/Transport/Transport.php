<?php

namespace AutoHeal\Laravel\Transport;

interface Transport
{
    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function send(array $events): DeliveryResult;
}
