<?php

namespace AutoHeal\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void captureException(\Throwable $exception)
 * @method static void captureLog(string $level, string $message, array<mixed> $context = [], ?string $channel = null)
 * @method static void deployment(string $release, ?string $source = null)
 * @method static void heartbeat(string $name)
 * @method static void healthCheck(string $name, string $status, ?int $latencyMs = null)
 * @method static void flush()
 * @method static bool isEnabled()
 *
 * @see \AutoHeal\Laravel\AutoHeal
 */
final class AutoHeal extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AutoHeal\Laravel\AutoHeal::class;
    }
}
