<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Services\CardService;
use Illuminate\Http\Request;

/**
 *   POST programmes/{id}/cards/pdf     {"beneficiaries": [ids]} or {"admin_3": "SL010101", "village": "..."}
 *   POST programmes/{id}/cards/check   {"code": "FBP1..."}
 */
class CardController extends PodController
{
    public function __construct(private CardService $cards)
    {
    }

    public function pdf(Request $request, string $id)
    {
        $programme = $this->programme($id);
        $data      = $request->validate([
            'beneficiaries'   => ['nullable', 'array', 'max:' . CardService::MAX_CARDS_PER_PDF],
            'beneficiaries.*' => ['string'],
            'admin_2'         => ['nullable', 'string'],
            'admin_3'         => ['nullable', 'string'],
            'admin_4'         => ['nullable', 'string'],
            'village'         => ['nullable', 'string'],
        ]);

        if (empty($data['beneficiaries']) && empty($data['admin_2']) && empty($data['admin_3']) && empty($data['admin_4']) && empty($data['village'])) {
            return $this->error('Choose the beneficiaries, or an area or village, to print cards for.');
        }

        $query = Beneficiary::where('programme_uuid', $programme->uuid)->with('programme');
        if (!empty($data['beneficiaries'])) {
            $query->where(fn ($q) => $q->whereIn('public_id', $data['beneficiaries'])->orWhereIn('uuid', $data['beneficiaries']));
        }
        foreach (['admin_2', 'admin_3', 'admin_4', 'village'] as $field) {
            if (!empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        // One more than the limit, so an over-large selection is refused rather than cut short.
        $beneficiaries = $query->orderBy('admin_3')->orderBy('village')->orderBy('name')->limit(CardService::MAX_CARDS_PER_PDF + 1)->get();

        try {
            $result = $this->cards->pdf($programme, $beneficiaries, $this->userUuid());
        } catch (\LogicException $e) {
            return $this->error($e->getMessage());
        }

        $filename = 'Farmer cards - ' . preg_replace('/[^A-Za-z0-9 _-]/', '', $programme->name) . ' - ' . $result['batch'] . '.pdf';

        return response($result['pdf'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Cards-Printed'     => (string) $result['count'],
            'X-Cards-Skipped'     => (string) $result['skipped'],
            'X-Print-Batch'       => $result['batch'],
        ]);
    }

    public function check(Request $request, string $id)
    {
        $programme = $this->programme($id);
        $code      = $request->validate(['code' => ['required', 'string', 'max:255']])['code'];

        return response()->json($this->cards->check($programme, $code));
    }
}
