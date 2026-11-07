<?php

namespace Fleetbase\Pod\Providers;

use Fleetbase\Pod\Auth\FleetbaseGate;
use Fleetbase\Pod\Auth\Gate;
use Fleetbase\Pod\Console\Commands\CheckRuntime;
use Fleetbase\Pod\Console\Commands\CreateAdmin;
use Fleetbase\Pod\Console\Commands\SeedAdminAreas;
use Fleetbase\Pod\Http\Middleware\EnforceProofOfDelivery;
use Fleetbase\Pod\Http\Middleware\RequirePermission;
use Fleetbase\Pod\Services\AdminAreaRepository;
use Fleetbase\Pod\Support\Countries\CountryProfiles;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class PodServiceProvider extends ServiceProvider
{
    /**
     * The Fleet-Ops public API action the proof guard is attached to.
     */
    public const GUARDED_ACTION = 'Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController@updateActivity';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/pod.php', 'pod');

        $this->app->singleton(CountryProfiles::class, fn () => new CountryProfiles(
            config('pod.countries', []),
            config('pod.default_country', 'SL')
        ));

        $this->app->singleton(AdminAreaRepository::class);

        // Who may do what: Fleetbase IAM roles (see Auth/Schemas/Pod.php). One per request.
        $this->app->scoped(Gate::class, FleetbaseGate::class);
    }

    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('pod.can', RequirePermission::class);
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([SeedAdminAreas::class, CreateAdmin::class, CheckRuntime::class]);
        }

        $this->attachProofGuard();
    }

    /**
     * Add the proof guard to the Fleet-Ops activity update route when it is
     * matched. Doing it at match time (not by editing the route list) also
     * works with cached routes, and leaves Fleet-Ops unchanged.
     */
    private function attachProofGuard(): void
    {
        Event::listen(RouteMatched::class, function (RouteMatched $event) {
            $route = $event->route;
            if ($route->getActionName() !== self::GUARDED_ACTION) {
                return;
            }
            if (!in_array(EnforceProofOfDelivery::class, $route->middleware(), true)) {
                $route->middleware(EnforceProofOfDelivery::class);
            }
        });
    }
}
