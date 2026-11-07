<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Services\CardService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 *   GET  programmes/{id}/beneficiaries    ?verification_status=&status=&admin_2=&admin_3=&village=&q=
 *   GET  beneficiaries/{id}               with entitlements and card history
 *   POST beneficiaries/{id}/verify        {"method", "consent": true, "updates": {...}} -> issues the card
 *   POST beneficiaries/{id}/reject        {"reason"}
 *   POST beneficiaries/{id}/card/reissue  {"reason"} -> the old card stops working
 *   GET  beneficiaries/{id}/card          the current card code
 */
class BeneficiaryController extends PodController
{
    public function __construct(private CardService $cards)
    {
    }

    public function index(Request $request, string $id)
    {
        $programme = $this->programme($id);

        $query = Beneficiary::where('programme_uuid', $programme->uuid)
            ->when($request->filled('verification_status'), fn ($q) => $q->where('verification_status', $request->input('verification_status')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('village'), fn ($q) => $q->where('village', $request->input('village')));

        foreach (['admin_1', 'admin_2', 'admin_3', 'admin_4'] as $level) {
            $query->when($request->filled($level), fn ($q) => $q->where($level, $request->input($level)));
        }

        if ($request->filled('q')) {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], $request->input('q')) . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('farmer_ref', 'like', $term)->orWhere('public_id', 'like', $term)->orWhere('phone', 'like', $term));
        }

        return response()->json($query->orderBy('name')->paginate(min(200, (int) $request->input('limit', 50))));
    }

    public function show(string $id)
    {
        $beneficiary = $this->beneficiary($id)->load(['entitlements', 'cardEvents']);

        return response()->json(['beneficiary' => $beneficiary]);
    }

    public function verify(Request $request, string $id)
    {
        $beneficiary = $this->beneficiary($id);
        $data        = $request->validate([
            'method'  => ['required', Rule::in(CardService::VERIFICATION_METHODS)],
            'consent' => ['required', 'boolean'],
            'updates' => ['nullable', 'array'],
            'updates.household_size'              => ['nullable', 'integer', 'min:1', 'max:100'],
            'updates.latitude'                    => ['nullable', 'numeric', 'between:-90,90'],
            'updates.longitude'                   => ['nullable', 'numeric', 'between:-180,180'],
            'updates.trained_restricted_products' => ['nullable', 'date'],
            'updates.livestock'                   => ['nullable', 'array'],
            'updates.livestock.*'                 => ['integer', 'min:0'],
        ]);

        try {
            $beneficiary = $this->cards->verify($beneficiary, $data['method'], (bool) $data['consent'], $data['updates'] ?? [], $this->userUuid());
        } catch (\InvalidArgumentException|\LogicException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['beneficiary' => $beneficiary, 'card_code' => $this->cards->code($beneficiary)]);
    }

    public function reject(Request $request, string $id)
    {
        $beneficiary = $this->beneficiary($id);
        $reason      = $request->validate(['reason' => ['required', Rule::in(CardService::REJECTION_REASONS)]])['reason'];

        return response()->json(['beneficiary' => $this->cards->reject($beneficiary, $reason, $this->userUuid())]);
    }

    public function reissue(Request $request, string $id)
    {
        $beneficiary = $this->beneficiary($id);
        $reason      = $request->validate(['reason' => ['required', Rule::in(CardService::REISSUE_REASONS)]])['reason'];

        try {
            $beneficiary = $this->cards->reissue($beneficiary, $reason, $this->userUuid());
        } catch (\LogicException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['beneficiary' => $beneficiary, 'card_code' => $this->cards->code($beneficiary)]);
    }

    public function card(string $id)
    {
        $beneficiary = $this->beneficiary($id);

        try {
            return response()->json(['card_code' => $this->cards->code($beneficiary), 'card_issue' => $beneficiary->card_issue]);
        } catch (\LogicException $e) {
            return $this->error($e->getMessage());
        }
    }
}
