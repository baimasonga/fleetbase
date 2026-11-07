<?php

namespace Fleetbase\Pod\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\CardEvent;
use Fleetbase\Pod\Models\Entitlement;
use Fleetbase\Pod\Models\Programme;
use Fleetbase\Pod\Support\Cards\CardData;
use Fleetbase\Pod\Support\Cards\CardSheetRenderer;
use Fleetbase\Pod\Support\Cards\ProgrammeCard;
use Fleetbase\Pod\Support\Countries\CountryProfiles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Field verification and programme cards (AGRI-INPUTS.md §7.1 step 3, §7.2).
 *
 * A card is issued when a beneficiary is verified. Reissuing (lost, damaged,
 * stolen) increments the card's issue number, which revokes every earlier
 * card. Every action is written to pod_card_events.
 */
class CardService
{
    public const VERIFICATION_METHODS = ['community_validation', 'field_visit', 'first_handover'];

    public const REJECTION_REASONS = ['not_known', 'moved', 'deceased', 'duplicate', 'ineligible', 'other'];

    public const REISSUE_REASONS = ['lost', 'damaged', 'stolen', 'details_changed'];

    public const MAX_CARDS_PER_PDF = 400;

    /**
     * Fields that can be filled in or corrected at verification.
     */
    public const VERIFICATION_FIELDS = ['village', 'group_name', 'household_size', 'farm_size', 'crop', 'livestock', 'latitude', 'longitude', 'trained_restricted_products', 'household_members', 'proxy'];

    public function __construct(
        private EntitlementService $entitlements,
        private AdminAreaRepository $areas,
        private CountryProfiles $countries,
    ) {
    }

    public function programmeKey(Programme $programme): string
    {
        return ProgrammeCard::programmeKey((string) config('pod.cards.secret'), $programme->public_id);
    }

    public function code(Beneficiary $beneficiary): string
    {
        if ($beneficiary->card_issue < 1 || !$beneficiary->isVerified()) {
            throw new \LogicException('This beneficiary has no card yet. Verify them first.');
        }

        return ProgrammeCard::issue($this->programmeKey($beneficiary->programme), $beneficiary->programme->public_id, $beneficiary->public_id, $beneficiary->card_issue);
    }

    /**
     * Mark a beneficiary verified, record consent, apply field corrections and issue their card.
     */
    public function verify(Beneficiary $beneficiary, string $method, bool $consent, array $updates = [], ?string $userUuid = null): Beneficiary
    {
        if (!in_array($method, self::VERIFICATION_METHODS, true)) {
            throw new \InvalidArgumentException('The method must be one of: ' . implode(', ', self::VERIFICATION_METHODS) . '.');
        }
        if (!$consent) {
            throw new \InvalidArgumentException("The farmer's consent must be recorded before verification.");
        }
        if ($beneficiary->isVerified()) {
            throw new \LogicException('This beneficiary is already verified.');
        }
        if (in_array($beneficiary->status, ['deceased', 'duplicate'], true)) {
            throw new \LogicException("A beneficiary marked {$beneficiary->status} can't be verified.");
        }

        $unknown = array_diff(array_keys($updates), self::VERIFICATION_FIELDS);
        if ($unknown) {
            throw new \InvalidArgumentException('These fields can\'t be changed at verification: ' . implode(', ', $unknown) . '.');
        }

        DB::transaction(function () use ($beneficiary, $method, $updates, $userUuid) {
            // First verification: card no. 1. Verified again after a rejection: a new
            // card number, so the card revoked at rejection stays revoked.
            $firstCard = $beneficiary->card_issue < 1;
            $issue     = $firstCard ? 1 : (int) $beneficiary->card_issue + 1;

            $beneficiary->fill($updates);
            $beneficiary->fill([
                'status'              => 'active',
                'verification_status' => 'verified',
                'verification_method' => $method,
                'verified_at'         => now(),
                'verified_by_uuid'    => $userUuid,
                'consent_at'          => now(),
                'card_issue'          => $issue,
            ]);
            $beneficiary->save();

            $this->log($beneficiary, $firstCard ? 'issued' : 'reissued', $firstCard ? $method : 're_verified', $userUuid);
        });

        return $beneficiary->refresh();
    }

    /**
     * Not found or not eligible in the field: reject, revoke any card, cancel untouched entitlements.
     */
    public function reject(Beneficiary $beneficiary, string $reason, ?string $userUuid = null): Beneficiary
    {
        if (!in_array($reason, self::REJECTION_REASONS, true)) {
            throw new \InvalidArgumentException('The reason must be one of: ' . implode(', ', self::REJECTION_REASONS) . '.');
        }

        DB::transaction(function () use ($beneficiary, $reason, $userUuid) {
            $hadCard = $beneficiary->isVerified() && $beneficiary->card_issue > 0;

            $beneficiary->update([
                'verification_status' => 'rejected',
                'status'              => match ($reason) {
                    'deceased'  => 'deceased',
                    'moved'     => 'moved',
                    'duplicate' => 'duplicate',
                    default     => 'suspended',
                },
                'meta' => array_merge($beneficiary->meta ?? [], ['rejection_reason' => $reason]),
            ]);

            if ($hadCard) {
                $this->log($beneficiary, 'revoked', $reason, $userUuid);
            }

            $this->entitlements->cancelUntouched($beneficiary, 'beneficiary_rejected');
        });

        return $beneficiary->refresh();
    }

