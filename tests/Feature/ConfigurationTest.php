<?php

use AutoHeal\Laravel\Buffer;
use AutoHeal\Laravel\Facades\AutoHeal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('starts reporting with only a key and an endpoint', function () {
    acceptingAutoHeal();

    $this->get('/autoheal/sample-exception')->assertStatus(500);

    expect(AutoHeal::isEnabled())->toBeTrue()
        ->and(sentEvents())->toHaveCount(1);
});

it('sends nothing and behaves as before without a key', function (array $config) {
    Http::fake();
    $this->bootWithAutoHeal($config);
    Http::fake();

    $this->get('/autoheal/sample-exception')->assertStatus(500);
    report(new RuntimeException('reported'));
    Log::error('payment provider timed out');
    AutoHeal::heartbeat('scheduler');
    AutoHeal::flush();

    expect(AutoHeal::isEnabled())->toBeFalse()
        ->and(app(Buffer::class)->events())->toBe([]);
    Http::assertNothingSent();
})->with([
    'no key' => [['key' => null]],
    'empty key' => [['key' => '']],
    'no endpoint' => [['endpoint' => null]],
    'turned off' => [['enabled' => false]],
]);

it('sends the key as a bearer token to the events endpoint, and no tenant identifiers', function () {
    acceptingAutoHeal();

    $this->get('/autoheal/sample-exception');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://autoheal.test/api/v1/telemetry/events'
        && $request->method() === 'POST'
        && authorizationOf($request) === 'Bearer ' . self::KEY);

    expect(sentEvents()[0])->not->toHaveKeys(['organization', 'organization_id', 'project_id', 'environment_id']);
});
