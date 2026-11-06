<?php

use AutoHeal\Laravel\Events\StackFrames;

function throwFromDepth(int $depth): never
{
    if ($depth <= 0) {
        throw new RuntimeException('deep');
    }

    throwFromDepth($depth - 1);
}

it('starts at the throw point with the function that threw', function () {
    $exception = null;

    try {
        throwFromDepth(0);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    $frames = (new StackFrames(dirname(__DIR__, 2)))->fromThrowable($exception)['frames'];

    expect($frames[0])->toMatchArray([
        'file' => 'tests/Unit/StackFramesTest.php',
        'line' => 8,
        'function' => 'throwFromDepth',
        'in_app' => true,
    ]);
});

it('marks vendor and outside files as not the application\'s own, with paths relative to the base path', function () {
    $frames = (new StackFrames(dirname(__DIR__, 2)))->fromThrowable(new RuntimeException('here'))['frames'];
    $files = array_column($frames, 'file');

    expect($frames[0]['in_app'])->toBeTrue()
        ->and(collect($frames)->contains(fn (array $frame): bool => str_starts_with($frame['file'] ?? '', 'vendor/') && $frame['in_app'] === false))->toBeTrue()
        ->and(collect($frames)->filter(fn (array $frame): bool => str_starts_with($frame['file'] ?? '', 'vendor/'))->pluck('in_app')->unique()->all())->toBe([false])
        ->and(collect($files)->filter(fn (?string $file): bool => $file !== null && str_starts_with($file, dirname(__DIR__, 2)))->all())->toBe([]);

    $outside = (new StackFrames('/somewhere/else'))->fromThrowable(new RuntimeException('here'))['frames'];

    expect(array_unique(array_column($outside, 'in_app')))->toBe([false]);
});

it('keeps at most 100 frames and counts the rest', function () {
    $exception = null;

    try {
        throwFromDepth(150);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    $stack = (new StackFrames(dirname(__DIR__, 2)))->fromThrowable($exception);

    expect($stack['frames'])->toHaveCount(100)
        ->and($stack['frames_omitted'])->toBe(count($exception->getTrace()) + 1 - 100);
});
