<?php

namespace App\Providers;

use App\Models\IptvUser;
use App\Models\M3uSource;
use App\Models\TuliproxServer;
use App\Observers\IptvUserObserver;
use App\Observers\M3uSourceObserver;
use App\Observers\TuliproxServerObserver;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS URLs in production when behind a reverse proxy
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        // Register observers for tuliprox sync
        IptvUser::observe(IptvUserObserver::class);
        M3uSource::observe(M3uSourceObserver::class);
        TuliproxServer::observe(TuliproxServerObserver::class);
    }
}
