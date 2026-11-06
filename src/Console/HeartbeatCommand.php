<?php

namespace AutoHeal\Laravel\Console;

use AutoHeal\Laravel\AutoHeal;
use AutoHeal\Laravel\Events\EventFactory;
use Illuminate\Console\Command;

final class HeartbeatCommand extends Command
{
    use ReportsDelivery;

    protected $signature = 'autoheal:heartbeat
        {name : The heartbeat name: letters, digits, ".", "_", ":" and "-", up to 100 characters}';

    protected $description = 'Send a named heartbeat to AutoHeal';

    public function handle(AutoHeal $autoHeal, EventFactory $eventFactory): int
    {
        $event = $eventFactory->heartbeat((string) $this->argument('name'));

        if ($event === null) {
            $this->components->error('The name must be 1 to 100 letters, digits, ".", "_", ":" or "-".');

            return self::FAILURE;
        }

        return $this->report($autoHeal, $event);
    }
}
