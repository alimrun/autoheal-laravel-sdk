<?php

use AutoHeal\Laravel\Jobs\SendEvents;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @return list<string>
 */
function eventIdsOfRequest(int $index): array
{
    return array_column(Http::recorded()[$index][0]->data()['events'], 'event_id');
}

function queuedJob(): SendEvents
{
    $job = null;

    Queue::assertPushed(SendEvents::class, function (SendEvents $pushed) use (&$job): bool {
        $job = $pushed;

        return true;
    });

    return $job;
}

it('hands events to a queued job instead of sending them', function () {
    $this->bootWithAutoHeal(['transport' => 'queue']);
    Queue::fake();
    Http::fake();

    $this->get('/autoheal/sample-exception')->assertStatus(500);

    Http::assertNothingSent();
    Queue::assertPushed(SendEvents::class, fn (SendEvents $job): bool => count($job->events) === 1
        && $job->events[0]['message'] === 'AutoHeal sample exception');

    expect(queuedJob()->tries)->toBe(3)
        ->and(queuedJob()->backoff)->toBe([10, 60]);
});

it('retries with the same event ids', function () {
    $this->bootWithAutoHeal(['transport' => 'queue']);
    Queue::fake();
    Http::fake(['*' => Http::sequence()
        ->push(['identical_code' => 'http_503', 'message' => 'Unavailable.', 'content' => null, 'errors' => []], 503)
        ->push(['identical_code' => 'telemetry_accepted_202', 'message' => 'Accepted.', 'content' => ['accepted' => 0, 'duplicates' => 1], 'errors' => []], 202)]);

    $this->get('/autoheal/sample-exception');
    $firstAttempt = unserialize(serialize(queuedJob()))->withFakeQueueInteractions();
    $firstAttempt->handle(app(AutoHeal\Laravel\AutoHeal::class));

    $firstAttempt->assertReleased(10);

    $this->travel(61)->seconds();
    $secondAttempt = unserialize(serialize(queuedJob()))->withFakeQueueInteractions();
    $secondAttempt->job->attempts = 2;
    $secondAttempt->handle(app(AutoHeal\Laravel\AutoHeal::class));

    $secondAttempt->assertNotReleased();

    expect(sentBatches())->toBe(2)
        ->and(eventIdsOfRequest(1))->toBe(eventIdsOfRequest(0))
        ->and(eventIdsOfRequest(0))->toBe(array_column(queuedJob()->events, 'event_id'));
});

it('gives up quietly after the last attempt', function () {
    Http::fake(['*' => Http::response(null, 503)]);
    $job = (new SendEvents([app(AutoHeal\Laravel\Events\EventFactory::class)->heartbeat('scheduler')]))->withFakeQueueInteractions();
    $job->job->attempts = 3;

    $job->handle(app(AutoHeal\Laravel\AutoHeal::class));

    $job->assertNotReleased();
    $job->assertNotFailed();
});

it('releases the job while sending is paused', function () {
    Http::fake(['*' => Http::response(null, 503)]);
    $events = [app(AutoHeal\Laravel\Events\EventFactory::class)->heartbeat('scheduler')];

    (new SendEvents($events))->withFakeQueueInteractions()->handle(app(AutoHeal\Laravel\AutoHeal::class));
    $paused = (new SendEvents($events))->withFakeQueueInteractions();
    $paused->job->attempts = 2;
    $paused->handle(app(AutoHeal\Laravel\AutoHeal::class));

    $paused->assertReleased(60);
    expect(sentBatches())->toBe(1);
});
