<?php

namespace App\Providers;

use App\Services\Integrations\HttpPart3Adapter;
use App\Services\Integrations\MockPart3Adapter;
use App\Services\Integrations\Part3AdapterManager;
use App\Services\Integrations\SandboxPart3Adapter;
use App\Services\Providers\ProviderAdapterManager;
use App\Services\Providers\TwilioAdapter;
use App\Services\Providers\VonageAdapter;
use App\Support\IntegrationMode;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(IntegrationMode::class, fn () => new IntegrationMode());
        $this->app->singleton(MockPart3Adapter::class, fn () => new MockPart3Adapter());
        $this->app->singleton(SandboxPart3Adapter::class, fn ($app) => new SandboxPart3Adapter($app->make(IntegrationMode::class)));
        $this->app->singleton(HttpPart3Adapter::class, fn () => new HttpPart3Adapter((array) config('services.part3', [])));
        $this->app->singleton(Part3AdapterManager::class, fn ($app) => new Part3AdapterManager(
            $app->make(SandboxPart3Adapter::class),
            $app->make(HttpPart3Adapter::class),
            $app->make(IntegrationMode::class),
        ));

        $this->app->singleton(TwilioAdapter::class, fn ($app) => new TwilioAdapter($app->make(IntegrationMode::class)));
        $this->app->singleton(VonageAdapter::class, fn ($app) => new VonageAdapter($app->make(IntegrationMode::class)));
        $this->app->singleton(ProviderAdapterManager::class, fn ($app) => new ProviderAdapterManager(
            $app->make(TwilioAdapter::class),
            $app->make(VonageAdapter::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(IntegrationMode::class)->assertRuntimeSafety();

        RateLimiter::for('auth', function (Request $request): Limit {
            $email = (string) $request->input('email', 'guest');
            $ip = (string) $request->ip();

            return Limit::perMinute(10)->by(strtolower($email).'|'.$ip);
        });

        RateLimiter::for('api', function (Request $request): Limit {
            $ip = (string) $request->ip();
            $tenant = $request->attributes->get('tenant');
            $tenantId = is_object($tenant) && isset($tenant->id) ? (string) $tenant->id : 'public';

            return Limit::perMinute(180)->by($tenantId.'|'.$ip);
        });

        RateLimiter::for('provider.twilio', function (Request $request): Limit {
            $ip = (string) $request->ip();
            $tenant = $request->attributes->get('tenant');
            $tenantId = is_object($tenant) && isset($tenant->id) ? (string) $tenant->id : 'public';
            $providerId = (string) $request->route('providerId', 'unknown');

            // Keep Twilio-backed admin operations bounded per tenant+provider.
            return Limit::perMinute(30)->by($tenantId.'|'.$providerId.'|'.$ip);
        });
    }
}
