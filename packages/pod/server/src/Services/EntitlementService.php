<?php

namespace Fleetbase\Pod\Services;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\Entitlement;
use Fleetbase\Pod\Models\Programme;
use Fleetbase\Pod\Models\ProgrammeItem;
use Fleetbase\Pod\Support\Entitlements\EntitlementCalculator;
use Fleetbase\Pod\Support\Entitlements\ProgrammeItem as ItemValue;
use Illuminate\Support\Facades\DB;

/**
 * Programme items, entitlement rules and entitlement generation
 * (AGRI-INPUTS.md §6, §7.1 step 4, REPORTING-AUDIT.md AR-27).
 *
 * Generation is safe to repeat. It:
 *  - creates entitlements for eligible beneficiaries (verified, or not yet
 *    verified when the programme verifies at the first handover);
 *  - updates quantities that changed, but only while nothing has been
 *    handed over;
 *  - cancels untouched entitlements that no longer apply (rule removed,
 *    beneficiary rejected or no longer eligible);
 *  - never changes an entitlement that has been partly or fully collected.
 */
class EntitlementService
{
    public function __construct(private AdminAreaRepository $areas)
    {
    }

    /**
     * Replace the programme's item list.
     *
     * @param list<array> $items
     *
     * @return list<ProgrammeItem>
     */
    public function setItems(Programme $programme, array $items): array
    {
        $values = [];
        foreach ($items as $i => $data) {
            try {
                $value = ItemValue::fromArray($data);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException('Item ' . ($i + 1) . ': ' . $e->getMessage());
            }
            if (isset($values[$value->sku])) {
                throw new \InvalidArgumentException("Item {$value->sku} is listed twice.");
            }
            $values[$value->sku] = $value;
        }

        $removed = ProgrammeItem::where('programme_uuid', $programme->uuid)->whereNotIn('sku', array_keys($values))->pluck('sku')->all();
        if ($removed) {
            $inUse = Entitlement::where('programme_uuid', $programme->uuid)->whereIn('sku', $removed)->where('status', '!=', Entitlement::CANCELLED)->distinct()->pluck('sku')->all();
            if ($inUse) {
                throw new \LogicException('These items still have entitlements: ' . implode(', ', $inUse) . '. Remove their rules and regenerate entitlements first.');
            }
        }

        $ruleSkus = array_column($programme->entitlementRules(), 'sku');
        $orphaned = array_diff($ruleSkus, array_keys($values));
        if ($orphaned) {
            throw new \LogicException('These items are used by entitlement rules: ' . implode(', ', array_unique($orphaned)) . '. Change the rules first.');
        }

        DB::transaction(function () use ($programme, $values, $removed) {
            ProgrammeItem::where('programme_uuid', $programme->uuid)->whereIn('sku', $removed)->delete();
            $sort = 0;
            foreach ($values as $value) {
                ProgrammeItem::updateOrCreate(
                    ['programme_uuid' => $programme->uuid, 'sku' => $value->sku],
                    $value->toArray() + ['company_uuid' => $programme->company_uuid, 'sort' => $sort++]
                );
            }
        });

        return $programme->items()->get()->all();
    }

    /**
     * Replace the programme's entitlement rules, after checking them against its items.
     */
    public function setRules(Programme $programme, array $rules): array
    {
        if (!array_is_list($rules)) {
            throw new \InvalidArgumentException('The rules must be a list.');
        }

        $this->calculator($programme, $rules); // validates

        $programme->rules = array_merge($programme->rules ?? [], ['entitlements' => $rules]);
        $programme->save();

        return $rules;
    }

