<?php

namespace Fleetbase\Pod\Support\Entitlements;

use Fleetbase\Pod\Support\Text\Text;

/**
 * Works out what each beneficiary is entitled to from a programme's rules
 * (AGRI-INPUTS.md §6 pod_entitlements, §9 caps, §10.1 livestock).
 *
 * A rule:
 *
 *   {
 *     "sku": "NPK-50",            the item
 *     "quantity": 2,              fixed amount, or the amount per unit of "per"
 *     "per": "farm_size_acres",   optional: household_size | farm_size_acres | farm_size_ha | livestock.<species>
 *     "round": "up",              with "per": up (default) | down | nearest
 *     "min": 1, "max": 4,         optional limits for the result
 *     "when": {                   optional conditions; all must hold
 *       "crop": ["rice"],         any of these (case ignored)
 *       "sex": "female",
 *       "type": "farmer",
 *       "admin_2": ["SL0101"],    p-codes at any admin level (admin_1..admin_4)
 *       "group": ["Kpandebu Women's FBO"]
 *     }
 *   }
 *
 * For each item, the FIRST rule that applies decides the quantity, so put
 * specific rules before general ones. Restricted items (pesticides,
 * veterinary drugs) are left out for beneficiaries without restricted-product
 * training, and every item's per-beneficiary maximum is applied last.
 */
final class EntitlementCalculator
{
    public const PER = ['household_size', 'farm_size_acres', 'farm_size_ha'];

    private const ACRES_PER_HECTARE = 2.4710538;

    /** @var array<string, ProgrammeItem> */
    private array $items = [];

    /**
     * @param list<ProgrammeItem> $items
     * @param list<array>         $rules
     */
    public function __construct(array $items, private array $rules)
    {
        foreach ($items as $item) {
            if (isset($this->items[$item->sku])) {
                throw new \InvalidArgumentException("Item {$item->sku} is listed twice.");
            }
            $this->items[$item->sku] = $item;
        }

        foreach ($rules as $i => $rule) {
            self::assertValidRule($rule, $i, $this->items);
        }
    }

    /**
     * @param array<string, ProgrammeItem> $items
     */
    public static function assertValidRule(array $rule, int $index, array $items): void
    {
        $where = 'Rule ' . ($index + 1);

        $sku = $rule['sku'] ?? null;
        if (!is_string($sku) || !isset($items[$sku])) {
            throw new \InvalidArgumentException("{$where}: item \"" . (is_string($sku) ? $sku : '') . '" is not in the programme\'s item list.');
        }
        if (!isset($rule['quantity']) || !is_numeric($rule['quantity']) || $rule['quantity'] <= 0) {
            throw new \InvalidArgumentException("{$where}: quantity must be more than 0.");
        }
        if (isset($rule['per']) && !in_array($rule['per'], self::PER, true) && !preg_match('/^livestock\.[a-z_]+$/', (string) $rule['per'])) {
            throw new \InvalidArgumentException("{$where}: \"per\" must be one of " . implode(', ', self::PER) . ' or livestock.<species>.');
        }
        if (isset($rule['round']) && !in_array($rule['round'], ['up', 'down', 'nearest'], true)) {
            throw new \InvalidArgumentException("{$where}: \"round\" must be up, down or nearest.");
        }
        foreach (['min', 'max'] as $limit) {
            if (isset($rule[$limit]) && (!is_numeric($rule[$limit]) || $rule[$limit] < 0)) {
                throw new \InvalidArgumentException("{$where}: \"{$limit}\" must be a number of 0 or more.");
            }
        }
        if (isset($rule['min'], $rule['max']) && $rule['min'] > $rule['max']) {
            throw new \InvalidArgumentException("{$where}: \"min\" is more than \"max\".");
        }
        $allowed = ['crop', 'sex', 'type', 'group', 'admin_1', 'admin_2', 'admin_3', 'admin_4'];
        foreach (array_keys($rule['when'] ?? []) as $condition) {
            if (!in_array($condition, $allowed, true)) {
                throw new \InvalidArgumentException("{$where}: unknown condition \"{$condition}\". Use " . implode(', ', $allowed) . '.');
            }
        }
    }

