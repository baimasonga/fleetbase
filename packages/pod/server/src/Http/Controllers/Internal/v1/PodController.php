<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

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

    protected function error(string $message, int $status = 422)
    {
        return response()->json(['errors' => [$message]], $status);
    }
}
