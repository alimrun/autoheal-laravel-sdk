<?php

namespace AutoHeal\Laravel\Events;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * The little the SDK says about where an event happened: the request's method, path and route
 * name, the job's class and queue, or the command's name. Headers, cookies, bodies, query strings,
 * the session and the user are never read.
 */
final class EventContext
{
    /** @var array{method: string, path: string, route: ?string}|null */
    private ?array $request = null;

    /** @var array{class: string, queue: ?string}|null */
    private ?array $job = null;

    private ?string $command = null;

    private int $commandDepth = 0;

    public function __construct(private Application $app)
    {
    }

    public function enterRequest(Request $request): void
    {
        $this->request = [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'route' => $request->route()?->getName(),
        ];
    }

    public function enterJob(string $class, ?string $queue): void
    {
        $this->job = ['class' => $class, 'queue' => $queue];
    }

    public function leaveJob(): void
    {
        $this->job = null;
    }

    public function enterCommand(?string $command): void
    {
        if ($this->commandDepth === 0) {
            $this->command = $command;
        }

        $this->commandDepth++;
    }

    /**
     * True when the outermost command has finished, which is when its events are sent.
     */
    public function leaveCommand(): bool
    {
        $this->commandDepth = max(0, $this->commandDepth - 1);

        if ($this->commandDepth > 0) {
            return false;
        }

        $this->command = null;

        return true;
    }

    public function reset(): void
    {
        $this->request = null;
        $this->job = null;
        $this->command = null;
        $this->commandDepth = 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $context = [
            'environment' => $this->app->environment(),
            'runtime' => ['php' => PHP_VERSION, 'laravel' => $this->app->version()],
        ];

        $request = $this->request ?? $this->unroutedRequest();

        if ($request !== null) {
            $context['request'] = array_filter($request, fn (?string $value): bool => $value !== null);
        }

        if ($this->job !== null) {
            $context['job'] = array_filter($this->job, fn (?string $value): bool => $value !== null);
        }

        if ($this->command !== null) {
            $context['command'] = $this->command;
        }

        return $context;
    }

    /**
     * A request that failed before a route matched, such as in global middleware. Console runs
     * carry a placeholder request, which is not reported.
     *
     * @return array{method: string, path: string, route: null}|null
     */
    private function unroutedRequest(): ?array
    {
        if ($this->app->runningInConsole() || ! $this->app->bound('request')) {
            return null;
        }

        $request = $this->app->make('request');

        return ['method' => $request->getMethod(), 'path' => $request->getPathInfo(), 'route' => null];
    }
}
