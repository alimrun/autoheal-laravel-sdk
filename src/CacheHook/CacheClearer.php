<?php

namespace AutoHeal\Laravel\CacheHook;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;

/**
 * Clears only the two fixed kinds. The configuration chooses a kind and a store name, never a
 * command or a class.
 */
final class CacheClearer
{
    public function __construct(
        private Repository $config,
        private Kernel $artisan,
        private CacheFactory $cache,
    ) {
    }

    /**
     * @param  array<mixed>  $entry
     */
    public function entryProblem(array $entry): ?string
    {
        $kind = is_string($entry['kind'] ?? null) ? CacheKind::tryFrom($entry['kind']) : null;

        if ($kind === null) {
            return 'its kind is not "views" or "store"';
        }

        $store = $entry['store'] ?? null;

        if ($kind === CacheKind::STORE
            && (! is_string($store) || ! array_key_exists($store, (array) $this->config->get('cache.stores', [])))) {
            return 'its store is not defined in cache.stores';
        }

        return null;
    }

    /**
     * @param  array<mixed>  $entry  an entry entryProblem() found nothing wrong with
     */
    public function clear(array $entry): bool
    {
        return match (CacheKind::from((string) $entry['kind'])) {
            CacheKind::VIEWS => $this->artisan->call('view:clear') === 0,
            CacheKind::STORE => $this->cache->store((string) $entry['store'])->clear(),
        };
    }
}
