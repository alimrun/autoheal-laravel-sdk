<?php

use AutoHeal\Laravel\Facades\AutoHeal;
use Illuminate\Support\Facades\Http;

it('buffers deployments, heartbeats and health checks reported from code', function () {
    acceptingAutoHeal();

    AutoHeal::deployment('2026.11.06-2', 'forge');
    AutoHeal::heartbeat('scheduler');
    AutoHeal::healthCheck('database', 'down', 1500);

    Http::assertNothingSent();

    AutoHeal::flush();

    expect(sentBatches())->toBe(1)
        ->and(collect(sentEvents())->pluck('type')->all())->toBe(['deployment', 'heartbeat', 'health_check'])
        ->and(sentEvents()[2]['severity'])->toBe('error');

    foreach (sentEvents() as $event) {
        expectValidEvent($event);
    }
});

it('ignores input the platform would refuse instead of throwing', function () {
    acceptingAutoHeal();

    AutoHeal::heartbeat('not valid');
    AutoHeal::healthCheck('database', 'sideways');
    AutoHeal::deployment('');
    AutoHeal::flush();

    Http::assertNothingSent();
});

it('sends at most once per request', function () {
    acceptingAutoHeal();

    AutoHeal::heartbeat('scheduler');
    AutoHeal::flush();
    AutoHeal::flush();

    expect(sentBatches())->toBe(1);
});
