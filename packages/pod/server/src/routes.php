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

        $router->get('programmes/{id}/items', 'EntitlementController@items');
        $router->put('programmes/{id}/items', 'EntitlementController@setItems');
        $router->get('programmes/{id}/entitlement-rules', 'EntitlementController@rules');
        $router->put('programmes/{id}/entitlement-rules', 'EntitlementController@setRules');
        $router->post('programmes/{id}/entitlements/generate', 'EntitlementController@generate');
        $router->get('programmes/{id}/entitlements/summary', 'EntitlementController@summary');

        $router->get('programmes/{id}/beneficiaries', 'BeneficiaryController@index');
        $router->get('beneficiaries/{id}', 'BeneficiaryController@show');
        $router->post('beneficiaries/{id}/verify', 'BeneficiaryController@verify');
        $router->post('beneficiaries/{id}/reject', 'BeneficiaryController@reject');
        $router->post('beneficiaries/{id}/card/reissue', 'BeneficiaryController@reissue');
        $router->get('beneficiaries/{id}/card', 'BeneficiaryController@card');

        $router->post('programmes/{id}/cards/pdf', 'CardController@pdf');
        $router->post('programmes/{id}/cards/check', 'CardController@check');
    });
