<?php

namespace Fleetbase\Pod\Support\AdminAreas;

/**
 * The result of matching one typed place name (e.g. a chiefdom in a
 * spreadsheet row) to an official admin area.
 */
final class PlaceMatch
{
    public const EXACT  = 'exact';   // same name, ignoring case, punctuation and words like "Chiefdom"
    public const ALIAS  = 'alias';   // matched a known alternative name or the source spelling
    public const PREFIX = 'prefix';  // the official name looks cut off and the typed name starts with it
    public const FUZZY  = 'fuzzy';   // close spelling, above the similarity threshold
    public const NONE   = 'none';    // no confident match
    public const EMPTY  = 'empty';   // nothing typed

    /**
     * @param list<string> $suggestions closest official names, for the fix-up screen
     */
    public function __construct(
        public readonly int $level,
        public readonly string $input,
        public readonly string $method,
        public readonly ?AdminArea $area = null,
        public readonly float $score = 0.0,
        public readonly array $suggestions = [],
    ) {
    }

    public function matched(): bool
    {
        return $this->area !== null;
    }

    /**
     * True when the match is good enough to accept without a person checking it.
     */
    public function isConfident(): bool
    {
        return in_array($this->method, [self::EXACT, self::ALIAS, self::PREFIX], true);
    }

    public function toArray(): array
    {
        return [
            'level'       => $this->level,
            'input'       => $this->input,
            'method'      => $this->method,
            'pcode'       => $this->area?->pcode,
            'name'        => $this->area?->name,
            'score'       => round($this->score, 3),
            'suggestions' => $this->suggestions,
        ];
    }
}
