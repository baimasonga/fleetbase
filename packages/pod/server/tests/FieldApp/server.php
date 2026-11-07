<?php
// php -S 127.0.0.1:8099 server.php
require __DIR__ . '/app.php';

$app = e2e_app();

// Stand-in for Fleetbase's login: one field officer account.
$app['router']->post('int/v1/auth/login', function (\Illuminate\Http\Request $request) {
    if ($request->input('identity') === 'officer@example.org' && $request->input('password') === 'field-pass') {
        return response()->json(['token' => 'e2e-11111111-1111-1111-1111-111111111111', 'type' => 'user']);
    }

    return response()->json(['errors' => ['These credentials do not match our records.']], 401);
});

$kernel   = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$request  = \Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
