<?php

namespace AutoHeal\Laravel\Tests;

use AutoHeal\Laravel\AutoHealServiceProvider;
use AutoHeal\Laravel\Events\StackFrames;
use Illuminate\Encryption\Encrypter;
use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    public const KEY = 'ahk_testtesttesttesttesttesttesttesttesttesttesttest';

    public const ENDPOINT = 'https://autoheal.test/api/v1';

    public const UNREACHABLE_ENDPOINT = 'http://127.0.0.1:9/api/v1';

    /** @var array<string, mixed> */
    private array $autoHealConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rerouteCommandEvents();
        $this->afterApplicationCreated(fn () => $this->rerouteCommandEvents());
    }

    protected function getPackageProviders($app): array
    {
        return [AutoHealServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', false);
        $app['config']->set('app.key', 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC')));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('autoheal', array_merge($app['config']->get('autoheal'), [
            'key' => self::KEY,
            'endpoint' => self::ENDPOINT,
            'release' => '2026.11.06-1',
            'service' => 'checkout-api',
        ], $this->autoHealConfig));

        // The package root stands in for the application, so tests/ and workbench/ are its own code.
        $app->singleton(StackFrames::class, fn (): StackFrames => new StackFrames(dirname(__DIR__)));
    }

    /**
     * Laravel turns Symfony's command events into CommandStarting and CommandFinished everywhere
     * except in unit tests.
     */
    private function rerouteCommandEvents(): void
    {
        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
    }

    /**
     * The SDK decides at boot whether it is on, so a different configuration needs a new
     * application.
     *
     * @param  array<string, mixed>  $config
     */
    protected function bootWithAutoHeal(array $config): void
    {
        $this->autoHealConfig = $config;
        $this->reloadApplication();
    }
}
