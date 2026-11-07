<?php

namespace Fleetbase\Pod\Support\Cards;

/**
 * What is printed on one farmer card.
 */
final class CardData
{
    public function __construct(
        public readonly string $code,          // the signed QR content (ProgrammeCard::issue)
        public readonly string $name,
        public readonly string $beneficiaryId,
        public readonly int $issueNumber,
        public readonly string $programmeName,
        public readonly ?string $season = null,
        public readonly ?string $farmerRef = null,
        public readonly ?string $sex = null,
        public readonly ?int $birthYear = null,
        public readonly ?string $village = null,
        public readonly ?string $placeLine = null,   // e.g. "Dea Chiefdom, Kailahun"
        public readonly ?string $issuer = null,      // the organisation running the programme
        public readonly ?string $photoDataUri = null,
    ) {
    }
}
