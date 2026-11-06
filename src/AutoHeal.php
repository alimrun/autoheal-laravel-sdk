<?php

namespace AutoHeal\Laravel;

use AutoHeal\Laravel\Events\EventFactory;
use AutoHeal\Laravel\Transport\DeliveryResult;
use AutoHeal\Laravel\Transport\DeliveryStatus;
use AutoHeal\Laravel\Transport\HttpTransport;
use AutoHeal\Laravel\Transport\QueueTransport;
use AutoHeal\Laravel\Transport\Transport;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The SDK's entry point. Everything that can fail runs isolated: a failure pauses sending and is
 * logged once, and never reaches the application, its responses or its exit codes.
 */
final class AutoHeal
{
    public function __construct(
        private Container $container,
        private Repository $config,
        private Buffer $buffer,
        private EventFactory $eventFactory,
        private CircuitBreaker $circuitBreaker,
    ) {
    }

    public function isEnabled(): bool
    {
        $key = $this->config->get('autoheal.key');
        $endpoint = $this->config->get('autoheal.endpoint');

        return (bool) $this->config->get('autoheal.enabled', true)
            && is_string($key) && $key !== ''
            && is_string($endpoint) && $endpoint !== '';
    }

    public function captureException(Throwable $exception): void
    {
        $this->record(fn (): array => $this->eventFactory->exception($exception));
    }

    /**
     * @param  array<mixed>  $context
     */
    public function captureLog(string $level, string $message, array $context = [], ?string $channel = null): void
    {
        $this->record(fn (): ?array => $this->eventFactory->errorLog($level, $message, $context, $channel));
    }

    public function deployment(string $release, ?string $source = null): void
    {
        $this->record(fn (): ?array => $this->eventFactory->deployment($release, $source));
    }

    public function heartbeat(string $name): void
    {
        $this->record(fn (): ?array => $this->eventFactory->heartbeat($name));
    }

    public function healthCheck(string $name, string $status, ?int $latencyMs = null): void
    {
        $this->record(fn (): ?array => $this->eventFactory->healthCheck($name, $status, $latencyMs));
    }

    /**
     * Sends what the current request, job or command has captured, through the configured
     * transport.
     */
    public function flush(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->isolated(function (): void {
            $drained = $this->buffer->drain();
            $events = $drained['events'];

            if ($events === []) {
                return;
            }

            if ($drained['dropped'] > 0) {
                $events[array_key_last($events)]['context']['sdk'] = ['dropped_events' => $drained['dropped']];
            }

            $this->deliver($this->transport(), $events);
        });
    }

    /**
     * Sends straight away over HTTP, for the commands and the queued job, which report or act on
     * the answer. A pause still applies.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function sendNow(array $events): DeliveryResult
    {
        if (! $this->isEnabled()) {
            return DeliveryResult::disabled();
        }

        return $this->deliver($this->container->make(HttpTransport::class), $events);
    }

    /**
     * @param  Closure(): (array<string, mixed>|null)  $makeEvent
     */
    private function record(Closure $makeEvent): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->isolated(function () use ($makeEvent): void {
            if ($this->buffer->isFull()) {
                $this->buffer->countDropped();

                return;
            }

            $event = $makeEvent();

            if ($event !== null) {
                $this->buffer->push($event);
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function deliver(Transport $transport, array $events): DeliveryResult
    {
        if ($this->circuitBreaker->isOpen()) {
            return DeliveryResult::paused();
        }

        try {
            $result = $transport->send($events);
        } catch (Throwable) {
            $result = DeliveryResult::unreachable();
        }

        if ($result->failed()) {
            $this->circuitBreaker->trip($this->failureReason($result), array_filter([
                'status' => $result->httpStatus,
                'code' => $result->code,
            ]), $result->retryAfter);
        }

        return $result;
    }

    private function transport(): Transport
    {
        return $this->config->get('autoheal.transport') === 'queue'
            ? $this->container->make(QueueTransport::class)
            : $this->container->make(HttpTransport::class);
    }

    private function failureReason(DeliveryResult $result): string
    {
        return match ($result->status) {
            DeliveryStatus::REFUSED => "AutoHeal refused the SDK key (HTTP {$result->httpStatus})",
            DeliveryStatus::REJECTED => "AutoHeal rejected the events (HTTP {$result->httpStatus})",
            default => 'AutoHeal could not be reached',
        };
    }

    private function isolated(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->circuitBreaker->trip('the SDK failed with ' . $exception::class);
        }
    }
}
