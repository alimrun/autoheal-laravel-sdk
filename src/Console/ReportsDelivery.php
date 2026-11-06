<?php

namespace AutoHeal\Laravel\Console;

use AutoHeal\Laravel\AutoHeal;
use AutoHeal\Laravel\Transport\DeliveryStatus;

/**
 * Sends one event straight away and says what AutoHeal answered. The exit code stays successful
 * whatever AutoHeal answers, so an AutoHeal outage never fails a deploy pipeline or the scheduler.
 */
trait ReportsDelivery
{
    /**
     * @param  array<string, mixed>  $event
     */
    private function report(AutoHeal $autoHeal, array $event): int
    {
        $result = $autoHeal->sendNow([$event]);

        if ($result->status === DeliveryStatus::ACCEPTED) {
            $this->components->info($result->describe());
        } else {
            $this->components->warn($result->describe());
        }

        return self::SUCCESS;
    }
}
