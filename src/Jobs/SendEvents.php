<?php

namespace AutoHeal\Laravel\Jobs;

use AutoHeal\Laravel\AutoHeal;
use AutoHeal\Laravel\Transport\DeliveryStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Carries a batch whose event ids were fixed at capture time, so a retry after a lost answer is
 * stored once. A failed attempt releases the job instead of throwing: a thrown exception would be
 * reported by the worker, captured again, and fill the host's failed jobs with AutoHeal's outages.
 */
final class SendEvents implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(public array $events)
    {
    }

    public function handle(AutoHeal $autoHeal): void
    {
        $result = $autoHeal->sendNow($this->events);

        if ($result->status === DeliveryStatus::ACCEPTED) {
            return;
        }

        $attempt = $this->attempts();

        if ($attempt < $this->tries) {
            $this->release($this->backoff[$attempt - 1] ?? end($this->backoff));
        }
    }
}
