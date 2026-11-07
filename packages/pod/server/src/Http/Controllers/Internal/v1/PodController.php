<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\Discrepancy;
use Fleetbase\Pod\Models\DistributionEvent;
use Fleetbase\Pod\Models\Site;
use Fleetbase\Pod\Models\TransportLeg;
use Fleetbase\Pod\Services\HandoverRefused;
use Fleetbase\Pod\Models\ImportBatch;
use Fleetbase\Pod\Models\Programme;
use Illuminate\Routing\Controller;

/**
 * Shared helpers. Every lookup is scoped to the signed-in user's company.
 */
abstract class PodController extends Controller
{
    protected function companyUuid(): string
    {
        $company = session('company');
        abort_unless(is_string($company) && $company !== '', 401, 'No company in the session.');

        return $company;
    }

    protected function userUuid(): ?string
    {
        $user = session('user');

        return is_string($user) ? $user : null;
    }

    protected function programme(string $id): Programme
    {
        return Programme::where('company_uuid', $this->companyUuid())
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
            ->firstOrFail();
    }

    protected function batch(string $id): ImportBatch
    {
        return ImportBatch::where('company_uuid', $this->companyUuid())
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
            ->firstOrFail();
    }

    protected function beneficiary(string $id): Beneficiary
    {
        return Beneficiary::where('company_uuid', $this->companyUuid())
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
            ->firstOrFail();
    }

    protected function site(string $id): Site
    {
        return $this->scoped(Site::query(), $id);
    }

    protected function leg(string $id): TransportLeg
    {
        return $this->scoped(TransportLeg::query(), $id);
    }

    protected function event(string $id): DistributionEvent
    {
        return $this->scoped(DistributionEvent::query(), $id);
    }

    protected function discrepancyRecord(string $id): Discrepancy
    {
        return $this->scoped(Discrepancy::query(), $id);
    }

    protected function scoped($query, string $id)
    {
        return $query->where('company_uuid', $this->companyUuid())
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
            ->firstOrFail();
    }

    /**
     * Run an action and turn rule refusals into 422 answers.
     */
    protected function guarded(callable $action)
    {
        try {
            return $action();
        } catch (HandoverRefused $e) {
            return response()->json($e->toArray(), 422);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    protected function error(string $message, int $status = 422)
    {
        return response()->json(['errors' => [$message]], $status);
    }
}
