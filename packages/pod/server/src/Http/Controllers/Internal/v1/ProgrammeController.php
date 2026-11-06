<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

use Fleetbase\Pod\Models\Programme;
use Fleetbase\Pod\Support\Countries\CountryProfiles;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgrammeController extends PodController
{
    public function index(Request $request)
    {
        $programmes = Programme::where('company_uuid', $this->companyUuid())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->withCount('beneficiaries')
            ->orderByDesc('created_at')
            ->paginate(min(100, (int) $request->input('limit', 25)));

        return response()->json($programmes);
    }

    public function store(Request $request, CountryProfiles $countries)
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:191'],
            'operator_type'    => ['required', Rule::in(Programme::OPERATOR_TYPES)],
            'funder'           => ['nullable', 'string', 'max:191'],
            'season'           => ['nullable', 'string', 'max:191'],
            'country'          => ['nullable', 'string', 'size:2'],
            'planting_windows' => ['nullable', 'array'],
            'rules'            => ['nullable', 'array'],
        ]);

        $data['country'] = strtoupper($data['country'] ?? $countries->default()->code);
        if (!$countries->has($data['country'])) {
            return $this->error("No country profile for {$data['country']}.");
        }

        $programme = Programme::create($data + [
            'company_uuid'    => $this->companyUuid(),
            'status'          => 'draft',
            'created_by_uuid' => $this->userUuid(),
        ]);

        return response()->json(['programme' => $programme], 201);
    }

    public function show(string $id)
    {
        $programme = $this->programme($id)->loadCount('beneficiaries');

        return response()->json(['programme' => $programme]);
    }

    public function update(Request $request, string $id)
    {
        $programme = $this->programme($id);
        $data      = $request->validate([
            'name'             => ['sometimes', 'string', 'max:191'],
            'operator_type'    => ['sometimes', Rule::in(Programme::OPERATOR_TYPES)],
            'funder'           => ['nullable', 'string', 'max:191'],
            'season'           => ['nullable', 'string', 'max:191'],
            'status'           => ['sometimes', Rule::in(Programme::STATUSES)],
            'planting_windows' => ['nullable', 'array'],
            'rules'            => ['nullable', 'array'],
        ]);

        $programme->update($data);

        return response()->json(['programme' => $programme]);
    }
}
