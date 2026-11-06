<?php

namespace AutoHeal\Laravel\Console;

use AutoHeal\Laravel\AutoHeal;
use AutoHeal\Laravel\Events\EventFactory;
use Illuminate\Console\Command;

final class DeployCommand extends Command
{
    use ReportsDelivery;

    protected $signature = 'autoheal:deploy
        {release : The release that was deployed, up to 100 characters}
        {--source= : Where the deployment came from, such as github-actions}';

    protected $description = 'Report a deployment to AutoHeal';

    public function handle(AutoHeal $autoHeal, EventFactory $eventFactory): int
    {
        $source = $this->option('source');
        $event = $eventFactory->deployment((string) $this->argument('release'), is_string($source) ? $source : null);

        if ($event === null) {
            $this->components->error('The release must be 1 to 100 characters.');

            return self::FAILURE;
        }

        return $this->report($autoHeal, $event);
    }
}
