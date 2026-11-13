<?php

namespace AutoHeal\Laravel\CacheHook;

use AutoHeal\Laravel\AutoHeal;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The agent connects over loopback with no proxy, so the TCP peer is read directly: Request::ip()
 * follows X-Forwarded-For when the application trusts proxies. A reverse proxy on the same host
 * makes public requests look local too, and it adds forwarding headers the agent never sends.
 */
final class RestrictToLocalAgent
{
    private const LOOPBACK = ['127.0.0.0/8', '::1'];

    private const FORWARDING_HEADERS = ['Forwarded', 'X-Forwarded-For', 'X-Forwarded-Host', 'X-Real-IP'];

    private const IPV4_MAPPED_PREFIX = '::ffff:';

    public function __construct(
        private AutoHeal $autoHeal,
        private Repository $config,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->autoHeal->cacheHookEnabled()) {
            return self::refusal(Response::HTTP_NOT_FOUND, 'Not found.');
        }

        if (! $this->isLoopback($request->server('REMOTE_ADDR')) || $this->isForwarded($request)) {
            return self::refusal(Response::HTTP_FORBIDDEN, 'The cache hook answers only requests from this host.');
        }

        $token = $request->bearerToken();

        if ($token === null || ! hash_equals((string) $this->config->get('autoheal.cache_hook.token'), $token)) {
            return self::refusal(Response::HTTP_UNAUTHORIZED, 'The cache hook token is missing or wrong.');
        }

        return $next($request);
    }

    public static function refusal(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }

    private function isLoopback(mixed $address): bool
    {
        if (! is_string($address)) {
            return false;
        }

        if (stripos($address, self::IPV4_MAPPED_PREFIX) === 0) {
            $address = substr($address, strlen(self::IPV4_MAPPED_PREFIX));
        }

        return filter_var($address, FILTER_VALIDATE_IP) !== false && IpUtils::checkIp($address, self::LOOPBACK);
    }

    private function isForwarded(Request $request): bool
    {
        foreach (self::FORWARDING_HEADERS as $header) {
            if ($request->headers->has($header)) {
                return true;
            }
        }

        return false;
    }
}
