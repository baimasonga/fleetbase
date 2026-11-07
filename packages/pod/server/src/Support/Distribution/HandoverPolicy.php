<?php

namespace Fleetbase\Pod\Support\Distribution;

use Fleetbase\Pod\Support\Policy\RequirementTree;

/**
 * The proof a farmer handover needs (AGRI-INPUTS.md §8): the programme's base
 * policy, plus conditional requirements for this handover.
 */
final class HandoverPolicy
{
    /**
     * The default when a programme sets none.
     */
    public const DEFAULT = ['all' => [
        ['type' => 'beneficiary_id', 'min_level' => 'L1'],
        ['type' => 'line_quantities', 'match' => 'entitlement'],
        ['type' => 'lot_scan', 'coverage' => 'all_lines'],
        ['type' => 'photo', 'min' => 1],
        ['any' => [['type' => 'signature'], ['type' => 'mark'], ['type' => 'thumbprint_image']]],
    ]];

    /**
     * @param array|null $base       the programme's policy, or null for the default
     * @param bool       $proxy      collected by a proxy
     * @param string     $level      identity level reached (L1, L2, L3)
     * @param bool       $restricted a pesticide or veterinary drug is handed over
     */
    public static function build(?array $base, bool $proxy, ?string $level, bool $restricted): array
    {
        $extra = [];
        if ($proxy || $level === 'L1') {
            $extra[] = ['type' => 'witness_signature'];
        }
        if ($restricted) {
            $extra[] = ['type' => 'safety_ack'];
        }

        return RequirementTree::allOf($base ?: self::DEFAULT, ...$extra);
    }
}
