<?php

namespace Fleetbase\Pod\Support\Cards;

final class CardVerification
{
    private function __construct(
        public readonly bool $valid,
        public readonly ?string $reason,
        public readonly ?string $programmeId,
        public readonly ?string $beneficiaryId,
        public readonly ?int $issueNumber,
    ) {
    }

    public static function valid(string $programmeId, string $beneficiaryId, int $issue): self
    {
        return new self(true, null, $programmeId, $beneficiaryId, $issue);
    }

    /**
     * @param string $reason malformed | bad_signature | wrong_programme | not_in_manifest | revoked
     */
    public static function invalid(string $reason, ?string $programmeId = null, ?string $beneficiaryId = null, ?int $issue = null): self
    {
        return new self(false, $reason, $programmeId, $beneficiaryId, $issue);
    }

    public function toArray(): array
    {
        return [
            'valid'          => $this->valid,
            'reason'         => $this->reason,
            'programme_id'   => $this->programmeId,
            'beneficiary_id' => $this->beneficiaryId,
            'issue_number'   => $this->issueNumber,
        ];
    }
}
