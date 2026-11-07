<?php

namespace Fleetbase\Pod\Support\Entitlements;

/**
 * One input a programme distributes (AGRI-INPUTS.md §5, §9, §10), e.g.
 * "NPK 15-15-15, 50 kg bag". Quantities in entitlements are in `unit`.
 */
final class ProgrammeItem
{
    public const CATEGORIES = ['seed', 'fertiliser', 'pesticide', 'ppe', 'tool', 'feed', 'vet_drug', 'vaccine', 'other'];

    public const UNITS = ['bag', 'kg', 'litre', 'bottle', 'dose', 'piece', 'pack'];

    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly string $category,
        public readonly string $unit,
        public readonly ?float $packSize = null,       // e.g. 50 (kg per bag)
        public readonly ?string $packUnit = null,      // e.g. kg
        public readonly bool $restricted = false,      // pesticides and veterinary drugs
        public readonly bool $coldChain = false,       // vaccines and some drugs
        public readonly ?float $maxPerBeneficiary = null,
    ) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}$/', $sku)) {
            throw new \InvalidArgumentException("SKU \"{$sku}\" may only use letters, digits, dots, dashes and underscores.");
        }
        if (trim($name) === '') {
            throw new \InvalidArgumentException("Item {$sku} needs a name.");
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException("Item {$sku}: unknown category \"{$category}\".");
        }
        if (!in_array($unit, self::UNITS, true)) {
            throw new \InvalidArgumentException("Item {$sku}: unknown unit \"{$unit}\".");
        }
        if ($maxPerBeneficiary !== null && $maxPerBeneficiary <= 0) {
            throw new \InvalidArgumentException("Item {$sku}: the maximum per beneficiary must be more than 0.");
        }
    }

    public static function fromArray(array $data): self
    {
        $category = $data['category'] ?? 'other';

        return new self(
            sku: trim((string) ($data['sku'] ?? '')),
            name: trim((string) ($data['name'] ?? '')),
            category: $category,
            unit: $data['unit'] ?? 'bag',
            packSize: isset($data['pack_size']) && $data['pack_size'] !== '' ? (float) $data['pack_size'] : null,
            packUnit: $data['pack_unit'] ?? null,
            // Pesticides, veterinary drugs and vaccines are restricted unless the programme says otherwise.
            restricted: (bool) ($data['restricted'] ?? in_array($category, ['pesticide', 'vet_drug', 'vaccine'], true)),
            coldChain: (bool) ($data['cold_chain'] ?? ($category === 'vaccine')),
            maxPerBeneficiary: isset($data['max_per_beneficiary']) && $data['max_per_beneficiary'] !== '' ? (float) $data['max_per_beneficiary'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'sku'                 => $this->sku,
            'name'                => $this->name,
            'category'            => $this->category,
            'unit'                => $this->unit,
            'pack_size'           => $this->packSize,
            'pack_unit'           => $this->packUnit,
            'restricted'          => $this->restricted,
            'cold_chain'          => $this->coldChain,
            'max_per_beneficiary' => $this->maxPerBeneficiary,
        ];
    }
}
