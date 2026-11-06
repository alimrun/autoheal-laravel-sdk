<?php

namespace AutoHeal\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stops sending for a while after a failed send or a refused key, so an AutoHeal outage costs the
 * application one connect timeout per pause instead of one per request. The pause is kept in the
 * process for long-running workers and in the application cache for PHP-FPM, where every request
 * starts with a fresh container.
 */
final class CircuitBreaker
{
    public const PAUSE_SECONDS = 60;

    private const MAX_PAUSE_SECONDS = 3600;

    private const CACHE_KEY = 'autoheal:paused_until';

    private ?int $pausedUntil = null;

    private bool $warning = false;

    public function __construct(private Container $container)
    {
    }

    public function isOpen(): bool
    {
        $now = Date::now()->getTimestamp();

        if ($this->pausedUntil !== null && $this->pausedUntil > $now) {
            return true;
        }

        $sharedPausedUntil = $this->readSharedPause();

        if ($sharedPausedUntil !== null && $sharedPausedUntil > $now) {
            $this->pausedUntil = $sharedPausedUntil;

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function trip(string $reason, array $details = [], ?int $retryAfter = null): void
    {
        $now = Date::now()->getTimestamp();

        if ($this->pausedUntil !== null && $this->pausedUntil > $now) {
            return;
        }

        $seconds = max(self::PAUSE_SECONDS, min($retryAfter ?? 0, self::MAX_PAUSE_SECONDS));
        $this->pausedUntil = $now + $seconds;
        $this->writeSharedPause($this->pausedUntil, $seconds);
        $this->warn($reason, $details, $seconds);
    }

    /**
     * True while the breaker writes its own warning, so the log capture can ignore it.
     */
    public function isWarning(): bool
    {
        return $this->warning;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function warn(string $reason, array $details, int $seconds): void
    {
        $this->warning = true;

        try {
            Log::warning("AutoHeal paused sending for {$seconds} seconds: {$reason}.", $details);
        } catch (Throwable) {
        } finally {
            $this->warning = false;
        }
    }

    private function readSharedPause(): ?int
    {
        if (! $this->container->bound('cache')) {
            return null;
        }

        try {
            $pausedUntil = $this->container->make('cache')->get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_numeric($pausedUntil) ? (int) $pausedUntil : null;
    }

    private function writeSharedPause(int $pausedUntil, int $seconds): void
    {
        if (! $this->container->bound('cache')) {
            return;
        }

        try {
            $this->container->make('cache')->put(self::CACHE_KEY, $pausedUntil, $seconds);
        } catch (Throwable) {
        }
    }
}
