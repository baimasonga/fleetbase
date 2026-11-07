<?php

namespace Fleetbase\Pod\Support\Policy;

/**
 * Identity levels at a farmer handover (AGRI-INPUTS.md §7.2).
 *
 *   L3  programme card + government ID (last 4 match) + face match
 *   L2  programme card + face match
 *   L1  no card: photo + witness confirmation by a registered chief, group leader or extension officer
 */
final class IdentityLevels
{
    public const L1 = 'L1';
    public const L2 = 'L2';
    public const L3 = 'L3';

    public static function rank(?string $level): int
    {
        return match ($level) {
            self::L3 => 3,
            self::L2 => 2,
            self::L1 => 1,
            default  => 0,
        };
    }

    /**
     * Work out the level from the checks done at the handover.
     * Returns null when the checks aren't enough for any level.
     */
    public static function determine(
        bool $cardValid,
        bool $faceMatched,
        bool $governmentIdMatched,
        bool $photoTaken,
        bool $witnessConfirmed,
    ): ?string {
        if ($cardValid && $faceMatched && $governmentIdMatched) {
            return self::L3;
        }
        if ($cardValid && $faceMatched) {
            return self::L2;
        }
        if ($photoTaken && $witnessConfirmed) {
            return self::L1;
        }

        return null;
    }
}
