<?php

namespace AutoHeal\Laravel\Console;

use AutoHeal\Laravel\AutoHeal;
use AutoHeal\Laravel\Events\EventFactory;
use Illuminate\Console\Command;

final class HealthCommand extends Command
{
    use ReportsDelivery;

    protected $signature = 'autoheal:health
        {name : The health check name: letters, digits, ".", "_", ":" and "-", up to 100 characters}
        {status : up, degraded or down}
        {--latency= : How long the check took, in milliseconds}';

    protected $description = 'Report a health check result to AutoHeal';

    public function handle(AutoHeal $autoHeal, EventFactory $eventFactory): int
    {
        $name = (string) $this->argument('name');
        $status = (string) $this->argument('status');
        $latency = $this->option('latency');

        if (! $eventFactory->isCheckName($name)) {
            $this->components->error('The name must be 1 to 100 letters, digits, ".", "_", ":" or "-".');

            return self::FAILURE;
        }

        if (! $eventFactory->isCheckStatus($status)) {
            $this->components->error('The status must be up, degraded or down.');

            return self::FAILURE;
        }

        if ($latency !== null && ! ctype_digit($latency)) {
            $this->components->error('The latency must be a whole number of milliseconds.');

            return self::FAILURE;
        }

        $event = $eventFactory->healthCheck($name, $status, $latency === null ? null : (int) $latency);

        return $event === null ? self::FAILURE : $this->report($autoHeal, $event);
    }
}
