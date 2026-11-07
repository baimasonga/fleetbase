<?php
// Boots the PoD package on Laravel (Orchestra Testbench) with SQLite, for end-to-end tests
// of the field app. Fleetbase core is replaced by the package's test stand-ins.

// POD_TEST_VENDOR: a vendor directory with orchestra/testbench (see the package README).
// POD_E2E_DIR: where the database, uploads and fixtures go (a temporary folder by default).
if (!getenv('POD_TEST_VENDOR')) {
    fwrite(STDERR, "Set POD_TEST_VENDOR to a vendor directory with orchestra/testbench.\n");
    exit(1);
}
define('POD_E2E_DIR', getenv('POD_E2E_DIR') ?: sys_get_temp_dir() . '/pod-e2e');
@mkdir(POD_E2E_DIR . '/storage', 0777, true);
require __DIR__ . '/../Integration/bootstrap.php';

use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application;

final class E2eAuth
{
    // "Bearer e2e-<company>" -> that company, as the field officer;
    // "Bearer e2e-<company>~<user>" -> that company, as another user. Anything else -> 401.
    public function handle(Request $request, \Closure $next)
    {
        $token = (string) $request->bearerToken();
        if (!str_starts_with($token, 'e2e-')) {
            return response()->json(['errors' => ['Unauthenticated.']], 401);
        }
        [$company, $user] = array_pad(explode('~', substr($token, 4), 2), 2, '33333333-3333-3333-3333-333333333333');
        session(['company' => $company, 'user' => $user]);

        return $next($request);
    }
}

function e2e_app(): \Illuminate\Foundation\Application
{
    $db = POD_E2E_DIR . '/e2e.sqlite';
    if (!file_exists($db)) {
        touch($db);
    }

    $app = Application::create(
        basePath: null,
        options: ['extra' => ['providers' => [\Fleetbase\Pod\Providers\PodServiceProvider::class]]],
    );

    // Everything below is read lazily, so it can be set after boot.
    $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
    $app['config']->set('app.debug', true);
    $app['config']->set('database.default', 'e2e');
    $app['config']->set('database.connections.e2e', ['driver' => 'sqlite', 'database' => $db, 'foreign_key_constraints' => false]);
    $app['config']->set('filesystems.default', 'local');
    $app['config']->set('filesystems.disks.local.root', POD_E2E_DIR . '/storage');
    $app['config']->set('session.driver', 'array');
    $app['config']->set('pod.cards.secret', 'e2e-card-secret');
    $app['config']->set('pod.import.id_hash_key', 'e2e-id-key');
    $app['router']->aliasMiddleware('fleetbase.protected', E2eAuth::class);
    $app['router']->aliasMiddleware('fleetbase.api', E2eAuth::class);

    // Stand-in for Fleetbase IAM: the officer, the supervisor and an administrator (for the test's own checks).
    $app->scoped(\Fleetbase\Pod\Auth\Gate::class, fn () => new \Fleetbase\Pod\Auth\RoleGate([
        '33333333-3333-3333-3333-333333333333' => ['PoD Field Officer'],
        '55555555-5555-5555-5555-555555555555' => ['PoD Supervisor'],
        'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => ['Administrator'],
    ]));

    return $app;
}
