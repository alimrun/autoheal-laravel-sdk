<?php

use AutoHeal\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Opis\JsonSchema\Validator;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

function acceptingAutoHeal(): void
{
    Http::fake(['*' => Http::response([
        'identical_code' => 'telemetry_accepted_202',
        'message' => 'Accepted.',
        'content' => ['accepted' => 1, 'duplicates' => 0],
        'errors' => [],
    ], 202)]);
}

function refusingAutoHeal(int $status = 401): void
{
    $code = $status === 401 ? 'sdk_key_unauthenticated_401' : 'sdk_key_unavailable_403';

    Http::fake(['*' => Http::response([
        'identical_code' => $code,
        'message' => 'Refused.',
        'content' => null,
        'errors' => [],
    ], $status)]);
}

/**
 * @return list<array<string, mixed>>
 */
function sentEvents(): array
{
    return Http::recorded()
        ->flatMap(fn (array $exchange): array => $exchange[0]->data()['events'] ?? [])
        ->values()
        ->all();
}

function sentBatches(): int
{
    return Http::recorded()->count();
}

/**
 * Validates against the platform's published contract, and the limits the schema states in prose.
 *
 * @param  array<string, mixed>  $event
 */
function expectValidEvent(array $event): void
{
    static $schema = null;
    $schema ??= json_decode((string) file_get_contents(__DIR__ . '/Fixtures/telemetry-event.schema.json'));

    $result = (new Validator())->validate(json_decode((string) json_encode($event)), $schema);

    expect($result->isValid())->toBeTrue((string) json_encode($result->error()?->info()))
        ->and(strlen((string) json_encode($event)))->toBeLessThanOrEqual(64 * 1024)
        ->and(depthOf($event['context'] ?? []))->toBeLessThanOrEqual(8)
        ->and(depthOf($event['body'] ?? []))->toBeLessThanOrEqual(8)
        ->and(longestString($event['context'] ?? []))->toBeLessThanOrEqual(8 * 1024);
}

function depthOf(mixed $value): int
{
    if (! is_array($value) || $value === []) {
        return 0;
    }

    return 1 + max(array_map('depthOf', $value));
}

function longestString(mixed $value): int
{
    if (is_string($value)) {
        return strlen($value);
    }

    if (! is_array($value) || $value === []) {
        return 0;
    }

    return max(array_map('longestString', $value));
}

function authorizationOf(Request $request): string
{
    return $request->header('Authorization')[0] ?? '';
}
