<?php

namespace AutoHeal\Laravel\Events;

use AutoHeal\Laravel\Redactor;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds schema version 1 events that the platform will accept: every limit in
 * contracts/telemetry-event.schema.json is applied here, and everything is redacted, so a refused
 * batch can only mean a bug. Builders return null for input that cannot become a valid event.
 */
final class EventFactory
{
    public const SCHEMA_VERSION = 1;

    public const MAX_EVENT_BYTES = 64000;

    public const MIN_HEARTBEAT_INTERVAL_SECONDS = 60;

    public const MAX_HEARTBEAT_INTERVAL_SECONDS = 604800;

    private const MAX_MESSAGE_LENGTH = 2000;

    private const MAX_SHORT_LENGTH = 100;

    private const MAX_CLASS_LENGTH = 255;

    private const MAX_CONTEXT_STRING_BYTES = 8000;

    /**
     * Levels below the log context itself. With "context" and "log" above them, nesting stays
     * well inside the platform's limit of 8.
     */
    private const MAX_LOG_CONTEXT_DEPTH = 5;

    private const MAX_PREVIOUS = 3;

    private const MAX_LATENCY_MS = 600000;

    private const CHECK_NAME_PATTERN = '/^[A-Za-z0-9._:-]{1,100}$/';

    private const CHECK_STATUSES = ['up', 'degraded', 'down'];

    private const LOG_SEVERITIES = [
        'debug' => 'debug',
        'info' => 'info',
        'notice' => 'info',
        'warning' => 'warning',
        'error' => 'error',
        'critical' => 'critical',
        'alert' => 'critical',
        'emergency' => 'critical',
    ];

