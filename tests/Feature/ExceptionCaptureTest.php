<?php

use AutoHeal\Laravel\Facades\AutoHeal;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class IgnoredByTheApplication extends RuntimeException
{
}

it('sends a reported exception once, with its frames', function () {
    acceptingAutoHeal();

    $this->get('/autoheal/sample-exception')->assertStatus(500);

    $events = sentEvents();
    $event = $events[0];

    expect(sentBatches())->toBe(1)
        ->and($events)->toHaveCount(1)
        ->and($event)->toMatchArray([
            'schema_version' => 1,
            'type' => 'exception',
            'severity' => 'error',
            'message' => 'AutoHeal sample exception',
            'release' => '2026.11.06-1',
            'service' => 'checkout-api',
        ])
        ->and(Str::isUuid($event['event_id']))->toBeTrue()
        ->and($event['body']['class'])->toBe(RuntimeException::class)
        ->and($event['body']['frames'][0])->toMatchArray(['file' => 'workbench/routes/api.php', 'line' => 6, 'in_app' => true])
        ->and(collect($event['body']['frames'])->where('in_app', false)->pluck('file')->filter()->every(
            fn (string $file): bool => str_starts_with($file, 'vendor/'),
        ))->toBeTrue()
        ->and(collect($event['body']['frames'])->contains('in_app', false))->toBeTrue()
        ->and($event['context']['request'])->toBe(['method' => 'GET', 'path' => '/autoheal/sample-exception', 'route' => 'autoheal.sample-exception'])
        ->and($event['context']['environment'])->toBe('testing')
        ->and($event['context']['runtime'])->toBe(['php' => PHP_VERSION, 'laravel' => app()->version()]);

    expectValidEvent($event);
});

it('respects the application\'s dontReport list', function () {
    acceptingAutoHeal();
    app(ExceptionHandler::class)->dontReport(IgnoredByTheApplication::class);
    Route::get('/ignored', fn () => throw new IgnoredByTheApplication('not reported'));

    $this->get('/ignored')->assertStatus(500);
    $this->get('/no-such-page')->assertNotFound();

    Http::assertNothingSent();
});

it('sends only after the response is complete', function () {
    acceptingAutoHeal();
    Route::get('/reports-and-answers', function () {
        report(new RuntimeException('handled'));

        return response((string) sentBatches());
    });

    $this->get('/reports-and-answers')->assertOk()->assertSee('0');

    expect(sentBatches())->toBe(1);
});

it('sends at most 50 events from an error storm and counts the dropped', function () {
    acceptingAutoHeal();
    Route::get('/storm', function () {
        foreach (range(1, 200) as $index) {
            report(new RuntimeException("storm {$index}"));
        }

        return 'still fine';
    });

    $this->get('/storm')->assertOk()->assertSee('still fine');

    $events = sentEvents();

    expect(sentBatches())->toBe(1)
        ->and($events)->toHaveCount(50)
        ->and($events[0]['message'])->toBe('storm 1')
        ->and($events[49]['context']['sdk'])->toBe(['dropped_events' => 150]);
});

it('never sends query strings, headers, cookies or bodies', function () {
    acceptingAutoHeal();
    Route::post('/checkout', fn () => throw new RuntimeException('checkout failed'))->name('checkout.store');

    $this->withCookie('laravel_session', 'cookie-value-xyz')
        ->withHeaders(['X-Customer-Header' => 'header-value-xyz'])
        ->post('/checkout?token=abc&coupon=query-value-xyz', ['card' => 'body-value-xyz'])
        ->assertStatus(500);

    $event = sentEvents()[0];
    $encoded = (string) json_encode(Http::recorded()->map(fn (array $exchange) => $exchange[0]->data())->all());

    expect($event['context']['request'])->toBe(['method' => 'POST', 'path' => '/checkout', 'route' => 'checkout.store'])
        ->and($encoded)->not->toContain('token=abc')
        ->and($encoded)->not->toContain('query-value-xyz')
        ->and($encoded)->not->toContain('header-value-xyz')
        ->and($encoded)->not->toContain('X-Customer-Header')
        ->and($encoded)->not->toContain('cookie-value-xyz')
        ->and($encoded)->not->toContain('body-value-xyz');
});

it('describes a worker\'s job and sends its events when the job ends', function () {
    acceptingAutoHeal();
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\ChargeCard');
    $job->shouldReceive('getQueue')->andReturn('payments');
    $job->shouldReceive('payload')->andReturn([]);

    event(new JobProcessing('redis', $job));
    report(new RuntimeException('charge failed softly'));
    event(new JobProcessed('redis', $job));

    expect(sentEvents())->toHaveCount(1)
        ->and(sentEvents()[0]['context']['job'])->toBe(['class' => 'App\Jobs\ChargeCard', 'queue' => 'payments']);
});

it('sends a failed job\'s exception, which the worker reports after the failing event, when the worker loops', function () {
    acceptingAutoHeal();
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\ChargeCard');
    $job->shouldReceive('getQueue')->andReturn('payments');
    $job->shouldReceive('payload')->andReturn([]);
    $exception = new RuntimeException('charge failed');

    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, $exception));
    report($exception);

    expect(sentBatches())->toBe(0);

    event(new Looping('redis', 'payments'));

    expect(sentEvents())->toHaveCount(1)
        ->and(sentEvents()[0]['message'])->toBe('charge failed')
        ->and(sentEvents()[0]['context']['job'])->toBe(['class' => 'App\Jobs\ChargeCard', 'queue' => 'payments']);
});

it('leaves jobs on the sync connection to the request or command that ran them', function () {
    acceptingAutoHeal();
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\SendReceipt');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('payload')->andReturn([]);

    event(new JobProcessing('sync', $job));
    report(new RuntimeException('receipt failed'));
    event(new JobProcessed('sync', $job));

    expect(sentBatches())->toBe(0);

    AutoHeal::flush();

    expect(sentEvents())->toHaveCount(1);
});

it('names the command and sends its events when it finishes', function () {
    acceptingAutoHeal();
    Artisan::command('orders:reconcile', function () {
        report(new RuntimeException('reconcile failed'));
        $this->call('orders:inner');

        return 0;
    });
    Artisan::command('orders:inner', fn () => 0);

    $this->artisan('orders:reconcile')->assertExitCode(0);

    expect(sentBatches())->toBe(1)
        ->and(sentEvents()[0]['context']['command'])->toBe('orders:reconcile');
});

it('sends when an Octane request ends', function () {
    require_once dirname(__DIR__) . '/Fixtures/OctaneRequestTerminated.php';
    $this->reloadApplication();
    acceptingAutoHeal();

    report(new RuntimeException('octane request failed'));
    event(new Laravel\Octane\Events\RequestTerminated());

    expect(sentEvents())->toHaveCount(1);
});
