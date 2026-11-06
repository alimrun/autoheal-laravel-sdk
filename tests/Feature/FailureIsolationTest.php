<?php

use AutoHeal\Laravel\Buffer;
use AutoHeal\Laravel\CircuitBreaker;
use AutoHeal\Laravel\Facades\AutoHeal;
use AutoHeal\Laravel\Tests\TestCase;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

function countSendAttempts(): Closure
{
    $attempts = 0;
    Event::listen(RequestSending::class, function () use (&$attempts): void {
        $attempts++;
    });

    return function () use (&$attempts): int {
        return $attempts;
    };
}

/**
 * @return array{status: int, content: string}
 */
function sampleResponse(TestCase $test): array
{
    $response = $test->get('/autoheal/sample-exception');

    return ['status' => $response->getStatusCode(), 'content' => (string) $response->getContent()];
}

it('answers as without the SDK when AutoHeal is unreachable, then pauses for 60 seconds with one warning', function () {
    $this->bootWithAutoHeal(['key' => null]);
    $withoutSdk = sampleResponse($this);

    $this->bootWithAutoHeal(['endpoint' => TestCase::UNREACHABLE_ENDPOINT]);
    Log::spy();
    $attempts = countSendAttempts();

    expect(sampleResponse($this))->toBe($withoutSdk)
        ->and($attempts())->toBe(1);

    expect(sampleResponse($this))->toBe($withoutSdk)
        ->and(sampleResponse($this))->toBe($withoutSdk)
        ->and($attempts())->toBe(1);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'could not be reached'));

    $this->travel(61)->seconds();
    sampleResponse($this);

    expect($attempts())->toBe(2);
});

it('waits at most the connect timeout for an address that never answers', function () {
    $this->bootWithAutoHeal(['endpoint' => 'http://10.255.255.1/api/v1']);
    Route::get('/fails', fn () => throw new RuntimeException('no answer'));
    $startedAt = microtime(true);

    $this->get('/fails')->assertStatus(500);

    expect(microtime(true) - $startedAt)->toBeLessThan(2.5)
        ->and(app(CircuitBreaker::class)->isOpen())->toBeTrue();
});

it('keeps working when AutoHeal refuses the key, and pauses for 60 seconds with one warning', function (int $status) {
    refusingAutoHeal($status);
    Log::spy();

    $this->get('/autoheal/sample-exception')->assertStatus(500);
    $this->get('/autoheal/sample-exception')->assertStatus(500);
    $this->artisan('autoheal:heartbeat', ['name' => 'scheduler'])->assertExitCode(0);

    expect(sentBatches())->toBe(1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'refused the SDK key') && $context['status'] === $status);

    $this->travel(61)->seconds();
    $this->get('/autoheal/sample-exception')->assertStatus(500);

    expect(sentBatches())->toBe(2);
})->with([401, 403]);

it('pauses other processes through the application cache', function () {
    refusingAutoHeal();

    $this->get('/autoheal/sample-exception');

    expect((new CircuitBreaker(app()))->isOpen())->toBeTrue();

    $this->travel(61)->seconds();

    expect((new CircuitBreaker(app()))->isOpen())->toBeFalse();
});

it('keeps working when the cache itself fails', function () {
    refusingAutoHeal();
    app()->instance('cache', new class () {
        public function __call(string $method, array $arguments): never
        {
            throw new RuntimeException('cache down');
        }
    });

    $this->get('/autoheal/sample-exception')->assertStatus(500);
    $this->get('/autoheal/sample-exception')->assertStatus(500);

    expect(sentBatches())->toBe(1)
        ->and(app(CircuitBreaker::class)->isOpen())->toBeTrue();
});

it('pauses on other errors and on rate limits, honouring a longer Retry-After', function () {
    Http::fake(['*' => Http::response(['identical_code' => 'http_429', 'message' => 'Slow down.', 'content' => null, 'errors' => []], 429, ['Retry-After' => '120'])]);

    $this->get('/autoheal/sample-exception');
    $this->travel(61)->seconds();
    $this->get('/autoheal/sample-exception');
    $this->travel(60)->seconds();
    $this->get('/autoheal/sample-exception');

    expect(sentBatches())->toBe(2);
});

it('does not capture its own warning', function () {
    $this->bootWithAutoHeal(['endpoint' => TestCase::UNREACHABLE_ENDPOINT, 'log_level' => 'debug']);

    report(new RuntimeException('first'));
    AutoHeal::flush();

    expect(app(Buffer::class)->events())->toBe([]);
});

it('never throws into the application when a transport or the SDK itself fails', function () {
    $this->bootWithAutoHeal(['transport' => 'queue']);
    app()->instance(Dispatcher::class, Mockery::mock(Dispatcher::class, function ($mock): void {
        $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    }));
    Log::spy();

    $this->get('/autoheal/sample-exception')->assertStatus(500);
    AutoHeal::captureLog('error', 'after the failure');

    expect(app(CircuitBreaker::class)->isOpen())->toBeTrue();
    Log::shouldHaveReceived('warning')->once();
});
