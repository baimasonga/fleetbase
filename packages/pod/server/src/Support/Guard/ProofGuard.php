<?php

namespace Fleetbase\Pod\Support\Guard;

/**
 * Decides whether an order activity update may go ahead without proof of
 * delivery (DESIGN.md §4.3, phase 0).
 *
 * The rule matches the one Fleet-Ops already applies to console updates:
 * proof is needed when the activity has `require_pod`, or when it completes
 * an order that has `pod_required`. This closes the gap where the public API
 * (used by the Navigator driver app) accepted such updates without proof.
 */
final class ProofGuard
{
    public const OFF     = 'off';
    public const LOG     = 'log';
    public const ENFORCE = 'enforce';

    public const ALLOW = 'allow';
    public const WARN  = 'warn';  // would be refused; allowed because the mode is "log"
    public const DENY  = 'deny';

    public function __construct(private string $mode)
    {
        if (!in_array($mode, [self::OFF, self::LOG, self::ENFORCE], true)) {
            throw new \InvalidArgumentException("Unknown proof guard mode [{$mode}]. Use off, log or enforce.");
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public static function requiresProof(bool $activityRequiresPod, bool $activityCompletesOrder, bool $orderPodRequired): bool
    {
        return $activityRequiresPod || ($activityCompletesOrder && $orderPodRequired);
    }

    /**
     * @param bool $hasValidProof a proof was sent and it belongs to this order
     */
    public function decide(bool $requiresProof, bool $hasValidProof): string
    {
        if ($this->mode === self::OFF || !$requiresProof || $hasValidProof) {
            return self::ALLOW;
        }

        return $this->mode === self::ENFORCE ? self::DENY : self::WARN;
    }
}
