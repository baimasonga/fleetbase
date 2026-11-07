<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Proof of Delivery internal API (console)
|--------------------------------------------------------------------------
|
| Under /int/v1/pod, behind Fleetbase's console authentication. Every
| route needs a PoD permission (pod.can, see Auth/Schemas/Pod.php), except
| the reference lists of countries and admin areas.
|
*/
Route::prefix(config('pod.api.internal_prefix', 'int') . '/v1/pod')
    ->namespace('Fleetbase\Pod\Http\Controllers\Internal\v1')
    ->middleware(['fleetbase.protected'])
    ->group(function ($router) {
        $router->get('countries', 'AdminAreaController@countries');
        $router->get('admin-areas', 'AdminAreaController@index');

        $router->get('programmes', 'ProgrammeController@index')->middleware('pod.can:list programme');
        $router->post('programmes', 'ProgrammeController@store')->middleware('pod.can:create programme');
        $router->get('programmes/{id}', 'ProgrammeController@show')->middleware('pod.can:view programme');
        $router->put('programmes/{id}', 'ProgrammeController@update')->middleware('pod.can:update programme');

        $router->get('programmes/{id}/farmer-list-template', 'BeneficiaryImportController@template')->middleware('pod.can:import farmer');
        $router->post('programmes/{id}/imports', 'BeneficiaryImportController@upload')->middleware('pod.can:import farmer');
        $router->get('imports/{id}', 'BeneficiaryImportController@show')->middleware('pod.can:import farmer');
        $router->post('imports/{id}/preview', 'BeneficiaryImportController@preview')->middleware('pod.can:import farmer');
        $router->get('imports/{id}/rows', 'BeneficiaryImportController@rows')->middleware('pod.can:import farmer');
        $router->post('imports/{id}/rows/{row}/decision', 'BeneficiaryImportController@decide')->whereNumber('row')->middleware('pod.can:import farmer');
        $router->post('imports/{id}/commit', 'BeneficiaryImportController@commit')->middleware('pod.can:import farmer');

        $router->get('programmes/{id}/items', 'EntitlementController@items')->middleware('pod.can:view programme');
        $router->put('programmes/{id}/items', 'EntitlementController@setItems')->middleware('pod.can:update programme');
        $router->get('programmes/{id}/entitlement-rules', 'EntitlementController@rules')->middleware('pod.can:view programme');
        $router->put('programmes/{id}/entitlement-rules', 'EntitlementController@setRules')->middleware('pod.can:update programme');
        $router->post('programmes/{id}/entitlements/generate', 'EntitlementController@generate')->middleware('pod.can:generate entitlement');
        $router->get('programmes/{id}/entitlements/summary', 'EntitlementController@summary')->middleware('pod.can:view entitlement');

        $router->get('programmes/{id}/beneficiaries', 'BeneficiaryController@index')->middleware('pod.can:list farmer');
        $router->get('beneficiaries/{id}', 'BeneficiaryController@show')->middleware('pod.can:view farmer');
        $router->post('beneficiaries/{id}/verify', 'BeneficiaryController@verify')->middleware('pod.can:verify farmer');
        $router->post('beneficiaries/{id}/reject', 'BeneficiaryController@reject')->middleware('pod.can:reject farmer');
        $router->post('beneficiaries/{id}/card/reissue', 'BeneficiaryController@reissue')->middleware('pod.can:reissue card');
        $router->get('beneficiaries/{id}/card', 'BeneficiaryController@card')->middleware('pod.can:print card');

        $router->post('programmes/{id}/cards/pdf', 'CardController@pdf')->middleware('pod.can:print card');
        $router->post('programmes/{id}/cards/check', 'CardController@check')->middleware('pod.can:check card');

        $router->get('sites', 'SiteController@index')->middleware('pod.can:list site');
        $router->post('sites', 'SiteController@store')->middleware('pod.can:create site');
        $router->get('sites/{id}', 'SiteController@show')->middleware('pod.can:view site');
        $router->post('sites/{id}/stock/receive', 'SiteController@receive')->middleware('pod.can:receive stock');

        $router->get('legs', 'TransportLegController@all')->middleware('pod.can:list trip|list own-trip');
        $router->get('programmes/{id}/legs', 'TransportLegController@index')->middleware('pod.can:list trip|list own-trip');
        $router->post('programmes/{id}/legs', 'TransportLegController@store')->middleware('pod.can:create trip');
        $router->get('legs/{id}', 'TransportLegController@show')->middleware('pod.can:view trip|view own-trip');
        $router->post('legs/{id}/load', 'TransportLegController@load')->middleware('pod.can:load trip');
        $router->post('legs/{id}/depart', 'TransportLegController@depart')->middleware('pod.can:depart trip|depart own-trip');
        $router->post('legs/{id}/arrive', 'TransportLegController@arrive')->middleware('pod.can:arrive trip|arrive own-trip');
        $router->post('legs/{id}/receive', 'TransportLegController@receive')->middleware('pod.can:receive trip');
        $router->post('legs/{id}/close', 'TransportLegController@close')->middleware('pod.can:close trip');
        $router->post('legs/{id}/cancel', 'TransportLegController@cancel')->middleware('pod.can:cancel trip');
        $router->post('discrepancies/{id}/resolve', 'TransportLegController@resolveDiscrepancy')->middleware('pod.can:resolve discrepancy');

        $router->get('programmes/{id}/events', 'DistributionEventController@index')->middleware('pod.can:list distribution');
        $router->post('programmes/{id}/events', 'DistributionEventController@store')->middleware('pod.can:create distribution');
        $router->get('events/{id}', 'DistributionEventController@show')->middleware('pod.can:view distribution');
        $router->post('events/{id}/assign', 'DistributionEventController@assign')->middleware('pod.can:assign distribution');
        $router->post('events/{id}/open', 'DistributionEventController@open')->middleware('pod.can:open distribution');
        $router->get('events/{id}/handovers', 'DistributionEventController@handovers')->middleware('pod.can:list handover');
        $router->post('events/{id}/handovers', 'DistributionEventController@handover')->middleware('pod.can:create handover');
        $router->post('events/{id}/attempts', 'DistributionEventController@failAttempt')->middleware('pod.can:create handover');
        $router->post('events/{id}/reassign', 'DistributionEventController@reassign')->middleware('pod.can:reassign distribution');
        $router->post('events/{id}/discrepancies', 'DistributionEventController@discrepancy')->middleware('pod.can:create discrepancy');
        $router->post('events/{id}/close', 'DistributionEventController@close')->middleware('pod.can:close distribution');
        $router->get('events/{id}/report', 'DistributionEventController@report')->middleware('pod.can:view report');

        $router->get('me', 'FieldController@me');
        $router->get('field/events', 'FieldController@events')->middleware('pod.can:sync field');
        $router->get('events/{id}/bundle', 'FieldController@bundle')->middleware('pod.can:sync field');
        $router->post('field/sync', 'FieldController@sync')->middleware('pod.can:sync field');
        $router->post('field/media', 'FieldController@media')->middleware('pod.can:sync field');
        $router->get('events/{id}/media-status', 'FieldController@mediaStatus')->middleware('pod.can:list handover');
        $router->get('events/{id}/conflicts', 'FieldController@conflicts')->middleware('pod.can:list conflict');

        $router->get('conflicts', 'ConflictController@index')->middleware('pod.can:list conflict');
        $router->get('conflicts/{id}', 'ConflictController@show')->middleware('pod.can:view conflict');
        $router->post('conflicts/{id}/resolve', 'ConflictController@resolve')->middleware('pod.can:resolve conflict');
        $router->get('media/{id}', 'ConflictController@media')->middleware('pod.can:view media');
    });

/*
|--------------------------------------------------------------------------
| Offline field app (static files)
|--------------------------------------------------------------------------
*/
Route::get('pod-field/{path?}', [Fleetbase\Pod\Http\Controllers\Field\FieldAppController::class, 'serve'])->where('path', '.*');
