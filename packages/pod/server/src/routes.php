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

        $router->get('sites', 'SiteController@index');
        $router->post('sites', 'SiteController@store');
        $router->get('sites/{id}', 'SiteController@show');
        $router->post('sites/{id}/stock/receive', 'SiteController@receive');

        $router->get('programmes/{id}/legs', 'TransportLegController@index');
        $router->post('programmes/{id}/legs', 'TransportLegController@store');
        $router->get('legs/{id}', 'TransportLegController@show');
        $router->post('legs/{id}/load', 'TransportLegController@load');
        $router->post('legs/{id}/depart', 'TransportLegController@depart');
        $router->post('legs/{id}/arrive', 'TransportLegController@arrive');
        $router->post('legs/{id}/receive', 'TransportLegController@receive');
        $router->post('legs/{id}/close', 'TransportLegController@close');
        $router->post('legs/{id}/cancel', 'TransportLegController@cancel');
        $router->post('discrepancies/{id}/resolve', 'TransportLegController@resolveDiscrepancy');

        $router->get('programmes/{id}/events', 'DistributionEventController@index');
        $router->post('programmes/{id}/events', 'DistributionEventController@store');
        $router->get('events/{id}', 'DistributionEventController@show');
        $router->post('events/{id}/assign', 'DistributionEventController@assign');
        $router->post('events/{id}/open', 'DistributionEventController@open');
        $router->get('events/{id}/handovers', 'DistributionEventController@handovers');
        $router->post('events/{id}/handovers', 'DistributionEventController@handover');
        $router->post('events/{id}/attempts', 'DistributionEventController@failAttempt');
        $router->post('events/{id}/reassign', 'DistributionEventController@reassign');
        $router->post('events/{id}/discrepancies', 'DistributionEventController@discrepancy');
        $router->post('events/{id}/close', 'DistributionEventController@close');
        $router->get('events/{id}/report', 'DistributionEventController@report');

        $router->get('field/events', 'FieldController@events');
        $router->get('events/{id}/bundle', 'FieldController@bundle');
        $router->post('field/sync', 'FieldController@sync');
        $router->post('field/media', 'FieldController@media');
        $router->get('events/{id}/media-status', 'FieldController@mediaStatus');
        $router->get('events/{id}/conflicts', 'FieldController@conflicts');
    });

/*
|--------------------------------------------------------------------------
| Offline field app (static files)
|--------------------------------------------------------------------------
*/
Route::get('pod-field/{path?}', [Fleetbase\Pod\Http\Controllers\Field\FieldAppController::class, 'serve'])->where('path', '.*');
