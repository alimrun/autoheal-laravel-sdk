<?php

namespace AutoHeal\Laravel\Transport;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpTransport implements Transport
{
    public const CONNECT_TIMEOUT_SECONDS = 1;

    public const TIMEOUT_SECONDS = 2;

    public function __construct(private Repository $config)
    {
    }

    public function send(array $events): DeliveryResult
    {
        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->withToken((string) $this->config->get('autoheal.key'))
                ->withUserAgent('autoheal-laravel-sdk')
                ->post($this->eventsUrl(), ['events' => $events]);
        } catch (Throwable) {
            return DeliveryResult::unreachable();
        }

        return $this->resultFor($response);
    }

    private function eventsUrl(): string
    {
        return rtrim((string) $this->config->get('autoheal.endpoint'), '/') . '/telemetry/events';
    }

    private function resultFor(Response $response): DeliveryResult
    {
        $status = $response->status();
        $code = $this->identicalCode($response);

        if ($response->successful()) {
            return DeliveryResult::accepted(
                $status,
                (int) $this->contentValue($response, 'accepted'),
                (int) $this->contentValue($response, 'duplicates'),
            );
        }

        if ($status === 401 || $status === 403) {
            return DeliveryResult::refused($status, $code);
        }

        $retryAfter = $response->header('Retry-After');

        return DeliveryResult::rejected($status, $code, is_numeric($retryAfter) ? (int) $retryAfter : null);
    }

    private function identicalCode(Response $response): ?string
    {
        $body = $this->decodedBody($response);
        $code = $body['identical_code'] ?? null;

        return is_string($code) ? $code : null;
    }

    private function contentValue(Response $response, string $key): mixed
    {
        $content = $this->decodedBody($response)['content'] ?? null;

        return is_array($content) ? ($content[$key] ?? 0) : 0;
    }

    /**
     * @return array<mixed>
     */
    private function decodedBody(Response $response): array
    {
        $decoded = json_decode($response->body(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
