<?php

namespace Fleetbase\Pod\Http\Requests;

/**
 * Validation rules for field records, shared by the online endpoints and the
 * offline sync endpoint so both accept exactly the same input.
 */
final class FieldRules
{
    public static function handover(): array
    {
        return [
            'client_handover_id'         => ['nullable', 'string', 'max:64'],
            'card_code'                  => ['nullable', 'string', 'max:255'],
            'beneficiary'                => ['nullable', 'string'],
            'received_by'                => ['nullable', 'in:self,household_member,proxy'],
            'recipient_name'             => ['nullable', 'string', 'max:191'],
            'identity'                   => ['nullable', 'array'],
            'identity.face_matched'      => ['nullable', 'boolean'],
            'identity.id_last4'          => ['nullable', 'string', 'max:8'],
            'identity.photo_taken'       => ['nullable', 'boolean'],
            'identity.witness_confirmed' => ['nullable', 'boolean'],
            'lines'                      => ['required', 'array', 'min:1'],
            'lines.*.sku'                => ['required', 'string'],
            'lines.*.lot'                => ['nullable', 'string', 'max:64'],
            'lines.*.quantity'           => ['required', 'numeric'],
            'evidence'                   => ['nullable', 'array'],
            'evidence.*.type'            => ['required', 'string', 'max:32'],
            'evidence.*.media'           => ['nullable', 'string', 'max:64'],
            'evidence.*.sha256'          => ['nullable', 'string', 'size:64'],
            'evidence.*.name'            => ['nullable', 'string', 'max:191'],
            'latitude'                   => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'                  => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m'                 => ['nullable', 'numeric', 'min:0'],
            'captured_at'                => ['nullable', 'date'],
        ];
    }

    /**
     * An opening or closing count recorded on a phone.
     */
    public static function count(): array
    {
        return [
            'stock'                 => ['present', 'array'],
            'stock.*.sku'           => ['required', 'string'],
            'stock.*.lot'           => ['nullable', 'string', 'max:64'],
            'stock.*.quantity'      => ['required', 'numeric', 'min:0'],
            'witnesses'             => ['required', 'array', 'min:1'],
            'witnesses.*.name'      => ['required', 'string', 'max:191'],
            'witnesses.*.role'      => ['required', 'string'],
            'witnesses.*.signature' => ['nullable', 'string', 'max:64'],
            'witnesses.*.sha256'    => ['nullable', 'string', 'size:64'],
            'captured_at'           => ['nullable', 'date'],
        ];
    }

    public static function discrepancy(): array
    {
        return [
            'sku'          => ['required', 'string'],
            'lot'          => ['nullable', 'string', 'max:64'],
            'quantity'     => ['required', 'numeric'],
            'reason'       => ['required', 'string'],
            'liable_party' => ['nullable', 'string'],
            'notes'        => ['required', 'string', 'max:2000'],
            'evidence'     => ['nullable', 'array'],
            'captured_at'  => ['nullable', 'date'],
        ];
    }

    public static function attempt(): array
    {
        return [
            'client_handover_id' => ['nullable', 'string', 'max:64'],
            'beneficiary'        => ['required', 'string'],
            'reason'             => ['required', 'string'],
            'evidence'           => ['nullable', 'array'],
            'evidence.*.type'    => ['required', 'string', 'max:32'],
            'evidence.*.media'   => ['nullable', 'string', 'max:64'],
            'evidence.*.sha256'  => ['nullable', 'string', 'size:64'],
            'latitude'           => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'          => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m'         => ['nullable', 'numeric', 'min:0'],
            'captured_at'        => ['nullable', 'date'],
        ];
    }
}