    public function __construct(
        private Repository $config,
        private EventContext $context,
        private StackFrames $stackFrames,
        private Redactor $redactor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function exception(Throwable $exception): array
    {
        $stack = $this->stackFrames->fromThrowable($exception);
        $message = $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class;

        $body = [
            'class' => $this->limit($exception::class, self::MAX_CLASS_LENGTH),
            'code' => $this->limit((string) $exception->getCode(), self::MAX_SHORT_LENGTH),
            'frames' => $stack['frames'],
            'frames_omitted' => $stack['frames_omitted'],
            'previous' => $this->previous($exception),
        ];

        return $this->fit($this->envelope('exception', 'error', $message, $this->context->toArray(), $body));
    }

    /**
     * @param  array<mixed>  $logContext
     * @return array<string, mixed>|null
     */
    public function errorLog(string $level, string $message, array $logContext = [], ?string $channel = null): ?array
    {
        $severity = self::LOG_SEVERITIES[strtolower($level)] ?? null;

        if ($severity === null) {
            return null;
        }

        $context = $this->context->toArray();

        if ($logContext !== []) {
            $context['log'] = $this->normalize($logContext, self::MAX_LOG_CONTEXT_DEPTH);
        }

        $body = array_filter([
            'level' => strtolower($level),
            'channel' => $channel === null ? null : $this->limit($channel, self::MAX_SHORT_LENGTH),
        ], fn (?string $value): bool => $value !== null && $value !== '');

        $message = $message !== '' ? $message : "Log message at {$level} level";

        return $this->fit($this->envelope('error_log', $severity, $message, $context, $body));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function deployment(string $release, ?string $source = null): ?array
    {
        if ($release === '' || mb_strlen($release) > self::MAX_SHORT_LENGTH) {
            return null;
        }

        $body = ['release' => $release];

        if ($source !== null && $source !== '') {
            $body['source'] = $this->limit($source, self::MAX_SHORT_LENGTH);
        }

        $event = $this->envelope('deployment', 'info', "Deployed {$release}", $this->context->toArray(), $body);
        $event['release'] = $release;

        return $this->fit($event);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function heartbeat(string $name, ?int $intervalSeconds = null): ?array
    {
        if (! $this->isCheckName($name) || ($intervalSeconds !== null && ! $this->isHeartbeatInterval($intervalSeconds))) {
            return null;
        }

        $body = ['name' => $name];

        if ($intervalSeconds !== null) {
            $body['interval_seconds'] = $intervalSeconds;
        }

        return $this->fit($this->envelope('heartbeat', 'info', "Heartbeat {$name}", $this->context->toArray(), $body));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function healthCheck(string $name, string $status, ?int $latencyMs = null): ?array
    {
        if (! $this->isCheckName($name) || ! in_array($status, self::CHECK_STATUSES, true)) {
            return null;
        }

        $body = ['name' => $name, 'status' => $status];

        if ($latencyMs !== null) {
            $body['latency_ms'] = max(0, min($latencyMs, self::MAX_LATENCY_MS));
        }

        $severity = match ($status) {
            'up' => 'info',
            'degraded' => 'warning',
            'down' => 'error',
        };

        return $this->fit($this->envelope('health_check', $severity, "Health check {$name} {$status}", $this->context->toArray(), $body));
    }

    public function isCheckName(string $name): bool
    {
        return preg_match(self::CHECK_NAME_PATTERN, $name) === 1;
    }

    public function isCheckStatus(string $status): bool
    {
        return in_array($status, self::CHECK_STATUSES, true);
    }

    public function isHeartbeatInterval(int $seconds): bool
    {
        return $seconds >= self::MIN_HEARTBEAT_INTERVAL_SECONDS && $seconds <= self::MAX_HEARTBEAT_INTERVAL_SECONDS;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function envelope(string $type, string $severity, string $message, array $context, array $body): array
    {
        $event = [
            'schema_version' => self::SCHEMA_VERSION,
            'event_id' => (string) Str::uuid(),
            'type' => $type,
            'severity' => $severity,
            'occurred_at' => Date::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'message' => $this->limit($this->scrub($this->redactor->redactString($message)), self::MAX_MESSAGE_LENGTH),
        ];

        foreach (['release', 'service'] as $field) {
            $value = $this->config->get("autoheal.{$field}");

            if (is_string($value) && $value !== '') {
                $event[$field] = $this->limit($value, self::MAX_SHORT_LENGTH);
            }
        }

        $event['context'] = $this->boundStrings($this->redactor->redact($context));
        $event['body'] = $this->redactBody($body);

        return $event;
    }

    /**
     * Strings are redacted before they are shortened, so a cut can never hide a secret's end from
     * the patterns. Frames stay readable: their paths, functions and classes are not redacted.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function redactBody(array $body): array
    {
        $frames = $body['frames'] ?? null;
        unset($body['frames']);

        $redacted = $this->boundStrings($this->redactor->redact($body));

        if ($frames !== null) {
            $redacted['frames'] = $frames;
        }

        return $redacted;
    }

    /**
     * @return list<array{class: string, message: string}>
     */
    private function previous(Throwable $exception): array
    {
        $previous = [];
        $current = $exception->getPrevious();

        while ($current !== null && count($previous) < self::MAX_PREVIOUS) {
            $previous[] = [
                'class' => $this->limit($current::class, self::MAX_CLASS_LENGTH),
                'message' => $this->limit($this->scrub($this->redactor->redactString($current->getMessage())), self::MAX_MESSAGE_LENGTH),
            ];
            $current = $current->getPrevious();
        }

        return $previous;
    }

    /**
     * Log context can hold anything. Objects become their class name rather than being
     * serialized, so models and requests never leak their attributes.
     */
    private function normalize(mixed $value, int $remainingDepth): mixed
    {
        if (is_array($value)) {
            if ($remainingDepth <= 0) {
                return '[nested too deep]';
            }

            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize($item, $remainingDepth - 1);
            }

            return $normalized;
        }

        if (is_object($value)) {
            return '[object ' . $value::class . ']';
        }

        if (is_resource($value)) {
            return '[resource]';
        }

        if (is_float($value) && ! is_finite($value)) {
            return (string) $value;
        }

        return $value;
    }

    private function boundStrings(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strcut($this->scrub($value), 0, self::MAX_CONTEXT_STRING_BYTES, 'UTF-8');
        }

        if (! is_array($value)) {
            return $value;
        }

        $bounded = [];

        foreach ($value as $key => $item) {
            $bounded[$key] = $this->boundStrings($item);
        }

        return $bounded;
    }

    /**
     * Keeps an oversized event under the platform's limit: the log context goes first, then
     * frames from the outer end of the stack, then most of the message.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function fit(array $event): array
    {
        if ($this->encodedSize($event) <= self::MAX_EVENT_BYTES) {
            return $event;
        }

        if (isset($event['context']['log'])) {
            $event['context']['log'] = '[omitted: event too large]';
        }

        while ($this->encodedSize($event) > self::MAX_EVENT_BYTES && ($event['body']['frames'] ?? []) !== []) {
            $keep = intdiv(count($event['body']['frames']), 2);
            $event['body']['frames_omitted'] += count($event['body']['frames']) - $keep;
            $event['body']['frames'] = array_slice($event['body']['frames'], 0, $keep);
        }

        if ($this->encodedSize($event) > self::MAX_EVENT_BYTES) {
            $event['message'] = $this->limit($event['message'], 200);
            $event['context'] = array_intersect_key($event['context'], array_flip(['environment', 'runtime']));
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function encodedSize(array $event): int
    {
        return strlen((string) json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    private function limit(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }

    private function scrub(string $value): string
    {
        return mb_scrub($value, 'UTF-8');
    }
}