    /**
     * @param array $beneficiary fields: type, sex, crop, group_name, admin_1..4, household_size,
     *                           farm_size, livestock (species => count), trained_restricted_products (bool or date)
     *
     * @return array{lines: list<array{sku: string, quantity: float, unit: string, rule: int}>, skipped: list<array{sku: string, reason: string, rule: int}>}
     */
    public function calculate(array $beneficiary): array
    {
        $lines   = [];
        $skipped = [];
        $decided = [];

        foreach ($this->rules as $index => $rule) {
            $sku = $rule['sku'];
            if (isset($decided[$sku]) || !$this->conditionsHold($rule['when'] ?? [], $beneficiary)) {
                continue;
            }
            $decided[$sku] = true;
            $item          = $this->items[$sku];

            if ($item->restricted && empty($beneficiary['trained_restricted_products'])) {
                $skipped[] = ['sku' => $sku, 'reason' => 'not_trained', 'rule' => $index];
                continue;
            }

            $quantity = $this->quantity($rule, $beneficiary);
            if ($quantity === null) {
                $skipped[] = ['sku' => $sku, 'reason' => 'no_' . str_replace('.', '_', $rule['per']), 'rule' => $index];
                continue;
            }

            if ($item->maxPerBeneficiary !== null) {
                $quantity = min($quantity, $item->maxPerBeneficiary);
            }
            if ($quantity <= 0) {
                $skipped[] = ['sku' => $sku, 'reason' => 'zero', 'rule' => $index];
                continue;
            }

            $lines[] = ['sku' => $sku, 'quantity' => $quantity, 'unit' => $item->unit, 'rule' => $index];
        }

        return ['lines' => $lines, 'skipped' => $skipped];
    }

    private function quantity(array $rule, array $beneficiary): ?float
    {
        $quantity = (float) $rule['quantity'];

        if (isset($rule['per'])) {
            $basis = $this->basis($rule['per'], $beneficiary);
            if ($basis === null || $basis <= 0) {
                return null;
            }
            $quantity = match ($rule['round'] ?? 'up') {
                'down'    => floor($quantity * $basis),
                'nearest' => round($quantity * $basis),
                default   => ceil(round($quantity * $basis, 6)),
            };
        }

        if (isset($rule['min'])) {
            $quantity = max($quantity, (float) $rule['min']);
        }
        if (isset($rule['max'])) {
            $quantity = min($quantity, (float) $rule['max']);
        }

        return $quantity;
    }

    private function basis(string $per, array $beneficiary): ?float
    {
        if ($per === 'household_size') {
            return isset($beneficiary['household_size']) ? (float) $beneficiary['household_size'] : null;
        }
        if ($per === 'farm_size_acres' || $per === 'farm_size_ha') {
            $hectares = self::farmSizeHectares($beneficiary['farm_size'] ?? null);
            if ($hectares === null) {
                return null;
            }

            return $per === 'farm_size_ha' ? $hectares : $hectares * self::ACRES_PER_HECTARE;
        }
        if (str_starts_with($per, 'livestock.')) {
            $species = substr($per, strlen('livestock.'));
            $count   = $beneficiary['livestock'][$species] ?? null;

            return is_numeric($count) ? (float) $count : null;
        }

        return null;
    }

    /**
     * Read a farm size as typed in lists: "2 acres", "1.5 ha", "0.5 hectare", "3".
     * A bare number is taken as acres, the unit most used by farmers in the region.
     */
    public static function farmSizeHectares(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? $value / self::ACRES_PER_HECTARE : null;
        }

        $text = strtolower(trim((string) $value));
        if ($text === '' || !preg_match('/^(\d+(?:[.,]\d+)?)\s*([a-z.]*)$/', $text, $m)) {
            return null;
        }

        $number = (float) str_replace(',', '.', $m[1]);
        if ($number <= 0) {
            return null;
        }

        return match (rtrim($m[2], '.')) {
            'ha', 'hectare', 'hectares', 'hect' => $number,
            '', 'ac', 'acre', 'acres'          => $number / self::ACRES_PER_HECTARE,
            default                            => null,
        };
    }

    private function conditionsHold(array $when, array $beneficiary): bool
    {
        foreach ($when as $field => $expected) {
            $expected = array_map(fn ($v) => Text::key((string) $v), (array) $expected);

            $actual = match ($field) {
                'group' => $beneficiary['group_name'] ?? null,
                default => $beneficiary[$field] ?? null,
            };

            if ($field === 'crop') {
                // A beneficiary may list several crops: "Rice, Cassava".
                $crops = array_map(fn ($c) => Text::key($c), preg_split('/[,;\/&]|\band\b/i', (string) $actual) ?: []);
                if (!array_intersect($expected, array_filter($crops))) {
                    return false;
                }
                continue;
            }

            if (str_starts_with($field, 'admin_')) {
                // p-codes are compared as given, not as text keys.
                if (!in_array((string) $actual, (array) $when[$field], true)) {
                    return false;
                }
                continue;
            }

            if (!in_array(Text::key((string) $actual), $expected, true)) {
                return false;
            }
        }

        return true;
    }
}
