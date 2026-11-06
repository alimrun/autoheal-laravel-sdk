<?php

namespace AutoHeal\Laravel\Transport;

/**
 * What became of one batch. "Refused" means AutoHeal turned the key down (401 or 403), "rejected"
 * means it answered with any other error, and "unreachable" means no answer arrived in time.
 */
final readonly class DeliveryResult
{
    public function __construct(
        public DeliveryStatus $status,
        public ?int $httpStatus = null,
        public ?string $code = null,
        public int $accepted = 0,
        public int $duplicates = 0,
        public ?int $retryAfter = null,
    ) {
    }

    public static function accepted(int $httpStatus, int $accepted, int $duplicates): self
    {
        return new self(DeliveryStatus::ACCEPTED, $httpStatus, accepted: $accepted, duplicates: $duplicates);
    }

    public static function queued(int $events): self
    {
        return new self(DeliveryStatus::QUEUED, accepted: $events);
    }

    public static function refused(int $httpStatus, ?string $code): self
    {
        return new self(DeliveryStatus::REFUSED, $httpStatus, $code);
    }

    public static function rejected(int $httpStatus, ?string $code, ?int $retryAfter = null): self
    {
        return new self(DeliveryStatus::REJECTED, $httpStatus, $code, retryAfter: $retryAfter);
    }

    public static function unreachable(): self
    {
        return new self(DeliveryStatus::UNREACHABLE);
    }

    public static function paused(): self
    {
        return new self(DeliveryStatus::PAUSED);
    }

    public static function disabled(): self
    {
        return new self(DeliveryStatus::DISABLED);
    }

    public function failed(): bool
    {
        return in_array($this->status, [DeliveryStatus::REFUSED, DeliveryStatus::REJECTED, DeliveryStatus::UNREACHABLE], true);
    }

    public function describe(): string
    {
        return match ($this->status) {
            DeliveryStatus::ACCEPTED => $this->duplicates > 0
                ? 'AutoHeal already had this event and stored it once.'
                : 'AutoHeal accepted the event.',
            DeliveryStatus::QUEUED => 'The event was handed to the queue.',
            DeliveryStatus::REFUSED => $this->httpStatus === 403
                ? "AutoHeal refused the SDK key: its environment or organization cannot receive events right now ({$this->answer()})."
                : "AutoHeal refused the SDK key: it is unknown or revoked ({$this->answer()}).",
            DeliveryStatus::REJECTED => "AutoHeal rejected the event ({$this->answer()}).",
            DeliveryStatus::UNREACHABLE => 'AutoHeal could not be reached at the configured endpoint.',
            DeliveryStatus::PAUSED => 'Sending is paused after a failed send. Try again in a minute.',
            DeliveryStatus::DISABLED => 'AutoHeal is not configured: set AUTOHEAL_KEY and AUTOHEAL_ENDPOINT.',
        };
    }

    private function answer(): string
    {
        return $this->code ?? "HTTP {$this->httpStatus}";
    }
}
