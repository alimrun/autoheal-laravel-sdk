<?php

use AutoHeal\Laravel\Redactor;

/**
 * @return array<string, array{mixed, mixed}>
 */
function redactionCases(): array
{
    $file = json_decode((string) file_get_contents(dirname(__DIR__) . '/Fixtures/redaction-cases.json'), true);
    $cases = [];

    foreach ($file['cases'] as $case) {
        $cases[$case['name']] = [$case['input'], $case['expected']];
    }

    return $cases;
}

it('passes the shared redaction case', function (mixed $input, mixed $expected) {
    $redactor = new Redactor(config('autoheal.redact_keys'));

    expect($redactor->redact($input))->toBe($expected);
})->with(redactionCases());

it('uses the shared marker', function () {
    $file = json_decode((string) file_get_contents(dirname(__DIR__) . '/Fixtures/redaction-cases.json'), true);

    expect(Redactor::MARKER)->toBe($file['marker']);
});

it('leaves numbers, booleans and null alone outside sensitive keys', function () {
    $redactor = new Redactor(config('autoheal.redact_keys'));

    expect($redactor->redact(['count' => 3, 'ok' => false, 'note' => null]))
        ->toBe(['count' => 3, 'ok' => false, 'note' => null]);
});