    /**
     * @return array{eligible: int, not_eligible: int, created: int, updated: int, cancelled: int, unchanged: int, locked: int, skipped: array<string, int>, totals: array<string, float>, dry_run: bool}
     */
    public function generate(Programme $programme, bool $dryRun = false): array
    {
        $calculator = $this->calculator($programme, $programme->entitlementRules());
        $model      = $programme->defaultDeliveryModel();

        $result = ['eligible' => 0, 'not_eligible' => 0, 'created' => 0, 'updated' => 0, 'cancelled' => 0, 'unchanged' => 0, 'locked' => 0, 'skipped' => [], 'totals' => [], 'dry_run' => $dryRun];

        DB::beginTransaction();
        try {
            Beneficiary::where('programme_uuid', $programme->uuid)
                ->with('entitlements')
                ->orderBy('id')
                ->chunk(500, function ($beneficiaries) use ($programme, $calculator, $model, &$result) {
                    foreach ($beneficiaries as $beneficiary) {
                        $this->generateFor($programme, $beneficiary, $calculator, $model, $result);
                    }
                });

            $result['totals'] = Entitlement::where('programme_uuid', $programme->uuid)
                ->whereIn('status', [Entitlement::OPEN, Entitlement::PARTIALLY_COLLECTED, Entitlement::COLLECTED])
                ->groupBy('sku')
                ->selectRaw('sku, SUM(quantity) as total')
                ->pluck('total', 'sku')
                ->map(fn ($total) => (float) $total)
                ->all();

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        ksort($result['skipped']);
        ksort($result['totals']);

        return $result;
    }

    private function generateFor(Programme $programme, Beneficiary $beneficiary, EntitlementCalculator $calculator, string $model, array &$result): void
    {
        $existing = $beneficiary->entitlements->keyBy('sku');
        $eligible = $this->isEligible($programme, $beneficiary);

        $lines = [];
        if ($eligible) {
            $result['eligible']++;
            $calculated = $calculator->calculate($beneficiary->toEntitlementInput());
            foreach ($calculated['lines'] as $line) {
                $lines[$line['sku']] = $line;
            }
            foreach ($calculated['skipped'] as $skip) {
                $key                     = $skip['sku'] . ':' . $skip['reason'];
                $result['skipped'][$key] = ($result['skipped'][$key] ?? 0) + 1;
            }
        } else {
            $result['not_eligible']++;
        }

        foreach ($lines as $sku => $line) {
            $entitlement = $existing->get($sku);

            if (!$entitlement) {
                Entitlement::create([
                    'company_uuid'     => $programme->company_uuid,
                    'programme_uuid'   => $programme->uuid,
                    'beneficiary_uuid' => $beneficiary->uuid,
                    'sku'              => $sku,
                    'quantity'         => $line['quantity'],
                    'unit'             => $line['unit'],
                    'status'           => Entitlement::OPEN,
                    'delivery_model'   => $model,
                    'rule_index'       => $line['rule'],
                ]);
                $result['created']++;
                continue;
            }

            if ($entitlement->status === Entitlement::CANCELLED) {
                // A rule or eligibility came back: reopen with the new quantity.
                $entitlement->update(['status' => Entitlement::OPEN, 'quantity' => $line['quantity'], 'unit' => $line['unit'], 'rule_index' => $line['rule'], 'cancel_reason' => null, 'collected_quantity' => 0]);
                $result['updated']++;
                continue;
            }

            if (!$entitlement->isUntouched()) {
                $result['locked']++;
                continue;
            }

            if (abs($entitlement->quantity - $line['quantity']) > 0.0005 || $entitlement->unit !== $line['unit'] || $entitlement->rule_index !== $line['rule']) {
                $entitlement->update(['quantity' => $line['quantity'], 'unit' => $line['unit'], 'rule_index' => $line['rule']]);
                $result['updated']++;
            } else {
                $result['unchanged']++;
            }
        }

        // Entitlements with no line any more.
        foreach ($existing as $sku => $entitlement) {
            if (isset($lines[$sku]) || $entitlement->status === Entitlement::CANCELLED) {
                continue;
            }
            if (!$entitlement->isUntouched()) {
                $result['locked']++;
                continue;
            }
            $entitlement->update(['status' => Entitlement::CANCELLED, 'cancel_reason' => $eligible ? 'rule_removed' : 'not_eligible']);
            $result['cancelled']++;
        }
    }

    private function isEligible(Programme $programme, Beneficiary $beneficiary): bool
    {
        if ($beneficiary->status !== 'active') {
            return false;
        }
        if ($beneficiary->verification_status === 'verified') {
            return true;
        }

        return $beneficiary->verification_status === 'unverified' && $programme->allowsUnverifiedEntitlements();
    }

    /**
     * Cancel untouched entitlements of one beneficiary (e.g. after rejection).
     */
    public function cancelUntouched(Beneficiary $beneficiary, string $reason): int
    {
        return Entitlement::where('beneficiary_uuid', $beneficiary->uuid)
            ->where('status', Entitlement::OPEN)
            ->where('collected_quantity', '<=', 0)
            ->update(['status' => Entitlement::CANCELLED, 'cancel_reason' => $reason]);
    }

    /**
     * Planned quantities by area and item, for procurement and dispatch.
     *
     * @param int $level admin level to group by (2 = district, 3 = chiefdom)
     *
     * @return list<array{pcode: ?string, area: ?string, sku: string, beneficiaries: int, quantity: float, collected: float, unit: string}>
     */
    public function summary(Programme $programme, int $level = 2): array
    {
        if ($level < 1 || $level > 4) {
            throw new \InvalidArgumentException('The level must be 1 to 4.');
        }
        $column = 'pod_beneficiaries.admin_' . $level;

        $rows = DB::table('pod_entitlements')
            ->join('pod_beneficiaries', 'pod_beneficiaries.uuid', '=', 'pod_entitlements.beneficiary_uuid')
            ->where('pod_entitlements.programme_uuid', $programme->uuid)
            ->where('pod_entitlements.status', '!=', Entitlement::CANCELLED)
            ->groupBy($column, 'pod_entitlements.sku', 'pod_entitlements.unit')
            ->orderBy($column)
            ->orderBy('pod_entitlements.sku')
            ->selectRaw("{$column} as pcode, pod_entitlements.sku as sku, pod_entitlements.unit as unit, COUNT(DISTINCT pod_entitlements.beneficiary_uuid) as beneficiaries, SUM(pod_entitlements.quantity) as quantity, SUM(pod_entitlements.collected_quantity) as collected")
            ->get();

        $areas = $this->areas->forCountry($programme->country);

        return $rows->map(fn ($row) => [
            'pcode'         => $row->pcode,
            'area'          => $row->pcode ? $areas->find($row->pcode)?->name : null,
            'sku'           => $row->sku,
            'unit'          => $row->unit,
            'beneficiaries' => (int) $row->beneficiaries,
            'quantity'      => (float) $row->quantity,
            'collected'     => (float) $row->collected,
        ])->all();
    }

    private function calculator(Programme $programme, array $rules): EntitlementCalculator
    {
        $items = $programme->items()->get()->map(fn (ProgrammeItem $item) => $item->toValue())->all();

        return new EntitlementCalculator($items, $rules);
    }
}
