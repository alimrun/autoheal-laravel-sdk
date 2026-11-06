<?php

namespace AutoHeal\Laravel\Console;

use AutoHeal\Laravel\AutoHeal;
use AutoHeal\Laravel\Events\EventFactory;
use AutoHeal\Laravel\Transport\DeliveryResult;
use AutoHeal\Laravel\Transport\DeliveryStatus;
use AutoHeal\Laravel\Transport\HttpTransport;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Sends one test exception straight away and says what AutoHeal answered. It ignores any pause and
 * does not start one, since it exists to check the connection after fixing it.
 */
final class TestCommand extends Command
{
    protected $signature = 'autoheal:test';

    protected $description = 'Send a test exception to AutoHeal and report whether it was accepted';

    public function handle(AutoHeal $autoHeal, EventFactory $eventFactory, HttpTransport $transport): int
    {
        if (! $autoHeal->isEnabled()) {
            $this->components->error(DeliveryResult::disabled()->describe());

            return self::FAILURE;
        }

        $event = $eventFactory->exception(new RuntimeException('AutoHeal test exception from autoheal:test'));
        $result = $transport->send([$event]);

        if ($result->status !== DeliveryStatus::ACCEPTED) {
            $this->components->error($result->describe());

            return self::FAILURE;
        }

        $this->components->info("AutoHeal accepted the test event {$event['event_id']}.");

        return self::SUCCESS;
    }
}
