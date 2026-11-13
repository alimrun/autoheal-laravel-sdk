<?php

namespace AutoHeal\Laravel\CacheHook;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The address picks one listed cache; the body only carries the agent's task ID, for the log line.
 */
final class CacheHookController
{
    public const CACHE_NAME_PATTERN = '[A-Za-z0-9][A-Za-z0-9._-]{0,99}';

    private const TASK_ID_PATTERN = '/^[0-9A-Za-z]{26}$/';

    public function __invoke(Request $request, string $cache, Repository $config, CacheClearer $clearer): Response
    {
        $caches = $config->get('autoheal.cache_hook.caches');
        $entry = is_array($caches) ? ($caches[$cache] ?? null) : null;

        if ($entry === null) {
            return RestrictToLocalAgent::refusal(Response::HTTP_NOT_FOUND, 'The cache is not listed.');
        }

        $problem = is_array($entry) ? $clearer->entryProblem($entry) : 'its settings are not a list';

        if ($problem !== null || ! is_array($entry)) {
            Log::warning("AutoHeal cache hook: the cache \"{$cache}\" is misconfigured: {$problem}.");

            return RestrictToLocalAgent::refusal(Response::HTTP_INTERNAL_SERVER_ERROR, 'The cache is misconfigured.');
        }

        $taskId = $this->taskId($request);

        if ($taskId === null) {
            return RestrictToLocalAgent::refusal(Response::HTTP_UNPROCESSABLE_ENTITY, 'The body must be {"task_id": "<task ID>"}.');
        }

        if (! $clearer->clear($entry)) {
            return RestrictToLocalAgent::refusal(Response::HTTP_INTERNAL_SERVER_ERROR, 'The cache could not be cleared.');
        }

        Log::info('AutoHeal cache hook cleared a cache.', ['cache' => $cache, 'task_id' => $taskId]);

        return new IlluminateResponse(status: Response::HTTP_NO_CONTENT);
    }

    private function taskId(Request $request): ?string
    {
        $body = json_decode($request->getContent(), true);

        if (! is_array($body) || array_keys($body) !== ['task_id'] || ! is_string($body['task_id'])) {
            return null;
        }

        return preg_match(self::TASK_ID_PATTERN, $body['task_id']) === 1 ? $body['task_id'] : null;
    }
}
