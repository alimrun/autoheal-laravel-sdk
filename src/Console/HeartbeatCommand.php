<?php

namespace AutoHeal\Laravel\Console;

use AutoHeal\Laravel\AutoHeal;
use AutoHeal\Laravel\Events\EventFactory;
use Illuminate\Console\Command;

final class HeartbeatCommand extends Command
{
    use ReportsDelivery;

    protected $signature = 'autoheal:heartbeat
        {name : The heartbeat name: letters, digits, ".", "_", ":" and "-", up to 100 characters}
        {--every= : How often the heartbeat is expected, in seconds, from 60 to 604800}';

    protected $description = 'Send a named heartbeat to AutoHeal';

    public function handle(AutoHeal $autoHeal, EventFactory $eventFactory): int
    {
        $name = (string) $this->argument('name');
        $every = $this->option('every');

        if (! $eventFactory->isCheckName($name)) {
            $this->components->error('The name must be 1 to 100 letters, digits, ".", "_", ":" or "-".');

            return self::FAILURE;
        }

        if ($every !== null && (! ctype_digit($every) || ! $eventFactory->isHeartbeatInterval((int) $every))) {
            $this->components->error('The interval must be a whole number of seconds from 60 to 604800 (1 minute to 7 days).');

            return self::FAILURE;
        }

        $event = $eventFactory->heartbeat($name, $every === null ? null : (int) $every);

        return $event === null ? self::FAILURE : $this->report($autoHeal, $event);
    }
}
