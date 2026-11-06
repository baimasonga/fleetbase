<?php

namespace Fleetbase\Pod\Support\Countries;

use Fleetbase\Pod\Support\Text\Text;

/**
 * Country-specific settings: phone code, admin level labels, ID types
 * (AGRI-INPUTS.md §16.1).
 */
final class CountryProfile
{
    /**
     * @param array<int, string>                                          $adminLevels level => label
     * @param array<string, array{label: string, aliases?: list<string>}> $idTypes
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $dialCode,
        public readonly string $timezone,
        public readonly array $adminLevels,
        public readonly array $idTypes,
        public readonly ?string $seedFile = null,
    ) {
    }

    public static function fromArray(string $code, array $data): self
    {
        return new self(
            code: strtoupper($code),
            name: $data['name'] ?? strtoupper($code),
            dialCode: $data['dial_code'] ?? '',
            timezone: $data['timezone'] ?? 'UTC',
            adminLevels: $data['admin_levels'] ?? [],
            idTypes: $data['id_types'] ?? ['other' => ['label' => 'Other']],
            seedFile: $data['seed_file'] ?? null,
        );
    }

    public function adminLevelLabel(int $level): string
    {
        return $this->adminLevels[$level] ?? ('Level ' . $level);
    }

    /**
     * Map whatever a spreadsheet says ("Voter's Card", "NIN", "national id") to an ID type key.
     * Returns null for an empty value and 'other' for anything unrecognised.
     */
    public function resolveIdType(?string $raw): ?string
    {
        $key = Text::key($raw);
        if ($key === '') {
            return null;
        }

        foreach ($this->idTypes as $type => $definition) {
            if ($key === Text::key($type) || $key === Text::key($definition['label'] ?? '')) {
                return $type;
            }
            foreach ($definition['aliases'] ?? [] as $alias) {
                if ($key === Text::key($alias)) {
                    return $type;
                }
            }
        }

        return array_key_exists('other', $this->idTypes) ? 'other' : null;
    }

    /**
     * @return array<string, string> type => label
     */
    public function idTypeLabels(): array
    {
        return array_map(fn ($definition) => $definition['label'] ?? '', $this->idTypes);
    }
}