    /**
     * Issue a replacement card. The previous card stops working.
     */
    public function reissue(Beneficiary $beneficiary, string $reason, ?string $userUuid = null): Beneficiary
    {
        if (!in_array($reason, self::REISSUE_REASONS, true)) {
            throw new \InvalidArgumentException('The reason must be one of: ' . implode(', ', self::REISSUE_REASONS) . '.');
        }
        if (!$beneficiary->isVerified() || $beneficiary->card_issue < 1) {
            throw new \LogicException('Only a verified beneficiary with a card can get a replacement.');
        }

        DB::transaction(function () use ($beneficiary, $reason, $userUuid) {
            // Lock the row so two reissues at once can't produce the same number.
            $locked = Beneficiary::whereKey($beneficiary->getKey())->lockForUpdate()->first();
            $locked->update(['card_issue' => $locked->card_issue + 1]);
            $beneficiary->card_issue = $locked->card_issue;
            $this->log($beneficiary, 'reissued', $reason, $userUuid);
        });

        return $beneficiary->refresh();
    }

    /**
     * Check a scanned or typed card code against the programme's current records.
     *
     * @return array{valid: bool, reason: ?string, beneficiary: ?array, entitlements: list<array>}
     */
    public function check(Programme $programme, string $code): array
    {
        $result = ProgrammeCard::verify($code, $this->programmeKey($programme), $programme->public_id);
        if (!$result->valid) {
            return ['valid' => false, 'reason' => $result->reason, 'beneficiary' => null, 'entitlements' => []];
        }

        $beneficiary = Beneficiary::where('programme_uuid', $programme->uuid)->where('public_id', $result->beneficiaryId)->first();
        $reason      = match (true) {
            $beneficiary === null                         => 'not_found',
            !$beneficiary->isVerified()                   => 'not_verified',
            $beneficiary->status !== 'active'             => 'not_active',
            $result->issueNumber !== $beneficiary->card_issue => 'revoked',
            default                                       => null,
        };

        if ($reason !== null) {
            return ['valid' => false, 'reason' => $reason, 'beneficiary' => null, 'entitlements' => []];
        }

        return [
            'valid'       => true,
            'reason'      => null,
            'beneficiary' => $beneficiary->only(['public_id', 'name', 'sex', 'birth_year', 'village', 'admin_2', 'admin_3', 'farmer_ref', 'card_issue']),
            'entitlements' => $beneficiary->entitlements()
                ->whereIn('status', [Entitlement::OPEN, Entitlement::PARTIALLY_COLLECTED])
                ->get(['sku', 'quantity', 'collected_quantity', 'unit', 'status'])
                ->toArray(),
        ];
    }

    /**
     * Print cards as an A4 PDF, 8 per page. Logs a "printed" event per card.
     *
     * @param Collection<int, Beneficiary> $beneficiaries
     *
     * @return array{pdf: string, count: int, batch: string, skipped: int}
     */
    public function pdf(Programme $programme, Collection $beneficiaries, ?string $userUuid = null): array
    {
        $printable = $beneficiaries->filter(fn (Beneficiary $b) => $b->isVerified() && $b->card_issue > 0 && $b->status === 'active')->values();
        if ($printable->isEmpty()) {
            throw new \LogicException('None of the selected beneficiaries has a card to print. Verify them first.');
        }
        if ($printable->count() > self::MAX_CARDS_PER_PDF) {
            throw new \LogicException('At most ' . self::MAX_CARDS_PER_PDF . ' cards can be printed at once. Print by chiefdom or village.');
        }

        $key     = $this->programmeKey($programme);
        $country = $this->countries->get($programme->country);
        $areas   = $this->areas->forCountry($country->code);
        $issuer  = $programme->rules['card_issuer'] ?? null;

        $cards = $printable->map(function (Beneficiary $b) use ($programme, $key, $areas, $country, $issuer) {
            $place = [];
            if ($b->admin_3 && ($area = $areas->find($b->admin_3))) {
                $place[] = $area->name . ' ' . ($area->levelLabel ?? $country->adminLevelLabel(3));
            }
            if ($b->admin_2 && ($area = $areas->find($b->admin_2))) {
                $place[] = $area->name;
            }

            return new CardData(
                code: ProgrammeCard::issue($key, $programme->public_id, $b->public_id, $b->card_issue),
                name: $b->name,
                beneficiaryId: $b->public_id,
                issueNumber: $b->card_issue,
                programmeName: $programme->name,
                season: $programme->season,
                farmerRef: $b->farmer_ref,
                sex: $b->sex,
                birthYear: $b->birth_year,
                village: $b->village,
                placeLine: $place ? implode(', ', $place) : null,
                issuer: $issuer,
            );
        })->all();

        $html    = (new CardSheetRenderer())->render($cards, $programme->name . ' - farmer cards');
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf  = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf = (string) $dompdf->output();

        $batch = 'print_' . Str::lower(Str::random(10));
        foreach ($printable as $b) {
            $this->log($b, 'printed', null, $userUuid, $batch);
        }

        return ['pdf' => $pdf, 'count' => $printable->count(), 'batch' => $batch, 'skipped' => $beneficiaries->count() - $printable->count()];
    }

    private function log(Beneficiary $beneficiary, string $action, ?string $reason, ?string $userUuid, ?string $batch = null): void
    {
        CardEvent::create([
            'company_uuid'     => $beneficiary->company_uuid,
            'programme_uuid'   => $beneficiary->programme_uuid,
            'beneficiary_uuid' => $beneficiary->uuid,
            'issue_number'     => $beneficiary->card_issue,
            'action'           => $action,
            'reason'           => $reason,
            'print_batch'      => $batch,
            'user_uuid'        => $userUuid,
        ]);
    }
}
