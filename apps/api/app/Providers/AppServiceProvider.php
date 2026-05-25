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

        if (str_starts_with(config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        RateLimiter::for('auth', function (Request $request): Limit {
            $email = (string) $request->input('email', 'guest');
            $ip = (string) $request->ip();

            return Limit::perMinute(10)->by(strtolower($email).'|'.$ip);
        });

        RateLimiter::for('api', function (Request $request): Limit {
            $ip = (string) $request->ip();
            $tenant = $request->attributes->get('tenant');
            $tenantId = is_object($tenant) && isset($tenant->id) ? (string) $tenant->id : (string) $request->header('X-Tenant-Id', '');

            if ($tenantId !== '') {
                $meter = \App\Models\UsageMeter::query()
                    ->where('tenant_id', $tenantId)
                    ->where('meter_type', 'api_requests')
                    ->first();

                if ($meter) {
                    if ($meter->period_end && now()->greaterThan($meter->period_end)) {
                        $meter->period_start = now()->startOfDay();
                        $meter->period_end = now()->addMonthNoOverflow()->endOfDay();
                        $meter->consumed_units = 0;
                        $meter->save();
                    }

                    $limit = (int) $meter->limit_units;
                    $consumed = (int) $meter->consumed_units;

                    // Sync database consumed units with Laravel cache-based rate limiter
                    $rateLimiter = app(\Illuminate\Cache\RateLimiter::class);
                    $cacheKey = md5('api'.$tenantId);
                    $currentAttempts = $rateLimiter->attempts($cacheKey);

                    if ($currentAttempts < $consumed) {
                        for ($i = $currentAttempts; $i < $consumed; $i++) {
                            $rateLimiter->hit($cacheKey, 60);
                        }
                    }

                    if ($consumed >= $limit) {
                        return Limit::perMinute($limit)
                            ->by($tenantId)
                            ->response(function (Request $request, array $headers) {
                                return response()->json([
                                    'error' => [
                                        'code' => 'BILLING_USAGE_LIMIT_EXCEEDED',
                                        'message' => 'API request quota exceeded. Please upgrade your plan.',
                                    ],
                                ], 429, $headers);
                            });
                    }

                    $meter->consumed_units = $consumed + 1;
                    $meter->save();

                    \App\Models\UsageEvent::query()->create([
                        'tenant_id' => $tenantId,
                        'subscription_id' => $meter->subscription_id,
                        'meter_type' => 'api_requests',
                        'quantity' => 1,
                        'source_type' => 'api_request',
                        'occurred_at' => now(),
                    ]);

                    return Limit::perMinute($limit)->by($tenantId);
                }
            }

            return Limit::perMinute(180)->by($tenantId !== '' ? $tenantId.'|'.$ip : 'public|'.$ip);
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
