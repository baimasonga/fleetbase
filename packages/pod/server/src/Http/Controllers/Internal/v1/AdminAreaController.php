<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

use Fleetbase\Pod\Services\AdminAreaRepository;
use Fleetbase\Pod\Support\AdminAreas\AdminArea;
use Fleetbase\Pod\Support\Countries\CountryProfiles;
use Illuminate\Http\Request;

class AdminAreaController extends PodController
{
    /**
     * GET /int/v1/pod/admin-areas?country=SL&level=3&parent=SL0101
     */
    public function index(Request $request, AdminAreaRepository $repository, CountryProfiles $countries)
    {
        $this->companyUuid();

        $country = $countries->get($request->input('country'));
        $areas   = $repository->forCountry($country->code);
        $level   = $request->integer('level') ?: null;
        $parent  = $request->input('parent');

        $list = match (true) {
            $parent !== null && $level !== null => $areas->atLevelUnder($level, $parent),
            $parent !== null                    => $areas->childrenOf($parent),
            $level !== null                     => $areas->atLevel($level),
            default                             => $areas->atLevel(1),
        };

        return response()->json([
            'country'      => $country->code,
            'admin_levels' => $country->adminLevels,
            'areas'        => array_map(fn (AdminArea $area) => $area->toArray(), $list),
        ]);
    }

    /**
     * GET /int/v1/pod/countries — the country profiles, for programme setup screens.
     */
    public function countries(CountryProfiles $countries)
    {
        $this->companyUuid();

        return response()->json([
            'default'   => $countries->default()->code,
            'countries' => array_values(array_map(fn ($c) => [
                'code'         => $c->code,
                'name'         => $c->name,
                'dial_code'    => $c->dialCode,
                'timezone'     => $c->timezone,
                'admin_levels' => $c->adminLevels,
                'id_types'     => $c->idTypeLabels(),
            ], $countries->all())),
        ]);
    }
}
