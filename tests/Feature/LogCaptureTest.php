<?php

use AutoHeal\Laravel\Facades\AutoHeal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('sends an error log as an error log event, with the password in its context redacted', function () {
    acceptingAutoHeal();

    Log::error('payment provider timed out', ['password' => 'hunter2', 'order' => 'A-10023']);
    AutoHeal::flush();

    $event = sentEvents()[0];

    expect(sentEvents())->toHaveCount(1)
        ->and($event)->toMatchArray(['type' => 'error_log', 'severity' => 'error', 'message' => 'payment provider timed out'])
        ->and($event['body'])->toBe(['level' => 'error', 'channel' => config('logging.default')])
        ->and($event['context']['log'])->toBe(['password' => '[redacted]', 'order' => 'A-10023'])
        ->and((string) json_encode(sentEvents()))->not->toContain('hunter2');

    expectValidEvent($event);
});

it('sends an exception once when Laravel also logs it', function () {
    acceptingAutoHeal();
    Route::get('/logs-and-throws', fn () => throw new RuntimeException('thrown once'));

    $this->get('/logs-and-throws')->assertStatus(500);
    Log::error('Wrapped', ['exception' => new RuntimeException('logged with an exception')]);
    AutoHeal::flush();

    expect(sentEvents())->toHaveCount(1)
        ->and(sentEvents()[0]['type'])->toBe('exception');
});

it('ignores messages below the configured level', function () {
    acceptingAutoHeal();

    Log::warning('cache is warm');
    Log::info('order placed');
    Log::critical('disk full');
    AutoHeal::flush();

    expect(collect(sentEvents())->pluck('message')->all())->toBe(['disk full'])
        ->and(sentEvents()[0]['severity'])->toBe('critical');
});

it('captures lower levels when configured to', function () {
    $this->bootWithAutoHeal(['log_level' => 'warning']);
    acceptingAutoHeal();

    Log::warning('cache is cold');
    Log::info('order placed');
    AutoHeal::flush();

    expect(collect(sentEvents())->pluck('message')->all())->toBe(['cache is cold']);
});
