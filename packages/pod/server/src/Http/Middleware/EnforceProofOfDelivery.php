<?php

namespace Fleetbase\Pod\Http\Middleware;

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Pod\Support\Guard\ProofGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Phase 0 proof guard (DESIGN.md §4.3) on the public API's
 * POST|PATCH /v1/orders/{id}/update-activity, the endpoint the Navigator
 * driver app uses.
 *
 * Fleet-Ops already refuses console updates that need proof of delivery and
 * have none; the public API did not. This applies the same rule there:
 * proof is needed when the activity has `require_pod`, or when it completes
 * an order with `pod_required`. The proof sent must exist and belong to the
 * order. In "log" mode nothing is refused; what would have been is logged.
 *
 * Attached by PodServiceProvider when the route is matched, so Fleet-Ops
 * itself is not changed. It runs after the route's own middleware, so the
 * API key has been checked and the company is in the session.
 */
class EnforceProofOfDelivery
{
    public function handle(Request $request, \Closure $next)
    {
        $guard = new ProofGuard((string) config('pod.guard.mode', ProofGuard::LOG));
        if ($guard->mode() === ProofGuard::OFF) {
            return $next($request);
        }

        $order = $this->order((string) $request->route('id'));
        if (!$order || $order->status === 'completed') {
            // Let Fleet-Ops answer: unknown order, or already completed.
            return $next($request);
        }

        try {
            $input    = $request->input('activity', []);
            $activity = new Activity(is_array($input) ? $input : [], $order->getConfigFlow());
        } catch (\Throwable $e) {
            // Never block a delivery because of a fault in the guard itself, but
            // make it visible: a silent failure would quietly switch the guard off.
            $this->logger()->error('[pod] Proof guard could not read the activity; the update was allowed', [
                'order' => $order->public_id,
                'error' => $e->getMessage(),
            ]);

            return $next($request);
        }

        if (!Utils::isActivity($activity)) {
            return $next($request);
        }

        $requires = ProofGuard::requiresProof(
            (bool) $activity->get('require_pod'),
            $activity->completesOrder(),
            (bool) $order->pod_required,
        );
        $hasProof = $requires && $this->proofBelongsToOrder($request->input('proof'), $order);
        $decision = $guard->decide($requires, $hasProof);

        if ($decision === ProofGuard::ALLOW) {
            return $next($request);
        }

        $context = [
            'decision'   => $decision,
            'order'      => $order->public_id,
            'company'    => $order->company_uuid,
            'activity'   => $activity->code ?? null,
            'pod_method' => $order->pod_method,
            'proof_sent' => $request->filled('proof'),
        ];
        $this->logger()->warning('[pod] Order activity update without required proof of delivery', $context);

        if ($decision === ProofGuard::WARN) {
            return $next($request);
        }

        return response()->json([
            'errors'  => ['Proof of delivery is required for this activity.'],
            'error'   => 'proof_required',
            'missing' => [['type' => $order->pod_method ?: 'any', 'subject' => $order->public_id]],
        ], 422);
    }

    private function logger(): \Psr\Log\LoggerInterface
    {
        $channel = config('pod.guard.log_channel');

        return $channel ? Log::channel($channel) : Log::driver();
    }

    private function order(string $id): ?Order
    {
        if ($id === '') {
            return null;
        }

        return Order::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->first();
    }

    private function proofBelongsToOrder(mixed $proof, Order $order): bool
    {
        if (!is_string($proof) || $proof === '') {
            return false;
        }

        return Proof::where('order_uuid', $order->uuid)
            ->where(fn ($query) => $query->where('public_id', $proof)->orWhere('uuid', $proof))
            ->exists();
    }
}
