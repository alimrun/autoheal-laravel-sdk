<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

const TASK_ID = '01JCX3V6Q2N5W8Y0B4D7F9H1KM';

beforeEach(function () {
    $this->token = bin2hex(random_bytes(32));
    $this->compiledViews = sys_get_temp_dir() . '/autoheal-views-' . bin2hex(random_bytes(6));
    mkdir($this->compiledViews);
    file_put_contents($this->compiledViews . '/compiled.php', '<?php echo "view";');

    bootHook();
});

afterEach(function () {
    TrustProxies::flushState();

    foreach (glob($this->compiledViews . '/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->compiledViews);
});

/**
 * @param  array<string, mixed>  $cacheHook
 * @param  array<string, mixed>  $autoHeal
 */
function bootHook(array $cacheHook = [], array $autoHeal = []): void
{
    $test = test();

    $test->bootWithAutoHeal(array_merge([
        'cache_hook' => array_merge([
            'enabled' => true,
            'token' => $test->token,
            'path' => 'autoheal/cache',
            'caches' => ['views' => ['kind' => 'views']],
        ], $cacheHook),
    ], $autoHeal));

    config()->set('view.compiled', $test->compiledViews);
    config()->set('logging.default', 'null');
    config()->set('cache.stores.first', ['driver' => 'array']);
    config()->set('cache.stores.second', ['driver' => 'array']);

    $lines = new ArrayObject();
    $test->logged = $lines;
    app('events')->listen(MessageLogged::class, function (MessageLogged $logged) use ($lines): void {
        $lines->append(['level' => $logged->level, 'message' => $logged->message, 'context' => $logged->context]);
    });

    Http::fake();
}

/**
 * @param  array<string, string>  $headers
 * @param  array<string, string>  $server
 */
function postToHook(
    string $cache = 'views',
    ?string $token = null,
    bool $withToken = true,
    array $headers = [],
    array $server = [],
    ?string $body = null,
    string $method = 'POST',
): TestResponse {
    $test = test();
    $authorization = $withToken ? ['Authorization' => 'Bearer ' . ($token ?? $test->token)] : [];

    return $test->call(
        $method,
        "/autoheal/cache/{$cache}",
        [],
        [],
        [],
        array_merge(headersAsServerVariables(array_merge(['Content-Type' => 'application/json'], $authorization, $headers)), $server),
        $body ?? (string) json_encode(['task_id' => TASK_ID]),
    );
}

/**
 * TestCase::call() takes headers only as server variables.
 *
 * @param  array<string, string>  $headers
 * @return array<string, string>
 */
function headersAsServerVariables(array $headers): array
{
    $server = [];

    foreach ($headers as $name => $value) {
        $variable = strtoupper(str_replace('-', '_', $name));
        $server[$variable === 'CONTENT_TYPE' ? $variable : "HTTP_{$variable}"] = $value;
    }

    return $server;
}

/**
 * @return list<array{level: string, message: string, context: array<mixed>}>
 */
function loggedLines(): array
{
    return test()->logged->getArrayCopy();
}

function compiledViewExists(): bool
{
    return is_file(test()->compiledViews . '/compiled.php');
}

it('stays unregistered unless it is turned on with a token of at least 32 characters', function (array $cacheHook, array $autoHeal) {
    bootHook($cacheHook, $autoHeal);

    expect(Route::has('autoheal.cache-hook'))->toBeFalse();
    postToHook()->assertNotFound();
    expect(compiledViewExists())->toBeTrue();
})->with([
    'not turned on' => [['enabled' => false], []],
    'no token' => [['token' => null], []],
    'short token' => [['token' => str_repeat('a', 31)], []],
    'SDK turned off' => [[], ['enabled' => false]],
]);

it('ships turned off, with only compiled views listed', function () {
    $this->bootWithAutoHeal([]);

    expect(config('autoheal.cache_hook.enabled'))->toBeFalse()
        ->and(config('autoheal.cache_hook.path'))->toBe('autoheal/cache')
        ->and(config('autoheal.cache_hook.caches'))->toBe(['views' => ['kind' => 'views']])
        ->and(Route::has('autoheal.cache-hook'))->toBeFalse();
});

it('refuses every request once turned off, even with the route already registered', function () {
    config()->set('autoheal.cache_hook.enabled', false);

    expect(Route::has('autoheal.cache-hook'))->toBeTrue();
    postToHook()->assertNotFound();
    expect(compiledViewExists())->toBeTrue();
});

it('answers at another prefix when one is set', function () {
    bootHook(['path' => '/internal/autoheal-cache/']);

    $this->call('POST', '/internal/autoheal-cache/views', [], [], [], headersAsServerVariables([
        'Authorization' => "Bearer {$this->token}",
    ]), (string) json_encode(['task_id' => TASK_ID]))->assertNoContent();
    postToHook()->assertNotFound();
});

it('clears compiled views and answers 204 with no body', function () {
    postToHook()->assertNoContent();

    expect(compiledViewExists())->toBeFalse();
});

it('is safe to repeat', function () {
    postToHook()->assertNoContent();
    postToHook()->assertNoContent();
});

it('flushes only the listed store', function () {
    bootHook(['caches' => ['application' => ['kind' => 'store', 'store' => 'first']]]);
    Cache::store('first')->put('greeting', 'hello');
    Cache::store('second')->put('greeting', 'hello');

    postToHook('application')->assertNoContent();

    expect(Cache::store('first')->get('greeting'))->toBeNull()
        ->and(Cache::store('second')->get('greeting'))->toBe('hello')
        ->and(compiledViewExists())->toBeTrue();
});

it('answers 500 when the store cannot flush', function () {
    bootHook(['caches' => ['application' => ['kind' => 'store', 'store' => 'broken']]]);
    config()->set('cache.stores.broken', ['driver' => 'failing']);
    Cache::extend('failing', fn () => Cache::repository(new class () extends ArrayStore {
        public function flush(): bool
        {
            return false;
        }
    }));

    postToHook('application')->assertInternalServerError();
});

it('refuses callers that are not on this host', function (array $server, array $headers) {
    postToHook(server: $server, headers: $headers)->assertForbidden();

    expect(compiledViewExists())->toBeTrue();
})->with([
    'another host' => [['REMOTE_ADDR' => '10.0.0.5'], []],
    'another IPv6 host' => [['REMOTE_ADDR' => '2001:db8::5'], []],
    'no peer address' => [['REMOTE_ADDR' => ''], []],
    'Forwarded' => [[], ['Forwarded' => 'for=203.0.113.9']],
    'X-Forwarded-For' => [[], ['X-Forwarded-For' => '203.0.113.9']],
    'X-Forwarded-Host' => [[], ['X-Forwarded-Host' => 'shop.example.com']],
    'X-Real-IP' => [[], ['X-Real-IP' => '203.0.113.9']],
]);

it('ignores a forged forwarding address when the application trusts every proxy', function () {
    TrustProxies::at('*');
    Route::post('/client-address', fn (Illuminate\Http\Request $request): ?string => $request->ip());

    expect($this->call('POST', '/client-address', [], [], [], [
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
    ])->getContent())->toBe('127.0.0.1');

    postToHook(server: ['REMOTE_ADDR' => '10.0.0.5'], headers: ['X-Forwarded-For' => '127.0.0.1'])->assertForbidden();

    expect(compiledViewExists())->toBeTrue();
});

it('accepts the agent on any loopback address', function (string $address) {
    postToHook(server: ['REMOTE_ADDR' => $address])->assertNoContent();
})->with(['127.0.0.1', '127.8.9.10', '::1', '::ffff:127.0.0.1']);

it('refuses a missing or wrong token', function (bool $withToken, ?string $token, array $headers) {
    postToHook(token: $token, withToken: $withToken, headers: $headers)->assertUnauthorized();

    expect(compiledViewExists())->toBeTrue();
})->with([
    'no token' => [false, null, []],
    'wrong token' => [true, str_repeat('b', 64), []],
    'empty token' => [true, '', []],
    'basic scheme' => [false, null, ['Authorization' => 'Basic ' . base64_encode('agent:secret')]],
]);

it('checks the token before saying whether a cache is listed', function () {
    postToHook('sessions', token: str_repeat('b', 64))->assertUnauthorized();
});

it('refuses caches that are not listed', function (string $cache) {
    postToHook($cache)->assertNotFound();

    expect(compiledViewExists())->toBeTrue();
})->with([
    'unlisted' => ['sessions'],
    'leading dot' => ['.views'],
    'too long' => [str_repeat('a', 101)],
    'other characters' => ['views!'],
]);

it('answers 500 and warns once for a misconfigured entry', function (mixed $entry) {
    bootHook(['caches' => ['application' => $entry]]);

    postToHook('application')->assertInternalServerError();

    expect(compiledViewExists())->toBeTrue()
        ->and(loggedLines())->toHaveCount(1)
        ->and(loggedLines()[0]['level'])->toBe('warning')
        ->and(loggedLines()[0]['message'])->toContain('"application" is misconfigured');
})->with([
    'unknown kind' => [['kind' => 'config']],
    'no kind' => [['store' => 'first']],
    'undefined store' => [['kind' => 'store', 'store' => 'missing']],
    'no store' => [['kind' => 'store']],
    'not a list' => ['views'],
]);

it('refuses a body other than the task ID', function (string $body) {
    postToHook(body: $body)->assertUnprocessable();

    expect(compiledViewExists())->toBeTrue();
})->with([
    'empty' => [''],
    'not JSON' => ['task_id=' . TASK_ID],
    'a list' => [(string) json_encode([TASK_ID])],
    'short task ID' => [(string) json_encode(['task_id' => 'abc'])],
    'task ID with symbols' => [(string) json_encode(['task_id' => '01JCX3V6Q2N5W8Y0B4D7F9H1K-'])],
    'task ID not a string' => [(string) json_encode(['task_id' => 12345])],
    'names another cache' => [(string) json_encode(['task_id' => TASK_ID, 'cache' => 'application'])],
    'names a command' => [(string) json_encode(['task_id' => TASK_ID, 'command' => 'cache:clear'])],
]);

it('clears nothing on GET', function () {
    expect(postToHook(method: 'GET')->status())->not->toBeBetween(200, 299)
        ->and(compiledViewExists())->toBeTrue();
});

it('logs one info line naming the cache and the task, and sends nothing to AutoHeal', function () {
    postToHook()->assertNoContent();

    expect(loggedLines())->toBe([[
        'level' => 'info',
        'message' => 'AutoHeal cache hook cleared a cache.',
        'context' => ['cache' => 'views', 'task_id' => TASK_ID],
    ]]);
    Http::assertNothingSent();
});

it('logs nothing and sends nothing for a refused request', function (Closure $send) {
    $send();

    expect(loggedLines())->toBe([]);
    Http::assertNothingSent();
})->with([
    'another host' => [fn () => postToHook(server: ['REMOTE_ADDR' => '10.0.0.5'])],
    'wrong token' => [fn () => postToHook(token: str_repeat('b', 64))],
    'unlisted cache' => [fn () => postToHook('sessions')],
    'bad body' => [fn () => postToHook(body: '{}')],
]);

it('never writes or answers with the token', function (Closure $send) {
    $response = $send();

    expect((string) json_encode(loggedLines()))->not->toContain($this->token)
        ->and((string) $response->getContent())->not->toContain($this->token);
})->with([
    'cleared' => [fn () => postToHook()],
    'wrong token' => [fn () => postToHook(token: substr(test()->token, 0, 63) . 'x')],
    'misconfigured' => [function () {
        bootHook(['caches' => ['views' => ['kind' => 'config']]]);

        return postToHook();
    }],
]);

it('clears without an SDK key, and still sends nothing', function () {
    bootHook([], ['key' => null]);

    postToHook()->assertNoContent();

    expect(compiledViewExists())->toBeFalse();
    Http::assertNothingSent();
});
