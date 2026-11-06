<?php

use AutoHeal\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

it('reports an accepted test event', function () {
    acceptingAutoHeal();

    $this->artisan('autoheal:test')
        ->expectsOutputToContain('AutoHeal accepted the test event')
        ->assertExitCode(0);

    expect(sentEvents())->toHaveCount(1)
        ->and(sentEvents()[0]['type'])->toBe('exception')
        ->and(sentEvents()[0]['context']['command'])->toBe('autoheal:test');

    expectValidEvent(sentEvents()[0]);
});

it('reports a refused key and fails', function (int $status, string $answer) {
    refusingAutoHeal($status);

    $this->artisan('autoheal:test')
        ->expectsOutputToContain($answer)
        ->assertExitCode(1);
})->with([
    'revoked' => [401, 'AutoHeal refused the SDK key: it is unknown or revoked (sdk_key_unauthenticated_401)'],
    'unavailable' => [403, 'AutoHeal refused the SDK key: its environment or organization cannot receive events right now (sdk_key_unavailable_403)'],
]);

it('reports an unreachable endpoint and fails', function () {
    $this->bootWithAutoHeal(['endpoint' => TestCase::UNREACHABLE_ENDPOINT]);

    $this->artisan('autoheal:test')
        ->expectsOutputToContain('AutoHeal could not be reached')
        ->assertExitCode(1);
});

it('says when it is not configured', function () {
    $this->bootWithAutoHeal(['key' => null]);

    $this->artisan('autoheal:test')
        ->expectsOutputToContain('AutoHeal is not configured')
        ->assertExitCode(1);
});

it('tests the connection even while sending is paused', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['identical_code' => 'sdk_key_unauthenticated_401', 'message' => 'Refused.', 'content' => null, 'errors' => []], 401)
        ->push(['identical_code' => 'telemetry_accepted_202', 'message' => 'Accepted.', 'content' => ['accepted' => 1, 'duplicates' => 0], 'errors' => []], 202)]);

    $this->artisan('autoheal:heartbeat', ['name' => 'scheduler'])->expectsOutputToContain('refused');
    $this->artisan('autoheal:deploy', ['release' => '2026.11.06-1'])->expectsOutputToContain('paused');
    $this->artisan('autoheal:test')->expectsOutputToContain('accepted')->assertExitCode(0);
});

it('reports a deployment from a pipeline', function () {
    acceptingAutoHeal();

    $this->artisan('autoheal:deploy', ['release' => '2026.11.06-1', '--source' => 'github-actions'])
        ->expectsOutputToContain('AutoHeal accepted the event')
        ->assertExitCode(0);

    expect(sentEvents()[0])->toMatchArray(['type' => 'deployment', 'release' => '2026.11.06-1'])
        ->and(sentEvents()[0]['body'])->toBe(['release' => '2026.11.06-1', 'source' => 'github-actions']);
});

it('sends a named heartbeat each time the scheduler runs it', function () {
    acceptingAutoHeal();

    $this->artisan('autoheal:heartbeat', ['name' => 'scheduler'])->expectsOutputToContain('accepted')->assertExitCode(0);
    $this->artisan('autoheal:heartbeat', ['name' => 'scheduler'])->expectsOutputToContain('accepted')->assertExitCode(0);

    expect(sentEvents())->toHaveCount(2)
        ->and(collect(sentEvents())->pluck('body')->all())->toBe([['name' => 'scheduler'], ['name' => 'scheduler']])
        ->and(sentEvents()[0]['event_id'])->not->toBe(sentEvents()[1]['event_id']);
});

it('sends how often a heartbeat is expected when it is given', function () {
    acceptingAutoHeal();

    $this->artisan('autoheal:heartbeat', ['name' => 'scheduler', '--every' => '60'])->expectsOutputToContain('accepted')->assertExitCode(0);

    expect(sentEvents()[0]['body'])->toBe(['name' => 'scheduler', 'interval_seconds' => 60]);

    expectValidEvent(sentEvents()[0]);
});

it('reports a health check with its status and latency', function () {
    acceptingAutoHeal();

    $this->artisan('autoheal:health', ['name' => 'database', 'status' => 'degraded', '--latency' => '840'])
        ->expectsOutputToContain('accepted')
        ->assertExitCode(0);

    expect(sentEvents()[0]['body'])->toBe(['name' => 'database', 'status' => 'degraded', 'latency_ms' => 840]);
});

it('says what AutoHeal answered without failing the pipeline or the scheduler', function (string $command, array $arguments) {
    refusingAutoHeal();

    $this->artisan($command, $arguments)->expectsOutputToContain('refused the SDK key')->assertExitCode(0);

    $this->bootWithAutoHeal(['endpoint' => TestCase::UNREACHABLE_ENDPOINT]);

    $this->artisan($command, $arguments)->expectsOutputToContain('could not be reached')->assertExitCode(0);
    $this->artisan($command, $arguments)->expectsOutputToContain('paused')->assertExitCode(0);
})->with([
    'deploy' => ['autoheal:deploy', ['release' => '2026.11.06-1']],
    'heartbeat' => ['autoheal:heartbeat', ['name' => 'scheduler']],
    'health' => ['autoheal:health', ['name' => 'database', 'status' => 'up']],
]);

it('rejects input the platform would refuse, without sending', function (string $command, array $arguments, string $message) {
    Http::fake();

    $this->artisan($command, $arguments)->expectsOutputToContain($message)->assertExitCode(1);

    Http::assertNothingSent();
})->with([
    'long release' => ['autoheal:deploy', ['release' => str_repeat('r', 101)], 'The release must be 1 to 100 characters'],
    'heartbeat name' => ['autoheal:heartbeat', ['name' => 'two words'], 'The name must be'],
    'heartbeat interval too short' => ['autoheal:heartbeat', ['name' => 'scheduler', '--every' => '59'], 'from 60 to 604800 (1 minute to 7 days)'],
    'heartbeat interval too long' => ['autoheal:heartbeat', ['name' => 'scheduler', '--every' => '604801'], 'from 60 to 604800 (1 minute to 7 days)'],
    'heartbeat interval not a number' => ['autoheal:heartbeat', ['name' => 'scheduler', '--every' => 'hourly'], 'from 60 to 604800 (1 minute to 7 days)'],
    'health name' => ['autoheal:health', ['name' => 'two words', 'status' => 'up'], 'The name must be'],
    'health status' => ['autoheal:health', ['name' => 'database', 'status' => 'sideways'], 'The status must be up, degraded or down'],
    'health latency' => ['autoheal:health', ['name' => 'database', 'status' => 'up', '--latency' => 'fast'], 'The latency must be'],
]);
