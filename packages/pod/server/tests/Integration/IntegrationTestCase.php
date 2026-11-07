<?php

namespace Fleetbase\Pod\Tests\Integration;

use Fleetbase\Pod\Providers\PodServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    public const COMPANY = '11111111-1111-1111-1111-111111111111';
    public const OTHER_COMPANY = '22222222-2222-2222-2222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        // Fail loudly when code writes a field the model doesn't allow, instead of
        // Laravel silently dropping it (this hid a bug in attempt counting).
        \Illuminate\Database\Eloquent\Model::preventSilentlyDiscardingAttributes();
    }

    protected function tearDown(): void
    {
        \Illuminate\Database\Eloquent\Model::preventSilentlyDiscardingAttributes(false);
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [PodServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('filesystems.disks.local.root', sys_get_temp_dir() . '/pod-tests-' . getmypid());

        // Stand-in for Fleetbase's console auth: the company comes from a header.
        $app['router']->aliasMiddleware('fleetbase.protected', FakeFleetbaseAuth::class);
        $app['router']->aliasMiddleware('fleetbase.api', FakeFleetbaseAuth::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');

        // The two Fleet-Ops tables the proof guard reads.
        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36);
            $table->string('public_id');
            $table->char('company_uuid', 36);
            $table->string('status')->default('started');
            $table->boolean('pod_required')->default(false);
            $table->string('pod_method')->nullable();
            $table->timestamps();
        });
        Schema::create('proofs', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36);
            $table->string('public_id');
            $table->char('order_uuid', 36);
            $table->timestamps();
        });
    }

    protected function defineRoutes($router): void
    {
        // The Fleet-Ops public API route the guard attaches to.
        $router->middleware('fleetbase.api')->match(['post', 'patch'], 'v1/orders/{id}/update-activity', 'Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController@updateActivity');
    }

    public const STOREKEEPER = '33333333-3333-3333-3333-333333333333';
    public const RECEIVER    = '44444444-4444-4444-4444-444444444444';

    protected function asCompany(string $company = self::COMPANY, string $user = self::STOREKEEPER): static
    {
        return $this->withHeaders(['X-Test-Company' => $company, 'X-Test-User' => $user, 'Accept' => 'application/json']);
    }
}

class FakeFleetbaseAuth
{
    public function handle(Request $request, \Closure $next)
    {
        $company = $request->header('X-Test-Company');
        if (!$company) {
            return response()->json(['errors' => ['Unauthenticated.']], 401);
        }
        session(['company' => $company, 'user' => $request->header('X-Test-User')]);

        return $next($request);
    }
}
