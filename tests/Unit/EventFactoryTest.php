<?php

use AutoHeal\Laravel\Events\EventFactory;
use Illuminate\Support\Str;

function eventFactory(): EventFactory
{
    return app(EventFactory::class);
}

it('builds every event type as the platform\'s schema expects', function () {
    $previous = new LogicException('inner', 7);
    $events = [
        eventFactory()->exception(new RuntimeException('outer', 42, $previous)),
        eventFactory()->errorLog('error', 'Payment provider timed out', ['order' => 'A-1'], 'stack'),
        eventFactory()->deployment('2026.11.06-1', 'github-actions'),
        eventFactory()->heartbeat('scheduler'),
        eventFactory()->healthCheck('database', 'degraded', 840),
    ];

    foreach ($events as $event) {
        expectValidEvent($event);
        expect(Str::isUuid($event['event_id']))->toBeTrue()
            ->and($event['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and($event['service'])->toBe('checkout-api')
            ->and($event)->not->toHaveKeys(['organization_id', 'project_id', 'environment_id']);
    }

    expect($events[0]['body'])->toMatchArray(['class' => RuntimeException::class, 'code' => '42'])
        ->and($events[0]['body']['previous'])->toBe([['class' => LogicException::class, 'message' => 'inner']])
        ->and($events[1]['body'])->toBe(['level' => 'error', 'channel' => 'stack'])
        ->and($events[2]['body'])->toBe(['release' => '2026.11.06-1', 'source' => 'github-actions'])
        ->and($events[3]['body'])->toBe(['name' => 'scheduler'])
        ->and($events[4]['body'])->toBe(['name' => 'database', 'status' => 'degraded', 'latency_ms' => 840])
        ->and($events[4]['severity'])->toBe('warning');
});

it('gives every event its own id', function () {
    $ids = collect(range(1, 20))->map(fn (): string => eventFactory()->heartbeat('scheduler')['event_id']);

    expect($ids->unique())->toHaveCount(20);
});

it('stays inside the schema limits whatever the input', function () {
    $deep = ['level' => 'leaf'];

    foreach (range(1, 20) as $level) {
        $deep = ['nested' => $deep];
    }

    $chain = new RuntimeException('first');

    foreach (range(1, 6) as $index) {
        $chain = new RuntimeException("wrapper {$index}", 0, $chain);
    }

    $hugeLog = eventFactory()->errorLog('critical', str_repeat('m', 5000), [
        'deep' => $deep,
        'big' => str_repeat('x', 20000),
        'many' => array_fill(0, 40, str_repeat('y', 7000)),
        'binary' => "\xB1\x31 not utf-8",
        'object' => new ArrayObject(['secret' => 'kept out']),
        'infinite' => INF,
    ]);
    $longException = eventFactory()->exception(new RuntimeException(str_repeat('e', 9000)));

    expectValidEvent($hugeLog);
    expectValidEvent($longException);
    expectValidEvent(eventFactory()->exception($chain));

    expect(mb_strlen($hugeLog['message']))->toBe(2000)
        ->and(count(eventFactory()->exception($chain)['body']['previous']))->toBe(3)
        ->and((string) json_encode($hugeLog))->not->toContain('kept out');
});

it('refuses to build events the platform would reject', function () {
    expect(eventFactory()->heartbeat('has spaces'))->toBeNull()
        ->and(eventFactory()->heartbeat(str_repeat('a', 101)))->toBeNull()
        ->and(eventFactory()->heartbeat(''))->toBeNull()
        ->and(eventFactory()->healthCheck('database', 'sideways'))->toBeNull()
        ->and(eventFactory()->deployment(''))->toBeNull()
        ->and(eventFactory()->deployment(str_repeat('r', 101)))->toBeNull()
        ->and(eventFactory()->errorLog('loud', 'message'))->toBeNull();
});

it('clamps health check latency to the schema range', function () {
    expect(eventFactory()->healthCheck('database', 'up', 900000)['body']['latency_ms'])->toBe(600000)
        ->and(eventFactory()->healthCheck('database', 'up', -5)['body']['latency_ms'])->toBe(0);
});

it('redacts the message and the previous messages, but not the frames', function () {
    $event = eventFactory()->exception(new RuntimeException(
        'could not connect to pgsql://app:Sup3r@db:5432/shop',
        0,
        new LogicException('header was Bearer abc123'),
    ));

    expect($event['message'])->toBe('could not connect to pgsql://app:[redacted]@db:5432/shop')
        ->and($event['body']['previous'][0]['message'])->toBe('header was Bearer [redacted]')
        ->and($event['body']['frames'][0]['file'])->toBe('tests/Unit/EventFactoryTest.php');
});
