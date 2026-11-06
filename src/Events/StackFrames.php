<?php

namespace AutoHeal\Laravel\Events;

use Throwable;

/**
 * Turns a throwable into schema v1 frames, innermost first. PHP records each call's file and line
 * on the frame of the function that was called, so every frame here takes its location from the
 * frame before it: the first frame is the throw point inside the function that threw.
 */
final class StackFrames
{
    public const MAX_FRAMES = 100;

    private const MAX_FILE_LENGTH = 500;

    private const MAX_NAME_LENGTH = 255;

    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * @return array{frames: list<array<string, mixed>>, frames_omitted: int}
     */
    public function fromThrowable(Throwable $throwable): array
    {
        $trace = $throwable->getTrace();
        $total = count($trace) + 1;
        $kept = min($total, self::MAX_FRAMES);
        $frames = [];
        $location = ['file' => $throwable->getFile(), 'line' => $throwable->getLine()];

        for ($index = 0; $index < $kept; $index++) {
            $call = $trace[$index] ?? [];
            $frames[] = $this->frame($location, $call);
            $location = ['file' => $call['file'] ?? null, 'line' => $call['line'] ?? null];
        }

        return ['frames' => $frames, 'frames_omitted' => $total - $kept];
    }

    /**
     * @param  array{file: ?string, line: ?int}  $location
     * @param  array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function frame(array $location, array $call): array
    {
        $frame = [];
        $inApp = false;

        if (is_string($location['file']) && $location['file'] !== '') {
            $relative = $this->relativePath($location['file']);
            $inApp = $relative !== $location['file'] && ! str_starts_with($relative, 'vendor/');
            $frame['file'] = mb_substr($relative, -self::MAX_FILE_LENGTH);
        }

        if (is_int($location['line'])) {
            $frame['line'] = max(0, $location['line']);
        }

        if (isset($call['function']) && is_string($call['function'])) {
            $frame['function'] = mb_substr($this->relativeName($call['function']), 0, self::MAX_NAME_LENGTH);
        }

        if (isset($call['class']) && is_string($call['class'])) {
            $frame['class'] = mb_substr($call['class'], 0, self::MAX_NAME_LENGTH);
        }

        $frame['in_app'] = $inApp;

        return $frame;
    }

    private function relativePath(string $file): string
    {
        if (! str_starts_with($file, $this->basePath)) {
            return $file;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($this->basePath)));
    }

    /**
     * Closure names carry the absolute path of the file that defines them.
     */
    private function relativeName(string $function): string
    {
        return str_replace($this->basePath, '', $function);
    }
}
