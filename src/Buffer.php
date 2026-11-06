<?php

namespace AutoHeal\Laravel;

/**
 * Holds the events of one request, job or command until they are flushed. Further events are
 * dropped and counted, so an error storm cannot grow memory without bound.
 */
final class Buffer
{
    public const CAPACITY = 50;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    private int $dropped = 0;

    public function isFull(): bool
    {
        return count($this->events) >= self::CAPACITY;
    }

    public function countDropped(): void
    {
        $this->dropped++;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function push(array $event): void
    {
        if ($this->isFull()) {
            $this->countDropped();

            return;
        }

        $this->events[] = $event;
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @return array{events: list<array<string, mixed>>, dropped: int}
     */
    public function drain(): array
    {
        $drained = ['events' => $this->events, 'dropped' => $this->dropped];

        $this->events = [];
        $this->dropped = 0;

        return $drained;
    }
}
