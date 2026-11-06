<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Proof of Delivery internal API (console)
|--------------------------------------------------------------------------
|
| Under /int/v1/pod, behind Fleetbase's console authentication.
|
*/
Route::prefix(config('pod.api.internal_prefix', 'int') . '/v1/pod')
    ->namespace('Fleetbase\Pod\Http\Controllers\Internal\v1')
    ->middleware(['fleetbase.protected'])
    ->group(function ($router) {
        $router->get('countries', 'AdminAreaController@countries');
        $router->get('admin-areas', 'AdminAreaController@index');

        $router->get('programmes', 'ProgrammeController@index');
        $router->post('programmes', 'ProgrammeController@store');
        $router->get('programmes/{id}', 'ProgrammeController@show');
        $router->put('programmes/{id}', 'ProgrammeController@update');

        $router->get('programmes/{id}/farmer-list-template', 'BeneficiaryImportController@template');
        $router->post('programmes/{id}/imports', 'BeneficiaryImportController@upload');
        $router->get('imports/{id}', 'BeneficiaryImportController@show');
        $router->post('imports/{id}/preview', 'BeneficiaryImportController@preview');
        $router->get('imports/{id}/rows', 'BeneficiaryImportController@rows');
        $router->post('imports/{id}/rows/{row}/decision', 'BeneficiaryImportController@decide')->whereNumber('row');
        $router->post('imports/{id}/commit', 'BeneficiaryImportController@commit');
    });
